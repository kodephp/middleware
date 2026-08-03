<?php

declare(strict_types=1);

namespace Kode\Middleware\Handler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 固定响应处理器
 *
 * 永远返回同一个预置响应，常用于：
 * - 管道兜底（例如统一的 404 / 503）
 * - 单元测试中充当终点桩件
 *
 * 由于本包不依赖任何具体的 PSR-7 实现，响应对象需由调用方注入，
 * 可以来自 kode/http、nyholm/psr7、guzzlehttp/psr7 等任意实现。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class FixedResponseHandler implements RequestHandlerInterface
{
    /**
     * @param ResponseInterface $response 固定返回的响应对象
     */
    public function __construct(private readonly ResponseInterface $response)
    {
    }

    /**
     * 处理请求：直接返回预置响应
     *
     * @param ServerRequestInterface $request 请求对象（未使用）
     * @return ResponseInterface 预置响应
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
