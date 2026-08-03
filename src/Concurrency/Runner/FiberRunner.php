<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency\Runner;

use Kode\Middleware\Contract\RunnerInterface;
use Kode\Middleware\Contract\TaskHandleInterface;
use Kode\Middleware\Exception\MiddlewareException;

/**
 * Fiber 协程运行器
 *
 * 适用于 I/O 密集型任务（数据库、HTTP 调用、缓存），单进程内并发等待，
 * 是中间件里做"并行拉取多个下游服务"的首选。
 *
 * 三级适配，自动选择当前环境最优路径：
 * 1. **kode/fibers**：存在 `Kode\Fibers\Fibers` 时走它的协程池，
 *    可复用调度器、支持超时与批量并发；
 * 2. **原生 \Fiber**：PHP 8.1+ 内置，手工 start / resume 直到协程结束；
 * 3. **同步降级**：理论上不会走到（PHP 8.3 必然有 Fiber），保留为防御性分支。
 *
 * 注意：原生 Fiber 只提供"可挂起的执行单元"，真正的并行等待需要
 * I/O 层配合挂起（如 Swoole 协程客户端）。纯阻塞式 I/O 在原生 Fiber 下
 * 仍是串行的——这一点由 kode/fibers 的调度器负责改善。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class FiberRunner implements RunnerInterface
{
    /** @var string kode/fibers 门面类名 */
    private const KODE_FIBERS = 'Kode\\Fibers\\Fibers';

    /**
     * @param float|null $timeout 默认超时秒数，null 表示不限制
     * @param bool $preferKodeFibers 是否优先使用 kode/fibers（存在时）
     */
    public function __construct(
        private readonly ?float $timeout = null,
        private readonly bool $preferKodeFibers = true,
    ) {
    }

    /**
     * 运行器名称
     *
     * @return string 固定返回 fiber
     */
    public function name(): string
    {
        return 'fiber';
    }

    /**
     * 是否可用
     *
     * @return bool PHP 具备 Fiber 类即返回 true
     */
    public function supported(): bool
    {
        return class_exists(\Fiber::class);
    }

    /**
     * 提交任务
     *
     * @param \Closure $task 待执行任务
     * @param array<int, mixed> $args 任务参数
     * @return TaskHandleInterface 任务句柄
     */
    public function submit(\Closure $task, array $args = []): TaskHandleInterface
    {
        if (!$this->supported()) {
            return (new SyncRunner())->submit($task, $args);
        }

        if ($this->useKodeFibers()) {
            return new TaskHandle(
                fn (?float $timeout): mixed => $this->runViaKodeFibers($task, $args, $timeout),
                $this->name()
            );
        }

        return new TaskHandle(
            fn (?float $timeout): mixed => $this->runViaNativeFiber($task, $args),
            $this->name()
        );
    }

    /**
     * 批量提交并等待全部完成
     *
     * kode/fibers 可用时走其 concurrent() 真正并发；
     * 否则逐个启动原生 Fiber，再统一收敛结果。
     *
     * @param array<array-key, \Closure> $tasks 任务集合
     * @param float|null $timeout 整体超时秒数
     * @return array<array-key, mixed> 结果集合，键与入参一致
     * @throws MiddlewareException 任务失败或超时时抛出
     */
    public function all(array $tasks, ?float $timeout = null): array
    {
        if ($tasks === [] || !$this->supported()) {
            return (new SyncRunner())->all($tasks, $timeout);
        }

        $timeout ??= $this->timeout;

        // @phpstan-ignore-next-line kode/fibers 为可选兄弟包，运行期才确定是否存在
        if ($this->useKodeFibers() && method_exists(self::KODE_FIBERS, 'concurrent')) {
            try {
                /** @var array<array-key, mixed> $results */
                $results = (self::KODE_FIBERS)::concurrent($tasks, $timeout);

                return $results;
            } catch (\Throwable $e) {
                throw MiddlewareException::taskFailed($this->name(), $e);
            }
        }

        // 原生 Fiber：先全部 start，再逐个 resume 至结束
        $fibers = [];
        foreach ($tasks as $key => $task) {
            $fibers[$key] = new \Fiber($task);
        }

        $results = [];
        foreach ($fibers as $key => $fiber) {
            try {
                $fiber->start();

                while (!$fiber->isTerminated()) {
                    $fiber->resume();
                }

                $results[$key] = $fiber->getReturn();
            } catch (\Throwable $e) {
                throw MiddlewareException::taskFailed($this->name(), $e);
            }
        }

        return $results;
    }

    /**
     * 释放资源
     *
     * @return void
     */
    public function close(): void
    {
        // 协程随请求结束自然回收，无需显式释放
    }

    /**
     * 是否应当走 kode/fibers 路径
     *
     * @return bool 可用且被允许时返回 true
     */
    private function useKodeFibers(): bool
    {
        return $this->preferKodeFibers && class_exists(self::KODE_FIBERS);
    }

    /**
     * 通过 kode/fibers 执行任务
     *
     * @param \Closure $task 任务
     * @param array<int, mixed> $args 任务参数
     * @param float|null $timeout 超时秒数
     * @return mixed 任务结果
     * @throws MiddlewareException 执行失败时抛出
     */
    private function runViaKodeFibers(\Closure $task, array $args, ?float $timeout): mixed
    {
        $bound = $args === [] ? $task : static fn (): mixed => $task(...$args);

        try {
            return (self::KODE_FIBERS)::run($bound, $timeout ?? $this->timeout);
        } catch (\Throwable $e) {
            throw MiddlewareException::taskFailed($this->name(), $e);
        }
    }

    /**
     * 通过原生 \Fiber 执行任务
     *
     * @param \Closure $task 任务
     * @param array<int, mixed> $args 任务参数
     * @return mixed 任务结果
     * @throws MiddlewareException 执行失败时抛出
     */
    private function runViaNativeFiber(\Closure $task, array $args): mixed
    {
        $fiber = new \Fiber($task);

        try {
            $fiber->start(...$args);

            while (!$fiber->isTerminated()) {
                $fiber->resume();
            }

            return $fiber->getReturn();
        } catch (\Throwable $e) {
            throw MiddlewareException::taskFailed($this->name(), $e);
        }
    }
}
