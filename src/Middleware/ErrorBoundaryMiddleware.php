<?php

declare(strict_types=1);

namespace Kode\Middleware\Middleware;

use Kode\Middleware\Contract\PrioritizedInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 异常边界中间件（洋葱最外层）
 *
 * 把下游任意深度抛出的 Throwable 转换成一个正常的 PSR-7 响应，
 * 让洋葱的响应回程不至于因为一次异常而整条断裂。
 *
 * ## 与 Kernel::withExceptionHandler() 的分工
 *
 * 二者是两道**互不重叠**的防线，建议同时启用：
 *
 * | | 位置 | 捕获范围 | 典型用途 |
 * |---|---|---|---|
 * | 本中间件 | 洋葱最外层（内） | 洋葱内部一切异常 | 渲染错误页 / 错误 JSON，且**仍走完外层响应加工** |
 * | Kernel 兜底 | 洋葱之外 | 连本中间件都失效的情况 | 最小化纯文本 500，保证不裸奔 |
 *
 * 之所以两者都要，是因为中间件无法捕获"比自己更外层的中间件"抛出的异常，
 * 也无法捕获自己 renderer 内部的二次异常——那些只能由内核兜住。
 *
 * ## 健壮性约定
 *
 * 1. `$reporter`（上报）与 `$renderer`（渲染）严格分离，上报失败绝不影响渲染；
 * 2. `$renderer` 自身抛出的异常会被包装为 5002 并继续上抛，交给内核，
 *    绝不静默返回一个空响应掩盖问题；
 * 3. 可通过 `$passthrough` 指定"不拦截"的异常类型，
 *    例如框架自身的 HttpException 需要交给更外层统一处理时。
 *
 * @example
 * ```php
 * $boundary = new ErrorBoundaryMiddleware(
 *     renderer: fn(\Throwable $e) => new JsonResponse(['error' => $e->getMessage()], 500),
 *     reporter: fn(\Throwable $e) => $logger->error($e->getMessage(), ['exception' => $e]),
 *     passthrough: [ValidationException::class],
 * );
 *
 * $pipe->beforeRoute($boundary, new TraceMiddleware(), ...);
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ErrorBoundaryMiddleware implements MiddlewareInterface, PrioritizedInterface
{
    /**
     * 默认优先级
     *
     * 高于 TraceMiddleware(1000)，确保连链路追踪自身的异常也能被兜住。
     */
    public const PRIORITY = 2000;

    /** @var string 请求属性名：被本层捕获的异常 */
    public const ATTRIBUTE = 'kode.error';

    /**
     * @param \Closure $renderer 渲染闭包，形如 fn(\Throwable, ServerRequestInterface): ResponseInterface
     * @param \Closure|null $reporter 上报闭包，形如 fn(\Throwable, ServerRequestInterface): void
     * @param list<class-string<\Throwable>> $passthrough 直接放行、不予拦截的异常类型
     * @param int $priority 优先级，越大越靠外层
     */
    public function __construct(
        private readonly \Closure $renderer,
        private readonly ?\Closure $reporter = null,
        private readonly array $passthrough = [],
        private readonly int $priority = self::PRIORITY,
    ) {
    }

    /**
     * 处理请求：捕获下游异常并渲染成响应
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     * @throws \Throwable 命中放行名单，或渲染器自身失败时抛出
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            if ($this->shouldPassthrough($e)) {
                throw $e;
            }

            $this->report($e, $request);

            return $this->render($e, $request);
        }
    }

    /**
     * 优先级
     *
     * @return int 优先级数值
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * 判断异常是否应当直接放行
     *
     * @param \Throwable $e 异常
     * @return bool 需要放行返回 true
     */
    private function shouldPassthrough(\Throwable $e): bool
    {
        foreach ($this->passthrough as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * 上报异常（失败不影响主流程）
     *
     * @param \Throwable $e 异常
     * @param ServerRequestInterface $request 请求对象
     * @return void
     */
    private function report(\Throwable $e, ServerRequestInterface $request): void
    {
        if ($this->reporter === null) {
            return;
        }

        try {
            ($this->reporter)($e, $request);
        } catch (\Throwable) {
            // 上报通道（日志 / APM）故障不能连累错误页渲染
        }
    }

    /**
     * 渲染错误响应
     *
     * @param \Throwable $e 异常
     * @param ServerRequestInterface $request 请求对象
     * @return ResponseInterface 错误响应
     * @throws MiddlewareException 渲染器自身失败或返回值非法时抛出
     */
    private function render(\Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        try {
            $response = ($this->renderer)($e, $request->withAttribute(self::ATTRIBUTE, $e));
        } catch (\Throwable $thrown) {
            throw MiddlewareException::rescueFailed($e, $thrown);
        }

        if (!$response instanceof ResponseInterface) {
            throw MiddlewareException::invalidResponse(self::class, $response);
        }

        return $response;
    }
}
