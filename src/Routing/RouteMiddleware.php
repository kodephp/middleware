<?php

declare(strict_types=1);

namespace Kode\Middleware\Routing;

use Kode\Middleware\Contract\PrioritizedInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 路由匹配中间件（路由分界点）
 *
 * 它是管道上"路由前"与"路由后"的分水岭，职责极窄——**只匹配，不调度**：
 *
 * 1. 调用注入的匹配器得到 RouteResult；
 * 2. 把结果与路径参数写入请求属性；
 * 3. 原样交给下游继续。
 *
 * 匹配与调度分离的价值在于：两者之间可以插入任意数量的中间件，
 * 这些中间件既能读到路由元数据（路由名、参数、权限标签），
 * 又依然运行在控制器之前。典型如"基于路由元数据的鉴权 / 审计 / 灰度分流"。
 *
 * 管道形态：
 * ```
 * [路由前中间件] → RouteMiddleware → [路由后中间件] → DispatchMiddleware → 控制器
 *   CORS/追踪/限流        匹配            鉴权/审计         路由级中间件
 * ```
 *
 * 匹配器签名：`fn(ServerRequestInterface $request): RouteResult`
 * 可对接 FastRoute、symfony/routing、kode/http 内置路由或任意自研路由器。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RouteMiddleware implements MiddlewareInterface, PrioritizedInterface
{
    /**
     * @param \Closure $matcher 路由匹配器，返回 RouteResult
     * @param bool $exposeParams 是否把路径参数逐个平铺到请求属性上
     * @param int $priority 管道优先级，默认 0（位于全局中间件之后）
     */
    public function __construct(
        private readonly \Closure $matcher,
        private readonly bool $exposeParams = true,
        private readonly int $priority = 0,
    ) {
    }

    /**
     * 执行路由匹配并写入请求属性
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = ($this->matcher)($request);

        if (!$result instanceof RouteResult) {
            // 匹配器返回 null 或其它值时，一律视为未命中，保持宽容
            $result = $result === null ? RouteResult::notFound() : RouteResult::matched($result);
        }

        $request = $request->withAttribute(RouteResult::ATTRIBUTE, $result);

        // 把路径参数平铺到属性上，方便控制器直接 $request->getAttribute('id')
        if ($this->exposeParams && $result->isMatched()) {
            foreach ($result->params() as $key => $value) {
                // 属性名必须为字符串；跳过多余的非字符串键（如误传的列表），
                // 避免抛 TypeError 直接打挂整个请求。
                if (!\is_string($key)) {
                    continue;
                }

                $request = $request->withAttribute($key, $value);
            }
        }

        return $handler->handle($request);
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
}
