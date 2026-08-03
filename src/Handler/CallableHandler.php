<?php

declare(strict_types=1);

namespace Kode\Middleware\Handler;

use Kode\Middleware\Exception\MiddlewareException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 可调用对象处理器适配器
 *
 * 把任意 `fn(ServerRequestInterface): ResponseInterface` 形式的可调用对象
 * 包装成标准的 PSR-15 请求处理器，用作管道终点。
 *
 * @example
 * ```php
 * $pipeline->to(fn($request) => new Response(200, [], 'hello'));
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class CallableHandler implements RequestHandlerInterface
{
    /** @var callable 被包装的可调用对象 */
    private $callable;

    /**
     * @param callable $callable 形如 fn(ServerRequestInterface): ResponseInterface
     */
    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    /**
     * 处理请求
     *
     * @param ServerRequestInterface $request 请求对象
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 可调用对象返回值不是 ResponseInterface 时抛出
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = ($this->callable)($request);

        if (!$response instanceof ResponseInterface) {
            throw MiddlewareException::invalidResponse(self::class, $response);
        }

        return $response;
    }
}
