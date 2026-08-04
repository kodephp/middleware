<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Integration\FrameworkBridge;
use Kode\Middleware\Kernel;
use Kode\Middleware\Pipe;
use Kode\Middleware\Routing\Router;
use Kode\Middleware\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 框架集成桥 + 路由分组 / 一站式路由 的集成测试
 *
 * 覆盖：Router 嵌套分组前缀与中间件累积、Pipe::router(Router) 直接接入、
 * Pipe::route / group 一站式登记、FrameworkBridge 三个入口产出可用内核。
 *
 * @package Kode\Middleware\Tests
 */
final class IntegrationTest extends TestCase
{
    public function testRouterNestedGroupAccumulatesPrefixAndMiddleware(): void
    {
        $router = (new Router())
            ->group('/api', ['cors'], function (Router $r): void {
                $r->add('/users', 'UserController', ['auth']);
                $r->add('/posts', 'PostController');
                $r->group('/v1', ['v1'], function (Router $r): void {
                    $r->add('/ping', 'PingController');
                });
            });

        $users = $router->match(Factory::request('/api/users'));
        self::assertTrue($users->isMatched());
        self::assertSame('UserController', $users->handler());
        self::assertSame(['cors', 'auth'], $users->middleware());

        $ping = $router->match(Factory::request('/api/v1/ping'));
        self::assertTrue($ping->isMatched());
        self::assertSame('PingController', $ping->handler());
        // 外层 cors + 内层 v1，顺序：外层 → 内层
        self::assertSame(['cors', 'v1'], $ping->middleware());

        self::assertFalse($router->match(Factory::request('/api/missing'))->isMatched());
    }

    public function testRouterGroupMethodConstraintStillAppliesWithPrefix(): void
    {
        $router = (new Router())
            ->group('/api', [], function (Router $r): void {
                $r->add('/x', 'X', [], null, ['GET']);
            });

        self::assertTrue($router->match(Factory::request('/api/x', 'GET'))->isMatched());
        self::assertTrue($router->match(Factory::request('/api/x', 'POST'))->isMethodNotAllowed());
    }

    public function testPipeAcceptsRouterInstanceDirectly(): void
    {
        $router = (new Router())->add('/', fn() => Factory::response(200, 'home'));

        $pipe = Pipe::create()
            ->router($router)
            ->fallback(fn() => Factory::response(404, 'nf'));

        $ok = $pipe->handle(Factory::request('/'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('home', (string) $ok->getBody());

        $miss = $pipe->handle(Factory::request('/nope'));
        self::assertSame(404, $miss->getStatusCode());
    }

    public function testPipeRouteOneLinerAndPathParams(): void
    {
        $pipe = Pipe::create()
            ->route('/', fn() => Factory::response(200, 'home'))
            ->route('/users/{id}', static function (ServerRequestInterface $r): ResponseInterface {
                return Factory::response(200, 'u' . $r->getAttribute('id'));
            }, [], 'user.show', ['GET'])
            ->fallback(fn() => Factory::response(404, 'nf'));

        $home = $pipe->handle(Factory::request('/'));
        self::assertSame('home', (string) $home->getBody());

        $user = $pipe->handle(Factory::request('/users/42', 'GET'));
        self::assertSame('u42', (string) $user->getBody());

        $miss = $pipe->handle(Factory::request('/missing'));
        self::assertSame(404, $miss->getStatusCode());
    }

    public function testPipeRouteGroupAppliesPrefixAndMiddleware(): void
    {
        $pipe = Pipe::create()
            ->group('auth', [Factory::tagging('auth')])
            ->group('cors', [Factory::tagging('cors')])
            ->route('/', fn() => Factory::response(200, 'home'))
            ->routeGroup('/api', ['cors'], function (Pipe $p): void {
                $p->route('/ping', fn() => Factory::response(200, 'pong'));
                $p->route('/secure', fn() => Factory::response(200, 's'), ['auth']);
            })
            ->fallback(fn() => Factory::response(404, 'nf'));

        $ping = $pipe->handle(Factory::request('/api/ping'));
        self::assertSame(200, $ping->getStatusCode());
        self::assertStringContainsString('[cors]', (string) $ping->getBody());

        // /api/secure 应同时命中路由分组链路中的 cors 与路由级 auth 中间件
        $secure = $pipe->handle(Factory::request('/api/secure'));
        self::assertSame(200, $secure->getStatusCode());
        self::assertStringContainsString('[cors]', (string) $secure->getBody());
        self::assertStringContainsString('[auth]', (string) $secure->getBody());
    }

    public function testBridgeBuildsRunnableKernelWithObserveAndStack(): void
    {
        $renderer = static fn (\Throwable $e, ServerRequestInterface $r): ResponseInterface
            => Factory::response(500, 'err:' . $e->getMessage());

        $kernel = FrameworkBridge::kernel(
            Pipe::create()
                ->route('/', fn() => Factory::response(200, 'home'))
                ->route('/boom', static function (): void {
                    throw new \RuntimeException('kaboom');
                })
                ->fallback(fn() => Factory::response(404, 'nf')),
            hooks: [
                'onResponse' => static fn (ResponseInterface $r, ServerRequestInterface $q): ResponseInterface
                    => $r->withHeader('X-Kode', '1'),
            ],
            renderer: $renderer,
        );

        self::assertInstanceOf(Kernel::class, $kernel);

        $ok = $kernel->run(Factory::request('/'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('home', (string) $ok->getBody());
        self::assertSame('1', $ok->getHeaderLine('X-Kode'));

        // 异常被边界渲染为 500，而非裸奔
        $boom = $kernel->run(Factory::request('/boom'));
        self::assertSame(500, $boom->getStatusCode());
        self::assertStringContainsString('err:kaboom', (string) $boom->getBody());

        $miss = $kernel->run(Factory::request('/x'));
        self::assertSame(404, $miss->getStatusCode());
    }

    public function testBridgeRequiresRendererWhenObserveEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/renderer/');

        FrameworkBridge::kernel(
            Pipe::create()->route('/', fn() => Factory::response(200, 'home')),
            observe: true, // 默认开启但没给 renderer
        );
    }

    public function testBridgeWithoutObserveRunsWithoutRenderer(): void
    {
        $kernel = FrameworkBridge::kernel(
            Pipe::create()
                ->route('/', fn() => Factory::response(200, 'home'))
                ->fallback(fn() => Factory::response(404, 'nf')),
            observe: false,
            stack: false,
        );

        $ok = $kernel->run(Factory::request('/'));
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testBridgeFunctionShortcut(): void
    {
        $kernel = \Kode\Middleware\bridge(
            Pipe::create()
                ->route('/', fn() => Factory::response(200, 'hi'))
                ->fallback(fn() => Factory::response(404, 'nf')),
            renderer: static fn (\Throwable $e, ServerRequestInterface $r): ResponseInterface
                => Factory::response(500, 'e'),
        );

        self::assertInstanceOf(Kernel::class, $kernel);
        self::assertSame('hi', (string) $kernel->run(Factory::request('/'))->getBody());
    }
}
