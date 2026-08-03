<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Pipe;
use Kode\Middleware\Routing\DispatchMiddleware;
use Kode\Middleware\Routing\RouteMiddleware;
use Kode\Middleware\Routing\RouteResult;
use Kode\Middleware\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 路由前后串联测试
 *
 * 验证"全局中间件 → 路由匹配 → 路由后中间件 → 路由级中间件 → 控制器"
 * 这条三段式生命周期的顺序与数据流转是否符合预期。
 *
 * @package Kode\Middleware\Tests
 */
#[CoversClass(Pipe::class)]
#[CoversClass(RouteMiddleware::class)]
#[CoversClass(DispatchMiddleware::class)]
#[CoversClass(RouteResult::class)]
final class RoutingTest extends TestCase
{
    /**
     * 完整三段式顺序：before → route → after → 路由级 → 控制器
     */
    public function testThreeStageOrder(): void
    {
        $pipe = Pipe::create()
            ->beforeRoute(Factory::tagging('GLOBAL'))
            ->router(static fn (ServerRequestInterface $request): RouteResult => RouteResult::matched(
                handler: static fn (): \Psr\Http\Message\ResponseInterface => Factory::response(200, 'ctrl'),
                params: ['id' => '42'],
                middleware: [Factory::tagging('ROUTE_MW')],
                name: 'user.show',
            ))
            ->afterRoute(Factory::tagging('AFTER'));

        $response = $pipe->handle(Factory::request('/users/42'));

        self::assertSame('ctrl[ROUTE_MW][AFTER][GLOBAL]', (string) $response->getBody());
    }

    /**
     * 路由后中间件可以读取路由元数据
     *
     * 这是"匹配与调度分离"的核心价值：鉴权中间件既在控制器之前，
     * 又能拿到路由名与参数做细粒度判断。
     */
    public function testAfterRouteMiddlewareSeesRouteMetadata(): void
    {
        $seenName = null;
        $seenParam = null;

        Pipe::create()
            ->router(static fn (): RouteResult => RouteResult::matched(
                handler: static fn () => Factory::response(200, 'ctrl'),
                params: ['id' => '42'],
                name: 'user.show',
            ))
            ->afterRoute(static function ($request, $handler) use (&$seenName, &$seenParam) {
                $route = RouteResult::from($request);
                $seenName = $route?->name();
                $seenParam = $route?->param('id');

                return $handler->handle($request);
            })
            ->handle(Factory::request('/users/42'));

        self::assertSame('user.show', $seenName);
        self::assertSame('42', $seenParam);
    }

    /**
     * 路径参数被平铺到请求属性
     */
    public function testParamsAreExposedAsAttributes(): void
    {
        $seen = null;

        Pipe::create()
            ->router(static fn (): RouteResult => RouteResult::matched(
                handler: static fn () => Factory::response(200, 'ctrl'),
                params: ['slug' => 'hello-world'],
            ))
            ->afterRoute(static function ($request, $handler) use (&$seen) {
                $seen = $request->getAttribute('slug');

                return $handler->handle($request);
            })
            ->handle(Factory::request('/posts/hello-world'));

        self::assertSame('hello-world', $seen);
    }

    /**
     * 未命中路由时走兜底处理器，且全局中间件依然生效
     */
    public function testNotFoundFallsBackButKeepsGlobalMiddleware(): void
    {
        $response = Pipe::create()
            ->beforeRoute(Factory::tagging('GLOBAL'))
            ->router(static fn (): RouteResult => RouteResult::notFound())
            ->afterRoute(Factory::tagging('AFTER'))
            ->fallback(static fn () => Factory::response(404, 'not-found'))
            ->handle(Factory::request('/nope'));

        self::assertSame(404, $response->getStatusCode());
        // 路由后中间件仍会执行（它在管道上位于 dispatch 之前）
        self::assertSame('not-found[AFTER][GLOBAL]', (string) $response->getBody());
    }

    /**
     * 方法不允许时携带 Allow 列表
     */
    public function testMethodNotAllowed(): void
    {
        $result = RouteResult::methodNotAllowed(['get', 'post']);

        self::assertTrue($result->isMethodNotAllowed());
        self::assertFalse($result->isMatched());
        self::assertSame(['GET', 'POST'], $result->allowed());
    }

    /**
     * 路由处理器支持 PSR-15 RequestHandlerInterface 形态
     */
    public function testRouteHandlerAsRequestHandler(): void
    {
        $response = Pipe::create()
            ->router(static fn (): RouteResult => RouteResult::matched(Factory::endpoint('psr15')))
            ->handle(Factory::request('/'));

        self::assertSame('psr15', (string) $response->getBody());
    }

    /**
     * 自定义 invoker 支持"控制器@方法"字符串形态
     */
    public function testCustomInvoker(): void
    {
        $response = Pipe::create()
            ->router(static fn (): RouteResult => RouteResult::matched('HomeController@index'))
            ->invoker(static function (mixed $handler, ServerRequestInterface $request) {
                [$class, $method] = explode('@', (string) $handler, 2);

                return Factory::response(200, "{$class}::{$method}");
            })
            ->handle(Factory::request('/'));

        self::assertSame('HomeController::index', (string) $response->getBody());
    }

    /**
     * 别名与分组在路由级中间件中同样可用
     */
    public function testAliasInRouteMiddleware(): void
    {
        $response = Pipe::create()
            ->alias('audit', Factory::tagging('AUDIT'))
            ->router(static fn (): RouteResult => RouteResult::matched(
                handler: static fn () => Factory::response(200, 'ctrl'),
                middleware: ['audit'],
            ))
            ->handle(Factory::request('/'));

        self::assertSame('ctrl[AUDIT]', (string) $response->getBody());
    }

    /**
     * RouteResult 是不可变值对象
     */
    public function testRouteResultIsImmutable(): void
    {
        $original = RouteResult::matched('h', ['a' => 1]);
        $extended = $original->withMiddleware('auth');

        self::assertSame([], $original->middleware());
        self::assertSame(['auth'], $extended->middleware());
        self::assertNotSame($original, $extended);
    }

    /**
     * 构建结果被缓存，重复 build() 返回同一实例
     */
    public function testBuildIsCached(): void
    {
        $pipe = Pipe::create()->beforeRoute(Factory::tagging('A'));

        self::assertSame($pipe->build(), $pipe->build());

        $pipe->beforeRoute(Factory::tagging('B'));

        self::assertSame(2, $pipe->build()->count(), '新增中间件后缓存应失效');
    }
}
