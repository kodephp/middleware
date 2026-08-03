<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Unit;

use Kode\Middleware\Adapter\CallableMiddleware;
use Kode\Middleware\Contract\PrioritizedInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Pipeline;
use Kode\Middleware\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 管道内核测试
 *
 * 重点验证三条设计承诺：不可变、可重入、可嵌套。
 *
 * @package Kode\Middleware\Tests
 */
#[CoversClass(Pipeline::class)]
final class PipelineTest extends TestCase
{
    /**
     * 中间件按洋葱模型执行：先注册的在外层
     */
    public function testOnionOrder(): void
    {
        $response = Pipeline::of([Factory::tagging('A'), Factory::tagging('B')])
            ->run(Factory::request(), Factory::endpoint('core'));

        // 请求自外向内 A→B，响应自内向外 B→A，因此追加顺序为 core[B][A]
        self::assertSame('core[B][A]', (string) $response->getBody());
    }

    /**
     * add() 不修改原管道（不可变性）
     */
    public function testAddIsImmutable(): void
    {
        $base = Pipeline::of([Factory::tagging('A')]);
        $extended = $base->add(Factory::tagging('B'));

        self::assertSame(1, $base->count());
        self::assertSame(2, $extended->count());
        self::assertNotSame($base, $extended);

        self::assertSame('core[A]', (string) $base->run(Factory::request(), Factory::endpoint('core'))->getBody());
        self::assertSame('core[B][A]', (string) $extended->run(Factory::request(), Factory::endpoint('core'))->getBody());
    }

    /**
     * 同一管道实例可重复处理请求（可重入性）
     *
     * 这是相对于"可变 $index 游标"实现的核心改进：
     * 可变游标在第二次调用时已停在末尾，会直接跳过所有中间件。
     */
    public function testReentrant(): void
    {
        $pipeline = Pipeline::of([Factory::tagging('A'), Factory::tagging('B')])
            ->withDestination(Factory::endpoint('core'));

        $first = (string) $pipeline->handle(Factory::request())->getBody();
        $second = (string) $pipeline->handle(Factory::request())->getBody();
        $third = (string) $pipeline->handle(Factory::request())->getBody();

        self::assertSame('core[B][A]', $first);
        self::assertSame($first, $second, '第二次执行结果必须与第一次一致');
        self::assertSame($first, $third, '第三次执行结果必须与第一次一致');
    }

    /**
     * 中间件可以对下游重试（多次调用 $handler->handle()）
     *
     * 可变游标实现下，第二次 handle() 会从错误位置继续，结果不可预期。
     */
    public function testHandlerCanBeCalledTwice(): void
    {
        $attempts = 0;

        $retry = new CallableMiddleware(
            static function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $handler->handle($request);          // 第一次：模拟失败后重试

                return $handler->handle($request);   // 第二次：取最终结果
            }
        );

        $counter = new CallableMiddleware(
            static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use (&$attempts): ResponseInterface {
                ++$attempts;

                return $handler->handle($request);
            }
        );

        $response = Pipeline::of([$retry, $counter])
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame(2, $attempts, '下游中间件应被执行两次');
        self::assertSame('core', (string) $response->getBody());
    }

    /**
     * 交错执行的两条游标互不干扰（协程安全的等价验证）
     *
     * 用生成器模拟协程切换：在中间件内部挂起，切到另一个请求，再切回来。
     */
    public function testInterleavedExecutionDoesNotCrossTalk(): void
    {
        $pipeline = Pipeline::of([Factory::tagging('A'), Factory::tagging('B'), Factory::tagging('C')])
            ->withDestination(Factory::endpoint('core'));

        $makeFiber = static fn (): \Fiber => new \Fiber(static function () use ($pipeline): string {
            \Fiber::suspend();            // 模拟 I/O 挂起，让出执行权

            return (string) $pipeline->handle(Factory::request())->getBody();
        });

        $one = $makeFiber();
        $two = $makeFiber();

        // 交错启动与恢复
        $one->start();
        $two->start();
        $one->resume();
        $two->resume();

        self::assertSame('core[C][B][A]', $one->getReturn());
        self::assertSame('core[C][B][A]', $two->getReturn(), '并发协程之间不得串号');
    }

    /**
     * 短路中间件阻止下游执行
     */
    public function testShortCircuit(): void
    {
        $reached = false;

        $response = Pipeline::of([
            Factory::tagging('A'),
            Factory::shortCircuit(403, 'denied'),
            new CallableMiddleware(static function ($request, $handler) use (&$reached) {
                $reached = true;

                return $handler->handle($request);
            }),
        ])->run(Factory::request(), Factory::endpoint('core'));

        self::assertFalse($reached, '短路之后的中间件不得被执行');
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('denied[A]', (string) $response->getBody());
    }

    /**
     * 管道自身可作为中间件被嵌套
     */
    public function testPipelineIsNestable(): void
    {
        $inner = Pipeline::of([Factory::tagging('I1'), Factory::tagging('I2')]);

        $response = Pipeline::of([Factory::tagging('O1'), $inner, Factory::tagging('O2')])
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame('core[O2][I2][I1][O1]', (string) $response->getBody());
    }

    /**
     * 优先级决定执行顺序，同优先级保持注册顺序（稳定排序）
     */
    public function testPrioritySorting(): void
    {
        $low = $this->prioritized('LOW', 10);
        $high = $this->prioritized('HIGH', 100);
        $alsoLowA = $this->prioritized('LA', 10);
        $alsoLowB = $this->prioritized('LB', 10);

        $response = Pipeline::of([$low, $high, $alsoLowA, $alsoLowB])
            ->run(Factory::request(), Factory::endpoint('core'));

        // HIGH 最外层；三个 10 分的按注册顺序 LOW → LA → LB
        self::assertSame('core[LB][LA][LOW][HIGH]', (string) $response->getBody());
    }

    /**
     * prepend() 把中间件放到最外层
     */
    public function testPrepend(): void
    {
        $response = Pipeline::of([Factory::tagging('A')])
            ->prepend(Factory::tagging('OUTER'))
            ->run(Factory::request(), Factory::endpoint('core'));

        self::assertSame('core[A][OUTER]', (string) $response->getBody());
    }

    /**
     * 空管道直接落到终点处理器
     */
    public function testEmptyPipeline(): void
    {
        $pipeline = new Pipeline();

        self::assertTrue($pipeline->isEmpty());
        self::assertSame('core', (string) $pipeline->run(Factory::request(), Factory::endpoint('core'))->getBody());
    }

    /**
     * 没有终点处理器时抛出明确异常
     */
    public function testMissingDestinationThrows(): void
    {
        $this->expectException(MiddlewareException::class);
        $this->expectExceptionCode(2002);

        (new Pipeline())->handle(Factory::request());
    }

    /**
     * 构造一个带优先级的标记中间件
     *
     * @param string $tag 标记文本
     * @param int $priority 优先级
     * @return MiddlewareInterface 中间件实例
     */
    private function prioritized(string $tag, int $priority): MiddlewareInterface
    {
        return new class ($tag, $priority) implements MiddlewareInterface, PrioritizedInterface {
            public function __construct(private readonly string $tag, private readonly int $weight)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);

                return Factory::response(
                    $response->getStatusCode(),
                    (string) $response->getBody() . '[' . $this->tag . ']'
                );
            }

            public function priority(): int
            {
                return $this->weight;
            }
        };
    }
}
