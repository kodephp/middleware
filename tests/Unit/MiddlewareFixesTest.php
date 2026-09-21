<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Blueprint;
use Kode\Middleware\Codegen\MiddlewareGenerator;
use Kode\Middleware\Concurrency\Runner\ProcessRunner;
use Kode\Middleware\Concurrency\TimeoutMiddleware;
use Kode\Middleware\Distributed\Propagator;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Routing\Router;
use Kode\Middleware\Routing\RouteResult;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * v1.3.0 修复回归：
 *  - Router 匹配不再被首个「路径命中但方法不符」的路由短路成 405；
 *  - Propagator 对用户可控的 X-Request-Id / tracestate 做控制字符剔除与截断；
 *  - 代码生成器中和描述里的 docblock 终结序列并校验命名空间；
 *  - TimeoutMiddleware 上游预算继承设下限，防客户端头压碎预算；
 *  - ProcessRunner 亚秒超时生效且超时杀子、正常回值不受 SIGKILL 自尽路径影响。
 */
#[CoversClass(Router::class)]
#[CoversClass(Propagator::class)]
#[CoversClass(MiddlewareGenerator::class)]
#[CoversClass(TimeoutMiddleware::class)]
#[CoversClass(ProcessRunner::class)]
final class MiddlewareFixesTest extends TestCase
{
    public function testRouterDoesNotShortCircuitOnFirstMethodMismatch(): void
    {
        $router = (new Router())
            ->add('/x', 'handlerGet', [], null, ['GET'])
            ->add('/x', 'handlerPost', [], null, ['POST']);

        $post = $router->match(new ServerRequest('POST', 'http://localhost/x'));
        $this->assertTrue($post->isMatched(), '后登记的同路径路由被前一条的 405 短路吞掉');
        $this->assertSame('handlerPost', $post->handler());

        $put = $router->match(new ServerRequest('PUT', 'http://localhost/x'));
        $this->assertFalse($put->isMatched());
        $this->canonical405($put);

        $get = $router->match(new ServerRequest('GET', 'http://localhost/x'));
        $this->assertTrue($get->isMatched());
        $this->assertSame('handlerGet', $get->handler());
    }

    private function canonical405(RouteResult $r): void
    {
        $allowed = $r->allowed();
        sort($allowed);
        $this->assertSame(['GET', 'POST'], $allowed, '405 应携带所有路径命中路由的方法并集');
    }

    public function testRouterUnconstrainedRouteStillWinsAfterMiss(): void
    {
        $router = (new Router())
            ->add('/y', 'onlyGet', [], null, ['GET'])
            ->add('/y', 'any');

        $post = $router->match(new ServerRequest('POST', 'http://localhost/y'));
        $this->assertTrue($post->isMatched());
        $this->assertSame('any', $post->handler());
    }

    public function testRouterReturns404WhenNoPathHit(): void
    {
        $router = (new Router())->add('/x', 'h', [], null, ['GET']);
        $r = $router->match(new ServerRequest('DELETE', 'http://localhost/zz'));
        $this->assertFalse($r->isMatched());
        $this->assertSame([], $r->allowed());
    }

    public function testPropagatorSanitizesUserControlledHeaders(): void
    {
        // 原始字节清洗逻辑直接验证（PSR-7 各实现宽严不一，HTTP/2 头值确实可携带控制字符）
        $sanitize = new \ReflectionMethod(Propagator::class, 'sanitizeHeaderValue');
        $sanitize->setAccessible(true);

        $dirty = $sanitize->invoke(null, "abc\r\nSet-Cookie: session=hijacked");
        $this->assertStringNotContainsString("\r", $dirty);
        $this->assertStringNotContainsString("\n", $dirty);
        $this->assertStringNotContainsString("\x07", $sanitize->invoke(null, "ven\x07\x09dor"));
        $this->assertSame(200, strlen($sanitize->invoke(null, str_repeat('A', 500))));

        // 公共入口：超长回退截断、纯垃圾值回退为自动生成 ID
        $long = new ServerRequest('GET', 'http://localhost/', ['X-Request-Id' => str_repeat('A', 500)]);
        $this->assertLessThanOrEqual(200, strlen(Propagator::extract($long)['requestId']));

        $blank = new ServerRequest('GET', 'http://localhost/', ['X-Request-Id' => '   ']);
        $ctx = Propagator::extract($blank);
        $this->assertSame(16, strlen($ctx['requestId']), '全空白应视为未提供并自动生成');
    }

