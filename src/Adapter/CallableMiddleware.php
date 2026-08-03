<?php

declare(strict_types=1);

namespace Kode\Middleware\Adapter;

use Kode\Middleware\Exception\MiddlewareException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 可调用对象中间件适配器
 *
 * 把闭包 / 函数名 / [$obj, 'method'] 等可调用对象包装成 PSR-15 中间件。
 * 可调用对象签名与 PSR-15 保持一致：
 * `fn(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface`
 *
 * @example
 * ```php
 * $pipeline->add(function ($request, $handler) {
 *     $response = $handler->handle($request);       // 继续向内
 *     return $response->withHeader('X-Powered-By', 'kode/middleware');
 * });
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class CallableMiddleware implements MiddlewareInterface
{
    /** @var callable 被包装的可调用对象 */
    private $callable;

    /**
     * @param callable $callable PSR-15 签名的可调用对象
     */
    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    /**
     * 处理请求
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 可调用对象返回值不是 ResponseInterface 时抛出
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = ($this->callable)($request, $handler);

        if (!$response instanceof ResponseInterface) {
            throw MiddlewareException::invalidResponse(self::class, $response);
        }

        return $response;
    }
}
