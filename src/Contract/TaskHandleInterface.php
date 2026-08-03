<?php

declare(strict_types=1);

namespace Kode\Middleware\Contract;

use Kode\Middleware\Exception\MiddlewareException;

/**
 * 并发任务句柄契约
 *
 * 对 Future / Promise 的最小化抽象，只保留中间件场景真正需要的三个动作：
 * 判断完成、阻塞取值、带超时等待。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
interface TaskHandleInterface
{
    /**
     * 任务是否已完成（无论成功或失败）
     *
     * @return bool 已完成返回 true
     */
    public function done(): bool;

    /**
     * 阻塞获取任务结果
     *
     * @param float|null $timeout 超时秒数，null 表示一直等待
     * @return mixed 任务返回值
     * @throws MiddlewareException 任务执行失败或等待超时时抛出
     */
    public function await(?float $timeout = null): mixed;

    /**
     * 非阻塞获取任务结果
     *
     * @return mixed 已完成时返回结果，未完成返回 null
     */
    public function poll(): mixed;
}
