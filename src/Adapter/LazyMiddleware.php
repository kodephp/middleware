<?php

declare(strict_types=1);

namespace Kode\Middleware\Adapter;

use Kode\Middleware\Contract\TerminableInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 惰性中间件
 *
 * 把"如何得到中间件实例"的工厂闭包包装成中间件本身，
 * 直到请求真正流经这一层时才创建实例。
 *
 * 价值场景：
 * - **短路节省**：位于认证中间件之后的重量级中间件，在未登录请求被短路时根本不会被创建；
 * - **常驻内存**：Swoole / Workerman 下管道只构建一次，惰性层避免了启动期实例化全部依赖；
 * - **跨进程蓝图**：Blueprint 还原出的类名声明统一走惰性通道，无需在主进程预先实例化。
 *
 * 实例默认会被缓存复用（$once = true）。若中间件持有请求级可变状态，
 * 必须传入 $once = false 强制每次新建，否则在协程环境下会串数据。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class LazyMiddleware implements MiddlewareInterface, TerminableInterface
{
    /** @var MiddlewareInterface|null 已创建的中间件实例缓存 */
    private ?MiddlewareInterface $resolved = null;

    /**
     * @param \Closure $factory 工厂闭包，返回 MiddlewareInterface 实例
     * @param string $label 用于错误提示的标识（通常是类名或别名）
     * @param bool $once 是否缓存实例复用；持有请求级状态的中间件请设为 false
     */
    public function __construct(
        private readonly \Closure $factory,
        private readonly string $label = 'closure',
        private readonly bool $once = true,
    ) {
    }

    /**
     * 处理请求：首次流经时创建真实中间件并委派
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 工厂未返回合法 PSR-15 中间件时抛出
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->instance()->process($request, $handler);
    }

    /**
     * 取得（必要时创建）真实中间件实例
     *
     * @return MiddlewareInterface 中间件实例
     * @throws MiddlewareException 工厂未返回合法 PSR-15 中间件时抛出
     */
    public function instance(): MiddlewareInterface
    {
        if ($this->once && $this->resolved !== null) {
            return $this->resolved;
        }

        $instance = ($this->factory)();

        if (!$instance instanceof MiddlewareInterface) {
            throw MiddlewareException::notMiddleware(
                is_object($instance) ? $instance::class : $this->label
            );
        }

        if ($this->once) {
            $this->resolved = $instance;
        }

        return $instance;
    }

    /**
     * 转发收尾回调给真实中间件
     *
     * 关键约定：**绝不为了收尾而实例化**。若本层从未被执行过（例如被上游短路），
     * $resolved 仍为 null，此时静默跳过——否则惰性带来的性能收益会在收尾阶段被抵消，
     * 更糟的是会为一个从未参与本次请求的中间件触发收尾逻辑。
     *
     * @param ServerRequestInterface $request 本次请求
     * @param ResponseInterface $response 已发送的响应
     * @return void
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        if ($this->resolved instanceof TerminableInterface) {
            $this->resolved->terminate($request, $response);
        }
    }

    /**
     * 是否已经实例化过
     *
     * @return bool 已实例化返回 true
     */
    public function isResolved(): bool
    {
        return $this->resolved !== null;
    }

    /**
     * 获取标识名
     *
     * @return string 类名或别名
     */
    public function label(): string
    {
        return $this->label;
    }
}
