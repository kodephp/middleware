<?php

declare(strict_types=1);

namespace Kode\Middleware;

use Kode\Middleware\Exception\MiddlewareException;
use Psr\Container\ContainerInterface;

/**
 * 管道蓝图（可序列化的管道描述）
 *
 * 解决分布式与多进程 / 多线程场景下的一个现实问题：
 * **管道对象本身无法跨边界传输**——闭包不能序列化，中间件实例往往持有连接、
 * 文件句柄等不可跨进程的资源。
 *
 * 蓝图只保留"名字"：中间件类名与注册表别名组成的纯数组。它可以被
 * json_encode 后写入配置中心、随任务投递到 ext-parallel 线程、
 * 通过 IPC 发给 kode/process 子进程，或下发到集群其它节点；
 * 对端拿到蓝图后用本地容器 rebuild() 出一条等价管道。
 *
 * 这样一来，"网关节点定义一次中间件编排，全集群 Worker 保持一致"
 * 就变成了一次 JSON 下发。
 *
 * ## 安全模型（重要）
 *
 * 蓝图很可能来自**网络**（配置中心、集群同步、控制面下发）。若不加校验地
 * 按名字实例化，等价于把"任意类实例化"的能力交给了下发方。
 *
 * 因此 rebuild() 默认启用白名单：一个名字只有满足以下任一条件才被接受
 *
 * 1. 它是本地注册表中已登记的别名 / 分组 / 工厂；
 * 2. 它是本地可加载、且实现了 PSR-15 `MiddlewareInterface` 的类。
 *
 * 二者都不满足即抛出 4002。需要放宽或收紧时，通过 `$trust` 参数传入自定义判定。
 * 推荐生产环境显式收紧到固定命名空间，例如：
 *
 * ```php
 * $pipeline = Blueprint::fromJson($json)->rebuild(
 *     $container,
 *     trust: fn(string $name) => str_starts_with($name, 'App\\Middleware\\'),
 * );
 * ```
 *
 * @example
 * ```php
 * // 主进程 / 配置中心
 * $json = $pipe->blueprint()->toJson();
 *
 * // Worker 进程 / 其它节点
 * $pipeline = Blueprint::fromJson($json)->rebuild($container);
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Blueprint implements \JsonSerializable
{
    /** @var string 蓝图格式版本，跨版本下发时用于兼容性判断 */
    public const VERSION = '1';

    /**
     * @param list<string> $before 路由前中间件（类名或别名）
     * @param list<string> $after 路由后中间件（类名或别名）
     * @param array<string, string> $aliases 别名 => 中间件类名
     * @param array<string, list<string>> $groups 分组名 => 中间件名列表
     */
    public function __construct(
        private readonly array $before = [],
        private readonly array $after = [],
        private readonly array $aliases = [],
        private readonly array $groups = [],
    ) {
    }

    /**
     * 从 Pipe 构建器的声明中提取蓝图
     *
     * 仅保留字符串声明；中间件实例与闭包无法跨进程传输，会被静默跳过。
     *
     * @param array<int, mixed> $before 路由前中间件声明
     * @param array<int, mixed> $after 路由后中间件声明
     * @param Registry|null $registry 中间件注册表
     * @return self 蓝图实例
     */
    public static function fromPipe(array $before, array $after, ?Registry $registry = null): self
    {
        $aliases = [];
        $groups = [];

        if ($registry !== null) {
            foreach ($registry->allAliases() as $name => $declaration) {
                if (is_string($declaration)) {
                    $aliases[$name] = $declaration;
                }
            }

            foreach ($registry->allGroups() as $name => $items) {
                $strings = array_values(array_filter($items, is_string(...)));
                if ($strings !== []) {
                    $groups[$name] = $strings;
                }
            }
        }

        return new self(
            self::onlyStrings($before),
            self::onlyStrings($after),
            $aliases,
            $groups,
        );
    }

    /**
     * 从数组还原蓝图
     *
     * @param array<string, mixed> $data 蓝图数组
     * @return self 蓝图实例
     * @throws MiddlewareException 数据结构非法时抛出
     */
    public static function fromArray(array $data): self
    {
        $version = \is_string($data['version'] ?? null) ? $data['version'] : self::VERSION;

        if ($version !== self::VERSION) {
            throw MiddlewareException::blueprintFailed(
                "蓝图版本不兼容（收到 {$version}，当前支持 " . self::VERSION . '）'
            );
        }

        /** @var array<string, mixed> $groupsRaw */
        $groupsRaw = is_array($data['groups'] ?? null) ? $data['groups'] : [];
        $groups = [];
        foreach ($groupsRaw as $name => $items) {
            $groups[(string) $name] = is_array($items) ? self::onlyStrings($items) : [];
        }

        /** @var array<string, mixed> $aliasRaw */
        $aliasRaw = is_array($data['aliases'] ?? null) ? $data['aliases'] : [];
        $aliases = [];
        foreach ($aliasRaw as $name => $class) {
            if (is_string($class)) {
                $aliases[(string) $name] = $class;
            }
        }

        return new self(
            self::onlyStrings(is_array($data['before'] ?? null) ? $data['before'] : []),
            self::onlyStrings(is_array($data['after'] ?? null) ? $data['after'] : []),
            $aliases,
            $groups,
        );
    }

    /**
     * 从 JSON 字符串还原蓝图
     *
     * @param string $json JSON 文本
     * @return self 蓝图实例
     * @throws MiddlewareException JSON 非法或结构不符时抛出
     */
    public static function fromJson(string $json): self
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw MiddlewareException::blueprintFailed('JSON 解析失败：' . $e->getMessage());
        }

        return self::fromArray($data);
    }

    /**
     * 在目标环境中重建管道
     *
     * @param ContainerInterface|null $container 目标环境的 PSR-11 容器
     * @param Registry|null $registry 额外的注册表，会与蓝图内的注册信息合并
     * @param \Closure|null $trust 自定义信任判定，形如 fn(string $name): bool；省略时使用默认白名单
     * @return Pipeline 重建出的不可变管道
     * @throws MiddlewareException 中间件声明未通过安全校验或无法解析时抛出
     */
    public function rebuild(
        ?ContainerInterface $container = null,
        ?Registry $registry = null,
        ?\Closure $trust = null,
    ): Pipeline {
        $registry = $this->hydrateRegistry($registry);
        $this->assertTrusted($registry, $container, $trust);

        return Pipeline::of(
            [...$this->before, ...$this->after],
            new Resolver($container, $registry)
        );
    }

    /**
     * 在目标环境中重建 Pipe 构建器
     *
     * 相比 rebuild()，它保留了"路由前 / 路由后"的分段信息，
     * 便于在对端补上本地的路由匹配器与兜底处理器。
     *
     * @param ContainerInterface|null $container 目标环境的 PSR-11 容器
     * @param \Closure|null $trust 自定义信任判定，形如 fn(string $name): bool
     * @return Pipe 构建器实例
     * @throws MiddlewareException 中间件声明未通过安全校验时抛出
     */
    public function rebuildPipe(?ContainerInterface $container = null, ?\Closure $trust = null): Pipe
    {
        $registry = $this->hydrateRegistry(null);
        $this->assertTrusted($registry, $container, $trust);

        return Pipe::create($container, $registry)
            ->beforeRoute(...$this->before)
            ->afterRoute(...$this->after);
    }

    /**
     * 把蓝图内的别名与分组灌入注册表
     *
     * @param Registry|null $registry 目标注册表，省略时新建
     * @return Registry 灌入后的注册表
     * @throws MiddlewareException 蓝图内存在自引用别名 / 分组时抛出
     */
    private function hydrateRegistry(?Registry $registry): Registry
    {
        $registry ??= new Registry();
        $registry->aliases($this->aliases);

        foreach ($this->groups as $name => $items) {
            $registry->group($name, $items);
        }

        return $registry;
    }

    /**
     * 逐个校验蓝图中出现的名字是否可信
     *
     * @param Registry $registry 已灌入蓝图信息的注册表
     * @param ContainerInterface|null $container 目标环境容器
     * @param \Closure|null $trust 自定义信任判定
     * @return void
     * @throws MiddlewareException 存在不可信名字时抛出
     */
    private function assertTrusted(Registry $registry, ?ContainerInterface $container, ?\Closure $trust): void
    {
        $names = [
            ...$this->before,
            ...$this->after,
            ...array_values($this->aliases),
        ];

        foreach ($this->groups as $items) {
            foreach ($items as $item) {
                $names[] = $item;
            }
        }

        foreach (array_unique($names) as $name) {
            if ($this->isTrusted($name, $registry, $container, $trust)) {
                continue;
            }

            throw MiddlewareException::blueprintUnsafeClass($name);
        }
    }

    /**
     * 单个名字的信任判定
     *
     * @param string $name 中间件名字（类名或别名，可带 `:参数` 后缀）
     * @param Registry $registry 注册表
     * @param ContainerInterface|null $container 目标环境容器
     * @param \Closure|null $trust 自定义信任判定
     * @return bool 可信返回 true
     */
    private function isTrusted(
        string $name,
        Registry $registry,
        ?ContainerInterface $container,
        ?\Closure $trust,
    ): bool {
        // 自定义判定优先，允许业务收紧到固定命名空间
        if ($trust !== null) {
            return (bool) $trust($name);
        }

        if ($name === '') {
            return false;
        }

        // 已在本地登记的别名 / 分组 / 工厂，视为本地授权
        if ($registry->has($name)) {
            return true;
        }

        // 容器已显式登记，同样视为本地授权
        if ($container?->has($name) === true) {
            return true;
        }

        // 回退「任意 PSR-15 类」判定前先验类名语法：非法串（含 `:参数` 后缀、
        // 控制字符等）不该喂给 class_exists 触发自动加载器的副作用
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $name)) {
            return false;
        }

        return class_exists($name)
            && is_subclass_of($name, \Psr\Http\Server\MiddlewareInterface::class);
    }

    /**
     * 导出为数组
     *
     * @return array{version: string, before: list<string>, after: list<string>, aliases: array<string, string>, groups: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'before' => $this->before,
            'after' => $this->after,
            'aliases' => $this->aliases,
            'groups' => $this->groups,
        ];
    }

    /**
     * 导出为 JSON 字符串
     *
     * @param int $flags json_encode 标志位
     * @return string JSON 文本
     * @throws MiddlewareException 序列化失败时抛出
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        try {
            return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw MiddlewareException::blueprintFailed('JSON 序列化失败：' . $e->getMessage());
        }
    }

    /**
     * 支持 json_encode() 直接序列化
     *
     * @return array<string, mixed> 蓝图数组
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 蓝图指纹，用于比对不同节点的编排是否一致
     *
     * @return string 32 位十六进制摘要
     */
    public function fingerprint(): string
    {
        return md5(serialize($this->toArray()));
    }

    /**
     * 路由前中间件列表
     *
     * @return list<string> 中间件名列表
     */
    public function before(): array
    {
        return $this->before;
    }

    /**
     * 路由后中间件列表
     *
     * @return list<string> 中间件名列表
     */
    public function after(): array
    {
        return $this->after;
    }

    /**
     * 过滤出数组中的字符串项
     *
     * @param array<array-key, mixed> $items 原始数组
     * @return list<string> 仅含字符串的列表
     */
    private static function onlyStrings(array $items): array
    {
        return array_values(array_filter($items, is_string(...)));
    }
}
