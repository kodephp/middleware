<?php

declare(strict_types=1);

namespace Kode\Middleware;

use Kode\Middleware\Contract\ResolverInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 管道游标（不可变）
 *
 * 这是整条管道协程安全的关键所在。
 *
 * 常见实现（例如 Kode\Http\Middleware\MiddlewarePipeline）会在调度器上保存一个
 * 可变的 `private int $index`，靠自增来推进管道。这种写法有三个致命缺陷：
 *
 * 1. **不可重入**：同一个调度器实例处理第二个请求时，index 已经停在末尾；
 * 2. **协程不安全**：Fiber / Swoole 协程交错执行时，两个请求会互相推进对方的游标，
 *    出现"串号"——A 请求跳过了本该执行的中间件，B 请求重复执行了同一个中间件；
 * 3. **无法重试**：中间件想对下游做一次重试（两次调用 $handler->handle()）时，
 *    第二次会从错误的位置继续。
 *
 * 本类改用**不可变游标**：每前进一步就构造一个 index+1 的新游标对象，
 * 自身状态在构造后永不改变。于是：
 * - 同一管道可被任意多个请求、任意多个协程并发共享；
 * - 中间件可以安全地多次调用 $handler->handle() 实现重试或分支；
 * - 管道实例可以安全地跨 Worker 复用，无需每请求重建。
 *
 * @internal 由 Pipeline 内部使用，不作为公开 API
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Cursor implements RequestHandlerInterface
{
    /**
     * @param list<mixed> $stack 中间件声明列表（已按优先级排序）
     * @param int $index 当前游标位置
     * @param RequestHandlerInterface|null $destination 管道终点处理器
     * @param ResolverInterface $resolver 中间件解析器
     */
    public function __construct(
        private readonly array $stack,
        private readonly int $index,
        private readonly ?RequestHandlerInterface $destination,
        private readonly ResolverInterface $resolver,
    ) {
    }

    /**
     * 处理请求：执行当前位置的中间件，并把 index+1 的新游标作为下游处理器传入
     *
     * @param ServerRequestInterface $request 请求对象
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 中间件返回值非法，或管道无终点处理器
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 游标已越过最后一个中间件，交给终点处理器收尾
        if (!array_key_exists($this->index, $this->stack)) {
            if ($this->destination === null) {
                throw MiddlewareException::noDestination();
            }

            return $this->destination->handle($request);
        }

        $middleware = $this->resolver->resolve($this->stack[$this->index]);

        // 关键：构造新游标而非自增，保证本对象自始至终只读
        $next = new self(
            $this->stack,
            $this->index + 1,
            $this->destination,
            $this->resolver,
        );

        $response = $middleware->process($request, $next);

        if (!$response instanceof ResponseInterface) {
            throw MiddlewareException::invalidResponse($middleware::class, $response);
        }

        return $response;
    }

    /**
     * 当前游标位置
     *
     * @return int 位置索引，从 0 开始
     */
    public function position(): int
    {
        return $this->index;
    }

    /**
     * 后续还有多少个中间件未执行
     *
     * @return int 剩余中间件数量
     */
    public function remaining(): int
    {
        return max(0, count($this->stack) - $this->index);
    }
}
