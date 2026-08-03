<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Adapter\LazyMiddleware;
use Kode\Middleware\Blueprint;
use Kode\Middleware\Contract\TerminableInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Pipeline;
use Kode\Middleware\Registry;
use Kode\Middleware\Resolver;
use Kode\Middleware\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 健壮性测试
 *
 * 专门覆盖"配置写错 / 数据不可信 / 收尾链断裂"这类容易被忽略、
 * 一旦发生却极难排查的场景。
 *
 * @package Kode\Middleware\Tests
 */
#[CoversNothing]
final class RobustnessTest extends TestCase
{
    // ---------------------------------------------------------------- 循环引用

    #[Test]
    public function testSelfAliasIsRejectedAtRegistration(): void
    {
        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(1006);

        (new Registry())->alias('auth', 'auth');
    }

    #[Test]
    public function testSelfReferencingGroupIsRejectedAtRegistration(): void
    {
        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(1006);

        (new Registry())->group('web', ['session', 'web']);
    }

    #[Test]
    public function testMutualAliasCycleIsDetectedOnResolve(): void
    {
        $registry = (new Registry())
            ->alias('a', 'b')
            ->alias('b', 'a');

        $resolver = new Resolver(null, $registry);

        try {
            $resolver->resolve('a');
            self::fail('互相引用的别名必须被拦截');
        } catch (MiddlewareException $e) {
            self::assertSame(1006, $e->getCode());
            self::assertSame(['a', 'b', 'a'], $e->context()['chain']);
        }
    }

    #[Test]
    public function testGroupCycleThroughAnotherGroupIsDetected(): void
    {
        $registry = (new Registry())
            ->group('web', ['api'])
            ->group('api', ['web']);

        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(1006);

        (new Resolver(null, $registry))->resolve('web');
    }

    #[Test]
    public function testDeepButAcyclicAliasChainStillResolves(): void
    {
        $registry = new Registry();

        // a0 → a1 → … → a9 → CountingMiddleware
        for ($i = 0; $i < 9; $i++) {
            $registry->alias("a{$i}", 'a' . ($i + 1));
        }
        $registry->alias('a9', CountingMiddleware::class);

        $middleware = (new Resolver(null, $registry))->resolve('a0');

        self::assertInstanceOf(MiddlewareInterface::class, $middleware);
    }

    // ------------------------------------------------------------------- 收尾

    #[Test]
    public function testTerminateNeverInstantiatesSkippedLazyMiddleware(): void
    {
        $created = 0;

        $lazy = new LazyMiddleware(
            function () use (&$created): MiddlewareInterface {
                $created++;

                return new CountingMiddleware();
            },
            'lazy'
        );

        // 前置中间件直接短路，惰性层根本不会被执行
        $pipeline = Pipeline::of([Factory::shortCircuit(), $lazy])
            ->withDestination(Factory::endpoint());

        $request = Factory::request();
        $response = $pipeline->handle($request);
        $pipeline->terminate($request, $response);

        self::assertSame(0, $created, '收尾阶段绝不能唤醒被短路的惰性中间件');
    }

    #[Test]
    public function testTerminateCascadesThroughNestedPipelines(): void
    {
        $trail = [];

        $inner = Pipeline::of([new RecordingTerminable('inner', $trail)]);
        $outer = Pipeline::of([new RecordingTerminable('outer', $trail), $inner])
            ->withDestination(Factory::endpoint());

        $request = Factory::request();
        $response = $outer->handle($request);
        $outer->terminate($request, $response);

        self::assertSame(['outer', 'inner'], $trail, '嵌套子管道的收尾必须级联');
    }

    #[Test]
    public function testTerminateReachesMiddlewareDeclaredByAlias(): void
    {
        $trail = [];

        $registry = (new Registry())->alias('audit', new RecordingTerminable('audit', $trail));
        $pipeline = Pipeline::of(['audit'], new Resolver(null, $registry))
            ->withDestination(Factory::endpoint());

        $request = Factory::request();
        $response = $pipeline->handle($request);
        $pipeline->terminate($request, $response);

        self::assertSame(['audit'], $trail, '字符串声明的中间件同样要参与收尾');
    }

    #[Test]
    public function testTerminateSwallowsFailures(): void
    {
        $broken = new class implements MiddlewareInterface, TerminableInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }

            public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
            {
                throw new \RuntimeException('收尾失败');
            }
        };

        $pipeline = Pipeline::of([$broken])->withDestination(Factory::endpoint());

        $request = Factory::request();
        $response = $pipeline->handle($request);
        $pipeline->terminate($request, $response);

        self::assertSame(200, $response->getStatusCode(), '收尾失败不得影响已完成的请求');
    }

    // --------------------------------------------------------------- 蓝图安全

    #[Test]
    public function testBlueprintRejectsUnknownClass(): void
    {
        $blueprint = Blueprint::fromArray([
            'version' => '1',
            'before' => ['App\\Evil\\NotLoadable'],
        ]);

        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(4002);

        $blueprint->rebuild();
    }

    #[Test]
    public function testBlueprintRejectsExistingButNonMiddlewareClass(): void
    {
        $blueprint = Blueprint::fromArray([
            'version' => '1',
            'before' => [Registry::class],
        ]);

        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(4002);

        $blueprint->rebuild();
    }

    #[Test]
    public function testBlueprintAcceptsRealMiddlewareClass(): void
    {
        $pipeline = Blueprint::fromArray([
            'version' => '1',
            'before' => [CountingMiddleware::class],
        ])->rebuild();

        self::assertCount(1, $pipeline);
    }

    #[Test]
    public function testBlueprintHonoursCustomTrustPolicy(): void
    {
        $blueprint = Blueprint::fromArray([
            'version' => '1',
            'before' => [CountingMiddleware::class],
        ]);

        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(4002);

        // 生产环境常见做法：收紧到固定命名空间
        $blueprint->rebuild(trust: static fn (string $name): bool => str_starts_with($name, 'App\\'));
    }

    #[Test]
    public function testBlueprintRoundTripKeepsAliases(): void
    {
        $registry = (new Registry())->alias('count', CountingMiddleware::class);
        $original = Blueprint::fromPipe(['count'], [], $registry);

        $restored = Blueprint::fromJson($original->toJson());
        $pipeline = $restored->rebuild();

        self::assertSame($original->fingerprint(), $restored->fingerprint());
        self::assertCount(1, $pipeline);
    }
}

/**
 * 记录收尾调用的测试中间件
 *
 * @package Kode\Middleware\Tests
 */
final class RecordingTerminable implements MiddlewareInterface, TerminableInterface
{
    /**
     * @param string $name 标识
     * @param array<int, string> $trail 收尾轨迹（引用）
     */
    public function __construct(private readonly string $name, private array &$trail)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }

    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->trail[] = $this->name;
    }
}
