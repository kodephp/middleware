<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Adapter\CallableMiddleware;
use Kode\Middleware\Adapter\ConditionalMiddleware;
use Kode\Middleware\Adapter\LazyMiddleware;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Pipeline;
use Kode\Middleware\Registry;
use Kode\Middleware\Resolver;
use Kode\Middleware\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 解析器与注册表测试
 *
 * @package Kode\Middleware\Tests
 */
#[CoversClass(Resolver::class)]
#[CoversClass(Registry::class)]
#[CoversClass(LazyMiddleware::class)]
#[CoversClass(ConditionalMiddleware::class)]
final class ResolverTest extends TestCase
{
    /**
     * 中间件实例原样返回
     */
    public function testResolvesInstance(): void
    {
        $middleware = Factory::tagging('A');

        self::assertSame($middleware, (new Resolver())->resolve($middleware));
    }

    /**
     * 闭包被包装为 CallableMiddleware
     */
    public function testResolvesClosure(): void
    {
        $resolved = (new Resolver())->resolve(
            static fn ($request, $handler) => $handler->handle($request)
        );

        self::assertInstanceOf(CallableMiddleware::class, $resolved);
    }

    /**
     * 类名字符串走惰性解析，未被触达时不实例化
     */
    public function testClassNameIsLazy(): void
    {
        CountingMiddleware::$instances = 0;

        $resolver = new Resolver();
        $resolved = $resolver->resolve(CountingMiddleware::class);

        self::assertInstanceOf(LazyMiddleware::class, $resolved);
        self::assertSame(0, CountingMiddleware::$instances, '仅解析不应触发实例化');

        // 管道中被短路，惰性中间件不应被实例化
        Pipeline::of([Factory::shortCircuit(), CountingMiddleware::class], $resolver)
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame(0, CountingMiddleware::$instances, '被短路的中间件不得被实例化');

        // 真正流经时才实例化
        Pipeline::of([CountingMiddleware::class], $resolver)
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame(1, CountingMiddleware::$instances);
    }

    /**
     * 别名解析
     */
    public function testResolvesAlias(): void
    {
        $registry = (new Registry())->alias('tag', Factory::tagging('ALIAS'));
        $resolver = new Resolver(null, $registry);

        $response = Pipeline::of(['tag'], $resolver)
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame('core[ALIAS]', (string) $response->getBody());
    }

    /**
     * 分组展开为嵌套子管道
     */
    public function testResolvesGroup(): void
    {
        $registry = (new Registry())
            ->alias('a', Factory::tagging('A'))
            ->alias('b', Factory::tagging('B'))
            ->group('web', ['a', 'b']);

        $response = Pipeline::of(['web'], new Resolver(null, $registry))
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame('core[B][A]', (string) $response->getBody());
    }

    /**
     * 带参数的工厂别名：`名称:参数1,参数2`
     */
    public function testResolvesFactoryWithArguments(): void
    {
        $registry = (new Registry())->factory(
            'throttle',
            static fn (string $max = '60', string $per = '1'): MiddlewareInterface
                => Factory::tagging("T{$max}/{$per}")
        );

        $response = Pipeline::of(['throttle:100,5'], new Resolver(null, $registry))
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame('core[T100/5]', (string) $response->getBody());
    }

    /**
     * 从 PSR-11 容器解析
     */
    public function testResolvesFromContainer(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return $id === 'svc.tag' ? Factory::tagging('FROM_DI') : throw new \RuntimeException($id);
            }

            public function has(string $id): bool
            {
                return $id === 'svc.tag';
            }
        };

        $response = Pipeline::of(['svc.tag'], new Resolver($container))
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame('core[FROM_DI]', (string) $response->getBody());
    }

    /**
     * 数组声明就地组成嵌套子管道
     */
    public function testResolvesArrayAsNestedPipeline(): void
    {
        $resolved = (new Resolver())->resolve([Factory::tagging('A'), Factory::tagging('B')]);

        self::assertInstanceOf(Pipeline::class, $resolved);
        self::assertSame(2, $resolved->count());
    }

    /**
     * 未注册的名称抛出可读异常
     */
    public function testUnknownDeclarationThrows(): void
    {
        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(1001);

        (new Pipeline())->add('完全不存在的中间件');
    }

    /**
     * 非中间件类被拒绝
     */
    public function testNonMiddlewareClassThrows(): void
    {
        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(1002);

        (new Resolver())->resolve(\stdClass::class)->process(
            Factory::request(),
            Factory::endpoint('core')
        );
    }

    /**
     * 构造函数有必填参数时给出明确指引
     */
    public function testConstructorWithRequiredParamsThrows(): void
    {
        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(1005);

        (new Resolver())->resolve(NeedsArgsMiddleware::class)->process(
            Factory::request(),
            Factory::endpoint('core')
        );
    }

    /**
     * 条件中间件：命中前缀才执行
     */
    public function testConditionalByPrefix(): void
    {
        $pipeline = Pipeline::of([
            ConditionalMiddleware::prefix('/api', Factory::tagging('API')),
        ]);

        self::assertSame(
            'core[API]',
            (string) $pipeline->run(Factory::request('/api/users'), Factory::endpoint('core'))->getBody()
        );

        self::assertSame(
            'core',
            (string) $pipeline->run(Factory::request('/health'), Factory::endpoint('core'))->getBody()
        );
    }

    /**
     * 条件中间件：按 HTTP 方法过滤
     */
    public function testConditionalByMethod(): void
    {
        $pipeline = Pipeline::of([
            ConditionalMiddleware::methods(['POST', 'PUT'], Factory::tagging('CSRF')),
        ]);

        self::assertSame(
            'core[CSRF]',
            (string) $pipeline->run(Factory::request('/', 'POST'), Factory::endpoint('core'))->getBody()
        );

        self::assertSame(
            'core',
            (string) $pipeline->run(Factory::request('/', 'GET'), Factory::endpoint('core'))->getBody()
        );
    }
}

/**
 * 计数中间件桩件：统计被实例化的次数
 *
 * @package Kode\Middleware\Tests
 */
final class CountingMiddleware implements MiddlewareInterface
{
    /** @var int 已创建的实例数量 */
    public static int $instances = 0;

    public function __construct()
    {
        ++self::$instances;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

/**
 * 构造函数带必填参数的中间件桩件
 *
 * @package Kode\Middleware\Tests
 */
final class NeedsArgsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $required)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
