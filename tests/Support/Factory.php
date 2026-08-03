<?php

declare(strict_types=1);

namespace Kode\Middleware\Tests\Support;

use Kode\Middleware\Adapter\CallableMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 测试辅助工厂
 *
 * 集中提供请求 / 响应 / 中间件桩件的构造方法，避免测试用例里重复样板代码。
 *
 * @package Kode\Middleware\Tests
 */
final class Factory
{
    /**
     * 构造测试请求
     *
     * @param string $path 请求路径
     * @param string $method HTTP 方法
     * @param array<string, string> $headers 请求头
     * @return ServerRequestInterface 请求对象
     */
    public static function request(
        string $path = '/',
        string $method = 'GET',
        array $headers = []
    ): ServerRequestInterface {
        return new ServerRequest($method, 'http://localhost' . $path, $headers);
    }

    /**
     * 构造测试响应
     *
     * @param int $status 状态码
     * @param string $body 响应体
     * @return ResponseInterface 响应对象
     */
    public static function response(int $status = 200, string $body = ''): ResponseInterface
    {
        return new Response($status, [], $body);
    }

    /**
     * 构造一个在响应体上追加标记的中间件
     *
     * 用于验证洋葱模型的执行顺序：先注册的中间件其标记出现在更外层。
     *
     * @param string $tag 标记文本
     * @return MiddlewareInterface 中间件实例
     */
    public static function tagging(string $tag): MiddlewareInterface
    {
        return new CallableMiddleware(
            static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($tag): ResponseInterface {
                $response = $handler->handle($request->withAttribute(
                    'trail',
                    trim(((string) $request->getAttribute('trail', '')) . '>' . $tag, '>')
                ));

                $body = (string) $response->getBody();

                return self::response($response->getStatusCode(), $body . '[' . $tag . ']');
            }
        );
    }

    /**
     * 构造一个直接短路返回的中间件（不调用下游）
     *
     * @param int $status 状态码
     * @param string $body 响应体
     * @return MiddlewareInterface 中间件实例
     */
    public static function shortCircuit(int $status = 403, string $body = 'denied'): MiddlewareInterface
    {
        return new CallableMiddleware(
            static fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                => self::response($status, $body)
        );
    }

    /**
     * 构造一个回显指定文本的终点处理器
     *
     * @param string $body 响应体
     * @return RequestHandlerInterface 处理器实例
     */
    public static function endpoint(string $body = 'ok'): RequestHandlerInterface
    {
        return new class ($body) implements RequestHandlerInterface {
            public function __construct(private readonly string $body)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Factory::response(200, $this->body);
            }
        };
    }
}
