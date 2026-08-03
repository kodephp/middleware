<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency\Runner;

use Kode\Middleware\Contract\TaskHandleInterface;
use Kode\Middleware\Exception\MiddlewareException;

/**
 * 通用任务句柄
 *
 * 用一个"求值闭包"统一表达四种运行器的取值语义：
 * - 同步运行器：任务已执行完，求值闭包直接返回缓存结果
 * - 协程 / 线程 / 进程运行器：求值闭包内部去 join / wait 底层的 Future
 *
 * 求值结果会被缓存，多次 await() 只会真正等待一次；
 * 任务抛出的异常同样会被缓存并在每次 await() 时重新抛出，
 * 避免"第一次报错、第二次静默返回 null"这类难以排查的行为。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class TaskHandle implements TaskHandleInterface
{
    /** @var bool 是否已完成求值 */
    private bool $settled = false;

    /** @var mixed 缓存的成功结果 */
    private mixed $value = null;

    /** @var \Throwable|null 缓存的失败异常 */
    private ?\Throwable $error = null;

    /**
     * @param \Closure $evaluator 求值闭包，形如 fn(?float $timeout): mixed
     * @param string $runner 运行器名称，用于错误信息
     * @param \Closure|null $checker 完成状态探测闭包，形如 fn(): bool
     */
    public function __construct(
        private readonly \Closure $evaluator,
        private readonly string $runner = 'sync',
        private readonly ?\Closure $checker = null,
    ) {
    }

    /**
     * 创建一个已完成的句柄（同步结果）
     *
     * @param mixed $value 结果值
     * @param string $runner 运行器名称
     * @return self 任务句柄
     */
    public static function completed(mixed $value, string $runner = 'sync'): self
    {
        $handle = new self(static fn (): mixed => $value, $runner);
        $handle->settled = true;
        $handle->value = $value;

        return $handle;
    }

    /**
     * 创建一个已失败的句柄
     *
     * @param \Throwable $error 失败异常
     * @param string $runner 运行器名称
     * @return self 任务句柄
     */
    public static function failed(\Throwable $error, string $runner = 'sync'): self
    {
        $handle = new self(static fn (): mixed => throw $error, $runner);
        $handle->settled = true;
        $handle->error = $error;

        return $handle;
    }

    /**
     * 任务是否已完成
     *
     * @return bool 已完成返回 true
     */
    public function done(): bool
    {
        if ($this->settled) {
            return true;
        }

        return $this->checker !== null && ($this->checker)() === true;
    }

    /**
     * 阻塞获取任务结果
     *
     * @param float|null $timeout 超时秒数，null 表示一直等待
     * @return mixed 任务返回值
     * @throws MiddlewareException 任务执行失败或等待超时时抛出
     */
    public function await(?float $timeout = null): mixed
    {
        if ($this->settled) {
            if ($this->error !== null) {
                throw $this->error instanceof MiddlewareException
                    ? $this->error
                    : MiddlewareException::taskFailed($this->runner, $this->error);
            }

            return $this->value;
        }

        try {
            $this->value = ($this->evaluator)($timeout);
        } catch (MiddlewareException $e) {
            $this->settled = true;
            $this->error = $e;

            throw $e;
        } catch (\Throwable $e) {
            $this->settled = true;
            $this->error = $e;

            throw MiddlewareException::taskFailed($this->runner, $e);
        }

        $this->settled = true;

        return $this->value;
    }

    /**
     * 非阻塞获取任务结果
     *
     * @return mixed 已完成时返回结果，未完成返回 null
     */
    public function poll(): mixed
    {
        if (!$this->done()) {
            return null;
        }

        try {
            return $this->await(0.0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 所属运行器名称
     *
     * @return string 运行器名称
     */
    public function runner(): string
    {
        return $this->runner;
    }
}
