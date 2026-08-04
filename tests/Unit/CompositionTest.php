<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Codegen\MiddlewareGenerator;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Pipe;
use Kode\Middleware\Registry;
use Kode\Middleware\Routing\Router;
use Kode\Middleware\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;

/**
 * 组合能力测试：分组递归展开、路由收集器、嵌套组合、路由引用命名分组、代码生成。
 *
 * @package Kode\Middleware\Tests
 */
final class CompositionTest extends TestCase
{
    public function testRegistryExpandFlattensNestedGroups(): void
    {
        $inline = Factory::tagging('inline');

        $registry = (new Registry())
            ->alias('auth', 'AuthCls')
            ->group('guard', ['session', 'auth'])
            ->group('api', ['cors', 'guard', $inline]);

        $flat = $registry->expand('api');

        // cors -> guard(session, auth) -> inline；auth 展开为类名字符串叶子，不继续展开
        $this->assertSame(['cors', 'session', 'AuthCls', $inline], $flat);
    }

    public function testRegistryExpandDetectsCycle(): void
    {
        $registry = (new Registry())
            ->group('a', ['b'])
            ->group('b', ['a']);

        $this->expectException(MiddlewareException::class);
        $registry->expand('a');
    }

    public function testRegistryExpandDiamondDoesNotFalseAlarm(): void
    {
        $registry = (new Registry())
            ->alias('leaf', 'LeafCls')
            ->group('b', ['leaf'])
            ->group('c', ['leaf'])
            ->group('a', ['b', 'c']);

        // 合法的菱形依赖（A→B,C；B→D；C→D）不应误报成环，D 出现两次
        $this->assertSame(['LeafCls', 'LeafCls'], $registry->expand('a'));
    }

    public function testRegistryExpandDepthLimit(): void
    {
        $registry = new Registry();

        for ($i = 0; $i < 33; $i++) {
            $registry->group('a' . $i, ['a' . ($i + 1)]);
        }

        $this->expectException(MiddlewareException::class);
        $registry->expand('a0');
    }

    public function testRouterMatchesWithParamsAndGroupMiddleware(): void
    {
        $router = (new Router())->add(
            '/users/{id}',
            static fn () => Factory::response(200, 'u'),
            ['api.guard'],
            'user.show',
            ['GET']
        );

        $result = $router->match(Factory::request('/users/42', 'GET'));

        $this->assertTrue($result->isMatched());
        $this->assertSame('42', $result->param('id'));
        $this->assertSame(['api.guard'], $result->middleware());
        $this->assertSame('user.show', $result->name());
    }

    public function testRouterMethodNotAllowed(): void
    {
        $router = (new Router())->add('/users/{id}', static fn () => Factory::response(200), [], null, ['GET']);

        $result = $router->match(Factory::request('/users/1', 'POST'));

        $this->assertTrue($result->isMethodNotAllowed());
        $this->assertSame(['GET'], $result->allowed());
    }

    public function testRouterNotFound(): void
    {
        $router = (new Router())->add('/a', static fn () => Factory::response(200));

        $result = $router->match(Factory::request('/b'));

        $this->assertTrue($result->isNotFound());
    }

    public function testPipeNestCreatesNestedLayer(): void
    {
        $pipe = Pipe::create()
            ->beforeRoute(Factory::tagging('outer'))
            ->nest(static function (Pipe $n): void {
                $n->beforeRoute(Factory::tagging('inner-a'), Factory::tagging('inner-b'));
            })
            ->fallback(static fn () => Factory::response(200, 'ok'));

        $body = (string) $pipe->handle(Factory::request('/'))->getBody();

        // 外层 outer 最后织入；嵌套层内部 inner-a(外) → inner-b(内) → 终点
        $this->assertSame('ok[inner-b][inner-a][outer]', $body);
    }

    public function testPipeUseGroupInsertsNamedGroup(): void
    {
        $pipe = Pipe::create()
            ->group('g', [Factory::tagging('x'), Factory::tagging('y')])
            ->useGroup('g')
            ->fallback(static fn () => Factory::response(200, 'ok'));

        $body = (string) $pipe->handle(Factory::request('/'))->getBody();

        $this->assertSame('ok[y][x]', $body);
    }

    public function testRouteUsesNamedGroupMiddleware(): void
    {
        $router = (new Router())->add(
            '/users/{id}',
            static fn () => Factory::response(200, 'ctrl'),
            ['api.guard']
        );

        $pipe = Pipe::create()
            ->group('api.guard', [Factory::tagging('g1'), Factory::tagging('g2')])
            ->router($router->matcher())
            ->fallback(static fn () => Factory::response(404, 'nf'));

        $body = (string) $pipe->handle(Factory::request('/users/7'))->getBody();

        // 路由级中间件引用命名分组，被展开为嵌套子管道（g1 外、g2 内）
        $this->assertSame('ctrl[g2][g1]', $body);
    }

    public function testCodegenProducesWorkingMiddleware(): void
    {
        $generator = new MiddlewareGenerator('Kode\\Middleware\\Tests\\Support\\Gen');
        $className = 'Gen_' . uniqid();
        $source = (string) preg_replace('/^<\?php\s*/', '', $generator->generate(
            $className,
            ['priority' => 7, 'description' => 'demo guard']
        ));

        // 在隔离作用域内编译生成的类（避免污染全局类表之外，uniqid 保证不重名）
        eval($source);

        $fqcn = 'Kode\\Middleware\\Tests\\Support\\Gen\\' . $className;
        /** @var class-string $fqcn */
        $middleware = new $fqcn();

        $this->assertInstanceOf(MiddlewareInterface::class, $middleware);

        $response = $middleware->process(Factory::request(), Factory::endpoint('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
    }

    public function testCodegenRejectsIllegalClassName(): void
    {
        $generator = new MiddlewareGenerator();

        $this->expectException(\RuntimeException::class);
        $generator->generate('123bad');
    }
}
