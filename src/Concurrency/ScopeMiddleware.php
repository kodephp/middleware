<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency;

use Kode\Middleware\Contract\PrioritizedInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 上下文作用域隔离中间件
 *
 * 在常驻内存 + 协程并发的运行模型下，"请求级全局状态"是最容易出错的地方：
 * 当前登录用户、租户 ID、链路 ID 如果存在普通静态变量里，
 * 两个交错执行的协程会互相覆盖，出现极难复现的串号 Bug。
 *
 * 本中间件为每个请求开辟独立的上下文作用域，请求结束时自动回滚，
 * 并把 traceId / requestId 等基础字段预置好。
 *
 * 依赖 kode/context（可选）：
 * - 已安装：调用 `Context::run()`（全新作用域）或 `Context::fork()`（继承父作用域），
 *   由 kode/context 按 Fiber / Swoole / Swow / 线程 / 进程自动选择底层存储；
 * - 未安装：退化为直通，不影响管道正常工作。
 *
 * 建议放在管道非常靠外的位置（默认优先级 900），
 * 仅次于异常捕获与链路追踪，确保后续所有中间件都在隔离作用域内运行。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ScopeMiddleware implements MiddlewareInterface, PrioritizedInterface
{
    /** @var string kode/context 上下文类名 */
    private const CONTEXT = 'Kode\\Context\\Context';

    /**
     * @param bool $inherit 是否继承父作用域（true 用 fork，false 用 run）
     * @param array<string, mixed> $seed 进入作用域后预置的上下文数据
     * @param int $priority 管道优先级
     */
    public function __construct(
        private readonly bool $inherit = false,
        private readonly array $seed = [],
        private readonly int $priority = 900,
    ) {
    }

    /**
     * 在隔离的上下文作用域内处理请求
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!self::available()) {
            return $handler->handle($request);
        }

        $context = self::CONTEXT;
        // @phpstan-ignore-next-line kode/context 为可选兄弟包，运行期才确定是否存在
        $method = $this->inherit && method_exists($context, 'fork') ? 'fork' : 'run';

        /** @var ResponseInterface $response */
        $response = $context::$method(function () use ($request, $handler, $context): ResponseInterface {
            foreach ($this->seed as $key => $value) {
                $context::set($key, $value);
            }

            return $handler->handle($request);
        });

        return $response;
    }

    /**
     * 管道优先级
     *
     * @return int 优先级数值
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * kode/context 是否可用
     *
     * @return bool 可用返回 true
     */
    public static function available(): bool
    {
        return class_exists(self::CONTEXT);
    }

    /**
     * 读取当前运行时名称（fiber / swoole / swow / thread / process / sync）
     *
     * @return string 运行时名称，kode/context 不可用时返回 unknown
     */
    public static function runtime(): string
    {
        $context = self::CONTEXT;

        // @phpstan-ignore-next-line kode/context 为可选兄弟包，运行期才确定是否存在
        if (!self::available() || !method_exists($context, 'getRuntime')) {
            return 'unknown';
        }

        return (string) $context::getRuntime();
    }
}
