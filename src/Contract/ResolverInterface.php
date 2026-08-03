<?php

declare(strict_types=1);

namespace Kode\Middleware\Contract;

use Kode\Middleware\Exception\MiddlewareException;
use Psr\Http\Server\MiddlewareInterface;

/**
 * 中间件解析器契约
 *
 * 负责把"中间件声明"归一化为真正的 PSR-15 中间件实例。
 * 解析动作发生在请求处理期间（而非管道构建期间），
 * 因此类名字符串声明天然具备惰性实例化能力——未命中的分支不会被创建。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
interface ResolverInterface
{
    /**
     * 将任意中间件声明解析为 PSR-15 中间件实例
     *
     * @param mixed $declaration 中间件声明
     * @return MiddlewareInterface 解析出的中间件实例
     * @throws MiddlewareException 当声明无法被解析时抛出
     */
    public function resolve(mixed $declaration): MiddlewareInterface;

    /**
     * 判断某个声明是否可被本解析器处理
     *
     * 用于在管道构建期做提前校验，避免把错误推迟到运行期。
     *
     * @param mixed $declaration 中间件声明
     * @return bool 可解析返回 true
     */
    public function accepts(mixed $declaration): bool;
}
