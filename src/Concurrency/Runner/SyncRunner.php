<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency\Runner;

use Kode\Middleware\Contract\RunnerInterface;
use Kode\Middleware\Contract\TaskHandleInterface;

/**
 * 同步运行器（保底实现）
 *
 * 任务在 submit() 时立即串行执行。它的存在不是为了性能，
 * 而是为了让业务代码只写一套：无论线上跑在 Swoole 协程、ext-parallel 线程，
 * 还是本地 php-fpm 裸环境，`$runner->all([...])` 的语义完全一致，
 * 差别只是并行还是串行。
 *
 * 单元测试与 CI 环境通常也落在这个运行器上，因此它必须永远可用。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class SyncRunner implements RunnerInterface
{
    /**
     * 运行器名称
     *
     * @return string 固定返回 sync
     */
    public function name(): string
    {
        return 'sync';
    }

    /**
     * 是否可用
     *
     * @return bool 恒为 true，同步运行器无任何环境依赖
     */
    public function supported(): bool
    {
        return true;
    }

    /**
     * 提交任务：立即执行
     *
     * @param \Closure $task 待执行任务
     * @param array<int, mixed> $args 任务参数
     * @return TaskHandleInterface 已完成或已失败的任务句柄
     */
    public function submit(\Closure $task, array $args = []): TaskHandleInterface
    {
        try {
            return TaskHandle::completed($task(...$args), $this->name());
        } catch (\Throwable $e) {
            return TaskHandle::failed($e, $this->name());
        }
    }

    /**
     * 批量提交并等待全部完成
     *
     * @param array<array-key, \Closure> $tasks 任务集合
     * @param float|null $timeout 超时秒数（同步执行下不生效）
     * @return array<array-key, mixed> 结果集合，键与入参一致
     */
    public function all(array $tasks, ?float $timeout = null): array
    {
        $results = [];

        foreach ($tasks as $key => $task) {
            $results[$key] = $this->submit($task)->await($timeout);
        }

        return $results;
    }

    /**
     * 释放资源（同步运行器无资源可释放）
     *
     * @return void
     */
    public function close(): void
    {
        // 无需释放
    }
}
