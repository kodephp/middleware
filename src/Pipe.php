<?php

declare(strict_types=1);

namespace Kode\Middleware;

use Kode\Middleware\Contract\ResolverInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Handler\CallableHandler;
use Kode\Middleware\Middleware\ErrorBoundaryMiddleware;
use Kode\Middleware\Observability\ProfilerMiddleware;
use Kode\Middleware\Routing\DispatchMiddleware;
use Kode\Middleware\Routing\RouteMiddleware;
use Kode\Middleware\Routing\RouteResult;
use Kode\Middleware\Routing\Router;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 管道构建器（门面）
 *
 * Pipeline 是"能跑"的最小内核，Pipe 是"好写"的表达层。
 * 它把一次 HTTP 请求的生命周期显式拆成三段，让中间件的位置一目了然：
 *
 * ```
 *  ┌──── beforeRoute ────┐ ┌── router ──┐ ┌──── afterRoute ────┐ ┌── dispatch ──┐
 *    异常捕获 / 链路追踪        路由匹配        鉴权 / 权限 / 审计       路由级中间件
 *    CORS / 限流 / 上下文                     （可读路由元数据）        + 控制器
 * ```
 *
 * 与直接使用 Pipeline 相比，Pipe 额外负责：
 * - 自动把 RouteMiddleware 与 DispatchMiddleware 放到正确位置；
 * - 保证 afterRoute 的中间件一定晚于路由匹配、早于控制器；
 * - 统一注入容器 / 注册表，省去手工构造 Resolver。
 *
 * 构建器本身可变（链式书写更顺手），build() 产出的 Pipeline 不可变。
 *
 * @example
 * ```php
 * $response = Pipe::create($container)
 *     ->alias('auth', AuthMiddleware::class)
 *     ->beforeRoute(new TraceMiddleware(), new ScopeMiddleware(), 'cors')
 *     ->router(fn($req) => $router->match($req))
 *     ->afterRoute('auth')
 *     ->fallback(fn($req) => new Response(404))
 *     ->handle($request);
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Pipe
{
    /** @var list<mixed> 路由前中间件声明 */
    private array $before = [];

    /** @var list<mixed> 路由后中间件声明 */
    private array $after = [];

    /** @var RouteMiddleware|null 路由匹配中间件 */
    private ?RouteMiddleware $router = null;

    /** @var \Closure|null 路由处理器调用器 */
    private ?\Closure $invoker = null;

    /** @var RequestHandlerInterface|null 兜底处理器 */
    private ?RequestHandlerInterface $fallback = null;

    /** @var Registry 中间件注册表 */
    private Registry $registry;

    /** @var ResolverInterface|null 中间件解析器 */
    private ?ResolverInterface $resolver = null;

    /** @var Pipeline|null 构建结果缓存 */
    private ?Pipeline $built = null;

    /** @var Router|null 惰性创建的内部路由收集器（route()/group() 触发） */
    private ?Router $lazyRouter = null;

    /** @var list<array{prefix: string, middleware: list<mixed>}> 路由分组上下文栈 */
    private array $routeGroupStack = [];

    /**
     * @param ContainerInterface|null $container PSR-11 容器
     * @param Registry|null $registry 中间件注册表
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        ?Registry $registry = null,
    ) {
        $this->registry = $registry ?? new Registry();
    }

    /**
     * 创建构建器
     *
     * @param ContainerInterface|null $container PSR-11 容器（如 kode/di 的 Container）
     * @param Registry|null $registry 中间件注册表
     * @return self 构建器实例
     */
    public static function create(?ContainerInterface $container = null, ?Registry $registry = null): self
    {
        return new self($container, $registry);
    }

    /**
     * 注册中间件别名
     *
     * @param string $name 别名
     * @param mixed $middleware 中间件声明
     * @return $this 支持链式调用
     */
    public function alias(string $name, mixed $middleware): self
    {
        $this->registry->alias($name, $middleware);
        $this->invalidate();

        return $this;
    }

    /**
     * 注册中间件分组
     *
     * @param string $name 分组名
     * @param array<int, mixed> $middleware 中间件声明列表
     * @return $this 支持链式调用
     */
    public function group(string $name, array $middleware): self
    {
        $this->registry->group($name, $middleware);
        $this->invalidate();

        return $this;
    }

    /**
     * 注册带参数的中间件工厂
     *
     * @param string $name 工厂名
     * @param \Closure $factory 工厂闭包
     * @return $this 支持链式调用
     */
    public function factory(string $name, \Closure $factory): self
    {
        $this->registry->factory($name, $factory);
        $this->invalidate();

        return $this;
    }

    /**
     * 内联一条嵌套子管道（作为当前位置的一层洋葱）
     *
     * 闭包里拿到的是一个独立子构建器，其 before/after 会组成一条**嵌套 Pipeline**，
     * 以单个中间件节点的身份插入主管道。子管道共享主构建器的容器与注册表，
     * 因此其中也能引用别名 / 分组。这是"嵌套 + 分组"组合最直观的写法。
     *
     * 嵌套层对主洋葱而言是透明的：它拥有自己的优先级排序，内部请求自外向内、
     * 响应自内向外，其最内层终点接到主管道的下游处理器。
     *
     * @param \Closure $configure 形如 fn(Pipe $sub): void
     * @return $this 支持链式调用
     */
    public function nest(\Closure $configure): self
    {
        $sub = new self($this->container, $this->registry);
        $configure($sub);

        // 把编译后的子管道作为一个中间件节点插入；Pipeline 实现 MiddlewareInterface，解析器原样放行
        $this->before[] = $sub->build();
        $this->invalidate();

        return $this;
    }

    /**
     * 把已注册的命名分组作为一层嵌套洋葱插入
     *
     * 与直接在 beforeRoute() 里写分组名等效，但语义更明确：此处强调"这是一个
     * 可复用、可嵌套的层"。分组名在运行期由解析器展开为嵌套子管道。
     *
     * @param string $name 已注册的分组名
     * @return $this 支持链式调用
     * @throws MiddlewareException 名称未注册为分组时抛出
     */
    public function useGroup(string $name): self
    {
        if ($this->registry->kind($name) !== 'group') {
            throw MiddlewareException::aliasNotFound($name);
        }

        $this->before[] = $name;
        $this->invalidate();

        return $this;
    }

    /**
     * 追加路由前中间件（全局中间件）
     *
     * 此处的中间件对所有请求生效，包括 404 请求。
     * 适合放：异常捕获、链路追踪、上下文隔离、CORS、全局限流。
     *
     * @param mixed ...$middleware 中间件声明
     * @return $this 支持链式调用
     */
    public function beforeRoute(mixed ...$middleware): self
    {
        foreach ($middleware as $item) {
            $this->before[] = $item;
        }
        $this->invalidate();

        return $this;
    }

    /**
     * 追加路由后中间件
     *
     * 此处的中间件在路由匹配之后、控制器之前执行，
     * 因此可以通过 RouteResult::from($request) 读取路由名、路径参数等元数据。
     * 适合放：鉴权、权限校验、按路由维度的审计与灰度。
     *
     * @param mixed ...$middleware 中间件声明
     * @return $this 支持链式调用
     */
    public function afterRoute(mixed ...$middleware): self
    {
        foreach ($middleware as $item) {
            $this->after[] = $item;
        }
        $this->invalidate();

        return $this;
    }

    /**
     * 设置路由匹配器
     *
     * 可接受三种形态，框架按需挑选最顺手的：
     *
     * - `\Closure`：形如 `fn(ServerRequestInterface): RouteResult` 的自定义匹配器；
     * - `RouteMiddleware`：已构造好的路由中间件实例；
     * - `Router`：本包提供的零依赖路由收集器，直接喂入即可。
     *
     * @param \Closure|RouteMiddleware|Router $matcher 路由匹配器
     * @return $this 支持链式调用
     */
    public function router(\Closure|RouteMiddleware|Router $matcher): self
    {
        if ($matcher instanceof Router) {
            $matcher = $matcher->matcher();
        }

        $this->router = $matcher instanceof RouteMiddleware
            ? $matcher
            : new RouteMiddleware($matcher);
        $this->invalidate();

        return $this;
    }

    /**
     * 一站式登记路由（惰性创建内部 Router）
     *
     * 框架无需先 new Router() 再 bind，直接在此逐条登记即可；
     * 内部自动惰性创建 {@see Router} 并持续绑定到路由槽位。
     * 路由级中间件可为命名分组名（经解析器在运行期展开为嵌套子管道）。
     *
     * @param string $pattern 路径模式，例如 `/users/{id}`
     * @param mixed $handler 路由处理器（控制器、闭包、类名等）
     * @param array<int, mixed> $middleware 路由级中间件声明
     * @param string|null $name 路由名称
     * @param array<int, string> $methods 允许访问的 HTTP 方法，空数组表示不限制
     * @return $this 支持链式调用
     */
    public function route(
        string $pattern,
        mixed $handler,
        array $middleware = [],
        ?string $name = null,
        array $methods = []
    ): self {
        if ($this->lazyRouter === null) {
            $this->lazyRouter = new Router();
        }

        [$pattern, $middleware] = $this->applyRouteGroups($pattern, $middleware);
        $this->lazyRouter->add($pattern, $handler, $middleware, $name, $methods);
        $this->router($this->lazyRouter);

        return $this;
    }

    /**
     * 一站式登记路由分组（惰性创建内部 Router）
     *
     * 闭包内调用 {@see route()} 登记的路由会自动带上累积前缀与分组共享中间件；
     * 支持多层嵌套，闭包收到的是当前 Pipe 自身，书写与扁平登记完全一致。
     *
     * 注意与 {@see group()}（注册命名中间件分组）区分：本方法用于"路由前缀分组"。
     *
     * @param string $prefix 路径前缀，例如 `/api`
     * @param array<int, mixed> $middleware 该分组共享的中间件声明
     * @param \Closure $configure 形如 fn(Pipe): void，在其中调用 route()
     * @return $this 支持链式调用
     */
    public function routeGroup(string $prefix, array $middleware, \Closure $configure): self
    {
        $this->routeGroupStack[] = [
            'prefix' => $prefix,
            'middleware' => array_values($middleware),
        ];

        try {
            $configure($this);
        } finally {
            array_pop($this->routeGroupStack);
        }

        return $this;
    }

    /**
     * 把当前路由分组栈累积成最终路径与中间件
     *
     * @param string $pattern 原始路径模式
     * @param array<int, mixed> $middleware 路由级中间件
     * @return array{0: string, 1: list<mixed>} [最终路径, 最终中间件列表]
     */
    private function applyRouteGroups(string $pattern, array $middleware): array
    {
        if ($this->routeGroupStack === []) {
            return [$pattern, $middleware];
        }

        $prefix = '';
        $shared = [];

        foreach ($this->routeGroupStack as $ctx) {
            $prefix .= '/' . trim($ctx['prefix'], '/');
            /** @var list<mixed> $shared */
            $shared = array_merge($shared, $ctx['middleware']);
        }

        $prefix = rtrim($prefix, '/');
        $pattern = $prefix . ($pattern === '/' ? '' : '/' . ltrim($pattern, '/'));

        return [$pattern, array_merge($shared, $middleware)];
    }

    /**
     * 设置路由处理器的调用方式
     *
     * 默认支持 PSR-15 处理器与可调用对象；若路由处理器是
     * `'App\Controller\UserController@show'` 这类字符串，请在此注入自定义调用逻辑。
     *
     * @param \Closure $invoker 形如 fn(mixed $handler, ServerRequestInterface $request): ResponseInterface
     * @return $this 支持链式调用
     */
    public function invoker(\Closure $invoker): self
    {
        $this->invoker = $invoker;
        $this->invalidate();

        return $this;
    }

    /**
     * 设置兜底处理器（未命中路由时使用）
     *
     * @param RequestHandlerInterface|callable $handler 兜底处理器
     * @return $this 支持链式调用
     */
    public function fallback(RequestHandlerInterface|callable $handler): self
    {
        $this->fallback = $handler instanceof RequestHandlerInterface
            ? $handler
            : new CallableHandler($handler);
        $this->invalidate();

        return $this;
    }

    /**
     * 指定自定义解析器（会覆盖容器与注册表的默认组合）
     *
     * @param ResolverInterface $resolver 中间件解析器
     * @return $this 支持链式调用
     */
    public function resolver(ResolverInterface $resolver): self
    {
        $this->resolver = $resolver;
        $this->invalidate();

        return $this;
    }

    /**
     * 构建不可变管道
     *
     * 构建结果会被缓存，重复调用返回同一实例；
     * 任何链式配置动作都会让缓存失效。
     *
     * @return Pipeline 可直接用于处理请求的不可变管道
     * @throws MiddlewareException 中间件声明非法时抛出
     */
    public function build(): Pipeline
    {
        if ($this->built !== null) {
            return $this->built;
        }

        $resolver = $this->resolver ?? new Resolver($this->container, $this->registry);

        $stack = $this->before;

        if ($this->router !== null) {
            $stack[] = $this->router;
        }

        foreach ($this->after as $item) {
            $stack[] = $item;
        }

        // 只要配置了路由或兜底，就补一层调度中间件收口
        if ($this->router !== null || $this->fallback !== null) {
            $stack[] = new DispatchMiddleware($this->invoker, $resolver, $this->fallback);
        }

        $pipeline = Pipeline::of($stack, $resolver);

        if ($this->fallback !== null) {
            $pipeline = $pipeline->withDestination($this->fallback);
        }

        return $this->built = $pipeline;
    }

    /**
     * 直接处理一次请求（内部先 build 再执行）
     *
     * @param ServerRequestInterface $request 请求对象
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 管道执行失败时抛出
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->build()->handle($request);
    }

    /**
     * 构建应用内核
     *
     * 内核在洋葱之外再包一层生命周期：启动、请求 / 响应钩子、最终兜底、收尾。
     * 框架集成的推荐入口。
     *
     * @return Kernel 内核实例
     * @throws MiddlewareException 中间件声明非法时抛出
     */
    public function kernel(): Kernel
    {
        return Kernel::of($this->build());
    }

    /**
     * 在洋葱最外层挂一道异常边界
     *
     * 语法糖，等价于把 ErrorBoundaryMiddleware 放进 beforeRoute()；
     * 由于该中间件声明了最高优先级，无论调用顺序如何都会落在最外层。
     *
     * @param \Closure $renderer 渲染闭包，形如 fn(\Throwable, $request): ResponseInterface
     * @param \Closure|null $reporter 上报闭包，形如 fn(\Throwable, $request): void
     * @param list<class-string<\Throwable>> $passthrough 直接放行的异常类型
     * @return $this 支持链式调用
     */
    public function onError(\Closure $renderer, ?\Closure $reporter = null, array $passthrough = []): self
    {
        return $this->beforeRoute(new ErrorBoundaryMiddleware($renderer, $reporter, $passthrough));
    }

    /**
     * 开启洋葱分层耗时剖析
     *
     * @param string $label 最外层名称
     * @param bool $header 是否输出 Server-Timing 响应头
     * @param \Closure|null $sink 结果回调，形如 fn(Profile, $request): void
     * @return $this 支持链式调用
     */
    public function profile(string $label = 'pipeline', bool $header = true, ?\Closure $sink = null): self
    {
        return $this->beforeRoute(new ProfilerMiddleware($label, $header, $sink));
    }

    /**
     * 导出管道蓝图，用于跨进程 / 跨节点下发
     *
     * 只有字符串形式的中间件声明才可被序列化；实例与闭包会被跳过。
     *
     * @return Blueprint 管道蓝图
     */
    public function blueprint(): Blueprint
    {
        return Blueprint::fromPipe($this->before, $this->after, $this->registry);
    }

    /**
     * 获取内部注册表
     *
     * @return Registry 中间件注册表
     */
    public function registry(): Registry
    {
        return $this->registry;
    }

    /**
     * 便捷读取请求上的路由结果
     *
     * @param ServerRequestInterface $request 请求对象
     * @return RouteResult|null 路由结果
     */
    public static function routeOf(ServerRequestInterface $request): ?RouteResult
    {
        return RouteResult::from($request);
    }

    /**
     * 让构建缓存失效
     *
     * @return void
     */
    private function invalidate(): void
    {
        $this->built = null;
    }
}
