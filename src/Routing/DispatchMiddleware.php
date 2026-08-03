<?php

declare(strict_types=1);

namespace Kode\Middleware\Routing;

use Kode\Middleware\Contract\PrioritizedInterface;
use Kode\Middleware\Contract\ResolverInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Handler\CallableHandler;
use Kode\Middleware\Pipeline;
use Kode\Middleware\Resolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 路由调度中间件（管道最内层）
 *
 * 读取 RouteMiddleware 写入的 RouteResult，把**路由级中间件**动态拼成一条
 * 子管道，以路由处理器作为终点执行。子管道由本包的 Pipeline 承载，
 * 因此同样具备不可变、可重入、协程安全的特性。
 *
 * 由于路由级中间件是"运行期才知道有哪些"的，这里每次请求都会构建一条
 * 轻量子管道——只是几个数组拷贝，不涉及中间件实例化（惰性解析保证了这一点）。
 * 命中同一路由的请求还会复用解析器缓存里的中间件实例。
 *
 * 未命中路由时的行为：
 * - 配置了 $fallback：交给兜底处理器；
 * - 未配置：透传给下游 $handler，交由外层的兜底中间件或管道终点处理。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class DispatchMiddleware implements MiddlewareInterface, PrioritizedInterface
{
    /** @var ResolverInterface 子管道使用的中间件解析器 */
    private ResolverInterface $resolver;

    /**
     * @param \Closure|null $invoker 处理器调用器，形如 fn(mixed $handler, ServerRequestInterface $request): ResponseInterface
     * @param ResolverInterface|null $resolver 中间件解析器，省略时新建默认解析器
     * @param RequestHandlerInterface|null $fallback 未命中路由时的兜底处理器
     * @param int $priority 管道优先级，默认 PHP_INT_MIN 以确保始终处于最内层
     */
    public function __construct(
        private readonly ?\Closure $invoker = null,
        ?ResolverInterface $resolver = null,
        private readonly ?RequestHandlerInterface $fallback = null,
        private readonly int $priority = PHP_INT_MIN,
    ) {
        $this->resolver = $resolver ?? new Resolver();
    }

    /**
     * 调度路由级中间件与路由处理器
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 路由处理器无法被调用时抛出
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteResult::from($request);

        // 尚未路由，或未命中：交给兜底 / 下游
        if ($route === null || !$route->isMatched()) {
            return ($this->fallback ?? $handler)->handle($request);
        }

        $destination = new CallableHandler(
            fn (ServerRequestInterface $req): ResponseInterface => $this->invoke($route->handler(), $req)
        );

        // 没有路由级中间件时省去子管道开销，直接调用处理器
        if ($route->middleware() === []) {
            return $destination->handle($request);
        }

        return Pipeline::of($route->middleware(), $this->resolver)
            ->run($request, $destination);
    }

    /**
     * 管道优先级
     *
     * @return int 优先级数值，默认最低以保证位于最内层
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * 调用路由处理器
     *
     * 默认支持三种处理器形态：
     * - PSR-15 RequestHandlerInterface 实例
     * - 可调用对象 fn(ServerRequestInterface): ResponseInterface
     * - 其它形态需通过构造函数注入自定义 $invoker（例如"控制器@方法"字符串）
     *
     * @param mixed $routeHandler 路由处理器
     * @param ServerRequestInterface $request 请求对象
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 处理器形态不被支持或返回值非法时抛出
     */
    private function invoke(mixed $routeHandler, ServerRequestInterface $request): ResponseInterface
    {
        if ($this->invoker !== null) {
            $response = ($this->invoker)($routeHandler, $request);
        } elseif ($routeHandler instanceof RequestHandlerInterface) {
            $response = $routeHandler->handle($request);
        } elseif (is_callable($routeHandler)) {
            $response = $routeHandler($request);
        } else {
            throw new MiddlewareException(
                '路由处理器形态不被默认调度器支持（类型 ' . get_debug_type($routeHandler) . '）；'
                . '请注入自定义 invoker 闭包，或让处理器实现 RequestHandlerInterface',
                2003,
                null,
                ['type' => get_debug_type($routeHandler)]
            );
        }

        if (!$response instanceof ResponseInterface) {
            throw MiddlewareException::invalidResponse(self::class, $response);
        }

        return $response;
    }
}
