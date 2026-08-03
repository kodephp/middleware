# Changelog

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/)（SemVer）。

## [1.0.0] - 2026-08-03

首个稳定版本。

### 新增

- **不可变管道内核 `Pipeline`**：`add()` / `withDestination()` / `run()` 等一律返回新实例；
  执行状态下沉到不可变 `Cursor`，实现协程安全、可重入、可重试，可跨 Worker 复用。
- **管道门面 `Pipe`**：显式三段式生命周期 `beforeRoute` → `router` → `afterRoute` →
  `dispatch`，自动装配 `RouteMiddleware` / `DispatchMiddleware`。
- **路由三段式**：`RouteMiddleware`（匹配 + 元数据写入）、`DispatchMiddleware`
  （路由级中间件子管道 + 控制器）、`RouteResult` 不可变值对象。
- **注册表与解析器**：`Registry`（别名 / 分组 / 带参工厂）+ `Resolver`（惰性解析、
  容器、条件、构建期循环引用 / 层级过深环检测）。
- **四级并发运行器**：`Sync → Fiber → Thread → Process` 自动探测与降级，
  `RunnerFactory` 按任务画像（`io` / `cpu` / `isolate`）选优。
- **分布式链路**：W3C `traceparent` 透传（`TraceMiddleware` / `Propagator` /
  `NodeIdentity`），可与 `kode/context` 协作做上下文隔离。
- **蓝图下发 `Blueprint`**：仅序列化类名 / 别名字符串，`json_encode` 后跨进程 /
  跨线程 / 跨节点重建，带 `VERSION` 与 `fingerprint()` 一致性校验，默认白名单收紧。
- **框架集成洋葱模式 `Kernel`**：洋葱之外的「启动 / 请求钩子 / 响应钩子 / 兜底 /
  收尾」生命周期；`withXxx()` 不可变，`handle()` / `terminate()` 协程安全。
- **异常边界 `ErrorBoundaryMiddleware`**（优先级 2000）：最外层捕获下游异常，按
  `passthrough` 放行、先上报再渲染；渲染失效抛 `5002`。
- **分层耗时剖析 `ProfilerMiddleware`**（优先级 1500）+ `Profile` 收集器：
  写 `Server-Timing` 响应头，异常路径下区段同样正确关闭并冲刷。
- **命名空间快捷函数**：`pipeline()` / `pipe()` / `kernel()` / `boundary()` /
  `profiler()` / `profile_of()` / `middleware()` / `handler()` / `lazy()` / `when()`
  / `route_of()` / `runner_of()` / `concurrently()` / `capabilities()`。
- **错误码体系**：1xxx 解析 / 2xxx 执行 / 3xxx 并发 / 4xxx 分布式 / 5xxx 内核。

### 健壮性与安全

- 别名 / 分组循环引用（自引用、互引用、分组绕行）运行期环检测。
- 惰性中间件收尾级联：绝不因收尾而实例化被短路的惰性中间件。
- 嵌套 / 别名管道收尾级联；收尾阶段异常一律静默。
- 蓝图 `rebuild()` 默认校验类名 / 别名白名单，`$trust` 可收紧信任策略。
- `Kernel::terminate()` 与 `ErrorBoundaryMiddleware` 构成双层兜底防线。

### 测试

- 74 个单元测试 / 137 条断言全绿（PHPUnit）。
- 覆盖不可变 / 可重入 / 协程交错不串号 / 可重试、`Resolver` 全部分支、路由三段式、
  `Kernel` 启动幂等 · 钩子 · 兜底 · 收尾级联 · Fiber 可重入、循环引用防护、惰性收尾、
  蓝图白名单、异常边界、分层剖析、完整框架洋葱链路。
- 静态分析 `phpstan --level=max` 0 错误。

### 许可证

- MIT。