    public function testGeneratorNeutralizesDocblockTerminator(): void
    {
        $src = (new MiddlewareGenerator())->generate('Probe', [
            'description' => "desc\nfinal class Dummy {}*/\nvar_dump(1);",
        ]);

        $outside = '';

        foreach (\PhpToken::tokenize($src) as $token) {
            if (in_array($token->id, [\T_DOC_COMMENT, \T_COMMENT, \T_OPEN_TAG, \T_WHITESPACE], true)) {
                continue;
            }

            $outside .= $token->text;
        }

        $this->assertSame(1, substr_count($outside, 'final'), 'docblock 终结序列把描述文本漏进了可执行区');
        $this->assertStringNotContainsString('var_dump', $outside);
        $this->assertStringNotContainsString('Dummy', $outside);
    }

    public function testGeneratorRejectsMalformedNamespace(): void
    {
        $this->expectException(\RuntimeException::class);
        new MiddlewareGenerator('App\\Middleware{}');
    }

    public function testBlueprintKeepsMalformedNamesUntrusted(): void
    {
        $bp = Blueprint::fromArray(['before' => ['Not\\Registered\\Middleware'], 'after' => []]);

        $this->expectException(MiddlewareException::class);
        $bp->rebuild();
    }

    public function testTimeoutInheritanceHasFloor(): void
    {
        $mw = new TimeoutMiddleware(budget: 30.0);

        $capture = null;
        $handler = new class implements RequestHandlerInterface {
            public mixed $captured = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request->getAttribute(TimeoutMiddleware::ATTRIBUTE_BUDGET);

                return new \Nyholm\Psr7\Response(200);
            }
        };

        $tight = new ServerRequest('GET', 'http://localhost/', ['X-Request-Timeout' => '1']);
        $mw->process($tight, $handler);
        $this->assertSame(TimeoutMiddleware::MIN_INHERITED_BUDGET, $handler->captured);

        $sane = new ServerRequest('GET', 'http://localhost/', ['X-Request-Timeout' => '5000']);
        $mw->process($sane, $handler);
        $this->assertSame(5.0, $handler->captured);

        $over = new ServerRequest('GET', 'http://localhost/', ['X-Request-Timeout' => '999000']);
        $mw->process($over, $handler);
        $this->assertSame(30.0, $handler->captured, '上游不得把预算放大到本地之上');
    }

    public function testProcessRunnerRoundTripUnderSigkillChildExit(): void
    {
        $runner = new ProcessRunner();

        if (!$runner->supported()) {
            $this->markTestSkipped('需要 pcntl 与 socket pair');
        }

        $this->assertSame(42, $runner->submit(static fn (): int => 42)->await(5.0));
    }

    public function testProcessRunnerSubSecondTimeoutKillsChild(): void
    {
        $runner = new ProcessRunner(timeout: 30.0);

        if (!$runner->supported()) {
            $this->markTestSkipped('需要 pcntl 与 socket pair');
        }

        $handle = $runner->submit(static function (): int {
            $end = microtime(true) + 3.0;

            while (microtime(true) < $end) {
                // 忙等，模拟不返回的子任务
            }

            return 1;
        });

        $start = microtime(true);
        $this->expectException(MiddlewareException::class);

        try {
            $handle->await(0.3);
        } finally {
            $elapsed = microtime(true) - $start;

            if ($elapsed >= 2.5) {
                $this->fail("超时未切断子进程等待（耗时 {$elapsed}s），阻塞式回收回归");
            }

            // 子进程必须已被终止回收：不留僵尸（waitpid(-1) 无待收项）
            $status = 0;
            $this->assertLessThanOrEqual(0, \pcntl_waitpid(-1, $status, \WNOHANG));
        }
    }
}
