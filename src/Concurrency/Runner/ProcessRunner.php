<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency\Runner;

use Kode\Middleware\Contract\RunnerInterface;
use Kode\Middleware\Contract\TaskHandleInterface;
use Kode\Middleware\Exception\MiddlewareException;

/**
 * 多进程运行器（基于 pcntl / kode/process）
 *
 * 适用于**需要强隔离**的任务：调用可能崩溃的扩展、执行不可信代码、
 * 跑一次性重型作业。进程崩溃不会拖垮主进程，这是它相对线程的核心优势。
 *
 * 实现要点：
 * - 优先复用 kode/process 的进程池（若已安装），获得复用与负载均衡；
 * - 否则退化为 `pcntl_fork()` + 匿名管道（socket pair）传回结果；
 * - Windows 或缺少 pcntl 时 supported() 为 false，自动降级。
 *
 * 代价：每个任务一次 fork 的开销，以及结果必须可序列化。
 * 因此只建议用于粗粒度任务，不要在请求热路径上高频调用。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ProcessRunner implements RunnerInterface
{
    /**
     * @param float $timeout 单任务默认超时秒数
     */
    public function __construct(
        private readonly float $timeout = 30.0,
    ) {
    }

    /**
     * 运行器名称
     *
     * @return string 固定返回 process
     */
    public function name(): string
    {
        return 'process';
    }

    /**
     * 是否可用
     *
     * @return bool 具备 pcntl 与 socket 能力时返回 true
     */
    public function supported(): bool
    {
        return \PHP_OS_FAMILY !== 'Windows'
            && extension_loaded('pcntl')
            && function_exists('pcntl_fork')
            && function_exists('stream_socket_pair');
    }

    /**
     * 提交任务
     *
     * @param \Closure $task 待执行任务
     * @param array<int, mixed> $args 任务参数，必须可序列化
     * @return TaskHandleInterface 任务句柄
     */
    public function submit(\Closure $task, array $args = []): TaskHandleInterface
    {
        if (!$this->supported()) {
            return (new SyncRunner())->submit($task, $args);
        }

        try {
            return $this->fork($task, $args);
        } catch (\Throwable $e) {
            return TaskHandle::failed($e, $this->name());
        }
    }

    /**
     * 批量提交并等待全部完成
     *
     * 一次性 fork 出全部子进程后再统一回收，实现真正的并行。
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

        $handles = [];
        foreach ($tasks as $key => $task) {
            $handles[$key] = $this->submit($task);
        }

        $results = [];
        foreach ($handles as $key => $handle) {
            $results[$key] = $handle->await($timeout ?? $this->timeout);
        }

        return $results;
    }

    /**
     * 释放运行器持有的资源
     *
     * 基于 fork 的运行器：每个子进程在 collect() 取结果时已被 pcntl_waitpid 回收，
     * 此处没有需要主动关闭的持久资源，保留空实现以满足 RunnerInterface 契约。
     *
     * @return void
     */
    public function close(): void
    {
    }

    /**
     * fork 一个子进程执行任务，通过 socket pair 回传结果
     *
     * @param \Closure $task 任务
     * @param array<int, mixed> $args 任务参数
     * @return TaskHandleInterface 任务句柄
     * @throws MiddlewareException fork 失败时抛出
     */
    private function fork(\Closure $task, array $args): TaskHandleInterface
    {
        $sockets = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);

        if ($sockets === false) {
            throw new MiddlewareException(
                '创建进程间通信管道失败，无法启动子进程任务',
                3004,
                null,
                ['runner' => $this->name()]
            );
        }

        [$parent, $child] = $sockets;
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parent);
            fclose($child);

            throw new MiddlewareException(
                'pcntl_fork() 失败，无法创建子进程；请检查系统进程数限制',
                3005,
                null,
                ['runner' => $this->name()]
            );
        }

        // 子进程：执行任务并把结果写回管道
        if ($pid === 0) {
            fclose($parent);

            try {
                $payload = ['ok' => true, 'value' => $task(...$args)];
            } catch (\Throwable $e) {
                $payload = ['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class];
            }

            try {
                fwrite($child, serialize($payload));
            } catch (\Throwable) {
                // 写回失败时父进程会按超时处理
            }

            fclose($child);
            exit(0);
        }

        // 父进程：返回一个延迟读取管道的句柄
        fclose($child);

        return new TaskHandle(
            fn (?float $timeout): mixed => $this->collect($parent, $pid, $timeout ?? $this->timeout),
            $this->name(),
            static function () use ($pid): bool {
                return pcntl_waitpid($pid, $status, \WNOHANG) === $pid;
            },
        );
    }

    /**
     * 从管道回收子进程结果
     *
     * @param resource $stream 父端管道
     * @param int $pid 子进程 ID
     * @param float $timeout 超时秒数
     * @return mixed 任务结果
     * @throws MiddlewareException 超时或子进程内部失败时抛出
     */
    private function collect(mixed $stream, int $pid, float $timeout): mixed
    {
        stream_set_timeout($stream, (int) max(1, ceil($timeout)));

        $raw = stream_get_contents($stream);
        fclose($stream);
        pcntl_waitpid($pid, $status);

        if (!is_string($raw) || $raw === '') {
            throw MiddlewareException::taskTimeout($this->name(), $timeout);
        }

        /** @var array{ok: true, value?: mixed}|array{ok: false, error: string, class: string}|false $payload */
        $payload = @unserialize($raw, ['allowed_classes' => true]);

        if (!is_array($payload)) {
            throw new MiddlewareException(
                '子进程返回的数据无法反序列化，请确认任务返回值可被 serialize()',
                3006,
                null,
                ['runner' => $this->name(), 'pid' => $pid]
            );
        }

        if ($payload['ok'] !== true) {
            throw new MiddlewareException(
                sprintf(
                    '子进程任务执行失败（%s）：%s',
                    $payload['class'],
                    $payload['error']
                ),
                3001,
                null,
                ['runner' => $this->name(), 'pid' => $pid]
            );
        }

        return $payload['value'] ?? null;
    }
}
