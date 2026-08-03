<?php

declare(strict_types=1);

namespace Kode\Middleware;

use Kode\Middleware\Adapter\CallableMiddleware;
use Kode\Middleware\Adapter\ConditionalMiddleware;
use Kode\Middleware\Adapter\LazyMiddleware;
use Kode\Middleware\Concurrency\ConcurrentMiddleware;
use Kode\Middleware\Concurrency\Runner\RunnerFactory;
use Kode\Middleware\Contract\RunnerInterface;
use Kode\Middleware\Handler\CallableHandler;
use Kode\Middleware\Middleware\ErrorBoundaryMiddleware;
use Kode\Middleware\Observability\Profile;
use Kode\Middleware\Observability\ProfilerMiddleware;
use Kode\Middleware\Routing\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 命名空间级快捷函数
 *
 * 提供最短的书写路径，适合在路由文件、配置文件这类"表达优先"的场景使用。
 * 全部函数都做了重复定义保护，可安全地被多次加载。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */

if (!function_exists(__NAMESPACE__ . '\\pipeline')) {
    /**
     * 快速创建一条管道
     *
     * @param array<int, mixed> $middleware 中间件声明列表
     * @param ContainerInterface|null $container PSR-11 容器
     * @return Pipeline 不可变管道
     */
    function pipeline(array $middleware = [], ?ContainerInterface $container = null): Pipeline
    {
        return Pipeline::of($middleware, new Resolver($container));
    }
}

if (!function_exists(__NAMESPACE__ . '\\pipe')) {
    /**
     * 快速创建一个管道构建器
     *
     * @param ContainerInterface|null $container PSR-11 容器
     * @return Pipe 构建器
     */
    function pipe(?ContainerInterface $container = null): Pipe
    {
        return Pipe::create($container);
    }
}

if (!function_exists(__NAMESPACE__ . '\\kernel')) {
    /**
     * 快速创建应用内核
     *
     * @param Contract\PipelineInterface|Pipe $source 管道或构建器
     * @return Kernel 内核实例
     */
    function kernel(Contract\PipelineInterface|Pipe $source): Kernel
    {
        return Kernel::of($source);
    }
}

if (!function_exists(__NAMESPACE__ . '\\boundary')) {
    /**
     * 快速创建洋葱最外层的异常边界中间件
     *
     * @param \Closure $renderer 渲染闭包，形如 fn(\Throwable, $request): ResponseInterface
     * @param \Closure|null $reporter 上报闭包，形如 fn(\Throwable, $request): void
     * @return MiddlewareInterface 异常边界中间件
     */
    function boundary(\Closure $renderer, ?\Closure $reporter = null): MiddlewareInterface
    {
        return new ErrorBoundaryMiddleware($renderer, $reporter);
    }
}

if (!function_exists(__NAMESPACE__ . '\\profiler')) {
    /**
     * 快速创建洋葱分层耗时剖析中间件
     *
     * @param string $label 本层名称
     * @param bool $header 是否输出 Server-Timing 响应头
     * @return MiddlewareInterface 剖析中间件
     */
    function profiler(string $label = 'pipeline', bool $header = true): MiddlewareInterface
    {
        return new ProfilerMiddleware($label, $header);
    }
}

if (!function_exists(__NAMESPACE__ . '\\profile_of')) {
    /**
     * 读取请求上的耗时剖析结果
     *
     * @param ServerRequestInterface $request 请求对象
     * @return Profile|null 剖析收集器，未启用时为 null
     */
    function profile_of(ServerRequestInterface $request): ?Profile
    {
        return ProfilerMiddleware::of($request);
    }
}

if (!function_exists(__NAMESPACE__ . '\\middleware')) {
    /**
     * 把可调用对象包装成 PSR-15 中间件
     *
     * @param callable $callable 形如 fn($request, $handler): ResponseInterface
     * @return MiddlewareInterface 中间件实例
     */
    function middleware(callable $callable): MiddlewareInterface
    {
        return new CallableMiddleware($callable);
    }
}

if (!function_exists(__NAMESPACE__ . '\\handler')) {
    /**
     * 把可调用对象包装成 PSR-15 请求处理器
     *
     * @param callable $callable 形如 fn($request): ResponseInterface
     * @return RequestHandlerInterface 处理器实例
     */
    function handler(callable $callable): RequestHandlerInterface
    {
        return new CallableHandler($callable);
    }
}

if (!function_exists(__NAMESPACE__ . '\\lazy')) {
    /**
     * 创建惰性中间件
     *
     * @param \Closure $factory 工厂闭包，返回 MiddlewareInterface
     * @param string $label 错误提示用标识
     * @return MiddlewareInterface 惰性中间件
     */
    function lazy(\Closure $factory, string $label = 'closure'): MiddlewareInterface
    {
        return new LazyMiddleware($factory, $label);
    }
}

if (!function_exists(__NAMESPACE__ . '\\when')) {
    /**
     * 创建条件中间件
     *
     * @param \Closure $predicate 谓词，形如 fn($request): bool
     * @param MiddlewareInterface $middleware 条件满足时执行的中间件
     * @return MiddlewareInterface 条件中间件
     */
    function when(\Closure $predicate, MiddlewareInterface $middleware): MiddlewareInterface
    {
        return ConditionalMiddleware::when($predicate, $middleware);
    }
}

if (!function_exists(__NAMESPACE__ . '\\route_of')) {
    /**
     * 读取请求上的路由结果
     *
     * @param ServerRequestInterface $request 请求对象
     * @return RouteResult|null 路由结果，尚未路由时为 null
     */
    function route_of(ServerRequestInterface $request): ?RouteResult
    {
        return RouteResult::from($request);
    }
}

if (!function_exists(__NAMESPACE__ . '\\runner_of')) {
    /**
     * 读取请求上的并发运行器
     *
     * @param ServerRequestInterface $request 请求对象
     * @return RunnerInterface 并发运行器，未启用时返回同步运行器
     */
    function runner_of(ServerRequestInterface $request): RunnerInterface
    {
        return ConcurrentMiddleware::runnerOf($request);
    }
}

if (!function_exists(__NAMESPACE__ . '\\concurrently')) {
    /**
     * 在当前请求的运行器上并发执行一组任务
     *
     * @param ServerRequestInterface $request 请求对象
     * @param array<array-key, \Closure> $tasks 任务集合
     * @param float|null $timeout 整体超时秒数
     * @return array<array-key, mixed> 结果集合，键与入参一致
     */
    function concurrently(ServerRequestInterface $request, array $tasks, ?float $timeout = null): array
    {
        return ConcurrentMiddleware::runnerOf($request)->all($tasks, $timeout);
    }
}

if (!function_exists(__NAMESPACE__ . '\\capabilities')) {
    /**
     * 探测当前环境支持的并发能力
     *
     * @return array<string, bool> 驱动名 => 是否可用
     */
    function capabilities(): array
    {
        return RunnerFactory::capabilities();
    }
}
