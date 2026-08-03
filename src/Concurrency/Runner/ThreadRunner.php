<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency\Runner;

use Kode\Middleware\Contract\RunnerInterface;
use Kode\Middleware\Contract\TaskHandleInterface;
use Kode\Middleware\Exception\MiddlewareException;

/**
 * 多线程运行器（基于 ext-parallel / kode/parallel）
 *
 * 适用于 CPU 密集型任务：图像处理、加解密、大批量序列化、模板编译等。
 * 与协程不同，线程能真正利用多核，代价是任务闭包必须是**自包含**的。
 *
 * 使用约束（ext-parallel 的硬性限制，不是本包引入的）：
 * - 任务闭包不能捕获资源型变量（连接、文件句柄、闭包）；
 * - 传参与返回值必须可序列化；
 * - 需要 ZTS 构建的 PHP 且已安装 ext-parallel。
 *
 * 环境不满足时 supported() 返回 false，由 RunnerFactory 自动降级为
 * FiberRunner 或 SyncRunner，业务代码无需改动。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ThreadRunner implements RunnerInterface
{
    /** @var string kode/parallel 线程池类名 */
    private const KODE_THREAD_POOL = 'Kode\\Parallel\\Thread\\ThreadPool';

    /** @var string kode/parallel 运行时类名 */
    private const KODE_RUNTIME = 'Kode\\Parallel\\Runtime\\Runtime';

    /** @var object|null 惰性创建的线程池 / 运行时实例 */
    private ?object $pool = null;

    /**
     * @param int $minWorkers 最小工作线程数
     * @param int $maxWorkers 最大工作线程数
     * @param string|null $bootstrap 线程引导文件（通常是 vendor/autoload.php）
     */
    public function __construct(
        private readonly int $minWorkers = 4,
        private readonly int $maxWorkers = 16,
        private readonly ?string $bootstrap = null,
    ) {
    }

    /**
     * 运行器名称
     *
     * @return string 固定返回 thread
     */
    public function name(): string
    {
        return 'thread';
    }

    /**
     * 是否可用
     *
     * @return bool 已加载 ext-parallel 且存在 kode/parallel 时返回 true
     */
    public function supported(): bool
    {
        return extension_loaded('parallel')
            && (class_exists(self::KODE_THREAD_POOL) || class_exists(self::KODE_RUNTIME));
    }

    /**
     * 提交任务
     *
     * @param \Closure $task 待执行任务，必须自包含
     * @param array<int, mixed> $args 任务参数，必须可序列化
     * @return TaskHandleInterface 任务句柄
     */
    public function submit(\Closure $task, array $args = []): TaskHandleInterface
    {
        if (!$this->supported()) {
            return (new SyncRunner())->submit($task, $args);
        }

        try {
            $future = $this->dispatch($task, $args);
        } catch (\Throwable $e) {
            return TaskHandle::failed($e, $this->name());
        }

        if ($future === null) {
            return (new SyncRunner())->submit($task, $args);
        }

        return new TaskHandle(
            fn (?float $timeout): mixed => $this->awaitFuture($future, $timeout),
            $this->name(),
            static fn (): bool => method_exists($future, 'done') && $future->done() === true,
        );
    }

    /**
     * 批量提交并等待全部完成
     *
     * 先全部投递、再统一收敛，从而获得真正的并行度。
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
            $results[$key] = $handle->await($timeout);
        }

        return $results;
    }

    /**
     * 关闭线程池
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->pool === null) {
            return;
        }

        try {
            if (method_exists($this->pool, 'shutdown')) {
                $this->pool->shutdown();
            } elseif (method_exists($this->pool, 'close')) {
                $this->pool->close();
            }
        } catch (\Throwable) {
            // 关闭失败不应影响主流程
        }

        $this->pool = null;
    }

    /**
     * 投递任务到线程池 / 运行时
     *
     * @param \Closure $task 任务
     * @param array<int, mixed> $args 任务参数
     * @return object|null kode/parallel 的 Future 对象，无法投递时返回 null
     */
    private function dispatch(\Closure $task, array $args): ?object
    {
        $pool = $this->pool();

        if ($pool !== null && method_exists($pool, 'submit')) {
            /** @var object|null $future */
            $future = $pool->submit($task, $args);

            return $future;
        }

        if ($pool !== null && method_exists($pool, 'run')) {
            /** @var object|null $future */
            $future = $pool->run($task, $args);

            return $future;
        }

        return null;
    }

    /**
     * 惰性创建线程池 / 运行时
     *
     * @return object|null 池对象，创建失败返回 null
     */
    private function pool(): ?object
    {
        if ($this->pool !== null) {
            return $this->pool;
        }

        try {
            if (class_exists(self::KODE_THREAD_POOL)) {
                /** @var object $pool */
                $pool = new (self::KODE_THREAD_POOL)($this->minWorkers, $this->maxWorkers);

                if (method_exists($pool, 'start')) {
                    $pool->start();
                }

                return $this->pool = $pool;
            }

            if (class_exists(self::KODE_RUNTIME)) {
                /** @var object $runtime */
                $runtime = new (self::KODE_RUNTIME)($this->bootstrap);

                return $this->pool = $runtime;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * 等待 kode/parallel Future 完成
     *
     * @param object $future Future 对象
     * @param float|null $timeout 超时秒数
     * @return mixed 任务结果
     * @throws MiddlewareException 等待超时或任务失败时抛出
     */
    private function awaitFuture(object $future, ?float $timeout): mixed
    {
        try {
            if ($timeout !== null && $timeout > 0 && method_exists($future, 'wait')) {
                if ($future->wait((int) ($timeout * 1000)) !== true) {
                    throw MiddlewareException::taskTimeout($this->name(), $timeout);
                }
            }

            if (method_exists($future, 'get')) {
                return $future->get();
            }

            if (method_exists($future, 'value')) {
                return $future->value();
            }
        } catch (MiddlewareException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw MiddlewareException::taskFailed($this->name(), $e);
        }

        return null;
    }
}
