<?php

declare(strict_types=1);

namespace Kode\Middleware\Routing;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 轻量级路由收集器
 *
 * 把"路径模式 → 处理器 + 路由级中间件"的关系集中登记，并在匹配时产出
 * {@see RouteResult}，从而无缝接入 {@see \Kode\Middleware\Pipe::router()}。
 *
 * 设计要点：
 *
 * - **零依赖**：只用到 PSR-7 的 `getUri()` / `getMethod()`，不绑定任何具体
 *   HTTP 实现，也不引入额外的路由库。
 * - **占位符**：`{id}` 等价于 `[^/]+`，`{id:\d+}` 可写正则；匹配到的命名
 *   捕获组会自动成为路径参数，供后续中间件 / 控制器读取。
 * - **方法约束**：`add()` 的 `$methods` 为空表示不限制方法；非空时只放行列表内方法，
 *   其余方法返回 405（methodNotAllowed）。
 * - **路由级中间件可为命名分组**：`$middleware` 里的字符串若命中
 *   {@see \Kode\Middleware\Registry} 中的分组 / 别名，会在
 *   {@see DispatchMiddleware} 处被展开成嵌套子管道——"分组 + 路由 + 嵌套"
 *   在此自然咬合。
 *
 * @example
 * ```php
 * $router = (new Router())
 *     ->add('/', fn($req) => new Response(200, [], 'home'))
 *     ->add('/users/{id}', UserController::class . '@show', ['api.guard'], 'user.show', ['GET'])
 *     ->add('/admin/{any:.+}', AdminController::class, ['admin.guard']);
 *
 * $pipe = Pipe::create()
 *     ->group('api.guard', ['cors', 'auth'])
 *     ->router($router->matcher())
 *     ->fallback(fn() => new Response(404));
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Router
{
    /**
     * @var list<array{regex: string, handler: mixed, middleware: list<mixed>, name: ?string, methods: list<string>, pattern: string}> 已登记的路由
     */
    private array $routes = [];

    /**
     * @var list<array{prefix: string, middleware: list<mixed>}> 嵌套分组上下文栈
     *
     * 调用 {@see group()} 时压入，闭包执行完弹出；`add()` 据此累积前缀与共享中间件。
     */
    private array $groupStack = [];

    /**
     * 登记一条路由
     *
     * @param string $pattern 路径模式，例如 `/users/{id}` 或 `/files/{path:.+}`
     * @param mixed $handler 路由处理器（控制器、闭包、类名等），由 DispatchMiddleware 调用
     * @param array<int, mixed> $middleware 路由级中间件声明，可为实例 / 类名 / 别名 / 分组名
     * @param string|null $name 路由名称，便于反查与观测
     * @param array<int, string> $methods 允许访问的 HTTP 方法，空数组表示不限制
     * @return $this 支持链式调用
     */
    public function add(
        string $pattern,
        mixed $handler,
        array $middleware = [],
        ?string $name = null,
        array $methods = []
    ): self {
        [$pattern, $middleware] = $this->applyGroups($pattern, $middleware);

        $this->routes[] = [
            'pattern' => $pattern,
            'regex' => $this->compile($pattern),
            'handler' => $handler,
            'middleware' => array_values($middleware),
            'name' => $name,
            'methods' => array_values(array_map(strtoupper(...), $methods)),
        ];

        return $this;
    }

    /**
     * 登记一组共享前缀与中间件的嵌套路由
     *
     * 闭包内调用 {@see add()} 登记的路由会自动带上**累积前缀**与**分组共享中间件**；
     * 支持多层嵌套——外层前缀与中间件沿栈向内层传递，顺序为：外层 → 内层 → 路由自身。
     * 闭包执行结束后分组上下文自动出栈（即使闭包内抛异常也出栈），不影响后续登记。
     *
     * @param string $prefix 路径前缀，例如 `/api`
     * @param array<int, mixed> $middleware 该分组共享的中间件声明（可为实例 / 类名 / 别名 / 分组名）
     * @param \Closure $configure 形如 fn(Router): void，在其中登记路由
     * @return $this 支持链式调用
     */
    public function group(string $prefix, array $middleware, \Closure $configure): self
    {
        $this->groupStack[] = [
            'prefix' => $prefix,
            'middleware' => array_values($middleware),
        ];

        try {
            $configure($this);
        } finally {
            array_pop($this->groupStack);
        }

        return $this;
    }

    /**
     * 把当前分组栈累积成最终路径与中间件
     *
     * @param string $pattern 原始路径模式
     * @param array<int, mixed> $middleware 路由级中间件
     * @return array{0: string, 1: list<mixed>} [最终路径, 最终中间件列表]
     */
    private function applyGroups(string $pattern, array $middleware): array
    {
        if ($this->groupStack === []) {
            return [$pattern, $middleware];
        }

        $prefix = '';
        $shared = [];

        foreach ($this->groupStack as $ctx) {
            $prefix .= '/' . trim($ctx['prefix'], '/');
            /** @var list<mixed> $shared */
            $shared = array_merge($shared, $ctx['middleware']);
        }

        $prefix = rtrim($prefix, '/');
        $pattern = $prefix . ($pattern === '/' ? '' : '/' . ltrim($pattern, '/'));

        return [$pattern, array_merge($shared, $middleware)];
    }

    /**
     * 产出可直接喂给 Pipe::router() 的匹配器闭包
     *
     * @return \Closure 形如 fn(ServerRequestInterface): RouteResult
     */
    public function matcher(): \Closure
    {
        return fn (ServerRequestInterface $request): RouteResult => $this->match($request);
    }

    /**
     * 根据请求匹配路由
     *
     * 匹配优先级：按登记顺序返回第一个路径与（可选）方法都命中的路由。
     * 路径命中但方法不符 → methodNotAllowed（携带 Allow 列表）；
     * 全部未命中 → notFound。
     *
     * @param ServerRequestInterface $request 请求对象
     * @return RouteResult 匹配结果（命中 / 未找到 / 方法不允许）
     */
    public function match(ServerRequestInterface $request): RouteResult
    {
        $path = $request->getUri()->getPath() ?: '/';
        $method = strtoupper($request->getMethod());

        foreach ($this->routes as $route) {
            // 方法约束优先于路径匹配：即使路径能匹配，方法不在白名单也应视为
            // "方法不允许"而非"未找到"，从而给出正确的 405 而非 404。
            $pathHit = preg_match($route['regex'], $path, $matches) === 1;

            if (!$pathHit) {
                continue;
            }

            if ($route['methods'] !== [] && !in_array($method, $route['methods'], true)) {
                return RouteResult::methodNotAllowed($route['methods']);
            }

            $params = [];

            foreach ($matches as $key => $value) {
                // 只收集命名捕获组，跳过数字索引
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return RouteResult::matched($route['handler'], $params, $route['middleware'], $route['name']);
        }

        return RouteResult::notFound();
    }

    /**
     * 把路径模式编译为正则
     *
     * 规则：
     * - 整体锚定 `^...$`（unicode 模式）；
     * - `{name}` → 命名捕获 `(?P<name>[^/]+)`；
     * - `{name:regex}` → 命名捕获 `(?P<name>regex)`（正则需自行保证不含 `}`）。
     *
     * @param string $pattern 原始路径模式
     * @return string 编译后的正则表达式（含分隔符与锚定）
     */
    private function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern
        );

        return '#^' . $regex . '$#u';
    }
}
