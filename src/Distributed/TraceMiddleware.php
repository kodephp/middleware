<?php

declare(strict_types=1);

namespace Kode\Middleware\Distributed;

use Kode\Middleware\Contract\PrioritizedInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 分布式链路追踪中间件
 *
 * 管道最外层的基础设施中间件（默认优先级 1000），职责：
 *
 * 1. 从入站请求头提取 W3C traceparent，缺失则新建一条链路；
 * 2. 把 traceId / spanId / requestId / nodeId 写入请求属性；
 * 3. 同步写入 kode/context（若已安装），使得任意深度的业务代码
 *    无需层层传参即可读到链路 ID——包括在协程、线程、子进程中；
 * 4. 在响应头回写 traceId 与节点标识，便于客户端与网关侧关联日志。
 *
 * 与 kode/context 的配合是这里的关键：Context 会按运行时自动选择
 * Fiber-local / 协程上下文 / 线程局部 / 进程全局存储，
 * 因此同一份链路 ID 在四种并发模型下都能正确隔离，不会串号。
 *
 * @example
 * ```php
 * $pipe->beforeRoute(new TraceMiddleware(), new ScopeMiddleware());
 *
 * // 业务任意深处
 * $traceId = TraceMiddleware::traceIdOf($request);
 *
 * // 调用下游服务时透传
 * $client->get($url, ['headers' => Propagator::forward($request)]);
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class TraceMiddleware implements MiddlewareInterface, PrioritizedInterface
{
    /** @var string 请求属性名：链路上下文数组 */
    public const ATTRIBUTE = 'kode.trace';

    /** @var string kode/context 上下文类名 */
    private const CONTEXT = 'Kode\\Context\\Context';

    /**
     * @param bool $exposeResponseHeaders 是否在响应头回写链路信息
     * @param bool $syncToContext 是否同步写入 kode/context
     * @param string|null $nodeId 节点标识，省略时自动探测
     * @param int $priority 管道优先级
     */
    public function __construct(
        private readonly bool $exposeResponseHeaders = true,
        private readonly bool $syncToContext = true,
        private readonly ?string $nodeId = null,
        private readonly int $priority = 1000,
    ) {
    }

    /**
     * 建立链路上下文并处理请求
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $node = new NodeIdentity($this->nodeId);

        $trace = Propagator::extract($request);
        $trace['nodeId'] = $node->id();
        $trace['instance'] = $node->instance();
        $trace['startedAt'] = microtime(true);

        $this->writeContext($trace);

        $request = $request->withAttribute(self::ATTRIBUTE, $trace);

        $response = $handler->handle($request);

        if (!$this->exposeResponseHeaders) {
            return $response;
        }

        return $response
            ->withHeader('X-Trace-Id', $trace['traceId'])
            ->withHeader('X-Request-Id', $trace['requestId'])
            ->withHeader(Propagator::HEADER_NODE_ID, $trace['nodeId'])
            ->withHeader('X-Duration-Ms', (string) (int) round((microtime(true) - $trace['startedAt']) * 1000));
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
     * 读取当前请求的链路上下文
     *
     * @param ServerRequestInterface $request 请求对象
     * @return array<string, mixed> 链路上下文，未启用中间件时返回空数组
     */
    public static function contextOf(ServerRequestInterface $request): array
    {
        $trace = $request->getAttribute(self::ATTRIBUTE);

        return is_array($trace) ? $trace : [];
    }

    /**
     * 读取当前请求的追踪 ID
     *
     * @param ServerRequestInterface $request 请求对象
     * @return string 追踪 ID，未启用中间件时返回空字符串
     */
    public static function traceIdOf(ServerRequestInterface $request): string
    {
        $trace = self::contextOf($request);

        return is_string($trace['traceId'] ?? null) ? $trace['traceId'] : '';
    }

    /**
     * 把链路信息同步进 kode/context
     *
     * kode/context 未安装时静默跳过，不影响管道运行。
     *
     * @param array<string, mixed> $trace 链路上下文
     * @return void
     */
    private function writeContext(array $trace): void
    {
        if (!$this->syncToContext || !class_exists(self::CONTEXT)) {
            return;
        }

        $context = self::CONTEXT;

        try {
            // 优先走 kode/context 自带的链路 API，可同时建立 span 关系
            // @phpstan-ignore-next-line kode/context 为可选兄弟包，运行期才确定是否存在
            if (method_exists($context, 'startTrace')) {
                $context::startTrace(
                    is_string($trace['traceId'] ?? null) ? $trace['traceId'] : null,
                    is_string($trace['nodeId'] ?? null) ? $trace['nodeId'] : null
                );
            }

            // @phpstan-ignore-next-line kode/context 为可选兄弟包，运行期才确定是否存在
            if (method_exists($context, 'set')) {
                foreach (['traceId', 'spanId', 'parentSpanId', 'requestId', 'nodeId'] as $key) {
                    if (isset($trace[$key])) {
                        $context::set($key, $trace[$key]);
                    }
                }
            }
        } catch (\Throwable) {
            // 链路写入失败不应影响业务请求
        }
    }
}
