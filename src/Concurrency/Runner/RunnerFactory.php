<?php

declare(strict_types=1);

namespace Kode\Middleware\Concurrency\Runner;

use Kode\Middleware\Contract\RunnerInterface;

/**
 * 运行器工厂
 *
 * 负责"按名字取运行器"与"自动挑选最佳运行器"两件事，
 * 并保证**任何情况下都能返回一个可用的运行器**——最差也是 SyncRunner。
 * 业务代码因此永远不需要写 `if (extension_loaded('parallel'))` 这类分支。
 *
 * 自动挑选策略（driver = auto）按任务类型区分：
 * - **io**（默认）：Fiber → Sync。I/O 密集用协程最划算，无需跨进程序列化。
 * - **cpu**：Thread → Process → Sync。CPU 密集必须真并行，线程优于进程。
 * - **isolate**：Process → Thread → Sync。强调故障隔离时进程优先。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RunnerFactory
{
    /** @var string 驱动：自动选择 */
    public const DRIVER_AUTO = 'auto';

    /** @var string 驱动：同步 */
    public const DRIVER_SYNC = 'sync';

    /** @var string 驱动：Fiber 协程 */
    public const DRIVER_FIBER = 'fiber';

    /** @var string 驱动：多线程 */
    public const DRIVER_THREAD = 'thread';

    /** @var string 驱动：多进程 */
    public const DRIVER_PROCESS = 'process';

    /** @var string 任务画像：I/O 密集 */
    public const PROFILE_IO = 'io';

    /** @var string 任务画像：CPU 密集 */
    public const PROFILE_CPU = 'cpu';

    /** @var string 任务画像：需要强隔离 */
    public const PROFILE_ISOLATE = 'isolate';

    /** @var array<string, RunnerInterface> 运行器实例缓存 */
    private static array $cache = [];

    /**
     * 按驱动名创建运行器（不可用时自动降级）
     *
     * @param string $driver 驱动名，取 DRIVER_* 之一
     * @param string $profile 任务画像，driver=auto 时决定选择顺序
     * @return RunnerInterface 可用的运行器
     */
    public static function make(string $driver = self::DRIVER_AUTO, string $profile = self::PROFILE_IO): RunnerInterface
    {
        $key = $driver . '|' . $profile;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $runner = match ($driver) {
            self::DRIVER_SYNC => new SyncRunner(),
            self::DRIVER_FIBER => new FiberRunner(),
            self::DRIVER_THREAD => new ThreadRunner(),
            self::DRIVER_PROCESS => new ProcessRunner(),
            default => self::auto($profile),
        };

        // 显式指定但环境不支持时，静默降级为同步
        if (!$runner->supported()) {
            $runner = new SyncRunner();
        }

        return self::$cache[$key] = $runner;
    }

    /**
     * 按任务画像自动挑选最佳可用运行器
     *
     * @param string $profile 任务画像，取 PROFILE_* 之一
     * @return RunnerInterface 可用的运行器
     */
    public static function auto(string $profile = self::PROFILE_IO): RunnerInterface
    {
        $candidates = match ($profile) {
            self::PROFILE_CPU => [new ThreadRunner(), new ProcessRunner(), new FiberRunner()],
            self::PROFILE_ISOLATE => [new ProcessRunner(), new ThreadRunner()],
            default => [new FiberRunner()],
        };

        foreach ($candidates as $runner) {
            if ($runner->supported()) {
                return $runner;
            }
        }

        return new SyncRunner();
    }

    /**
     * 探测当前环境支持哪些运行器
     *
     * 便于在健康检查接口或启动日志里输出并发能力画像。
     *
     * @return array<string, bool> 驱动名 => 是否可用
     */
    public static function capabilities(): array
    {
        return [
            self::DRIVER_SYNC => true,
            self::DRIVER_FIBER => (new FiberRunner())->supported(),
            self::DRIVER_THREAD => (new ThreadRunner())->supported(),
            self::DRIVER_PROCESS => (new ProcessRunner())->supported(),
        ];
    }

    /**
     * 清空运行器缓存并释放其资源
     *
     * 常驻进程下 Worker 退出前应调用，避免线程 / 子进程泄漏。
     *
     * @return void
     */
    public static function reset(): void
    {
        foreach (self::$cache as $runner) {
            $runner->close();
        }

        self::$cache = [];
    }
}
