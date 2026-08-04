<?php

declare(strict_types=1);

namespace Kode\Middleware\Integration;

use Kode\Middleware\Concurrency\ConcurrentMiddleware;
use Kode\Middleware\Concurrency\ScopeMiddleware;
use Kode\Middleware\Concurrency\TimeoutMiddleware;
use Kode\Middleware\Distributed\TraceMiddleware;
use Kode\Middleware\Kernel;
use Kode\Middleware\Pipe;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * kode 框架集成桥
 *
 * 给框架侧一行式接入中间件能力，屏蔽样板代码。框架只需：
 *
 * 1. 用 {@see Pipe} 描述业务路由与中间件；
 * 2. 调用 {@see self::kernel()} 把生命周期钩子（`boot` / `onRequest` /
 *    `onResponse` / `onTerminate` / `rescue`）以关联数组一次性传入；
 * 3. 拿到 {@see Kernel} 直接 `run()`。
 *
 * 两个可选项默认开启：
 *
 * - `stack`：挂上内置中间件栈（按优先级自动排序）
 *   `Trace(1000) → Scope(900) → Timeout(850) → Concurrent(800)`，
 *   它们对未安装的兄弟扩展软降级，挂上即生效，无需框架关心底层驱动。
 * - `observe`：挂上可观测性（异常边界 + 分层剖析），二者都声明高优先级，
 *   恒落在洋葱最外层。异常渲染器由框架提供（库不绑定具体 PSR-7 实现）。
 *
 * @example
 * ```php
 * $kernel = FrameworkBridge::kernel(
 *     pipe: Pipe::create($container)
 *         ->route('/', fn() => new Response(200, [], 'home'))
 *         ->route('/users/{id}', UserController::class, ['auth']),
 *     hooks: [
 *         'boot'       => fn(Kernel $k) => RouteCache::warm(),
 *         'onResponse' => fn(ResponseInterface $r, $q) => $r->withHeader('X-Powered-By', 'kode'),
 *         'rescue'     => fn(\Throwable $e, $q) => new Response(500, [], 'err'),
 *     ],
 *     renderer: fn(\Throwable $e, $q) => new Response(500, [], $e->getMessage()),
 * );
 *
 * $kernel->boot();
 * $response = $kernel->handle($request);
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class FrameworkBridge
{
    /**
     * 一站式构建框架内核
     *
     * @param Pipe $pipe 已配置路由与业务中间件的构建器
     * @param array{
     *     boot?: \Closure,
     *     onRequest?: \Closure,
     *     onResponse?: \Closure,
     *     onTerminate?: \Closure,
     *     rescue?: \Closure,
     * } $hooks 内核生命周期钩子（均为可选）
     * @param bool $observe 是否挂载异常边界 + 分层剖析（默认 true）
     * @param bool $stack 是否挂载内置中间件栈（默认 true）
     * @param \Closure|null $renderer 异常渲染器，形如 fn(\Throwable, ServerRequestInterface): ResponseInterface；
     *                                开启 observe 时必填
     * @param \Closure|null $reporter 异常上报器，形如 fn(\Throwable, ServerRequestInterface): void
     * @param \Closure|null $sink 剖析结果回调，形如 fn(Profile, ServerRequestInterface): void
     * @return Kernel 可直接 run() / handle() 的内核
     * @throws \InvalidArgumentException 开启 observe 但未提供 $renderer 时抛出
     */
    public static function kernel(
        Pipe $pipe,
        array $hooks = [],
        bool $observe = true,
        bool $stack = true,
        ?\Closure $renderer = null,
        ?\Closure $reporter = null,
        ?\Closure $sink = null,
    ): Kernel {
        if ($stack) {
            $pipe = self::stack($pipe);
        }

        if ($observe) {
            if ($renderer === null) {
                throw new \InvalidArgumentException('开启可观测性（observe=true）时必须提供 $renderer 异常渲染器闭包');
            }

            $pipe = self::observe($pipe, $renderer, $reporter, $sink);
        }

        $kernel = Kernel::of($pipe->build());

        if (isset($hooks['boot'])) {
            $kernel = $kernel->withBootstrapper($hooks['boot']);
        }
        if (isset($hooks['onRequest'])) {
            $kernel = $kernel->onRequest($hooks['onRequest']);
        }
        if (isset($hooks['onResponse'])) {
            $kernel = $kernel->onResponse($hooks['onResponse']);
        }
        if (isset($hooks['onTerminate'])) {
            $kernel = $kernel->onTerminate($hooks['onTerminate']);
        }
        if (isset($hooks['rescue'])) {
            $kernel = $kernel->withExceptionHandler($hooks['rescue']);
        }

        return $kernel;
    }

    /**
     * 挂载内置中间件栈（按优先级自动排序）
     *
     * 全部位于业务中间件之前。它们对未安装的兄弟扩展软降级，
     * 挂上即生效，框架无需关心底层是 Fiber / 线程 / 进程。
     *
     * @param Pipe $pipe 构建器
     * @return Pipe 已挂载内置栈的构建器
     */
    public static function stack(Pipe $pipe): Pipe
    {
        return $pipe->beforeRoute(
            new TraceMiddleware(),
            new ScopeMiddleware(),
            new TimeoutMiddleware(),
            new ConcurrentMiddleware(),
        );
    }

    /**
     * 挂载可观测性（异常边界 + 分层剖析）
     *
     * 异常边界把任何未被中间件兜住的异常渲染为响应（渲染器由框架提供）；
     * 分层剖析输出 `Server-Timing`。二者都声明高优先级，恒落在洋葱最外层。
     *
     * @param Pipe $pipe 构建器
     * @param \Closure $renderer 异常渲染器，形如 fn(\Throwable, ServerRequestInterface): ResponseInterface
     * @param \Closure|null $reporter 异常上报器
     * @param \Closure|null $sink 剖析结果回调
     * @return Pipe 已挂载可观测性的构建器
     */
    public static function observe(
        Pipe $pipe,
        \Closure $renderer,
        ?\Closure $reporter = null,
        ?\Closure $sink = null,
    ): Pipe {
        $pipe = $pipe->onError($renderer, $reporter);
        $pipe = $pipe->profile('framework', true, $sink);

        return $pipe;
    }
}
