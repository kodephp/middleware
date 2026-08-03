<?php

declare(strict_types=1);

namespace Kode\Middleware\Contract;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 可终结中间件契约
 *
 * 实现此接口的中间件会在**响应已经发送给客户端之后**被回调，
 * 适合执行埋点上报、日志落盘、会话持久化等不应阻塞响应的收尾工作。
 *
 * 在 FPM 下需配合 fastcgi_finish_request()；
 * 在 Swoole / Workerman / kode/process 常驻进程下则在 Worker 中直接执行。
 *
 * 注意：terminate() 中抛出的异常会被管道吞掉并忽略，
 * 以保证收尾阶段的失败不会影响已完成的请求。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
interface TerminableInterface
{
    /**
     * 响应发送完毕后的收尾回调
     *
     * @param ServerRequestInterface $request  本次请求
     * @param ResponseInterface      $response 已发送的响应
     * @return void
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void;
}
