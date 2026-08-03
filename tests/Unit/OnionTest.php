<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Middleware\ErrorBoundaryMiddleware;
use Kode\Middleware\Observability\Profile;
use Kode\Middleware\Observability\ProfilerMiddleware;
use Kode\Middleware\Pipe;
use Kode\Middleware\Pipeline;
use Kode\Middleware\Routing\RouteResult;
use Kode\Middleware\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 洋葱模型行为测试
 *
 * 覆盖异常边界、分层耗时剖析，以及"框架集成后"三段式 + 内核的完整链路。
 *
 * @package Kode\Middleware\Tests
 */
#[CoversClass(ErrorBoundaryMiddleware::class)]
#[CoversClass(ProfilerMiddleware::class)]
#[CoversClass(Profile::class)]
final class OnionTest extends TestCase
{
    // --------------------------------------------------------------- 异常边界

    #[Test]
    public function testBoundaryConvertsDownstreamThrowableIntoResponse(): void
    {
        $pipeline = Pipeline::of([
            new ErrorBoundaryMiddleware(
                static fn (\Throwable $e) => Factory::response(500, 'caught:' . $e->getMessage())
            ),
            Factory::tagging('inner'),
        ])->to(static fn () => throw new \RuntimeException('boom'));

        $response = $pipeline->handle(Factory::request());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('caught:boom', (string) $response->getBody());
    }

    #[Test]
    public function testBoundarySitsOutermostRegardlessOfRegistrationOrder(): void
    {
        // 故意把边界放在最后注册，靠优先级把它推到最外层
        $pipeline = Pipeline::of([
            Factory::tagging('a'),
            new ErrorBoundaryMiddleware(static fn () => Factory::response(500, 'E')),
        ])->to(static fn () => throw new \RuntimeException('boom'));

        $response = $pipeline->handle(Factory::request());

        self::assertSame('E', (string) $response->getBody(), '边界在最外层，内层标记不应被追加');
    }

    #[Test]
    public function testBoundaryPassesThroughWhitelistedExceptions(): void
    {
        $pipeline = Pipeline::of([
            new ErrorBoundaryMiddleware(
                renderer: static fn () => Factory::response(500),
                passthrough: [\DomainException::class],
            ),
        ])->to(static fn () => throw new \DomainException('业务异常'));

        $this->expectException(\DomainException::class);

        $pipeline->handle(Factory::request());
    }

    #[Test]
    public function testReporterFailureDoesNotBreakRendering(): void
    {
        $pipeline = Pipeline::of([
            new ErrorBoundaryMiddleware(
                renderer: static fn () => Factory::response(503, 'rendered'),
                reporter: static fn () => throw new \RuntimeException('日志服务挂了'),
            ),
        ])->to(static fn () => throw new \RuntimeException('boom'));

        $response = $pipeline->handle(Factory::request());

        self::assertSame('rendered', (string) $response->getBody(), '上报通道故障不能连累错误页');
    }

    #[Test]
    public function testFailingRendererIsSurfaced(): void
    {
        $pipeline = Pipeline::of([
            new ErrorBoundaryMiddleware(static fn () => throw new \LogicException('渲染器也炸了')),
        ])->to(static fn () => throw new \RuntimeException('原始故障'));

        try {
            $pipeline->handle(Factory::request());
            self::fail('渲染器失效必须上抛，不能静默');
        } catch (MiddlewareException $e) {
            self::assertSame(5002, $e->getCode());
            self::assertStringContainsString('原始故障', $e->getMessage());
        }
    }

    #[Test]
    public function testKernelRescuesWhatBoundaryCannot(): void
    {
        // 边界自身的渲染器失效 → 只有内核能兜住
        $pipeline = Pipeline::of([
            new ErrorBoundaryMiddleware(static fn () => throw new \LogicException('渲染器失效')),
        ])->to(static fn () => throw new \RuntimeException('原始故障'));

        $response = Pipe::create()->kernel()
            ->withPipeline($pipeline)
            ->withExceptionHandler(static fn () => Factory::response(500, 'kernel-fallback'))
            ->handle(Factory::request());

        self::assertSame('kernel-fallback', (string) $response->getBody());
    }

    // --------------------------------------------------------------- 耗时剖析

