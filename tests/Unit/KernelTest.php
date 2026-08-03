<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Contract\TerminableInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Kernel;
use Kode\Middleware\Pipeline;
use Kode\Middleware\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 应用内核测试
 *
 * 覆盖"洋葱之外"那层壳的全部职责：启动、钩子、兜底、收尾。
 *
 * @package Kode\Middleware\Tests
 */
#[CoversClass(Kernel::class)]
final class KernelTest extends TestCase
{
    #[Test]
    public function testBootRunsOnceAndIsIdempotent(): void
    {
        $count = 0;

        $kernel = Kernel::of(Pipeline::of()->withDestination(Factory::endpoint('ok')))
            ->withBootstrapper(function () use (&$count): void {
                $count++;
            });

        self::assertFalse($kernel->isBooted());

        $kernel->boot();
        $kernel->boot();
        $kernel->handle(Factory::request());
        $kernel->handle(Factory::request());

        self::assertTrue($kernel->isBooted());
        self::assertSame(1, $count, '启动回调必须且只能执行一次');
    }

    #[Test]
    public function testHandleBootsLazily(): void
    {
        $booted = false;

        $kernel = Kernel::of(Pipeline::of()->withDestination(Factory::endpoint('ok')))
            ->withBootstrapper(function () use (&$booted): void {
                $booted = true;
            });

        self::assertFalse($booted);
        $kernel->handle(Factory::request());
        self::assertTrue($booted, '首次 handle() 应自动触发启动');
    }

    #[Test]
    public function testBootFailureIsWrapped(): void
    {
        $kernel = Kernel::of(Pipeline::of()->withDestination(Factory::endpoint()))
            ->withBootstrapper(static fn () => throw new \LogicException('配置缺失'));

        try {
            $kernel->boot();
            self::fail('启动失败应抛出异常');
        } catch (MiddlewareException $e) {
            self::assertSame(5001, $e->getCode());
            self::assertFalse($kernel->isBooted(), '启动失败后不应被标记为已启动');
        }
    }

    #[Test]
    public function testRequestHookRewritesRequest(): void
    {
        $pipeline = Pipeline::of()->to(
            static fn (ServerRequestInterface $r): ResponseInterface
                => Factory::response(200, (string) $r->getAttribute('tenant'))
        );

        $response = Kernel::of($pipeline)
            ->onRequest(static fn (ServerRequestInterface $r) => $r->withAttribute('tenant', 'acme'))
            ->handle(Factory::request());

        self::assertSame('acme', (string) $response->getBody());
    }

    #[Test]
    public function testResponseHookRewritesResponse(): void
    {
        $response = Kernel::of(Pipeline::of()->withDestination(Factory::endpoint('body')))
            ->onResponse(static fn (ResponseInterface $r) => $r->withHeader('X-Node', 'n1'))
            ->handle(Factory::request());

        self::assertSame('n1', $response->getHeaderLine('X-Node'));
    }

    #[Test]
    public function testInvalidHookReturnThrows(): void
    {
        $kernel = Kernel::of(Pipeline::of()->withDestination(Factory::endpoint()))
            ->onRequest(static fn (): string => 'not-a-request');

        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(5003);

        $kernel->handle(Factory::request());
    }

    #[Test]
    public function testExceptionIsRethrownWithoutHandler(): void
    {
        $kernel = Kernel::of(Pipeline::of()->to(static fn () => throw new \RuntimeException('boom')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $kernel->handle(Factory::request());
    }

    #[Test]
    public function testExceptionHandlerRescuesAndStillRunsResponseHooks(): void
    {
        $response = Kernel::of(Pipeline::of()->to(static fn () => throw new \RuntimeException('boom')))
            ->withExceptionHandler(static fn (\Throwable $e) => Factory::response(500, $e->getMessage()))
            ->onResponse(static fn (ResponseInterface $r) => $r->withHeader('X-Rescued', '1'))
            ->handle(Factory::request());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('boom', (string) $response->getBody());
        self::assertSame('1', $response->getHeaderLine('X-Rescued'), '兜底响应同样要过响应钩子');
    }

    #[Test]
    public function testFailingExceptionHandlerSurfacesBothErrors(): void
    {
        $kernel = Kernel::of(Pipeline::of()->to(static fn () => throw new \RuntimeException('原始故障')))
            ->withExceptionHandler(static fn () => throw new \LogicException('兜底也炸了'));

        try {
            $kernel->handle(Factory::request());
            self::fail('兜底失效必须上抛');
        } catch (MiddlewareException $e) {
            self::assertSame(5002, $e->getCode());
            self::assertStringContainsString('原始故障', $e->getMessage());
            self::assertStringContainsString('兜底也炸了', $e->getMessage());
        }
    }

    #[Test]
    public function testTerminateCascadesAndNeverThrows(): void
    {
        $trail = [];

        $terminable = new class ($trail) implements MiddlewareInterface, TerminableInterface {
            /** @param array<int, string> $trail */
            public function __construct(private array &$trail)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }

            public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
            {
                $this->trail[] = 'middleware';
            }
        };

        $kernel = Kernel::of(Pipeline::of([$terminable])->withDestination(Factory::endpoint()))
            ->onTerminate(function () use (&$trail): void {
                $trail[] = 'hook';
            })
            ->onTerminate(static fn () => throw new \RuntimeException('收尾失败'));

        $request = Factory::request();
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        self::assertSame(['middleware', 'hook'], $trail);
    }

    #[Test]
    public function testRunCombinesHandleAndTerminate(): void
    {
        $terminated = false;

        $response = Kernel::of(Pipeline::of()->withDestination(Factory::endpoint('done')))
            ->onTerminate(function () use (&$terminated): void {
                $terminated = true;
            })
            ->run(Factory::request());

        self::assertSame('done', (string) $response->getBody());
        self::assertTrue($terminated);
    }

    #[Test]
    public function testWithPipelineKeepsHooksAndIsImmutable(): void
    {
        $base = Kernel::of(Pipeline::of()->withDestination(Factory::endpoint('v1')))
            ->onResponse(static fn (ResponseInterface $r) => $r->withHeader('X-Hook', 'kept'));

        $next = $base->withPipeline(Pipeline::of()->withDestination(Factory::endpoint('v2')));

        self::assertNotSame($base, $next);
        self::assertSame('v1', (string) $base->handle(Factory::request())->getBody());

        $response = $next->handle(Factory::request());
        self::assertSame('v2', (string) $response->getBody());
        self::assertSame('kept', $response->getHeaderLine('X-Hook'), '钩子应随内核一起继承');
    }

    #[Test]
    public function testKernelIsReentrantAcrossFibers(): void
    {
        $pipeline = Pipeline::of([
            Factory::tagging('outer'),
        ])->to(static fn (ServerRequestInterface $r): ResponseInterface => Factory::response(
            200,
            (string) $r->getAttribute('who')
        ));

        $kernel = Kernel::of($pipeline);

        $make = static fn (string $who): \Fiber => new \Fiber(static function () use ($kernel, $who): string {
            \Fiber::suspend();

            return (string) $kernel->handle(
                Factory::request()->withAttribute('who', $who)
            )->getBody();
        });

        $a = $make('A');
        $b = $make('B');

        $a->start();
        $b->start();
        $a->resume();
        $b->resume();

        self::assertSame('A[outer]', $a->getReturn());
        self::assertSame('B[outer]', $b->getReturn());
    }
}
