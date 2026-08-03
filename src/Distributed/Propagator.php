<?php

declare(strict_types=1);

namespace Kode\Middleware\Distributed;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 链路上下文传播器
 *
 * 负责在服务边界上做两件对称的事：
 * - **extract**：从入站请求头里还原上游的链路信息
 * - **inject**：把当前链路信息写进出站请求头，交给下游服务
 *
 * 采用 W3C Trace Context 标准的 `traceparent` 头，格式：
 * `版本-追踪ID(32位hex)-SpanID(16位hex)-采样标志(2位hex)`，
 * 例如 `00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01`。
 *
 * 遵循标准而非自定义格式的好处：能直接被 Jaeger、SkyWalking、
 * OpenTelemetry Collector 等现成链路系统识别，无需额外适配层。
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Propagator
{
    /** @var string W3C 标准链路头 */
    public const HEADER_TRACEPARENT = 'traceparent';

    /** @var string W3C 标准链路附加数据头 */
    public const HEADER_TRACESTATE = 'tracestate';

    /** @var string 请求 ID 头（兼容常见网关约定） */
    public const HEADER_REQUEST_ID = 'X-Request-Id';

    /** @var string 节点标识头 */
    public const HEADER_NODE_ID = 'X-Node-Id';

    /** @var string traceparent 版本号 */
    private const VERSION = '00';

    /**
     * 从入站请求中提取链路上下文
     *
     * 上游未传或格式非法时自动生成一条新链路，
     * 因此调用方永远能拿到可用的 traceId。
     *
     * @param ServerRequestInterface $request 入站请求
     * @return array{traceId: string, spanId: string, parentSpanId: string|null, sampled: bool, requestId: string, tracestate: string}
     */
    public static function extract(ServerRequestInterface $request): array
    {
        $parsed = self::parseTraceparent($request->getHeaderLine(self::HEADER_TRACEPARENT));

        $requestId = $request->getHeaderLine(self::HEADER_REQUEST_ID);

        return [
            'traceId' => $parsed['traceId'] ?? self::randomHex(32),
            'spanId' => self::randomHex(16),
            'parentSpanId' => $parsed['spanId'] ?? null,
            'sampled' => $parsed['sampled'] ?? true,
            'requestId' => $requestId !== '' ? $requestId : self::randomHex(16),
            'tracestate' => $request->getHeaderLine(self::HEADER_TRACESTATE),
        ];
    }

    /**
     * 生成出站请求应携带的链路头
     *
     * 在控制器里调用下游服务前使用，把当前链路继续往下传。
     *
     * @param array{traceId?: string, spanId?: string, sampled?: bool, requestId?: string, tracestate?: string} $context 链路上下文
     * @return array<string, string> 出站请求头
     */
    public static function inject(array $context): array
    {
        $traceId = $context['traceId'] ?? self::randomHex(32);
        $spanId = $context['spanId'] ?? self::randomHex(16);
        $sampled = ($context['sampled'] ?? true) ? '01' : '00';

        $headers = [
            self::HEADER_TRACEPARENT => sprintf('%s-%s-%s-%s', self::VERSION, $traceId, $spanId, $sampled),
            self::HEADER_NODE_ID => NodeIdentity::resolve(),
        ];

        if (isset($context['requestId']) && $context['requestId'] !== '') {
            $headers[self::HEADER_REQUEST_ID] = $context['requestId'];
        }

        if (isset($context['tracestate']) && $context['tracestate'] !== '') {
            $headers[self::HEADER_TRACESTATE] = $context['tracestate'];
        }

        return $headers;
    }

    /**
     * 从请求属性中读取链路上下文并生成出站头
     *
     * 是 extract + inject 的便捷组合，控制器里最常用的一个方法。
     *
     * @param ServerRequestInterface $request 当前请求
     * @return array<string, string> 出站请求头
     */
    public static function forward(ServerRequestInterface $request): array
    {
        $context = $request->getAttribute(TraceMiddleware::ATTRIBUTE);

        if (!is_array($context)) {
            $context = self::extract($request);
        }

        /** @var array{traceId?: string, spanId?: string, sampled?: bool, requestId?: string, tracestate?: string} $context */
        return self::inject($context);
    }

    /**
     * 解析 W3C traceparent 头
     *
     * @param string $header traceparent 头原始值
     * @return array{traceId?: string, spanId?: string, sampled?: bool} 解析结果，非法时返回空数组
     */
    public static function parseTraceparent(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $parts = explode('-', trim($header));

        if (count($parts) !== 4) {
            return [];
        }

        [, $traceId, $spanId, $flags] = $parts;

        // 全零的 ID 按标准视为非法
        if (!self::isHex($traceId, 32) || !self::isHex($spanId, 16)) {
            return [];
        }

        if (trim($traceId, '0') === '' || trim($spanId, '0') === '') {
            return [];
        }

        return [
            'traceId' => strtolower($traceId),
            'spanId' => strtolower($spanId),
            'sampled' => (hexdec($flags) & 0x01) === 1,
        ];
    }

    /**
     * 生成指定长度的随机十六进制字符串
     *
     * @param int $length 字符长度，必须为偶数
     * @return string 小写十六进制字符串
     */
    public static function randomHex(int $length): string
    {
        return bin2hex(random_bytes(max(1, intdiv($length, 2))));
    }

    /**
     * 校验字符串是否为指定长度的十六进制
     *
     * @param string $value 待校验字符串
     * @param int $length 期望长度
     * @return bool 合法返回 true
     */
    private static function isHex(string $value, int $length): bool
    {
        return strlen($value) === $length && ctype_xdigit($value);
    }
}
