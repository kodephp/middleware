<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency;

use Kode\Middleware\Contract\PrioritizedInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求时间预算中间件
 *
 * 为整条下游链路设定一个时间预算，并把"剩余可用时间"沿请求属性向下传递，
 * 让下游的 HTTP 客户端、数据库查询可以据此设置自己的超时时间——
 * 这是分布式系统里避免"超时时间层层放大"的标准做法（deadline propagation）。
 *
 * 关于"强制中断"的说明（请务必阅读）：
 * PHP 没有安全的抢占式线程中断机制。因此本中间件的行为是：
 * - **总是**记录起止时间，并在超时后按 $onTimeout 策略处理；
 * - 默认策略 `report`：不打断执行，仅在超时时抛出异常（下游已跑完，用于快速暴露慢接口）；
 * - 策略 `header`：不抛异常，只在响应头标注超时信息，适合灰度观测期；
 * - 真正的"到点即断"需要下游配合读取 deadline 主动放弃，这也是本中间件
 *   传递剩余时间的意义所在。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class TimeoutMiddleware implements MiddlewareInterface, PrioritizedInterface
{
    /** @var string 请求属性名：截止时间戳（浮点秒） */
    public const ATTRIBUTE_DEADLINE = 'kode.deadline';

    /** @var string 请求属性名：时间预算（秒） */
    public const ATTRIBUTE_BUDGET = 'kode.budget';

    /** @var string 超时策略：抛出异常 */
    public const ON_TIMEOUT_THROW = 'throw';

    /** @var string 超时策略：仅在响应头标注 */
    public const ON_TIMEOUT_HEADER = 'header';

    /**
     * @param float $budget 时间预算（秒）
     * @param string $onTimeout 超时策略，取 ON_TIMEOUT_* 之一
     * @param bool $inheritUpstream 是否继承上游通过请求头传来的剩余预算
     * @param int $priority 管道优先级
     */
    public function __construct(
        private readonly float $budget = 30.0,
        private readonly string $onTimeout = self::ON_TIMEOUT_HEADER,
        private readonly bool $inheritUpstream = true,
        private readonly int $priority = 850,
    ) {
    }

    /**
     * 设置时间预算并监控耗时
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 策略为 throw 且超出预算时抛出
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $budget = $this->resolveBudget($request);
        $start = microtime(true);
        $deadline = $start + $budget;

        $request = $request
            ->withAttribute(self::ATTRIBUTE_BUDGET, $budget)
            ->withAttribute(self::ATTRIBUTE_DEADLINE, $deadline);

        $response = $handler->handle($request);

        $elapsed = microtime(true) - $start;

        if ($elapsed <= $budget) {
            return $response;
        }

        if ($this->onTimeout === self::ON_TIMEOUT_THROW) {
            throw MiddlewareException::requestTimeout($budget);
        }

        return $response
            ->withHeader('X-Timeout-Exceeded', '1')
            ->withHeader('X-Elapsed-Ms', (string) (int) round($elapsed * 1000));
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
     * 读取请求剩余可用时间
     *
     * 下游调用外部服务时应据此设置客户端超时，避免超时时间逐层放大。
     *
     * @param ServerRequestInterface $request 请求对象
     * @param float $default 未设置预算时返回的缺省值
     * @return float 剩余秒数，已超时返回 0.0
     */
    public static function remaining(ServerRequestInterface $request, float $default = 0.0): float
    {
        $deadline = $request->getAttribute(self::ATTRIBUTE_DEADLINE);

        if (!is_float($deadline) && !is_int($deadline)) {
            return $default;
        }

        return max(0.0, (float) $deadline - microtime(true));
    }

    /**
     * 解析本次请求的时间预算
     *
     * 上游通过 `X-Request-Timeout`（毫秒）传来的剩余预算若更紧，则以其为准。
     *
     * @param ServerRequestInterface $request 请求对象
     * @return float 时间预算（秒）
     */
    private function resolveBudget(ServerRequestInterface $request): float
    {
        if (!$this->inheritUpstream) {
            return $this->budget;
        }

        $header = $request->getHeaderLine('X-Request-Timeout');

        if ($header === '' || !is_numeric($header)) {
            return $this->budget;
        }

        $upstream = ((float) $header) / 1000.0;

        return $upstream > 0 ? min($this->budget, $upstream) : $this->budget;
    }
}
