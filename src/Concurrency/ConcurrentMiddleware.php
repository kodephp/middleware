<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency;

use Kode\Middleware\Concurrency\Runner\RunnerFactory;
use Kode\Middleware\Contract\PrioritizedInterface;
use Kode\Middleware\Contract\RunnerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 并发能力注入中间件
 *
 * 把一个已经选好的运行器放进请求属性，让下游的控制器、领域服务
 * 无需感知底层是协程、线程还是进程，直接并发跑任务。
 *
 * 这一层解决的是"并发能力如何传递"的工程问题：
 * 不用全局单例（协程下不安全），不用构造注入（控制器签名会被污染），
 * 而是沿着 PSR-7 请求对象自然流动，天然具备请求级隔离。
 *
 * @example
 * ```php
 * // 编排阶段
 * $pipe->beforeRoute(new ConcurrentMiddleware(RunnerFactory::DRIVER_AUTO));
 *
 * // 控制器内
 * $runner = ConcurrentMiddleware::runnerOf($request);
 * [$user, $orders, $coupons] = array_values($runner->all([
 *     'user'    => fn() => $userApi->find($id),
 *     'orders'  => fn() => $orderApi->recent($id),
 *     'coupons' => fn() => $couponApi->usable($id),
 * ], timeout: 2.0));
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ConcurrentMiddleware implements MiddlewareInterface, PrioritizedInterface
{
    /** @var string 请求属性名：并发运行器 */
    public const ATTRIBUTE = 'kode.runner';

    /** @var RunnerInterface|null 显式指定的运行器 */
    private ?RunnerInterface $runner;

    /**
     * @param string $driver 驱动名，取 RunnerFactory::DRIVER_* 之一
     * @param string $profile 任务画像，取 RunnerFactory::PROFILE_* 之一
     * @param RunnerInterface|null $runner 直接指定运行器（优先级高于 driver）
     * @param bool $closeAfterRequest 请求结束后是否释放运行器资源
     * @param int $priority 管道优先级
     */
    public function __construct(
        private readonly string $driver = RunnerFactory::DRIVER_AUTO,
        private readonly string $profile = RunnerFactory::PROFILE_IO,
        ?RunnerInterface $runner = null,
        private readonly bool $closeAfterRequest = false,
        private readonly int $priority = 800,
    ) {
        $this->runner = $runner;
    }

    /**
     * 注入运行器并继续处理请求
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $runner = $this->runner ?? RunnerFactory::make($this->driver, $this->profile);

        try {
            return $handler->handle($request->withAttribute(self::ATTRIBUTE, $runner));
        } finally {
            // 一次性运行器（例如临时进程池）在请求结束后立即回收
            if ($this->closeAfterRequest) {
                $runner->close();
            }
        }
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
     * 从请求中取出并发运行器
     *
     * 若中间件未启用，返回同步运行器兜底，保证调用方代码不需要判空。
     *
     * @param ServerRequestInterface $request 请求对象
     * @return RunnerInterface 并发运行器
     */
    public static function runnerOf(ServerRequestInterface $request): RunnerInterface
    {
        $runner = $request->getAttribute(self::ATTRIBUTE);

        return $runner instanceof RunnerInterface
            ? $runner
            : RunnerFactory::make(RunnerFactory::DRIVER_SYNC);
    }
}
