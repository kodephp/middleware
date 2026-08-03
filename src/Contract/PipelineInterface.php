<?php

declare(strict_types=1);

namespace Kode\Middleware\Contract;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 中间件管道契约
 *
 * 管道同时具备两种身份：
 * 1. 作为 PSR-15 请求处理器（RequestHandlerInterface）—— 可独立驱动一次完整请求；
 * 2. 作为 PSR-15 中间件（MiddlewareInterface）—— 可被嵌套进另一条管道，天然支持分组。
 *
 * 实现必须保证**不可变**：任何 add / withXxx 操作都返回新实例，
 * 原实例不受影响。这是管道能在多进程、多线程、协程环境下被安全共享的前提。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
interface PipelineInterface extends MiddlewareInterface, RequestHandlerInterface
{
    /**
     * 追加中间件，返回新管道实例
     *
     * 支持的声明形式由解析器（ResolverInterface）决定，通常包括：
     * PSR-15 中间件实例、可调用对象、类名字符串、别名字符串、中间件数组。
     *
     * @param mixed ...$middleware 一个或多个中间件声明
     * @return static 携带新中间件的管道副本
     */
    public function add(mixed ...$middleware): static;

    /**
     * 指定管道终点处理器，返回新管道实例
     *
     * 终点处理器在所有中间件都调用 $handler->handle() 之后执行，
     * 通常是控制器调度器或兜底的 404 处理器。
     *
     * @param RequestHandlerInterface $destination 终点处理器
     * @return static 携带新终点的管道副本
     */
    public function withDestination(RequestHandlerInterface $destination): static;

    /**
     * 获取按优先级排序后的中间件声明列表
     *
     * @return list<mixed> 已排序的中间件声明（未解析为实例）
     */
    public function stack(): array;

    /**
     * 管道中的中间件数量
     *
     * @return int 中间件个数
     */
    public function count(): int;
}
