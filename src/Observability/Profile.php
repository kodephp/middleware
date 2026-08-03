<?php

declare(strict_types=1);

namespace Kode\Middleware\Observability;

/**
 * 请求级耗时剖析收集器
 *
 * 洋葱模型天然是嵌套的，因此耗时也应该按嵌套关系呈现：外层的耗时必然
 * 包含内层的耗时，「外层耗时 − 内层耗时」才是这一层自己的开销（自耗时）。
 * 本类就是为记录这种层级关系而生。
 *
 * ## 生命周期与并发
 *
 * 每个请求对应**一个**实例，通过请求属性 `kode.profile` 在洋葱各层之间传递。
 * 由于 PSR-7 的 `withAttribute()` 只复制消息本身、不复制属性里的对象引用，
 * 因此内外层拿到的是同一个收集器，无需任何全局状态。
 *
 * 请求之间彼此隔离，所以在多进程 / 多线程 / 协程环境下都是安全的；
 * 但**同一请求内部**若把同一个 Profile 交给并发子任务同时写入，
 * 则需自行保证串行——常规洋葱调用链是严格串行的，不受影响。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Profile
{
    /** @var array<int, array{name: string, depth: int, start: float, duration: float|null}> 全部区段 */
    private array $spans = [];

    /** @var int 当前嵌套深度 */
    private int $depth = 0;

    /** @var int 自增区段编号 */
    private int $nextId = 0;

    /**
     * 进入一个计时区段
     *
     * @param string $name 区段名称，建议用洋葱层名，例如 pipeline / auth / controller
     * @return int 区段编号，用于配对调用 leave()
     */
    public function enter(string $name): int
    {
        $id = $this->nextId++;

        $this->spans[$id] = [
            'name' => $name,
            'depth' => $this->depth++,
            'start' => microtime(true),
            'duration' => null,
        ];

        return $id;
    }

    /**
     * 离开一个计时区段
     *
     * 重复调用同一编号不会产生副作用，便于放在 finally 中无脑收口。
     *
     * @param int $id enter() 返回的区段编号
     * @return float 本区段耗时（秒）
     */
    public function leave(int $id): float
    {
        if (!isset($this->spans[$id]) || $this->spans[$id]['duration'] !== null) {
            return $this->spans[$id]['duration'] ?? 0.0;
        }

        $duration = microtime(true) - $this->spans[$id]['start'];
        $this->spans[$id]['duration'] = $duration;
        $this->depth = max(0, $this->depth - 1);

        return $duration;
    }

    /**
     * 获取全部区段（按进入顺序）
     *
     * @return list<array{name: string, depth: int, duration: float}> 区段列表，未结束的区段计 0
     */
    public function spans(): array
    {
        $result = [];

        foreach ($this->spans as $span) {
            $result[] = [
                'name' => $span['name'],
                'depth' => $span['depth'],
                'duration' => $span['duration'] ?? 0.0,
            ];
        }

        return $result;
    }

    /**
     * 最外层区段的总耗时（秒）
     *
     * @return float 总耗时，无区段时为 0
     */
    public function total(): float
    {
        foreach ($this->spans as $span) {
            if ($span['depth'] === 0) {
                return $span['duration'] ?? 0.0;
            }
        }

        return 0.0;
    }

    /**
     * 导出为 W3C Server-Timing 响应头的值
     *
     * 浏览器开发者工具可直接把它渲染成瀑布图，无需接入 APM 即可肉眼定位慢层。
     *
     * @param int $precision 毫秒保留小数位
     * @return string 形如 `pipeline;dur=12.30, auth;dur=3.10`
     */
    public function toServerTiming(int $precision = 2): string
    {
        $parts = [];

        foreach ($this->spans() as $index => $span) {
            // 名称需符合 HTTP token 规范：仅保留字母数字与下划线短横，并附加序号以区分同名层
            $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $span['name']) ?? 'span';
            $parts[] = sprintf(
                '%s_%d;dur=%.' . $precision . 'F',
                $name,
                $index,
                $span['duration'] * 1000
            );
        }

        return implode(', ', $parts);
    }

    /**
     * 导出为可读的层级文本，便于日志打印
     *
     * @return string 每行一个区段，按深度缩进
     */
    public function toText(): string
    {
        $lines = [];

        foreach ($this->spans() as $span) {
            $lines[] = sprintf(
                '%s%s %.3f ms',
                str_repeat('  ', $span['depth']),
                $span['name'],
                $span['duration'] * 1000
            );
        }

        return implode(PHP_EOL, $lines);
    }
}
