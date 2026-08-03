<?php

declare(strict_types=1);

namespace Kode\Middleware\Adapter;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 条件中间件
 *
 * 按谓词决定是否执行被包裹的中间件；条件不满足时直接透传给下游，
 * 被包裹的中间件不会被执行（若它是惰性的，甚至不会被实例化）。
 *
 * 常用于"除了健康检查以外都要鉴权"这类局部豁免场景，
 * 比在管道里做路由分叉更轻量。
 *
 * @example
 * ```php
 * // 仅对 /api 前缀启用限流
 * $pipeline->add(ConditionalMiddleware::prefix('/api', $rateLimiter));
 *
 * // 排除健康检查
 * $pipeline->add(ConditionalMiddleware::unless(
 *     fn($req) => $req->getUri()->getPath() === '/health',
 *     $auth
 * ));
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ConditionalMiddleware implements MiddlewareInterface
{
    /**
     * @param \Closure $predicate 谓词，形如 fn(ServerRequestInterface): bool
     * @param MiddlewareInterface $middleware 条件满足时执行的中间件
     */
    public function __construct(
        private readonly \Closure $predicate,
        private readonly MiddlewareInterface $middleware,
    ) {
    }

    /**
     * 条件满足时执行
     *
     * @param \Closure $predicate 谓词，返回 true 则执行
     * @param MiddlewareInterface $middleware 目标中间件
     * @return self 条件中间件实例
     */
    public static function when(\Closure $predicate, MiddlewareInterface $middleware): self
    {
        return new self($predicate, $middleware);
    }

    /**
     * 条件不满足时执行（谓词取反）
     *
     * @param \Closure $predicate 谓词，返回 false 则执行
     * @param MiddlewareInterface $middleware 目标中间件
     * @return self 条件中间件实例
     */
    public static function unless(\Closure $predicate, MiddlewareInterface $middleware): self
    {
        return new self(
            static fn (ServerRequestInterface $request): bool => !$predicate($request),
            $middleware
        );
    }

    /**
     * 仅当请求路径匹配指定前缀时执行
     *
     * @param string $prefix 路径前缀，例如 /api
     * @param MiddlewareInterface $middleware 目标中间件
     * @return self 条件中间件实例
     */
    public static function prefix(string $prefix, MiddlewareInterface $middleware): self
    {
        return new self(
            static fn (ServerRequestInterface $request): bool
                => str_starts_with($request->getUri()->getPath(), $prefix),
            $middleware
        );
    }

    /**
     * 仅当请求方法命中列表时执行
     *
     * @param array<int, string> $methods HTTP 方法列表，例如 ['POST', 'PUT']
     * @param MiddlewareInterface $middleware 目标中间件
     * @return self 条件中间件实例
     */
    public static function methods(array $methods, MiddlewareInterface $middleware): self
    {
        $upper = array_map(strtoupper(...), $methods);

        return new self(
            static fn (ServerRequestInterface $request): bool
                => in_array(strtoupper($request->getMethod()), $upper, true),
            $middleware
        );
    }

    /**
     * 处理请求
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (($this->predicate)($request) === true) {
            return $this->middleware->process($request, $handler);
        }

        return $handler->handle($request);
    }
}
