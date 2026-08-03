<?php

declare(strict_types=1);

namespace Kode\Middleware\Routing;

/**
 * 路由匹配结果
 *
 * 一个不可变值对象，是"路由前"与"路由后"两段中间件之间唯一的通信载体。
 * RouteMiddleware 产出它并写入请求属性，之后管道中的任何中间件
 * 都能通过 RouteResult::from($request) 读到路由元数据。
 *
 * 三种状态：
 * - 命中（matched）：拿到处理器、路径参数与路由级中间件
 * - 未找到（notFound）：没有任何路由匹配该路径
 * - 方法不允许（methodNotAllowed）：路径匹配但 HTTP 方法不符，携带 Allow 列表
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RouteResult
{
    /** @var string 请求属性名：路由结果 */
    public const ATTRIBUTE = 'kode.route';

    /** @var string 状态：匹配成功 */
    public const STATUS_MATCHED = 'matched';

    /** @var string 状态：未找到路由 */
    public const STATUS_NOT_FOUND = 'not_found';

    /** @var string 状态：请求方法不被允许 */
    public const STATUS_METHOD_NOT_ALLOWED = 'method_not_allowed';

    /**
     * @param string $status 匹配状态，取 STATUS_* 之一
     * @param mixed $handler 路由处理器（控制器、闭包、类名等）
     * @param array<string, mixed> $params 路径参数
     * @param list<mixed> $middleware 路由级中间件声明
     * @param string|null $name 路由名称
     * @param list<string> $allowed 方法不允许时的可用方法列表
     */
    private function __construct(
        private readonly string $status,
        private readonly mixed $handler = null,
        private readonly array $params = [],
        private readonly array $middleware = [],
        private readonly ?string $name = null,
        private readonly array $allowed = [],
    ) {
    }

    /**
     * 构造一个"匹配成功"的结果
     *
     * @param mixed $handler 路由处理器
     * @param array<string, mixed> $params 路径参数
     * @param array<int, mixed> $middleware 路由级中间件声明
     * @param string|null $name 路由名称
     * @return self 路由结果
     */
    public static function matched(
        mixed $handler,
        array $params = [],
        array $middleware = [],
        ?string $name = null
    ): self {
        return new self(self::STATUS_MATCHED, $handler, $params, array_values($middleware), $name);
    }

    /**
     * 构造一个"未找到路由"的结果
     *
     * @return self 路由结果
     */
    public static function notFound(): self
    {
        return new self(self::STATUS_NOT_FOUND);
    }

    /**
     * 构造一个"方法不被允许"的结果
     *
     * @param array<int, string> $allowed 该路径支持的 HTTP 方法列表
     * @return self 路由结果
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(
            self::STATUS_METHOD_NOT_ALLOWED,
            allowed: array_values(array_map(strtoupper(...), $allowed))
        );
    }

    /**
     * 从请求属性中取出路由结果
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request 请求对象
     * @return self|null 路由结果，尚未路由时返回 null
     */
    public static function from(\Psr\Http\Message\ServerRequestInterface $request): ?self
    {
        $result = $request->getAttribute(self::ATTRIBUTE);

        return $result instanceof self ? $result : null;
    }

    /**
     * 是否匹配成功
     *
     * @return bool 命中路由返回 true
     */
    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }

    /**
     * 是否未找到路由
     *
     * @return bool 未找到返回 true
     */
    public function isNotFound(): bool
    {
        return $this->status === self::STATUS_NOT_FOUND;
    }

    /**
     * 是否方法不被允许
     *
     * @return bool 方法不允许返回 true
     */
    public function isMethodNotAllowed(): bool
    {
        return $this->status === self::STATUS_METHOD_NOT_ALLOWED;
    }

    /**
     * 获取匹配状态
     *
     * @return string STATUS_* 之一
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * 获取路由处理器
     *
     * @return mixed 处理器，未命中时为 null
     */
    public function handler(): mixed
    {
        return $this->handler;
    }

    /**
     * 获取全部路径参数
     *
     * @return array<string, mixed> 路径参数
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * 获取单个路径参数
     *
     * @param string $key 参数名
     * @param mixed $default 缺省值
     * @return mixed 参数值
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * 获取路由级中间件声明
     *
     * @return list<mixed> 中间件声明列表
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    /**
     * 获取路由名称
     *
     * @return string|null 路由名称
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * 获取允许的 HTTP 方法列表
     *
     * @return list<string> 方法列表，仅在 methodNotAllowed 状态下非空
     */
    public function allowed(): array
    {
        return $this->allowed;
    }

    /**
     * 追加路由级中间件（返回新实例）
     *
     * @param mixed ...$middleware 中间件声明
     * @return self 新的路由结果
     */
    public function withMiddleware(mixed ...$middleware): self
    {
        return new self(
            $this->status,
            $this->handler,
            $this->params,
            [...$this->middleware, ...array_values($middleware)],
            $this->name,
            $this->allowed,
        );
    }

    /**
     * 替换路由处理器（返回新实例）
     *
     * @param mixed $handler 新的处理器
     * @return self 新的路由结果
     */
    public function withHandler(mixed $handler): self
    {
        return new self(
            $this->status,
            $handler,
            $this->params,
            $this->middleware,
            $this->name,
            $this->allowed,
        );
    }
}
