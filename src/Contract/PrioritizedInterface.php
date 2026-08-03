<?php

declare(strict_types=1);

namespace Kode\Middleware\Contract;

/**
 * 可排序中间件契约
 *
 * 中间件实现此接口后，可以自行声明在管道中的优先级，
 * 而不必依赖注册顺序。数值越大越靠外层（越早进入、越晚退出）。
 *
 * 典型分层建议：
 * - 1000 及以上：异常捕获、链路追踪等必须最外层的基础设施中间件
 * - 500 ~ 999 ：上下文隔离、超时控制、并发运行器注入
 * - 0   ~ 499 ：CORS、限流、会话、认证等业务前置中间件
 * - 负数      ：贴近业务的路由级中间件
 *
 * 相同优先级的中间件严格保持注册先后顺序（稳定排序）。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
interface PrioritizedInterface
{
    /**
     * 返回中间件优先级
     *
     * @return int 优先级数值，越大越靠近管道外层
     */
    public function priority(): int;
}