    #[Test]
    public function testProfilerRecordsNestedSpans(): void
    {
        $captured = null;

        $pipeline = Pipeline::of([
            new ProfilerMiddleware('outer', header: true, sink: function (Profile $p) use (&$captured): void {
                $captured = $p;
            }),
            new ProfilerMiddleware('inner', header: false, priority: 100),
        ])->withDestination(Factory::endpoint());

        $response = $pipeline->handle(Factory::request());

        self::assertInstanceOf(Profile::class, $captured);

        $spans = $captured->spans();
        self::assertCount(2, $spans);
        self::assertSame(['outer', 'inner'], array_column($spans, 'name'));
        self::assertSame([0, 1], array_column($spans, 'depth'), '内层深度必须比外层深');
        self::assertNotSame('', $response->getHeaderLine('Server-Timing'));
    }

    #[Test]
    public function testOnlyOutermostProfilerWritesHeader(): void
    {
        $pipeline = Pipeline::of([
            new ProfilerMiddleware('outer'),
            new ProfilerMiddleware('inner', priority: 100),
        ])->withDestination(Factory::endpoint());

        $header = $pipeline->handle(Factory::request())->getHeader('Server-Timing');

        self::assertCount(1, $header, '嵌套剖析不得重复写响应头');
        self::assertStringContainsString('outer_0', $header[0]);
        self::assertStringContainsString('inner_1', $header[0]);
    }

    #[Test]
    public function testProfilerClosesSpanEvenWhenDownstreamThrows(): void
    {
        $captured = null;

        $pipeline = Pipeline::of([
            new ErrorBoundaryMiddleware(static fn () => Factory::response(500)),
            new ProfilerMiddleware('outer', header: false, sink: function (Profile $p) use (&$captured): void {
                $captured = $p;
            }, priority: 100),
        ])->to(static fn () => throw new \RuntimeException('boom'));

        $pipeline->handle(Factory::request());

        self::assertInstanceOf(Profile::class, $captured);
        self::assertGreaterThan(0.0, $captured->total(), '异常路径下区段也必须被正确关闭');
    }

    #[Test]
    public function testProfileTextIsIndentedByDepth(): void
    {
        $profile = new Profile();
        $outer = $profile->enter('outer');
        $inner = $profile->enter('inner');
        $profile->leave($inner);
        $profile->leave($outer);

        $lines = explode(PHP_EOL, $profile->toText());

        self::assertStringStartsWith('outer', $lines[0]);
        self::assertStringStartsWith('  inner', $lines[1]);
    }

    #[Test]
    public function testProfileLeaveIsIdempotent(): void
    {
        $profile = new Profile();
        $span = $profile->enter('x');

        $first = $profile->leave($span);
        $second = $profile->leave($span);

        self::assertSame($first, $second, '重复 leave 不应改写已记录的耗时');
    }

    // ------------------------------------------------- 框架集成：完整洋葱链路

    #[Test]
    public function testFullFrameworkOnionOrder(): void
    {
        $kernel = Pipe::create()
            ->onError(static fn (\Throwable $e) => Factory::response(500, 'E:' . $e->getMessage()))
            ->beforeRoute(Factory::tagging('global'))
            ->router(static fn (ServerRequestInterface $r): RouteResult => RouteResult::matched(
                static fn (): \Psr\Http\Message\ResponseInterface => Factory::response(200, 'ctrl'),
                [],                       // 路径参数（此处无）
                ['auth'],                 // 路由级中间件：在控制器之外再包一层
                'home'                    // 路由名称
            ))
            ->alias('auth', Factory::tagging('auth'))
            ->afterRoute(Factory::tagging('perm'))
            ->fallback(static fn () => Factory::response(404, 'nf'))
            ->kernel()
            ->onRequest(static fn (ServerRequestInterface $r) => $r->withAttribute('boot', 1))
            ->onResponse(static fn ($resp) => $resp->withHeader('X-Done', '1'));

        $response = $kernel->run(Factory::request('/'));

        // 洋葱回程：最内层的标记最先追加，最外层最后追加
        self::assertSame('ctrl[auth][perm][global]', (string) $response->getBody());
        self::assertSame('1', $response->getHeaderLine('X-Done'));
    }

    #[Test]
    public function testFrameworkOnionCatchesControllerException(): void
    {
        $kernel = Pipe::create()
            ->onError(static fn (\Throwable $e) => Factory::response(500, 'E:' . $e->getMessage()))
            ->beforeRoute(Factory::tagging('global'))
            ->router(static fn (): RouteResult => RouteResult::matched(
                static fn () => throw new \RuntimeException('控制器异常')
            ))
            ->fallback(static fn () => Factory::response(404))
            ->kernel();

        $response = $kernel->handle(Factory::request('/'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('E:控制器异常', (string) $response->getBody(), '边界在最外层，全局标记不应被追加');
    }
}
