<?php

declare(strict_types=1);

namespace Kode\Middleware\Contract;

/**
 * 并发运行器契约
 *
 * 把"任务如何被并发执行"从中间件里抽离出来，屏蔽底层差异：
 * 同步、Fiber 协程、多线程（ext-parallel）、多进程（fork / 进程池）
 * 对调用方而言是同一套 submit / await 语义。
 *
 * 所有实现都必须保证：即便底层扩展缺失，也能通过 supported() 提前告知，
 * 由 RunnerFactory 自动降级到 SyncRunner，绝不在运行期抛出致命错误。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
interface RunnerInterface
{
    /**
     * 运行器名称
     *
     * @return string sync / fiber / thread / process 之一
     */
    public function name(): string;

    /**
     * 当前环境是否支持该运行器
     *
     * @return bool 支持返回 true
     */
    public function supported(): bool;

    /**
     * 提交一个任务
     *
     * 任务不保证立即执行：同步运行器会立即求值，协程 / 线程 / 进程运行器
     * 则可能延迟到 await() 时才真正开始或结束。
     *
     * @param \Closure $task 待执行任务
     * @param array<int, mixed> $args 传递给任务的参数
     * @return TaskHandleInterface 任务句柄，用于取结果
     */
    public function submit(\Closure $task, array $args = []): TaskHandleInterface;

    /**
     * 批量提交并等待全部完成
     *
     * @param array<array-key, \Closure> $tasks 任务集合，键会原样保留在结果中
     * @param float|null $timeout 整体超时秒数，null 表示不限制
     * @return array<array-key, mixed> 与入参键一一对应的结果集合
     */
    public function all(array $tasks, ?float $timeout = null): array;

    /**
     * 释放运行器持有的资源
     *
     * 常驻进程下应在 Worker 退出前调用，避免线程 / 子进程泄漏。
     *
     * @return void
     */
    public function close(): void;
}
