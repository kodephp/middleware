<?php

declare(strict_types=1);

namespace Kode\Middleware\Distributed;

/**
 * 节点身份
 *
 * 在分布式部署下回答"这个响应是哪台机器、哪个进程产生的"。
 * 排查线上问题时，这往往比任何日志都更快定位到故障实例。
 *
 * 节点 ID 解析优先级：
 * 1. 构造函数显式传入
 * 2. 环境变量 KODE_NODE_ID（K8s 下可注入 Pod 名）
 * 3. 环境变量 HOSTNAME
 * 4. gethostname() 返回值
 * 5. 兜底 'unknown'
 *
 * 结果在进程内缓存，避免每请求重复读取环境。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class NodeIdentity
{
    /** @var string 环境变量名：节点 ID */
    public const ENV_NODE_ID = 'KODE_NODE_ID';

    /** @var string|null 进程内缓存的节点 ID */
    private static ?string $cached = null;

    /**
     * @param string|null $nodeId 显式指定的节点 ID
     */
    public function __construct(private readonly ?string $nodeId = null)
    {
    }

    /**
     * 获取节点 ID
     *
     * @return string 节点标识
     */
    public function id(): string
    {
        return $this->nodeId ?? self::resolve();
    }

    /**
     * 获取当前进程 ID
     *
     * @return int 进程 ID，无法获取时返回 0
     */
    public function pid(): int
    {
        return function_exists('getmypid') ? (int) getmypid() : 0;
    }

    /**
     * 获取"节点@进程"形式的完整实例标识
     *
     * @return string 例如 web-01#12345
     */
    public function instance(): string
    {
        return $this->id() . '#' . $this->pid();
    }

    /**
     * 解析节点 ID（带进程内缓存）
     *
     * @return string 节点标识
     */
    public static function resolve(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        foreach ([self::ENV_NODE_ID, 'HOSTNAME'] as $key) {
            $value = getenv($key);

            if (is_string($value) && $value !== '') {
                return self::$cached = $value;
            }
        }

        $hostname = gethostname();

        return self::$cached = ($hostname !== false && $hostname !== '') ? $hostname : 'unknown';
    }

    /**
     * 重置缓存（主要供测试使用）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$cached = null;
    }
}
