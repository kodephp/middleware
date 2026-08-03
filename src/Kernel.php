<?php

declare(strict_types=1);

namespace Kode\Middleware;

use Kode\Middleware\Contract\PipelineInterface;
use Kode\Middleware\Contract\TerminableInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 应用内核（洋葱之外的那层壳）
 *
 * Pipeline 负责"洋葱本体"，Kernel 负责"洋葱之外"——也就是框架真正要接管的那部分。
 *
 * ## 为什么洋葱之外还需要一层？
 *
 * PSR-15 只定义了「请求进、响应出」，但一个真实框架的请求生命周期比洋葱更长：
 *
 * ```
 *  ┌─────────────────────── Kernel（本类）─────────────────────────┐
 *  │  boot 启动（进程级，仅一次）                                    │
 *  │  ┌──── onRequest 钩子（请求改写、埋点）                          │
 *  │  │  ┌──────────────── Pipeline 洋葱 ─────────────────┐        │
 *  │  │  │  ErrorBoundary → Trace → Scope → Timeout       │        │
 *  │  │  │    → 路由前 → Route → 路由后 → Dispatch → 控制器 │        │
 *  │  │  └────────────────────────────────────────────────┘        │
 *  │  └──── onResponse 钩子（响应改写、压缩、埋点收口）                 │
 *  │  rescue 兜底（洋葱最外层再炸也不能裸奔 500）                       │
 *  │  ── 响应写回客户端 ──                                          │
 *  │  terminate 收尾（日志落盘、指标上报、连接归还）                     │
 *  └───────────────────────────────────────────────────────────────┘
 * ```
 *
 * 洋葱内部（中间件）解决的是「可组合的横切逻辑」；
 * 洋葱外部（内核）解决的是「进程生命周期」与「最终容错」。二者不可互相替代：
 * 中间件无法兜住"洋葱最外层中间件自己抛异常"的情况，而内核可以。
 *
 * ## 并发与常驻内存
 *
 * - `boot()` 只在**开始服务请求之前**调用，非线程/协程安全，由框架在启动阶段负责；
 * - `handle()` 与 `terminate()` 完全无写入，**可重入、协程安全**，
 *   同一个 Kernel 实例可被 Swoole / Workerman / RoadRunner 的全部 Worker 与协程共享；
 * - 配置类方法一律 `withXxx()` 返回副本，运行期不会被意外改写。
 *
 * @example 传统 FPM
 * ```php
 * $kernel = Kernel::of($pipe)->withExceptionHandler(fn($e) => new Response(500));
 * $response = $kernel->run($request);   // handle + terminate 一体
 * ```
 *
 * @example 常驻内存（Swoole）
 * ```php
 * $kernel = Kernel::of($pipe)->boot();       // 启动期一次
 * $server->on('request', function ($req, $res) use ($kernel) {
 *     $request  = ServerRequest::fromSwoole($req);
 *     $response = $kernel->handle($request);  // 每请求，协程安全
 *     emit($res, $response);
 *     $kernel->terminate($request, $response); // 响应写回后收尾
 * });
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Kernel implements RequestHandlerInterface, TerminableInterface
{
    /** @var list<\Closure> 启动回调，形如 fn(Kernel): void */
    private array $bootstrappers = [];

    /** @var list<\Closure> 请求钩子，形如 fn(ServerRequestInterface): ?ServerRequestInterface */
    private array $requestHooks = [];

    /** @var list<\Closure> 响应钩子，形如 fn(ResponseInterface, ServerRequestInterface): ?ResponseInterface */
    private array $responseHooks = [];

    /** @var list<\Closure> 收尾钩子，形如 fn(ServerRequestInterface, ResponseInterface): void */
    private array $terminateHooks = [];

    /** @var \Closure|null 最终异常兜底，形如 fn(\Throwable, ServerRequestInterface): ResponseInterface */
    private ?\Closure $rescuer = null;

    /**
     * 是否已启动
     *
     * 这是本类唯一的可变状态，且只在启动阶段被写入一次。
     * 请求处理期间只读，因此不影响 handle() 的可重入性。
     */
    private bool $booted = false;

    /**
     * @param PipelineInterface $pipeline 已构建完成的不可变管道
     */
    public function __construct(private readonly PipelineInterface $pipeline)
    {
    }

    /**
     * 从管道或构建器创建内核
     *
     * @param PipelineInterface|Pipe $source 管道实例或 Pipe 构建器
     * @return self 内核实例
     * @throws MiddlewareException 构建器中的中间件声明非法时抛出
     */
    public static function of(PipelineInterface|Pipe $source): self
    {
        return new self($source instanceof Pipe ? $source->build() : $source);
    }

    /**
     * 注册启动回调（返回新实例）
     *
     * 启动回调在首个请求到来之前执行一次，适合放：配置加载、连接池预热、
     * 路由表编译、指标注册。回调抛出的异常会被包装为启动失败，不做吞没。
     *
     * @param \Closure ...$bootstrappers 启动回调，形如 fn(Kernel): void
     * @return self 内核副本
     */
    public function withBootstrapper(\Closure ...$bootstrappers): self
    {
        $new = clone $this;
        foreach ($bootstrappers as $bootstrapper) {
            $new->bootstrappers[] = $bootstrapper;
        }

        return $new;
    }

    /**
     * 注册请求钩子（返回新实例）
     *
     * 在请求进入洋葱之前执行，可返回新的请求对象完成改写；返回 null 表示不改写。
     *
     * 注意：钩子**不允许短路返回响应**。需要短路请写成中间件放在洋葱最外层，
     * 这样才能享受洋葱的响应回程（后续中间件仍有机会加工响应）。
     *
     * @param \Closure ...$hooks 请求钩子，形如 fn(ServerRequestInterface): ?ServerRequestInterface
     * @return self 内核副本
     */
    public function onRequest(\Closure ...$hooks): self
    {
        $new = clone $this;
        foreach ($hooks as $hook) {
            $new->requestHooks[] = $hook;
        }

        return $new;
    }

    /**
     * 注册响应钩子（返回新实例）
     *
     * 在响应离开洋葱之后、写回客户端之前执行，可返回新的响应对象完成改写。
     *
     * @param \Closure ...$hooks 响应钩子，形如 fn(ResponseInterface, ServerRequestInterface): ?ResponseInterface
     * @return self 内核副本
     */
    public function onResponse(\Closure ...$hooks): self
    {
        $new = clone $this;
        foreach ($hooks as $hook) {
            $new->responseHooks[] = $hook;
        }

        return $new;
    }

    /**
     * 注册收尾钩子（返回新实例）
     *
     * 在响应写回客户端之后执行。钩子抛出的异常一律被吞没，
     * 因为此时响应已经发出，任何异常都不应影响已完成的请求。
     *
     * @param \Closure ...$hooks 收尾钩子，形如 fn(ServerRequestInterface, ResponseInterface): void
     * @return self 内核副本
     */
    public function onTerminate(\Closure ...$hooks): self
    {
        $new = clone $this;
        foreach ($hooks as $hook) {
            $new->terminateHooks[] = $hook;
        }

        return $new;
    }

    /**
     * 设置最终异常兜底（返回新实例）
     *
     * 这是整个请求链路的最后一道防线，捕获洋葱内**任何**未被处理的异常，
     * 包括洋葱最外层中间件自身抛出的异常——这正是中间件做不到的部分。
     *
     * 未设置时，异常会原样向上抛出，交由框架 / SAPI 处理（库不越权）。
     *
     * @param \Closure $rescuer 兜底闭包，形如 fn(\Throwable, ServerRequestInterface): ResponseInterface
     * @return self 内核副本
     */
    public function withExceptionHandler(\Closure $rescuer): self
    {
        $new = clone $this;
        $new->rescuer = $rescuer;

        return $new;
    }

    /**
     * 执行启动流程（幂等）
     *
     * 重复调用只会生效一次。应在开始服务请求之前于主线程调用；
     * 若未显式调用，首次 handle() 会自动触发。
     *
     * @return $this 支持链式调用
     * @throws MiddlewareException 启动回调抛出异常时抛出
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        // 先置位再执行，避免启动回调内部递归调用 boot() 造成死循环
        $this->booted = true;

        foreach ($this->bootstrappers as $bootstrapper) {
            try {
                $bootstrapper($this);
            } catch (\Throwable $e) {
                $this->booted = false;

                throw MiddlewareException::bootFailed($e);
            }
        }

        return $this;
    }

    /**
     * 处理一次请求（协程安全、可重入）
     *
     * 完整顺序：boot → onRequest → 洋葱 → onResponse → （异常时）rescue。
     *
     * @param ServerRequestInterface $request 请求对象
     * @return ResponseInterface 响应对象
     * @throws \Throwable 未配置兜底处理器时，原样抛出洋葱内的异常
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->boot();

        try {
            $request = $this->applyRequestHooks($request);
            $response = $this->pipeline->handle($request);

            return $this->applyResponseHooks($response, $request);
        } catch (\Throwable $e) {
            // 兜底产出的响应同样要过一遍响应钩子，保证埋点 / 头部注入不被跳过
            return $this->applyResponseHooks($this->rescue($e, $request), $request);
        }
    }

    /**
     * 处理请求并立即完成收尾（适合传统 FPM 场景）
     *
     * 在 FPM 下响应由 SAPI 统一输出，无法精确插入"写回之后"的时机，
     * 因此此处约定：拿到响应即视为可收尾。常驻内存场景请改用
     * handle() + 输出 + terminate() 的三段写法。
     *
     * @param ServerRequestInterface $request 请求对象
     * @return ResponseInterface 响应对象
     * @throws \Throwable 未配置兜底处理器时，原样抛出洋葱内的异常
     */
    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->handle($request);
        $this->terminate($request, $response);

        return $response;
    }

    /**
     * 收尾：触发管道内可终结中间件与内核收尾钩子
     *
     * 本方法承诺**永不抛出异常**。响应此时已经写回客户端，
     * 任何收尾失败都只能记录而不能影响用户。
     *
     * @param ServerRequestInterface $request 本次请求
     * @param ResponseInterface $response 已发送的响应
     * @return void
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        if ($this->pipeline instanceof TerminableInterface) {
            try {
                $this->pipeline->terminate($request, $response);
            } catch (\Throwable) {
                // 契约：收尾阶段静默
            }
        }

        foreach ($this->terminateHooks as $hook) {
            try {
                $hook($request, $response);
            } catch (\Throwable) {
                // 契约：收尾阶段静默
            }
        }
    }

    /**
     * 获取内核持有的管道
     *
     * @return PipelineInterface 不可变管道
     */
    public function pipeline(): PipelineInterface
    {
        return $this->pipeline;
    }

    /**
     * 替换管道（返回新实例）
     *
     * 热更新场景下可用新管道生成新内核，旧内核继续服务在途请求，
     * 由于两者都不可变，切换过程无需加锁。
     *
     * @param PipelineInterface $pipeline 新管道
     * @return self 内核副本（未启动状态被继承）
     */
    public function withPipeline(PipelineInterface $pipeline): self
    {
        $new = new self($pipeline);
        $new->bootstrappers = $this->bootstrappers;
        $new->requestHooks = $this->requestHooks;
        $new->responseHooks = $this->responseHooks;
        $new->terminateHooks = $this->terminateHooks;
        $new->rescuer = $this->rescuer;
        $new->booted = $this->booted;

        return $new;
    }

    /**
     * 是否已完成启动
     *
     * @return bool 已启动返回 true
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * 依次执行请求钩子
     *
     * @param ServerRequestInterface $request 原始请求
     * @return ServerRequestInterface 改写后的请求
     * @throws MiddlewareException 钩子返回值类型非法时抛出
     */
    private function applyRequestHooks(ServerRequestInterface $request): ServerRequestInterface
    {
        foreach ($this->requestHooks as $hook) {
            $result = $hook($request);

            if ($result === null) {
                continue;
            }

            if (!$result instanceof ServerRequestInterface) {
                throw MiddlewareException::invalidHookReturn(
                    'onRequest',
                    'Psr\\Http\\Message\\ServerRequestInterface',
                    $result
                );
            }

            $request = $result;
        }

        return $request;
    }

    /**
     * 依次执行响应钩子
     *
     * @param ResponseInterface $response 原始响应
     * @param ServerRequestInterface $request 本次请求
     * @return ResponseInterface 改写后的响应
     * @throws MiddlewareException 钩子返回值类型非法时抛出
     */
    private function applyResponseHooks(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        foreach ($this->responseHooks as $hook) {
            $result = $hook($response, $request);

            if ($result === null) {
                continue;
            }

            if (!$result instanceof ResponseInterface) {
                throw MiddlewareException::invalidHookReturn(
                    'onResponse',
                    'Psr\\Http\\Message\\ResponseInterface',
                    $result
                );
            }

            $response = $result;
        }

        return $response;
    }

    /**
     * 最终兜底
     *
     * @param \Throwable $e 洋葱内抛出的异常
     * @param ServerRequestInterface $request 本次请求
     * @return ResponseInterface 兜底响应
     * @throws \Throwable 未配置兜底处理器时原样抛出；兜底逻辑自身失败时抛出 5002
     */
    private function rescue(\Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        if ($this->rescuer === null) {
            throw $e;
        }

        try {
            $response = ($this->rescuer)($e, $request);
        } catch (\Throwable $thrown) {
            throw MiddlewareException::rescueFailed($e, $thrown);
        }

        if (!$response instanceof ResponseInterface) {
            throw MiddlewareException::invalidHookReturn(
                'exceptionHandler',
                'Psr\\Http\\Message\\ResponseInterface',
                $response
            );
        }

        return $response;
    }
}
