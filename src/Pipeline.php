<?php

declare(strict_types=1);

namespace Kode\Middleware;

use Kode\Middleware\Contract\PipelineInterface;
use Kode\Middleware\Contract\PrioritizedInterface;
use Kode\Middleware\Contract\ResolverInterface;
use Kode\Middleware\Contract\TerminableInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Kode\Middleware\Handler\CallableHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 中间件管道
 *
 * 本包的核心。三个设计承诺：
 *
 * 1. **不可变（Immutable）**：add() / withXxx() 一律返回新实例。构建完成的管道
 *    可以作为常量安全地在多进程、多线程、多协程之间共享，无需加锁、无需每请求重建。
 * 2. **可重入（Reentrant）**：执行状态全部下沉到 Cursor，管道对象本身在处理请求
 *    期间零写入。同一实例可同时服务任意多个并发请求。
 * 3. **可嵌套（Composable）**：管道自身实现了 MiddlewareInterface，
 *    因此一条管道可以直接作为另一条管道中的一个中间件，路由分组、
 *    模块级中间件都由这一条性质自然导出。
 *
 * 洋葱模型：先注册的中间件在更外层，请求自外向内、响应自内向外。
 *
 * @example
 * ```php
 * $pipeline = (new Pipeline())
 *     ->add(new TraceMiddleware())        // 最外层
 *     ->add(new ScopeMiddleware())
 *     ->add(fn($req, $next) => $next->handle($req->withAttribute('t', microtime(true))))
 *     ->withDestination($controller);     // 最内层终点
 *
 * $response = $pipeline->handle($request);
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class Pipeline implements PipelineInterface, TerminableInterface, \Countable
{
    /**
     * 中间件条目列表
     *
     * 每个条目结构为 ['declaration' => mixed, 'priority' => int, 'seq' => int]，
     * seq 用于保证同优先级下的稳定排序。
     *
     * @var list<array{declaration: mixed, priority: int, seq: int}>
     */
    private array $entries = [];

    /** @var RequestHandlerInterface|null 管道终点处理器 */
    private ?RequestHandlerInterface $destination = null;

    /** @var ResolverInterface 中间件声明解析器 */
    private ResolverInterface $resolver;

    /**
     * 排序后的声明列表缓存
     *
     * 管道不可变，因此排序结果只需计算一次；克隆时会被重置。
     *
     * @var list<mixed>|null
     */
    private ?array $compiled = null;

    /** @var int 自增序号，用于稳定排序 */
    private int $sequence = 0;

    /**
     * @param ResolverInterface|null $resolver 中间件解析器，省略时使用默认解析器
     */
    public function __construct(?ResolverInterface $resolver = null)
    {
        $this->resolver = $resolver ?? new Resolver();
    }

    /**
     * 静态构造：从中间件数组快速建立管道
     *
     * @param array<int, mixed> $middleware 中间件声明数组
     * @param ResolverInterface|null $resolver 中间件解析器
     * @return static 新管道实例
     */
    public static function of(array $middleware = [], ?ResolverInterface $resolver = null): static
    {
        /** @var static $pipeline */
        // @phpstan-ignore-next-line 不可变工厂按调用者具体类型派生新实例，属于预期的 new static 用法
        $pipeline = new static($resolver);

        return $pipeline->add(...$middleware);
    }

    /**
     * 追加中间件（返回新实例）
     *
     * 若中间件实现了 PrioritizedInterface，则自动采用其声明的优先级；
     * 否则默认优先级为 0，按注册顺序执行。
     *
     * @param mixed ...$middleware 一个或多个中间件声明
     * @return static 携带新中间件的管道副本
     * @throws MiddlewareException 声明形式不被解析器接受时抛出
     */
    public function add(mixed ...$middleware): static
    {
        $new = clone $this;

        foreach ($middleware as $declaration) {
            // 允许直接展开一维数组，写法上更自由
            if (is_array($declaration) && !is_callable($declaration)) {
                foreach ($declaration as $item) {
                    $new->push($item, $new->priorityOf($item));
                }

                continue;
            }

            $new->push($declaration, $new->priorityOf($declaration));
        }

        return $new;
    }

    /**
     * 以显式优先级追加中间件（返回新实例）
     *
     * 显式优先级会覆盖中间件自身通过 PrioritizedInterface 声明的值。
     *
     * @param mixed $middleware 中间件声明
     * @param int $priority 优先级，越大越靠外层
     * @return static 管道副本
     * @throws MiddlewareException 声明形式不被解析器接受时抛出
     */
    public function addWithPriority(mixed $middleware, int $priority): static
    {
        $new = clone $this;
        $new->push($middleware, $priority);

        return $new;
    }

    /**
     * 在管道最外层插入中间件（返回新实例）
     *
     * 等价于赋予一个比现有全部中间件都高的优先级。
     *
     * @param mixed ...$middleware 中间件声明
     * @return static 管道副本
     * @throws MiddlewareException 声明形式不被解析器接受时抛出
     */
    public function prepend(mixed ...$middleware): static
    {
        $highest = 0;
        foreach ($this->entries as $entry) {
            $highest = max($highest, $entry['priority']);
        }

        $new = clone $this;
        foreach ($middleware as $declaration) {
            $new->push($declaration, $highest + 1);
        }

        return $new;
    }

    /**
     * 指定管道终点处理器（返回新实例）
     *
     * @param RequestHandlerInterface $destination 终点处理器
     * @return static 管道副本
     */
    public function withDestination(RequestHandlerInterface $destination): static
    {
        $new = clone $this;
        $new->destination = $destination;

        return $new;
    }

    /**
     * 以可调用对象指定管道终点（返回新实例）
     *
     * @param callable $handler 形如 fn(ServerRequestInterface): ResponseInterface
     * @return static 管道副本
     */
    public function to(callable $handler): static
    {
        return $this->withDestination(new CallableHandler($handler));
    }

    /**
     * 更换中间件解析器（返回新实例）
     *
     * @param ResolverInterface $resolver 新解析器
     * @return static 管道副本
     */
    public function withResolver(ResolverInterface $resolver): static
    {
        $new = clone $this;
        $new->resolver = $resolver;

        return $new;
    }

    /**
     * 合并另一条管道的中间件（返回新实例）
     *
     * 注意这是"摊平合并"而非嵌套；若需要嵌套语义（分组），
     * 请直接用 add($otherPipeline)，因为管道本身就是中间件。
     *
     * @param PipelineInterface $other 另一条管道
     * @return static 管道副本
     * @throws MiddlewareException 声明形式不被解析器接受时抛出
     */
    public function merge(PipelineInterface $other): static
    {
        return $this->add(...$other->stack());
    }

    /**
     * 作为 PSR-15 请求处理器执行整条管道
     *
     * 每次调用都会创建一个全新的不可变游标，因此本方法天然可重入、协程安全。
     *
     * @param ServerRequestInterface $request 请求对象
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 管道无终点处理器，或中间件返回值非法
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->cursor($this->destination)->handle($request);
    }

    /**
     * 作为 PSR-15 中间件被另一条管道调度
     *
     * 此时外层传入的 $handler 充当本管道的终点，从而形成嵌套洋葱。
     * 这是路由分组、模块中间件的实现基础。
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 外层下游处理器
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 中间件返回值非法时抛出
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->cursor($this->destination ?? $handler)->handle($request);
    }

    /**
     * 一次性执行：等价于 withDestination()->handle()，但不产生管道副本
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface|callable|null $destination 本次执行的终点
     * @return ResponseInterface 响应对象
     * @throws MiddlewareException 管道无终点处理器，或中间件返回值非法
     */
    public function run(ServerRequestInterface $request, RequestHandlerInterface|callable|null $destination = null): ResponseInterface
    {
        if ($destination !== null && !$destination instanceof RequestHandlerInterface) {
            $destination = new CallableHandler($destination);
        }

        return $this->cursor($destination ?? $this->destination)->handle($request);
    }

    /**
     * 触发所有可终结中间件的收尾回调
     *
     * 应在响应写回客户端之后调用。任何中间件在 terminate() 中抛出的异常
     * 都会被静默忽略，以免影响已完成的请求。
     *
     * @param ServerRequestInterface $request 本次请求
     * @param ResponseInterface $response 已发送的响应
     * @return void
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        foreach ($this->compile() as $declaration) {
            try {
                $terminable = $this->terminableOf($declaration);

                // 嵌套管道同样实现了本接口，因此分组 / 子管道的收尾会自动级联
                $terminable?->terminate($request, $response);
            } catch (\Throwable) {
                // 收尾阶段的失败不影响主流程，按契约静默忽略
            }
        }
    }

    /**
     * 获取按优先级排序后的中间件声明列表
     *
     * @return list<mixed> 已排序的声明列表
     */
    public function stack(): array
    {
        return $this->compile();
    }

    /**
     * 获取管道终点处理器
     *
     * @return RequestHandlerInterface|null 终点处理器，未设置时为 null
     */
    public function destination(): ?RequestHandlerInterface
    {
        return $this->destination;
    }

    /**
     * 获取当前解析器
     *
     * @return ResolverInterface 中间件解析器
     */
    public function resolver(): ResolverInterface
    {
        return $this->resolver;
    }

    /**
     * 中间件数量
     *
     * @return int 中间件个数
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * 管道是否为空
     *
     * @return bool 没有任何中间件时返回 true
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * 克隆时重置排序缓存
     *
     * @return void
     */
    public function __clone(): void
    {
        $this->compiled = null;
    }

    /**
     * 把声明还原成可终结对象（不触发真实中间件的实例化）
     *
     * 字符串声明经解析器得到的是 LazyMiddleware 包装层，本身就实现了
     * TerminableInterface，并只在内部已实例化时才向下转发。因此这里调用
     * resolve() 是安全的：命中解析器缓存，不会为了收尾而唤醒惰性中间件。
     *
     * @param mixed $declaration 中间件声明
     * @return TerminableInterface|null 可终结对象，不支持收尾时返回 null
     */
    private function terminableOf(mixed $declaration): ?TerminableInterface
    {
        if ($declaration instanceof TerminableInterface) {
            return $declaration;
        }

        // 已经是中间件实例但不可终结，无需再走解析器
        if ($declaration instanceof MiddlewareInterface) {
            return null;
        }

        $resolved = $this->resolver->resolve($declaration);

        return $resolved instanceof TerminableInterface ? $resolved : null;
    }

    /**
     * 创建一个新的不可变游标
     *
     * @param RequestHandlerInterface|null $destination 本次执行的终点处理器
     * @return Cursor 游标实例
     */
    private function cursor(?RequestHandlerInterface $destination): Cursor
    {
        return new Cursor($this->compile(), 0, $destination, $this->resolver);
    }

    /**
     * 压入一个中间件条目
     *
     * @param mixed $declaration 中间件声明
     * @param int $priority 优先级
     * @return void
     * @throws MiddlewareException 声明形式不被解析器接受时抛出
     */
    private function push(mixed $declaration, int $priority): void
    {
        if (!$this->resolver->accepts($declaration)) {
            throw MiddlewareException::unresolvable($declaration);
        }

        $this->entries[] = [
            'declaration' => $declaration,
            'priority' => $priority,
            'seq' => $this->sequence++,
        ];
        $this->compiled = null;
    }

    /**
     * 推断中间件声明的默认优先级
     *
     * @param mixed $declaration 中间件声明
     * @return int 优先级数值
     */
    private function priorityOf(mixed $declaration): int
    {
        return $declaration instanceof PrioritizedInterface ? $declaration->priority() : 0;
    }

    /**
     * 编译并缓存排序后的声明列表
     *
     * 排序规则：优先级降序；优先级相同时按注册顺序升序（稳定）。
     *
     * @return list<mixed> 排序后的声明列表
     */
    private function compile(): array
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $entries = $this->entries;

        usort($entries, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority']
                ?: $a['seq'] <=> $b['seq'];
        });

        return $this->compiled = array_map(
            static fn (array $entry): mixed => $entry['declaration'],
            $entries
        );
    }
}
