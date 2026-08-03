<?php

declare(strict_types=1);

namespace Kode\Middleware\Exception;

use RuntimeException;
use Throwable;

/**
 * 中间件管道异常
 *
 * 本包对外抛出的唯一异常类型，统一继承 RuntimeException，
 * 通过静态工厂方法生成语义明确的中文错误消息，并携带结构化上下文。
 *
 * 错误码分段：
 * - 1xxx 解析类错误（无法把声明变成中间件实例）
 * - 2xxx 管道执行类错误（返回值非法、终点缺失）
 * - 3xxx 并发类错误（任务失败、等待超时、运行器不可用）
 * - 4xxx 分布式类错误（链路头非法、蓝图还原失败）
 * - 5xxx 内核类错误（启动失败、异常处理器失效、钩子返回值非法）
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class MiddlewareException extends RuntimeException
{
    /** @var array<string, mixed> 附加的结构化上下文，便于日志采集 */
    protected array $context = [];

    /**
     * @param string $message 中文错误消息
     * @param int $code 错误码
     * @param Throwable|null $previous 上游异常
     * @param array<string, mixed> $context 结构化上下文
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * 获取结构化上下文
     *
     * @return array<string, mixed> 上下文数据
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * 中间件声明无法被解析
     *
     * @param mixed $declaration 原始声明
     * @return self 异常实例
     */
    public static function unresolvable(mixed $declaration): self
    {
        $type = get_debug_type($declaration);
        $hint = is_string($declaration) ? "「{$declaration}」" : '';

        return new self(
            "无法解析中间件声明{$hint}（类型 {$type}）；"
            . '可接受的形式为：PSR-15 中间件实例、可调用对象、中间件类名、已注册别名或它们组成的数组',
            1001,
            null,
            ['type' => $type, 'declaration' => is_scalar($declaration) ? $declaration : null]
        );
    }

    /**
     * 类存在但不是合法的 PSR-15 中间件
     *
     * @param string $class 类名
     * @return self 异常实例
     */
    public static function notMiddleware(string $class): self
    {
        return new self(
            "类「{$class}」未实现 Psr\\Http\\Server\\MiddlewareInterface，不能作为中间件使用",
            1002,
            null,
            ['class' => $class]
        );
    }

    /**
     * 容器解析中间件失败
     *
     * @param string $id 容器标识
     * @param Throwable $previous 容器抛出的原始异常
     * @return self 异常实例
     */
    public static function containerFailed(string $id, Throwable $previous): self
    {
        return new self(
            "从容器解析中间件「{$id}」失败：{$previous->getMessage()}",
            1003,
            $previous,
            ['id' => $id]
        );
    }

    /**
     * 别名未注册
     *
     * @param string $alias 别名
     * @return self 异常实例
     */
    public static function aliasNotFound(string $alias): self
    {
        return new self(
            "中间件别名「{$alias}」尚未注册，请先通过 Registry::alias() 或 Registry::group() 注册",
            1004,
            null,
            ['alias' => $alias]
        );
    }

    /**
     * 别名解析出现循环引用
     *
     * 例如 `alias('a', 'b')` 与 `alias('b', 'a')` 互指，或分组包含自身。
     * 若不检测，解析器会无限递归直至栈溢出。
     *
     * @param list<string> $chain 引用链，按解析顺序排列
     * @return self 异常实例
     */
    public static function circularAlias(array $chain): self
    {
        return new self(
            '中间件别名存在循环引用：' . implode(' → ', $chain)
            . '；请检查 Registry 中的别名与分组定义',
            1006,
            null,
            ['chain' => $chain]
        );
    }

    /**
     * 别名解析层级过深
     *
     * @param string $alias 起始别名
     * @param int $limit 最大层级
     * @return self 异常实例
     */
    public static function aliasTooDeep(string $alias, int $limit): self
    {
        return new self(
            "中间件别名「{$alias}」的解析层级超过上限 {$limit}；"
            . '过深的别名链通常意味着配置错误，请适当扁平化',
            1007,
            null,
            ['alias' => $alias, 'limit' => $limit]
        );
    }

    /**
     * 中间件返回了非 ResponseInterface 的值
     *
     * @param string $class 中间件类名
     * @param mixed $returned 实际返回值
     * @return self 异常实例
     */
    public static function invalidResponse(string $class, mixed $returned): self
    {
        return new self(
            "中间件「{$class}」的 process() 必须返回 Psr\\Http\\Message\\ResponseInterface，"
            . '实际返回 ' . get_debug_type($returned),
            2001,
            null,
            ['class' => $class, 'returned' => get_debug_type($returned)]
        );
    }

    /**
     * 管道跑到尽头却没有终点处理器
     *
     * @return self 异常实例
     */
    public static function noDestination(): self
    {
        return new self(
            '管道已执行完全部中间件但没有配置终点处理器；'
            . '请调用 withDestination() 指定终点，或在管道末尾放置能够自行产生响应的中间件',
            2002
        );
    }

    /**
     * 并发任务执行失败
     *
     * @param string $runner 运行器名称
     * @param Throwable $previous 任务内部异常
     * @return self 异常实例
     */
    public static function taskFailed(string $runner, Throwable $previous): self
    {
        return new self(
            "并发任务在「{$runner}」运行器中执行失败：{$previous->getMessage()}",
            3001,
            $previous,
            ['runner' => $runner]
        );
    }

    /**
     * 等待任务超时
     *
     * @param string $runner 运行器名称
     * @param float $timeout 超时秒数
     * @return self 异常实例
     */
    public static function taskTimeout(string $runner, float $timeout): self
    {
        return new self(
            sprintf('等待「%s」运行器的任务超时（%.3f 秒）', $runner, $timeout),
            3002,
            null,
            ['runner' => $runner, 'timeout' => $timeout]
        );
    }

    /**
     * 请求处理超时
     *
     * @param float $timeout 超时秒数
     * @return self 异常实例
     */
    public static function requestTimeout(float $timeout): self
    {
        return new self(
            sprintf('请求处理超过时间预算 %.3f 秒，已被 TimeoutMiddleware 中断', $timeout),
            3003,
            null,
            ['timeout' => $timeout]
        );
    }

    /**
     * 管道蓝图还原失败
     *
     * @param string $reason 失败原因
     * @return self 异常实例
     */
    public static function blueprintFailed(string $reason): self
    {
        return new self(
            "管道蓝图还原失败：{$reason}",
            4001,
            null,
            ['reason' => $reason]
        );
    }

    /**
     * 蓝图中出现不被信任的类名
     *
     * 蓝图可能来自网络（配置中心下发、集群同步），因此还原时必须做白名单校验，
     * 否则等价于允许远端指定任意类被实例化。
     *
     * @param string $class 被拒绝的类名
     * @return self 异常实例
     */
    public static function blueprintUnsafeClass(string $class): self
    {
        return new self(
            "蓝图中的中间件「{$class}」未通过安全校验：它既不是已注册别名，"
            . '也不是实现了 PSR-15 MiddlewareInterface 的可加载类；'
            . '请先在 Registry 中登记，或通过 Blueprint::rebuild() 的白名单参数放行',
            4002,
            null,
            ['class' => $class]
        );
    }

    /**
     * 内核启动回调执行失败
     *
     * @param Throwable $previous 启动回调抛出的原始异常
     * @return self 异常实例
     */
    public static function bootFailed(Throwable $previous): self
    {
        return new self(
            "内核启动失败：{$previous->getMessage()}",
            5001,
            $previous
        );
    }

    /**
     * 内核异常处理器自身抛出了异常
     *
     * 这是最危险的一类故障：兜底逻辑本身失效。此时必须把两个异常一并抛出，
     * 交给最外层（框架 / SAPI）处理，绝不能再吞掉。
     *
     * @param Throwable $original 被兜底的原始异常
     * @param Throwable $thrown 兜底逻辑自身抛出的异常
     * @return self 异常实例
     */
    public static function rescueFailed(Throwable $original, Throwable $thrown): self
    {
        return new self(
            sprintf(
                '内核异常处理器在兜底「%s: %s」时自身抛出了「%s: %s」',
                $original::class,
                $original->getMessage(),
                $thrown::class,
                $thrown->getMessage()
            ),
            5002,
            $thrown,
            ['original' => $original::class, 'thrown' => $thrown::class]
        );
    }

    /**
     * 生命周期钩子返回了非法类型
     *
     * @param string $hook 钩子名称
     * @param string $expected 期望类型
     * @param mixed $returned 实际返回值
     * @return self 异常实例
     */
    public static function invalidHookReturn(string $hook, string $expected, mixed $returned): self
    {
        return new self(
            "内核钩子「{$hook}」必须返回 {$expected} 或 null，实际返回 " . get_debug_type($returned),
            5003,
            null,
            ['hook' => $hook, 'expected' => $expected, 'returned' => get_debug_type($returned)]
        );
    }
}
