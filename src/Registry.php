<?php

declare(strict_types=1);

namespace Kode\Middleware;

use Kode\Middleware\Exception\MiddlewareException;

/**
 * 中间件注册表
 *
 * 维护三类可复用的命名声明，让管道可以用短字符串书写：
 *
 * 1. **别名（alias）**：`'auth' => AuthMiddleware::class`
 * 2. **分组（group）**：`'web' => ['session', 'csrf', 'auth']`，一次展开为多个
 * 3. **工厂（factory）**：带参数的别名，支持 `'throttle:60,1'` 语法
 *
 * 注册表是可变的（构建期使用），而管道是不可变的（运行期使用），
 * 二者职责分离：注册表负责"起名字"，管道负责"定顺序"。
 *
 * @example
 * ```php
 * $registry = (new Registry())
 *     ->alias('auth', AuthMiddleware::class)
 *     ->group('web', ['session', 'csrf', 'auth'])
 *     ->factory('throttle', fn(string $max = '60', string $per = '1')
 *         => new RateLimitMiddleware((int) $max, (int) $per));
 *
 * $pipeline = Pipeline::of(['web', 'throttle:100,1'], new Resolver(null, $registry));
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Registry
{
    /** @var array<string, mixed> 别名 => 中间件声明 */
    private array $aliases = [];

    /** @var array<string, list<mixed>> 分组名 => 中间件声明列表 */
    private array $groups = [];

    /** @var array<string, \Closure> 工厂名 => 工厂闭包 */
    private array $factories = [];

    /**
     * 注册单个别名
     *
     * @param string $name 别名，例如 auth
     * @param mixed $middleware 中间件声明（实例、类名、可调用对象）
     * @return $this 支持链式调用
     * @throws MiddlewareException 别名直接指向自身时抛出
     */
    public function alias(string $name, mixed $middleware): self
    {
        // 直接自引用是最常见的手误，且会让解析器无限递归，构建期就拒绝
        if (is_string($middleware) && $this->split($middleware)[0] === $name) {
            throw MiddlewareException::circularAlias([$name, $name]);
        }

        $this->aliases[$name] = $middleware;

        return $this;
    }

    /**
     * 批量注册别名
     *
     * @param array<string, mixed> $map 别名 => 中间件声明
     * @return $this 支持链式调用
     */
    public function aliases(array $map): self
    {
        foreach ($map as $name => $middleware) {
            $this->alias($name, $middleware);
        }

        return $this;
    }

    /**
     * 注册一个中间件分组
     *
     * 分组在解析时会被就地展开为一条嵌套子管道，
     * 因此分组内部的中间件之间仍保持标准的洋葱嵌套关系。
     *
     * @param string $name 分组名，例如 web / api
     * @param array<int, mixed> $middleware 中间件声明列表
     * @return $this 支持链式调用
     * @throws MiddlewareException 分组直接包含自身时抛出
     */
    public function group(string $name, array $middleware): self
    {
        foreach ($middleware as $item) {
            if (is_string($item) && $this->split($item)[0] === $name) {
                throw MiddlewareException::circularAlias([$name, $name]);
            }
        }

        $this->groups[$name] = array_values($middleware);

        return $this;
    }

    /**
     * 注册一个带参数的中间件工厂
     *
     * 之后即可用 `名称:参数1,参数2` 的形式在管道中引用。
     * 参数以字符串形式原样传给工厂，由工厂自行转换类型。
     *
     * @param string $name 工厂名，例如 throttle
     * @param \Closure $factory 工厂闭包，形如 fn(string ...$args): MiddlewareInterface
     * @return $this 支持链式调用
     */
    public function factory(string $name, \Closure $factory): self
    {
        $this->factories[$name] = $factory;

        return $this;
    }

    /**
     * 判断名称是否已注册（别名 / 分组 / 工厂任一命中）
     *
     * @param string $name 名称，可带 `:参数` 后缀
     * @return bool 已注册返回 true
     */
    public function has(string $name): bool
    {
        [$key] = $this->split($name);

        return isset($this->aliases[$key])
            || isset($this->groups[$key])
            || isset($this->factories[$key]);
    }

    /**
     * 查找名称对应的声明
     *
     * 返回值语义：
     * - 工厂命中：返回已经调用工厂产出的中间件实例
     * - 分组命中：返回中间件声明数组（由解析器展开成子管道）
     * - 别名命中：返回原始声明
     *
     * @param string $name 名称，可带 `:参数` 后缀
     * @return mixed 对应的中间件声明
     * @throws MiddlewareException 名称未注册时抛出
     */
    public function lookup(string $name): mixed
    {
        [$key, $args] = $this->split($name);

        if (isset($this->factories[$key])) {
            return ($this->factories[$key])(...$args);
        }

        if (isset($this->groups[$key])) {
            return $this->groups[$key];
        }

        if (array_key_exists($key, $this->aliases)) {
            return $this->aliases[$key];
        }

        throw MiddlewareException::aliasNotFound($name);
    }

    /**
     * 判断名称的注册类型（不触发任何实例化）
     *
     * @param string $name 名称，可带 `:参数` 后缀
     * @return string|null alias / group / factory，未注册时返回 null
     */
    public function kind(string $name): ?string
    {
        [$key] = $this->split($name);

        return match (true) {
            isset($this->factories[$key]) => 'factory',
            isset($this->groups[$key]) => 'group',
            array_key_exists($key, $this->aliases) => 'alias',
            default => null,
        };
    }

    /**
     * 窥探名称对应的原始定义（不调用工厂、不实例化任何对象）
     *
     * 与 lookup() 的区别：lookup() 会执行工厂闭包产出真实中间件，
     * 而 peek() 只返回静态定义，因此可以安全地用于构建期的图遍历
     * （例如循环引用检测），不会产生任何副作用。
     *
     * @param string $name 名称，可带 `:参数` 后缀
     * @return mixed 分组返回声明数组，别名返回原始声明，工厂与未注册均返回 null
     */
    public function peek(string $name): mixed
    {
        [$key] = $this->split($name);

        if (isset($this->groups[$key])) {
            return $this->groups[$key];
        }

        if (!isset($this->factories[$key]) && array_key_exists($key, $this->aliases)) {
            return $this->aliases[$key];
        }

        return null;
    }

    /**
     * 获取全部别名
     *
     * @return array<string, mixed> 别名映射
     */
    public function allAliases(): array
    {
        return $this->aliases;
    }

    /**
     * 获取全部分组
     *
     * @return array<string, list<mixed>> 分组映射
     */
    public function allGroups(): array
    {
        return $this->groups;
    }

    /**
     * 合并另一个注册表（同名以传入者为准）
     *
     * @param Registry $other 另一个注册表
     * @return $this 支持链式调用
     */
    public function mergeFrom(Registry $other): self
    {
        $this->aliases = array_merge($this->aliases, $other->aliases);
        $this->groups = array_merge($this->groups, $other->groups);
        $this->factories = array_merge($this->factories, $other->factories);

        return $this;
    }

    /**
     * 拆分 `名称:参数1,参数2` 语法
     *
     * @param string $name 原始名称
     * @return array{0: string, 1: list<string>} [名称, 参数列表]
     */
    private function split(string $name): array
    {
        if (!str_contains($name, ':')) {
            return [$name, []];
        }

        [$key, $rest] = explode(':', $name, 2);
        $args = $rest === '' ? [] : array_map(trim(...), explode(',', $rest));

        return [$key, array_values($args)];
    }
}
