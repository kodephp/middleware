<?php

declare(strict_types=1);

namespace Kode\Middleware;

use Kode\Middleware\Adapter\CallableMiddleware;
use Kode\Middleware\Adapter\LazyMiddleware;
use Kode\Middleware\Contract\ResolverInterface;
use Kode\Middleware\Exception\MiddlewareException;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * 默认中间件解析器
 *
 * 把各种"中间件声明"归一化成 PSR-15 中间件实例。支持的形式：
 *
 * | 声明形式 | 解析结果 |
 * |---------|---------|
 * | `MiddlewareInterface` 实例 | 原样返回 |
 * | 闭包 / 可调用对象 | 包装为 CallableMiddleware |
 * | 中间件类名字符串 | 惰性实例化（优先走 PSR-11 容器） |
 * | 注册表别名字符串 | 展开后递归解析 |
 * | 注册表分组 / 数组 | 就地组成一条嵌套子管道 |
 *
 * 解析发生在请求处理期而非管道构建期，配合 LazyMiddleware
 * 实现"被短路的中间件永不实例化"。
 *
 * 解析结果按声明的字符串键做进程内缓存，常驻内存场景下
 * 每个中间件类只会经历一次容器解析与反射开销。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class Resolver implements ResolverInterface
{
    /** @var int 别名 / 分组的最大解析层级，超出即判定为配置失控 */
    public const MAX_ALIAS_DEPTH = 32;

    /** @var array<string, MiddlewareInterface> 字符串声明的解析结果缓存 */
    private array $cache = [];

    /** @var array<string, true> 已通过循环检测的名称，避免重复遍历注册表 */
    private array $verified = [];

    /**
     * @param ContainerInterface|null $container PSR-11 容器，用于解析类名与构造依赖
     * @param Registry|null $registry 中间件注册表，用于解析别名与分组
     * @param bool $shared 解析结果是否缓存复用；持有请求级状态的中间件请设为 false
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly ?Registry $registry = null,
        private readonly bool $shared = true,
    ) {
    }

    /**
     * 解析中间件声明
     *
     * @param mixed $declaration 中间件声明
     * @return MiddlewareInterface 中间件实例
     * @throws MiddlewareException 声明无法被解析时抛出
     */
    public function resolve(mixed $declaration): MiddlewareInterface
    {
        // 1. 已经是中间件实例（管道自身也走这里，因为 Pipeline 实现了 MiddlewareInterface）
        if ($declaration instanceof MiddlewareInterface) {
            return $declaration;
        }

        // 2. 字符串：可能是别名、分组名或类名
        if (is_string($declaration)) {
            return $this->resolveString($declaration);
        }

        // 3. 数组：组成一条嵌套子管道（数组形式的可调用对象要先排除）
        if (is_array($declaration) && !is_callable($declaration)) {
            return Pipeline::of($declaration, $this);
        }

        // 4. 可调用对象
        if (is_callable($declaration)) {
            return new CallableMiddleware($declaration);
        }

        throw MiddlewareException::unresolvable($declaration);
    }

    /**
     * 判断声明是否可被解析
     *
     * 仅做形态校验，不触发实例化；类名是否真的实现了 PSR-15 接口
     * 留到运行期由 LazyMiddleware 校验，以保持惰性。
     *
     * @param mixed $declaration 中间件声明
     * @return bool 可解析返回 true
     */
    public function accepts(mixed $declaration): bool
    {
        if ($declaration instanceof MiddlewareInterface) {
            return true;
        }

        if (is_string($declaration)) {
            return $declaration !== ''
                && ($this->registry?->has($declaration) === true
                    || class_exists($declaration)
                    || $this->container?->has($declaration) === true);
        }

        if (is_array($declaration)) {
            return true;
        }

        return is_callable($declaration);
    }

    /**
     * 解析字符串声明
     *
     * 优先级：注册表别名 / 分组 > 容器条目 > 类名。
     * 别名优先于类名，使得业务可以用别名覆盖默认实现。
     *
     * @param string $declaration 字符串声明
     * @return MiddlewareInterface 中间件实例
     * @throws MiddlewareException 无法解析时抛出
     */
    private function resolveString(string $declaration): MiddlewareInterface
    {
        if ($this->shared && isset($this->cache[$declaration])) {
            return $this->cache[$declaration];
        }

        // 展开注册表条目之前先做一次静态环检测，避免无限递归 / 栈溢出
        if ($this->registry?->has($declaration) === true) {
            $this->assertAcyclic($declaration);
        }

        $resolved = match (true) {
            $this->registry?->has($declaration) === true
                => $this->resolve($this->registry->lookup($declaration)),

            $this->container?->has($declaration) === true
                => new LazyMiddleware(
                    fn (): mixed => $this->fromContainer($declaration),
                    $declaration,
                    $this->shared
                ),

            class_exists($declaration)
                => new LazyMiddleware(
                    fn (): mixed => $this->instantiate($declaration),
                    $declaration,
                    $this->shared
                ),

            default => throw MiddlewareException::unresolvable($declaration),
        };

        if ($this->shared) {
            $this->cache[$declaration] = $resolved;
        }

        return $resolved;
    }

    /**
     * 静态校验注册表子图无环
     *
     * 只读取注册表的**静态定义**（Registry::peek()），不调用任何工厂、
     * 不实例化任何对象，因此可以放心地在解析前执行。
     *
     * 检测能力覆盖三类环：
     * - 自引用：`alias('a', 'a')`
     * - 互引用：`alias('a', 'b')` + `alias('b', 'a')`
     * - 经分组绕行：`group('web', ['api'])` + `group('api', ['web'])`
     *
     * 校验通过的名称会被记忆，重复解析不再遍历。由于子图无环性与访问路径无关
     * （路径自身成环的情况已由 in_array 提前拦截），该缓存是安全的。
     *
     * @param string $name 名称，可带 `:参数` 后缀
     * @param list<string> $chain 当前解析链（不含本节点）
     * @return void
     * @throws MiddlewareException 发现环或层级超限时抛出
     */
    private function assertAcyclic(string $name, array $chain = []): void
    {
        if ($this->registry === null) {
            return;
        }

        $key = explode(':', $name, 2)[0];

        if (in_array($key, $chain, true)) {
            throw MiddlewareException::circularAlias([...$chain, $key]);
        }

        if (isset($this->verified[$key])) {
            return;
        }

        if (count($chain) >= self::MAX_ALIAS_DEPTH) {
            throw MiddlewareException::aliasTooDeep($chain[0] ?? $key, self::MAX_ALIAS_DEPTH);
        }

        $definition = $this->registry->peek($name);

        // 工厂条目没有静态子图可遍历，直接视为安全
        if ($definition !== null) {
            $chain[] = $key;

            foreach (is_array($definition) ? $definition : [$definition] as $item) {
                if (is_string($item) && $this->registry->has($item)) {
                    $this->assertAcyclic($item, $chain);
                }
            }
        }

        $this->verified[$key] = true;
    }

    /**
     * 从 PSR-11 容器取出中间件
     *
     * @param string $id 容器标识
     * @return mixed 容器返回的对象
     * @throws MiddlewareException 容器解析失败时抛出
     */
    private function fromContainer(string $id): mixed
    {
        try {
            return $this->container?->get($id);
        } catch (\Throwable $e) {
            throw MiddlewareException::containerFailed($id, $e);
        }
    }

    /**
     * 直接实例化中间件类
     *
     * 无容器时的兜底路径：仅支持无参构造，或全部参数都有默认值的构造函数。
     * 需要自动装配请注入 kode/di 等 PSR-11 容器。
     *
     * @param class-string $class 类名
     * @return object 中间件实例
     * @throws MiddlewareException 类不是合法中间件或无法无参实例化时抛出
     */
    private function instantiate(string $class): object
    {
        if (!is_subclass_of($class, MiddlewareInterface::class)) {
            throw MiddlewareException::notMiddleware($class);
        }

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new MiddlewareException(
                "中间件「{$class}」的构造函数存在必填参数，无法自动实例化；"
                . '请改为注入实例、注册工厂别名，或为解析器提供 PSR-11 容器（如 kode/di）',
                1005,
                null,
                ['class' => $class]
            );
        }

        return $reflection->newInstance();
    }
}
