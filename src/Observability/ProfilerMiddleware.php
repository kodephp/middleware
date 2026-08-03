<?php

declare(strict_types=1);

namespace Kode\Middleware\Observability;

use Kode\Middleware\Contract\PrioritizedInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 洋葱分层耗时剖析中间件
 *
 * 在洋葱的不同深度各放一个实例，即可得到一张按层级缩进的耗时账单：
 *
 * ```
 * pipeline   12.480 ms      ← 放在最外层
 *   auth      3.110 ms      ← 放在鉴权之外
 *     query   8.020 ms      ← 放在控制器之外
 * ```
 *
 * 外层耗时包含内层，相减即得每层自耗时。相比"在每个中间件里手写 microtime"，
 * 这种做法零侵入、可开关、并且天然贴合洋葱结构。
 *
 * 最外层实例会把结果写入 `Server-Timing` 响应头（可关闭），
 * 浏览器开发者工具即可直接渲染成瀑布图。
 *
 * @example
 * ```php
 * $pipe->beforeRoute(new ProfilerMiddleware('pipeline'))        // 最外层，输出响应头
 *      ->afterRoute(new ProfilerMiddleware('auth', header: false))
 *      ->…;
 *
 * // 在任意中间件内读取
 * $profile = ProfilerMiddleware::of($request);
 * $logger->debug($profile?->toText() ?? '');
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ProfilerMiddleware implements MiddlewareInterface, PrioritizedInterface
{
    /** @var string 请求属性名：耗时收集器 */
    public const ATTRIBUTE = 'kode.profile';

    /** @var int 默认优先级，略低于异常边界、高于链路追踪 */
    public const PRIORITY = 1500;

    /**
     * @param string $label 本层名称
     * @param bool $header 是否把结果写入 Server-Timing 响应头（通常只在最外层开启）
     * @param \Closure|null $sink 结果回调，形如 fn(Profile, ServerRequestInterface): void
     * @param int $priority 优先级，越大越靠外层
     */
    public function __construct(
        private readonly string $label = 'pipeline',
        private readonly bool $header = true,
        private readonly ?\Closure $sink = null,
        private readonly int $priority = self::PRIORITY,
    ) {
    }

    /**
     * 读取请求上的耗时收集器
     *
     * @param ServerRequestInterface $request 请求对象
     * @return Profile|null 收集器，未启用剖析时为 null
     */
    public static function of(ServerRequestInterface $request): ?Profile
    {
        $profile = $request->getAttribute(self::ATTRIBUTE);

        return $profile instanceof Profile ? $profile : null;
    }

    /**
     * 处理请求：包裹下游并记录本层耗时
     *
     * 使用 finally 收口，保证下游抛异常时区段依然被正确关闭，
     * 不会污染后续层级的深度计算。
     *
     * @param ServerRequestInterface $request 请求对象
     * @param RequestHandlerInterface $handler 下游处理器
     * @return ResponseInterface 响应对象
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $profile = self::of($request);
        $owner = false;

        // 最外层的剖析中间件负责创建收集器，内层复用同一实例形成层级
        if ($profile === null) {
            $profile = new Profile();
            $owner = true;
            $request = $request->withAttribute(self::ATTRIBUTE, $profile);
        }

        $span = $profile->enter($this->label);

        try {
            $response = $handler->handle($request);
        } finally {
            // 区段关闭与结果冲刷必须放在 finally：下游抛异常时（被外层异常边界兜住）
            // 区段同样要被正确关闭，观测结果也照常落地，不能因异常而静默丢失。
            $profile->leave($span);

            // 只有创建收集器的那一层负责冲刷，避免嵌套时重复回调
            if ($owner) {
                $this->emit($profile, $request);
            }
        }

        // 响应头写入仅成功路径需要（异常路径的响应由外层边界产出）
        if ($owner && $this->header) {
            $timing = $profile->toServerTiming();

            if ($timing !== '') {
                $response = $response->withHeader('Server-Timing', $timing);
            }
        }

        return $response;
    }

    /**
     * 优先级
     *
     * @return int 优先级数值
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * 把剖析结果交给外部回调（失败不影响响应）
     *
     * @param Profile $profile 收集器
     * @param ServerRequestInterface $request 请求对象
     * @return void
     */
    private function emit(Profile $profile, ServerRequestInterface $request): void
    {
        if ($this->sink === null) {
            return;
        }

        try {
            ($this->sink)($profile, $request);
        } catch (\Throwable) {
            // 观测通道故障不能影响正常响应
        }
    }
}
