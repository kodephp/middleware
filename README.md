# Kode\Middleware

![PHP Version](https://img.shields.io/badge/PHP-%5E8.3-8892BF)

![PSR-15](https://img.shields.io/badge/PSR--15-Compatible-blue)

![PSR-7 / PSR-11](https://img.shields.io/badge/PSR--7%2F11-brightgreen)

![License](https://img.shields.io/badge/License-MIT-blue)

> 轻量、可重入、协程安全的 **PSR-15 中间件管道**，显式串联「路由前 / 路由后 / 调度」三段式生命周期，原生融合 **多进程 / 多线程 / Fiber 协程** 与 **分布式链路透传**。

`Kode\Middleware` 是 kode 生态的「管道底座」：它只负责**中间件如何被组织与执行**，不实现具体的 CORS / 限流 / 鉴权等业务中间件（这些留给 `kode/http` 与你的业务层）。它的核心改进点，是针对 `kode/http` 已有 `MiddlewarePipeline` 的**可变 `$index` 竞态缺陷**，用 **不可变管道 + 可重入游标** 重构，使管道可以在多进程、多线程、Fiber 协程之间安全共享、可重试、可重入。

## 核心特性

- **🧷 不可变（Immutable）**：`add()` / `withXxx()` 一律返回新实例。构建完成的管道作为只读对象，可在 Worker、线程、协程之间共享，**无需加锁、无需每请求重建**。
- **🔁 可重入（Reentrant）**：执行状态全部下沉到不可变 `Cursor`，管道对象在处理期间零写入。同一实例可同时服务任意多个并发请求，中间件可安全地多次调用 `$handler->handle()` 实现重试 / 分支。
- **🧩 可嵌套（Composable）**：`Pipeline` 自身实现 `MiddlewareInterface`，可直接作为另一条管道里的一个中间件——路由分组、模块级中间件由此自然导出。
- **🧭 三段式生命周期**：`beforeRoute`（路由前全局）→ `RouteMiddleware`（路由匹配）→ `afterRoute`（路由后，可读路由元数据）→ `DispatchMiddleware`（路由级中间件 + 控制器）。
- **⚡ 四级并发运行器**：`Sync → Fiber → Thread → Process` 自动探测与降级，业务代码零分支。
- **🌐 分布式链路透传**：W3C `traceparent` 标准 + `kode/context` 上下文隔离，跨进程 / 跨节点不串号。
- **📦 蓝图下发**：`Blueprint` 把中间件编排序列化为 JSON，一份配置全集群 Worker 一致。
- **🔌 软依赖优雅降级**：`kode/context`、`kode/di`、`kode/fibers`、`kode/parallel`、`kode/process` 均为 `suggest`，未安装时静默降级。
- **🧅 框架集成洋葱模式**：`Kernel` 在洋葱之外再包一层「启动 / 请求钩子 / 响应钩子 / 兜底 / 收尾」生命周期；`ErrorBoundaryMiddleware`（最外层异常边界）与 `ProfilerMiddleware`（分层耗时剖析）让洋葱真正「可兜底、可观测」。

## 环境要求

| 环境                                | 版本要求             |
| --------------------------------- | ---------------- |
| PHP                               | `>= 8.3`         |
| PSR-7                             | `^1.1 \|\| ^2.0` |
| PSR-15（Server Middleware/Handler） | `^1.0`           |
| PSR-11（Container）                 | `^1.1 \|\| ^2.0` |

### 可选扩展（启用对应能力时）

| 扩展 / 包                           | 启用能力                         |
| -------------------------------- | ---------------------------- |
| `kode/context`                   | 链路 ID / 请求上下文的协程 / 线程 / 进程隔离 |
| `kode/di`                        | 用 PSR-11 容器按类名解析并自动装配中间件     |
| `kode/fibers`                    | Fiber 协程运行器（FiberRunner）     |
| `ext-parallel` + `kode/parallel` | 多线程运行器（ThreadRunner）         |
| `kode/process`                   | 多进程运行器（ProcessRunner）        |
| `kode/http`                      | PSR-7 消息实现与 HTTP 服务端集成       |

> 注意：本包**不内置** PSR-7 消息实现，运行测试与示例时需引入 `nyholm/psr7` 或其它实现。

## 安装

```bash
composer require kode/middleware
# 如需 PSR-7 实现（运行示例 / 测试）
composer require nyholm/psr7
```

## 快速开始

### 三段式管道（推荐用 `Pipe` 门面）

`Pipe` 把一次请求的生命周期显式拆成三段，让中间件的位置一目了然：

```
 ┌──── beforeRoute ────┐ ┌── router ──┐ ┌──── afterRoute ────┐ ┌── dispatch ──┐
   异常捕获 / 链路追踪        路由匹配        鉴权 / 权限 / 审计       路由级中间件
   CORS / 限流 / 上下文                     （可读路由元数据）        + 控制器
```

```php
<?php

require 'vendor/autoload.php';

use Kode\Middleware\Pipe;
use Kode\Middleware\Routing\RouteResult;
use Kode\Middleware\Routing\RouteMiddleware;
use Kode\Middleware\TraceMiddleware;
use Kode\Middleware\ScopeMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;

// 路由匹配器：返回 RouteResult
$matcher = new RouteMiddleware(function (ServerRequest $req): RouteResult {
    if ($req->getUri()->getPath() === '/user/{id}') {
        return RouteResult::matched(
            handler: fn ($r) => new Response(200, [], 'user'),
            params:   ['id' => '42'],
            name:     'user.show'
        );
    }
    return RouteResult::notFound();
});

$response = Pipe::create()
    ->alias('auth', AuthMiddleware::class)
    ->beforeRoute(new TraceMiddleware(), new ScopeMiddleware()) // 全局前置
    ->router($matcher)
    ->afterRoute('auth')                                       // 路由后：可读路由元数据
    ->fallback(fn ($req) => new Response(404, [], 'Not Found'))
    ->handle(new ServerRequest('GET', '/user/42'));

echo $response->getBody(); // "user"
```

### 更底层的 `Pipeline`

当你不需要「路由前后」分段语义时，直接用 `Pipeline`：

```php
<?php

use Kode\Middleware\Pipeline;
use Kode\Middleware\middleware;

$pipeline = (new Pipeline())
    ->add(new TraceMiddleware())                                   // 最外层
    ->add(middleware(fn ($req, $next) =>
        $next->handle($req->withAttribute('t', microtime(true)))))  // 闭包式中间件
    ->withDestination($controller);                                // 最内层终点

$response = $pipeline->handle($request);
```

### 命名空间快捷函数

`composer.json` 已通过 `files` 自动加载 `src/functions.php`：

```php
use function Kode\Middleware\pipeline;
use function Kode\Middleware\pipe;
use function Kode\Middleware\middleware;
use function Kode\Middleware\handler;
use function Kode\Middleware\lazy;
use function Kode\Middleware\when;
use function Kode\Middleware\boundary;
use function Kode\Middleware\profiler;
use function Kode\Middleware\profile_of;
use function Kode\Middleware\route_of;
use function Kode\Middleware\runner_of;
use function Kode\Middleware\concurrently;
use function Kode\Middleware\capabilities;
use function Kode\Middleware\kernel;

$p = pipeline([
    new TraceMiddleware(),
    when(fn ($req) => $req->getUri()->getPath() === '/health' ? false : true, $auth),
]);

// 探测当前环境的并发能力
print_r(capabilities());
// ['sync' => true, 'fiber' => true, 'thread' => false, 'process' => false]

// 框架集成入口
$k = kernel(pipe()->beforeRoute(boundary(fn ($e) => new Response(500))));
```

---

## API 参考

### `Pipe`（管道构建器 / 门面）

| 方法                                                    | 说明                          |
| ----------------------------------------------------- | --------------------------- |
| `Pipe::create(?ContainerInterface $c, ?Registry $r)`  | 创建构建器                       |
| `alias(string $name, mixed $mw)`                      | 注册中间件别名                     |
| `group(string $name, array $mw)`                      | 注册中间件分组                     |
| `factory(string $name, \Closure $f)`                  | 注册带参工厂（`name:arg1,arg2` 语法） |
| `beforeRoute(mixed ...$mw)`                           | 追加路由前中间件（全局，含 404 请求）       |
| `afterRoute(mixed ...$mw)`                            | 追加路由后中间件（可读取路由元数据）          |
| `router(\Closure\|RouteMiddleware $matcher)`          | 设置路由匹配器                     |
| `invoker(\Closure $invoker)`                          | 自定义路由处理器调用方式                |
| `fallback(RequestHandlerInterface\|callable $h)`      | 设置兜底处理器                     |
| `resolver(ResolverInterface $r)`                      | 指定自定义解析器                    |
| `build(): Pipeline`                                   | 构建不可变管道（结果缓存）               |
| `handle(ServerRequestInterface): ResponseInterface`   | 直接处理一次请求                    |
| `blueprint(): Blueprint`                              | 导出管道蓝图（跨进程 / 跨节点下发）         |
| `Pipe::routeOf(ServerRequestInterface): ?RouteResult` | 读取请求上的路由结果                  |

> `Pipe` 自身可变（链式书写顺手），但 `build()` 产出的 `Pipeline` 不可变。

### `Pipeline`（不可变管道内核）

| 方法                                                                            | 说明                                                     |
| ----------------------------------------------------------------------------- | ------------------------------------------------------ |
| `Pipeline::of(array $mw, ?ResolverInterface $r)`                              | 静态工厂                                                   |
| `add(mixed ...$mw): static`                                                   | 追加中间件，返回新实例                                            |
| `addWithPriority(mixed $mw, int $priority): static`                           | 显式优先级追加                                                |
| `prepend(mixed ...$mw): static`                                               | 插入到最外层，返回新实例                                           |
| `withDestination(RequestHandlerInterface $d): static`                         | 设置终点处理器                                                |
| `to(callable $h): static`                                                     | 以可调用对象设终点                                              |
| `withResolver(ResolverInterface $r): static`                                  | 更换解析器                                                  |
| `merge(PipelineInterface $other): static`                                     | 摊平合并另一条管道                                              |
| `handle(ServerRequestInterface): ResponseInterface`                           | 作为处理器执行（可重入）                                           |
| `process(ServerRequestInterface, RequestHandlerInterface): ResponseInterface` | 作为中间件被调度（嵌套洋葱）                                         |
| `run(ServerRequestInterface, $destination): ResponseInterface`                | 一次性执行                                                  |
| `terminate(ServerRequestInterface, ResponseInterface): void`                  | 触发 `TerminableInterface` 收尾回调                          |
| `stack(): array`                                                              | 已按优先级排序的声明列表                                           |
| `count(): int` / `isEmpty(): bool`                                            | 中间件数量 / 是否为空                                           |
| 实现接口                                                                          | `PipelineInterface`、`MiddlewareInterface`、`\Countable` |

### 中间件适配器（`src/Adapter`）

| 类                       | 作用                                                                                                          |
| ----------------------- | ----------------------------------------------------------------------------------------------------------- |
| `CallableMiddleware`    | 把 `fn($req, $next): Response` 包装成 PSR-15 中间件（亦可用 `middleware()` 函数）                                         |
| `LazyMiddleware`        | 惰性中间件，仅在被实际 `process` 时才实例化目标（亦可用 `lazy()` 函数）                                                              |
| `ConditionalMiddleware` | 条件中间件，静态工厂：`when(predicate, mw)` / `unless(predicate, mw)` / `prefix('/api', mw)` / `methods(['POST'], mw)` |

### 处理器（`src/Handler`）

| 类                      | 作用                                                        |
| ---------------------- | --------------------------------------------------------- |
| `CallableHandler`      | 把 `fn($req): Response` 包装成 PSR-15 处理器（亦可用 `handler()` 函数） |
| `FixedResponseHandler` | 始终返回固定响应的处理器，常用于兜底 / 短路测试                                 |

### 路由三段式（`src/Routing`）

| 类 / 方法                                                                               | 说明                                                       |
| ------------------------------------------------------------------------------------ | -------------------------------------------------------- |
| `RouteMiddleware`                                                                    | 包装一个匹配器闭包（`fn($req): RouteResult`），把结果写入 `kode.route` 属性 |
| `DispatchMiddleware`                                                                 | 读 `RouteResult` 动态拼子管道，优先级 `PHP_INT_MIN` 保证最内层           |
| `RouteResult::matched($handler, $params, $middleware, $name)`                        | 命中                                                       |
| `RouteResult::notFound()` / `methodNotAllowed(array $allowed)`                       | 未找到 / 方法不允许                                              |
| `RouteResult::from($request): ?self`                                                 | 从请求读取                                                    |
| `isMatched()` / `isNotFound()` / `isMethodNotAllowed()` / `status()`                 | 状态判断                                                     |
| `handler()` / `params()` / `param($k, $d)` / `middleware()` / `name()` / `allowed()` | 读取元数据                                                    |
| `withMiddleware(...)` / `withHandler($h)`                                            | 不可变追加 / 替换，返回新实例                                         |

### 优先级约定

数字越大越靠外层（更早进入、更晚退出）。内置中间件默认优先级：

| 中间件                    | 优先级           | 位置                       |
| ---------------------- | ------------- | ------------------------ |
| `ErrorBoundaryMiddleware` | `2000`      | 最外层异常边界（洋葱兜底，render 失败抛 5002） |
| `ProfilerMiddleware`   | `1500`        | 分层耗时剖析（写 `Server-Timing` 头）   |
| `TraceMiddleware`      | `1000`        | 链路追踪                     |
| `ScopeMiddleware`      | `900`         | 上下文隔离（依赖 `kode/context`） |
| `TimeoutMiddleware`    | `850`         | 时间预算                     |
| `ConcurrentMiddleware` | `800`         | 注入并发运行器                  |
| 业务 `beforeRoute`       | `0`（默认）       | 路由前                      |
| `RouteMiddleware`      | `0`（默认）       | 路由匹配                     |
| 业务 `afterRoute`        | `0`（默认）       | 路由后                      |
| `DispatchMiddleware`   | `PHP_INT_MIN` | 最内层（路由级中间件 + 控制器）        |

### 异常边界与可观测性

| 类 / 方法                                                                                  | 说明                                                                                              |
| --------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `ErrorBoundaryMiddleware`                                                               | 优先级 `2000`，洋葱最外层异常边界；捕获下游 `Throwable`，按 `passthrough` 放行、先 `reporter` 上报再 `renderer` 渲染；`renderer` 失效抛 `5002` |
| `ErrorBoundaryMiddleware::PRIORITY` / `ATTRIBUTE`                                        | `2000` / 写入 `kode.error` 供内层读取                                                                |
| `ProfilerMiddleware`                                                                   | 优先级 `1500`，分层耗时剖析；最外层创建 `Profile`、写 `Server-Timing` 头，内层复用同一实例形成层级                              |
| `ProfilerMiddleware::of($req): ?Profile`                                               | 任意中间件内读取当前 `Profile`（`kode.profile` 属性），无全局状态                                                |
| `Profile`                                                                              | 耗时收集器：`enter($name)` / `leave($id)` / `spans()` / `toServerTiming()` / `toText()`，通过请求属性在洋葱层间传递          |

### 应用内核（`src/Kernel.php`）

`Kernel` 是框架集成的总入口：洋葱本身只负责「请求进、响应出」，而 `Kernel` 负责洋葱之外的生命周期——启动、请求 / 响应钩子、最终兜底、收尾。

| 方法                                                                          | 说明                                                          |
| --------------------------------------------------------------------------- | ----------------------------------------------------------- |
| `Kernel::of(PipelineInterface\|Pipe $source)`                              | 从管道或构建器创建内核                                              |
| `withBootstrapper(\Closure ...$b)`                                         | 注册启动回调（进程级，仅一次）                                         |
| `onRequest(\Closure ...$h)` / `onResponse(\Closure ...$h)`                | 请求改写钩子 / 响应改写钩子（返回 null 表示不改写）                            |
| `onTerminate(\Closure ...$h)`                                              | 收尾钩子（响应写回后，异常一律静默）                                     |
| `withExceptionHandler(\Closure $r)`                                        | 最终兜底（捕获洋葱内任何未处理异常，含最外层中间件自身抛错）                         |
| `withPipeline(PipelineInterface $p)`                                       | 替换管道（热更新，无需加锁）                                          |
| `boot(): self`                                                            | 启动（幂等）                                                     |
| `handle(ServerRequestInterface): ResponseInterface`                        | 处理一次请求（协程安全、可重入）                                        |
| `run(ServerRequestInterface): ResponseInterface`                          | `handle` + `terminate`（传统 FPM 一体）                             |
| `terminate(ServerRequestInterface, ResponseInterface): void`              | 收尾（**永不抛异常**）                                            |

> 所有 `withXxx()` 配置方法返回**新实例**，运行期配置不会被意外改写；`handle()` / `terminate()` 完全无写入，同一个 `Kernel` 实例可被 Swoole / Workerman / RoadRunner 的全部 Worker 与协程共享。

---

## 框架集成：洋葱模式（Onion after Framework）

`Pipeline` 负责「洋葱本体」，`Kernel` 负责「洋葱之外」。二者职责分离，互不替代：

```
┌─────────────────────── Kernel（洋葱之外的壳）─────────────────────────┐
│  boot 启动（进程级，仅一次）                                            │
│  ┌──── onRequest 钩子（请求改写、埋点）                                  │
│  │  ┌──────────────── Pipeline 洋葱 ─────────────────┐                │
│  │  │  ErrorBoundary → Profiler → Trace → Scope       │                │
│  │  │    → 路由前 → Route → 路由后 → Dispatch → 控制器  │                │
│  │  └────────────────────────────────────────────────┘                │
│  └──── onResponse 钩子（响应改写、压缩、埋点收口）                         │
│  rescue 兜底（洋葱最外层再炸也不能裸奔 500）                             │
│  ── 响应写回客户端 ──                                                  │
│  terminate 收尾（日志落盘、指标上报、连接归还）                           │
└───────────────────────────────────────────────────────────────────────┘
```

为什么洋葱之外还需要一层？PSR-15 只定义了「请求进、响应出」，但一个真实框架的请求生命周期更长。**中间件无法兜住「洋葱最外层中间件自己抛异常」的情况，而内核可以**——这是 `ErrorBoundaryMiddleware` 与 `Kernel::withExceptionHandler` 构成的双层防线：

```php
<?php

use Kode\Middleware\Pipe;
use Kode\Middleware\Kernel;

$kernel = Pipe::create()
    ->onError(fn (\Throwable $e) => new Response(500, [], 'E:' . $e->getMessage())) // 洋葱最外层异常边界
    ->beforeRoute(new TraceMiddleware(), new ScopeMiddleware())
    ->router($matcher)
    ->afterRoute('auth')
    ->fallback(fn () => new Response(404, [], 'Not Found'))
    ->kernel()                                  // 洋葱之外的壳
    ->onRequest(fn ($req) => $req->withAttribute('boot', 1))
    ->onResponse(fn ($resp) => $resp->withHeader('X-Done', '1'));

// 常驻内存（Swoole / RoadRunner）：boot 一次，每请求 handle，写回后 terminate
$kernel->boot();
$response = $kernel->handle($request);
emit($response);
$kernel->terminate($request, $response);

// 传统 FPM：handle + terminate 一体
$response = $kernel->run($request);
```

完整链路（含「框架洋葱」三段式顺序、控制器异常被边界兜住）由 `tests/Unit/OnionTest.php` 覆盖。

---

## 分组 · 嵌套 · 路由集成（Group · Nest · Router）

「可分组、结合 router、可嵌套」三者其实是同一件事的不同切面：本包用**一个机制**把它们统一起来——**分组名在运行期被解析器展开成一条嵌套子管道**。

### 1. 命名分组（Registry）

分组是一个"中间件别名列表"，可引用实例、类名、别名，甚至可以**递归引用其它分组**：

```php
use Kode\Middleware\Registry;

$registry = (new Registry())
    ->alias('auth', AuthMiddleware::class)
    ->group('guard', ['session', 'csrf', 'auth'])      // 引用别名
    ->group('api', ['cors', 'guard']);                // 递归引用分组
```

解析器遇到分组名时会就地展开为嵌套 `Pipeline`，因此分组内部仍是标准的洋葱嵌套关系。需要**构建期确定性摊平**（调试、序列化前校验）时，用 `Registry::expand()`——它带深度上限与环检测，且对合法的菱形依赖（A→B,C；B→D；C→D）**不会误报成环**：

```php
$registry->expand('api'); // ['cors', 'session', 'csrf', AuthMiddleware::class]
```

### 2. 嵌套组合（Pipe）

`Pipeline` 自身实现了 `MiddlewareInterface`，因此天然可作为另一条管道里的一个洋葱层。`Pipe` 门面提供两种显式写法：

```php
use Kode\Middleware\Pipe;

$pipe = Pipe::create()
    // 内联一条嵌套子管道，作为当前位置的一层洋葱（共享容器与注册表）
    ->nest(fn (Pipe $sub) => $sub
        ->beforeRoute(Factory::tagging('inner-a'))
        ->afterRoute(Factory::tagging('inner-b')))
    // 把已注册的命名分组作为一层嵌套洋葱插入
    ->useGroup('guard')
    ->beforeRoute(TraceMiddleware::class)
    ->fallback(fn () => new Response(404));
```

嵌套层对外层完全透明：它有自己的优先级排序，内部请求自外向内、响应自内向外，最内层终点接到主管道的下游处理器。`terminate()` 收尾会沿嵌套层级自动级联。

### 3. 路由集成（Router）

`Routing\Router` 是零依赖的模式路由收集器，把"路径模式 → 处理器 + 路由级中间件"登记起来，并产出 `RouteResult` 接入 `Pipe::router()`。路由级中间件可以是**命名分组名**，经 `Pipe` 与 `DispatchMiddleware` 共享的 `Resolver` 在运行期展开：

```php
use Kode\Middleware\Pipe;
use Kode\Middleware\Routing\Router;

$router = (new Router())
    ->add('/', fn ($req) => new Response(200, [], 'home'))
    ->add('/users/{id}', UserController::class . '@show', ['api.guard'], 'user.show', ['GET'])
    ->add('/admin/{path:.+}', AdminController::class, ['admin.guard']);

$pipe = Pipe::create($container)
    ->group('api.guard', ['cors', 'auth'])      // 分组定义
    ->router($router->matcher())                // 接入路由收集器
    ->fallback(fn () => new Response(404));

$pipe->handle($request);
// 命中 /users/42 时：auth/cors（api.guard 分组展开为嵌套层）→ 控制器
// 路径参数 {id}=42 会被平铺到 $request->getAttribute('id')
```

`Router` 支持 `{name}`（`[^/]+`）与 `{name:regex}` 占位符、方法约束（不满足方法返回 405）、未命中返回 404。分组 + 路由 + 嵌套在此自然咬合。

### 4. 路由嵌套分组（`Router::group`）

路由收集器原生支持带前缀与共享中间件的嵌套分组，闭包内登记的路由会自动带上**累积前缀**与**分组共享中间件**（外层 → 内层 → 路由自身）：

```php
$router = (new Router())
    ->group('/api', ['cors'], function (Router $r): void {
        $r->add('/users', UserController::class, ['auth']);
        $r->group('/v1', ['v1'], function (Router $r): void {
            $r->add('/ping', PingController::class);
        });
    });

// /api/users    → 中间件 [cors, auth]
// /api/v1/ping  → 中间件 [cors, v1]
```

`Pipe::router()` 直接接受 `Router` 实例，无需再包一层 `matcher()`：

```php
$pipe = Pipe::create()->router($router)->fallback(fn () => new Response(404));
```

### 5. 一站式路由（`Pipe::route` / `Pipe::routeGroup`）

不想先 `new Router()` 再绑定的话，直接用 `Pipe` 逐条登记即可——内部惰性创建 `Router` 并自动绑定路由槽位：

```php
$pipe = Pipe::create()
    ->group('auth', [AuthMiddleware::class])        // 命名中间件分组
    ->route('/', fn () => new Response(200, [], 'home'))
    ->route('/users/{id}', UserController::class . '@show', ['auth'], 'user.show', ['GET'])
    ->routeGroup('/api', ['cors'], function (Pipe $p): void {
        $p->route('/ping', fn () => new Response(200, [], 'pong'));
        $p->route('/secure', fn () => new Response(200, [], 's'), ['auth']);
    })
    ->fallback(fn () => new Response(404));

// /api/ping    → 命中 cors 分组链路
// /api/secure  → 同时命中 cors（路由分组）+ auth（路由级命名分组）
```

> `Pipe::group(name, array)` 注册的是**命名中间件分组**（可被路由引用），`Pipe::routeGroup(prefix, mw, fn)` 做的是**路由前缀分组**，二者职责不同，请勿混淆。

---

## 框架集成桥（Integration\FrameworkBridge）

给 kode 框架一行式接入中间件能力，屏蔽样板代码。框架只需描述业务路由，再把生命周期钩子以关联数组一次性传入，即可拿到可直接 `run()` 的 {@see Kernel}：

```php
use function Kode\Middleware\bridge;

$kernel = bridge(
    pipe: Pipe::create($container)
        ->route('/', fn () => new Response(200, [], 'home'))
        ->route('/users/{id}', UserController::class . '@show', ['auth'])
        ->fallback(fn () => new Response(404)),
    hooks: [
        'boot'       => fn (Kernel $k) => RouteCache::warm(),
        'onResponse' => fn (ResponseInterface $r, $q) => $r->withHeader('X-Powered-By', 'kode'),
        'rescue'     => fn (\Throwable $e, $q) => new Response(500, [], 'err'),
    ],
    renderer: fn (\Throwable $e, $q) => new Response(500, [], $e->getMessage()), // 异常边界渲染器（必填）
);

$kernel->boot();                 // 常驻内存：启动期一次
$response = $kernel->handle($request); // 每请求，协程安全
```

桥默认开启两项便利：

- `stack`（默认 `true`）：挂上内置中间件栈 `Trace(1000) → Scope(900) → Timeout(850) → Concurrent(800)`，按优先级自动排序。它们对未安装的兄弟扩展软降级，挂上即生效。
- `observe`（默认 `true`）：挂上**异常边界 + 分层剖析**（`Server-Timing`），二者声明高优先级恒落洋葱最外层。开启时**必须**提供 `$renderer` 异常渲染器（库不绑定具体 PSR-7 实现，由框架提供）。

`FrameworkBridge::kernel()` 与 `bridge()` 函数完全等价，后者只是命名空间级快捷入口。

---

## 代码生成（Codegen）

`Codegen\MiddlewareGenerator` 把"类名 / 优先级 / 描述"确定性地渲染成符合本包约定的 PSR-15 中间件源码，不依赖任何模板引擎，可无缝接入脚手架与 `middleware-assistant` 技能：

```php
use Kode\Middleware\Codegen\MiddlewareGenerator;

$code = (new MiddlewareGenerator('App\Middleware'))
    ->generate('AuthMiddleware', ['priority' => 1000, 'description' => '鉴权']);

file_put_contents(__DIR__ . '/AuthMiddleware.php', $code);

// 命名空间函数版
$code = \Kode\Middleware\generate_middleware('AuthMiddleware', [
    'priority' => 1000,
    'namespace' => 'App\Middleware',
]);
```

生成的类满足：`declare(strict_types=1)`、实现 `Psr\Http\Server\MiddlewareInterface`、暴露 `public const PRIORITY`（供 `Pipeline` 自动取用优先级）、`process()` 内给出"调用下游之前 / 响应返回之前"两处织入点注释。类名非法（含路径穿越字符）会抛 `RuntimeException`。

---

## 并发：多进程 / 多线程 / Fiber 协程

### 运行器自动降级

`RunnerFactory` 保证**任何情况下都能返回一个可用的运行器**，最差也是 `SyncRunner`。业务代码永远不需要写 `if (extension_loaded('parallel'))` 这类分支：

```php
use Kode\Middleware\Concurrency\Runner\RunnerFactory;

$runner = RunnerFactory::make(RunnerFactory::DRIVER_AUTO, RunnerFactory::PROFILE_IO);
```

| 驱动（`DRIVER_*`） | 运行器             | 需要                               |
| -------------- | --------------- | -------------------------------- |
| `auto`（默认）     | 按 `profile` 自动选 | —                                |
| `sync`         | `SyncRunner`    | 无                                |
| `fiber`        | `FiberRunner`   | `kode/fibers`（或原生 `\Fiber`）      |
| `thread`       | `ThreadRunner`  | `ext-parallel` + `kode/parallel` |
| `process`      | `ProcessRunner` | `kode/process`                   |

**自动挑选策略（driver = `auto`）按任务画像（`PROFILE_*`）区分：**

- `io`（默认）：`Fiber → Sync`。I/O 密集用协程最划算，无需跨进程序列化。
- `cpu`：`Thread → Process → Sync`。CPU 密集必须真并行，线程优于进程。
- `isolate`：`Process → Thread → Sync`。强调故障隔离时进程优先。

`RunnerInterface` 统一方法：`run($task)` / `all(array $tasks, ?float $timeout)` / `supported(): bool` / `close(): void`。

### 在控制器里并发

通过 `ConcurrentMiddleware` 把运行器注入请求属性，下游直接取用：

```php
<?php

use Kode\Middleware\Concurrency\ConcurrentMiddleware;
use function Kode\Middleware\concurrently;

$pipe = Pipe::create()
    ->beforeRoute(new ConcurrentMiddleware(RunnerFactory::DRIVER_AUTO, RunnerFactory::PROFILE_IO))
    ->router($matcher)
    ->fallback($notFound);

// 控制器内
[$user, $orders, $coupons] = array_values(concurrently($request, [
    'user'    => fn () => $userApi->find($id),
    'orders'  => fn () => $orderApi->recent($id),
    'coupons' => fn () => $couponApi->usable($id),
], timeout: 2.0));
```

相关类：`ConcurrentMiddleware`（优先级 800，`ATTRIBUTE = kode.runner`，静态 `runnerOf($req)`）、`TimeoutMiddleware`（优先级 850，deadline propagation，`ON_TIMEOUT_HEADER` / `ON_TIMEOUT_THROW` 策略，静态 `remaining($req, $default)`）、`ScopeMiddleware`（优先级 900，依赖 `kode/context` 做上下文隔离）。

---

## 分布式：链路追踪与节点标识

链路以 **W3C Trace Context**（`traceparent` 头）为基准，可直接被 Jaeger / SkyWalking / OTel Collector 识别。

```php
<?php

use Kode\Middleware\Distributed\TraceMiddleware;
use Kode\Middleware\Distributed\Propagator;

$pipe->beforeRoute(new TraceMiddleware(exposeResponseHeaders: true, syncToContext: true));

// 业务任意深处读取链路 ID
$traceId = TraceMiddleware::traceIdOf($request);

// 调用下游服务时透传（把当前链路继续往下传）
$client->get($url, ['headers' => Propagator::forward($request)]);
```

| 类 / 方法            | 说明                                                                                                                                                                               |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `TraceMiddleware` | 优先级 1000；从 `traceparent` 取 / 建链路，写入 `kode.trace` 属性与 `kode/context`；响应头回写 `X-Trace-Id` / `X-Request-Id` / `X-Node-Id` / `X-Duration-Ms`。静态 `contextOf($req)` / `traceIdOf($req)` |
| `Propagator`      | `extract($req)` 入站解析、`inject($ctx)` 出站头、`forward($req)` 便捷组合、`parseTraceparent($h)`、`HEADER_TRACEPARENT` / `HEADER_REQUEST_ID` / `HEADER_NODE_ID` 常量                             |
| `NodeIdentity`    | 节点 ID 解析优先级：构造参数 → `KODE_NODE_ID` → `HOSTNAME` → `gethostname()` → `unknown`；`id()` / `pid()` / `instance()`（`web-01#12345` 形式）                                                  |

---

## 注册表与别名（`Registry`）

让管道用短字符串书写。`Registry` 在构建期可变，管道运行期不可变，二者职责分离。

```php
<?php

use Kode\Middleware\Registry;
use Kode\Middleware\Pipeline;
use Kode\Middleware\Resolver;

$registry = (new Registry())
    ->alias('auth', AuthMiddleware::class)
    ->group('web', ['session', 'csrf', 'auth'])
    ->factory('throttle', fn (string $max = '60', string $per = '1') =>
        new RateLimitMiddleware((int) $max, (int) $per));

$pipeline = Pipeline::of(['web', 'throttle:100,1'], new Resolver(null, $registry));
```

| 方法                                          | 说明                     |
| ------------------------------------------- | ---------------------- |
| `alias($name, $mw)` / `aliases(array $map)` | 注册单个别名 / 批量            |
| `group($name, array $mw)`                   | 注册分组（解析时展开为子管道）        |
| `factory($name, \Closure $f)`               | 注册带参工厂                 |
| `has($name)` / `lookup($name)`              | 是否注册 / 查声明（`名称:参数` 语法） |
| `allAliases()` / `allGroups()`              | 导出（供 `Blueprint` 序列化）  |
| `mergeFrom(Registry $other)`                | 合并（同名以传入者为准）           |

---

## 蓝图：跨进程 / 跨节点下发（`Blueprint`）

管道对象本身无法跨边界传输（闭包不可序列化、实例常持有连接等资源）。`Blueprint` 只保留「名字」——中间件类名与注册表别名——可 `json_encode` 后写入配置中心、随任务投递到 `ext-parallel` 线程、通过 IPC 发给 `kode/process` 子进程或下发集群其它节点；对端用本地容器 `rebuild()` 出等价管道。

```php
<?php

use Kode\Middleware\Blueprint;

// 主进程 / 配置中心
$json = $pipe->blueprint()->toJson();

// Worker 进程 / 其它节点
$pipeline = Blueprint::fromJson($json)->rebuild($container);
```

| 方法                                                               | 说明                           |
| ---------------------------------------------------------------- | ---------------------------- |
| `Blueprint::fromPipe(array $before, array $after, ?Registry $r)` | 从 `Pipe` 声明提取（仅保留字符串声明）      |
| `Blueprint::fromArray(array $data)` / `fromJson(string $json)`   | 从数组 / JSON 还原                |
| `rebuild(?ContainerInterface $c, ?Registry $r): Pipeline`        | 重建管道                         |
| `rebuildPipe(?ContainerInterface $c): Pipe`                      | 重建 `Pipe`（保留分段信息，便于补本地路由匹配器） |
| `toArray()` / `toJson($flags)` / `jsonSerialize()`               | 序列化                          |
| `fingerprint(): string`                                          | MD5 摘要，用于比对各节点编排是否一致         |
| `VERSION` 常量                                                     | 蓝图格式版本（`'1'`）                |

---

## 与其他 kode 包集成

| 包               | 关系                                                                                                       |
| --------------- | -------------------------------------------------------------------------------------------------------- |
| `kode/http`     | 提供 PSR-7 消息实现与 HTTP 服务端；本包是它底层管道的可重入升级底座。**业务中间件（CORS / 限流 / 鉴权）建议在 `kode/http` 或你的业务层实现，本包只负责编排与执行**。   |
| `kode/context`  | `TraceMiddleware` / `ScopeMiddleware` 把链路 ID、请求上下文写入 `kode/context`，在 Fiber / 协程 / 线程 / 进程四种模型下正确隔离、不串号。 |
| `kode/di`       | 作为 PSR-11 容器注入 `Pipe` / `Resolver`，按类名解析并自动装配中间件构造函数。                                                    |
| `kode/fibers`   | `FiberRunner` 的底层协程引擎。                                                                                   |
| `kode/parallel` | `ThreadRunner` 的底层多线程引擎（`ext-parallel`）。                                                                 |
| `kode/process`  | `ProcessRunner` 的底层多进程引擎。                                                                                |

> 以上均为 `composer.json` 中的 `suggest` 软依赖，未安装时对应能力自动降级，不影响核心管道运行。

---

## 兼容性

| 维度     | 支持情况                                                        |
| ------ | ----------------------------------------------------------- |
| PHP    | `8.3+`（用到 `readonly`、枚举、`\Fiber`、稳定排序等特性）                   |
| 运行模式   | FPM / CLI / Swoole / Workerman / RoadRunner 均可（管道本身与运行模式无关） |
| 并发模型   | 同步、Fiber 协程、ext-parallel 多线程、kode/process 多进程               |
| PSR 兼容 | PSR-7、PSR-11、PSR-15、PSR-17（消息实现由外部提供）                       |
| 序列化下发  | `Blueprint`（JSON）跨进程 / 跨线程 / 跨节点                            |

---

## 设计要点：为什么能「协程安全、可重入、可重试」

常见的 `MiddlewarePipeline`（如 `kode/http` 早期实现）会在调度器上保存一个可变的 `private int $index`，靠自增推进管道。这有三个致命缺陷：

1. **不可重入**：同一调度器处理第二个请求时，`index` 已停在末尾；
2. **协程不安全**：Fiber / Swoole 协程交错时，两个请求会互相推进对方的游标，导致「串号」；
3. **无法重试**：中间件想对下游做一次重试（两次调用 `$handler->handle()`）时，第二次会从错误位置继续。

本包改用 **不可变游标 `Cursor`**：每前进一步就构造一个 `index+1` 的新游标对象，自身状态构造后永不改变。于是管道对象在处理期间零写入，可并发共享、可重入、可重试，且可安全地跨 Worker 复用。

---

## 测试

```bash
# 运行全部单元测试
composer test

# 生成覆盖率报告
composer test-coverage

# 静态分析（phpstan，level=max）
composer check

# 代码风格修复（php-cs-fixer，@PSR12）
composer fix
```

当前测试覆盖（95 tests / 187 assertions）：管道不可变 / 可重入 / 协程交错不串号 / 可重试、`Resolver` 惰性 / 别名 / 分组 / 带参工厂 / 容器 / 条件 / 错误码、路由三段式顺序与兜底、**`Kernel` 启动幂等 · 钩子 · 兜底 · 收尾级联 · Fiber 可重入**、循环引用防护 / 惰性收尾级联 / 蓝图白名单等健壮性、**异常边界 · 分层剖析 · 完整框架洋葱链路**，以及**分组递归展开 / `Router` 路由收集器 / `Pipe` 嵌套组合 / 路由引用命名分组 / `Codegen` 代码生成**等能力；本轮新增 **`Router` 嵌套分组前缀与中间件累积、 `Pipe::router(Router)` 直接接入、 `Pipe::route` / `routeGroup` 一站式登记、 `FrameworkBridge` 框架集成桥（stack / observe / 一行式内核）** 的集成测试。

## 许可证

MIT
