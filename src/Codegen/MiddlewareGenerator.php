<?php

declare(strict_types=1);

namespace Kode\Middleware\Codegen;

use RuntimeException;

/**
 * PSR-15 中间件源码生成器
 *
 * 把"类名 / 优先级 / 描述"确定性地渲染成一份符合本包约定的中间件源码字符串，
 * 不依赖任何模板引擎或外部服务。用于脚手架、代码生成插件与 `middleware-assistant`
 * 技能，让"健壮架构 + 代码生成"形成闭环：手写配置，生成可被静态分析、可被
 * 解析器直接实例化的标准中间件。
 *
 * 生成的类满足：
 * - `declare(strict_types=1)` + PSR-4 兼容的命名空间；
 * - 实现 `Psr\Http\Server\MiddlewareInterface`；
 * - 暴露 `public const PRIORITY`，便于 {@see \Kode\Middleware\Pipeline} 自动取用优先级；
 * - `process()` 内给出"调用下游之前 / 响应返回之前"两处 TODO 注释，引导正确织入。
 *
 * @example
 * ```php
 * $code = (new MiddlewareGenerator('App\\Middleware'))
 *     ->generate('AuthMiddleware', ['priority' => 1000, 'description' => '鉴权']);
 * file_put_contents(__DIR__ . '/AuthMiddleware.php', $code);
 * ```
 *
 * @package Kode\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class MiddlewareGenerator
{
    /**
     * @param string $namespace 生成类的命名空间（不含结尾反斜杠）
     */
    public function __construct(private readonly string $namespace = 'App\\Middleware')
    {
        // 命名空间会被原样插进生成的源码：不校验就等于把注入面留给配置/CLI 参数
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace)) {
            throw new RuntimeException("非法的命名空间「{$namespace}」；仅允许字母、数字、下划线与反斜杠分段");
        }
    }

    /**
     * 生成中间件源码
     *
     * @param string $className 类名（仅字母 / 数字 / 下划线，须以字母或下划线开头）
     * @param array{priority?: int, description?: string} $options 可选配置
     * @return string 完整 PHP 源码（含 `<?php` 开头）
     * @throws RuntimeException 类名非法时抛出
     */
    public function generate(string $className, array $options = []): string
    {
        $this->assertValidClass($className);

        $priority = (int) ($options['priority'] ?? 0);
        $doc = trim((string) ($options['description'] ?? ''));
        // 描述里的星斜杠序列会提前终结 docblock，把后续文本变成源码里的 PHP——必须中和
        $doc = str_replace('*/', '* /', $doc);
        $docBlock = $doc === '' ? '' : "\n *\n * " . str_replace("\n", "\n * ", $doc);

        // 命名空间里的反斜杠在源码里是单个字面量，构造函数已存为 'App\\Middleware'
        $namespace = $this->namespace;

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * {$className} 中间件
 *{$docBlock}
 *
 * 由 kode/middleware 代码生成器生成，可自由修改。
 *
 * @package {$namespace}
 * @license MIT
 */
final class {$className} implements MiddlewareInterface
{
    /** 管道优先级：越大越靠外层（参考 Pipeline 的优先级约定） */
    public const PRIORITY = {$priority};

    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
    {
        // TODO: 在调用下游之前织入请求处理逻辑（如鉴权、改写请求头、短路拦截）
        \$response = \$handler->handle(\$request);

        // TODO: 在响应返回客户端之前织入响应处理逻辑（如加头、改写响应体）

        return \$response;
    }
}
PHP;
    }

    /**
     * 生成并写入文件
     *
     * @param string $className 类名
     * @param string $directory 目标目录（必须已存在）
     * @param array{priority?: int, description?: string} $options 可选配置
     * @return string 写入的文件绝对路径
     * @throws RuntimeException 目录不存在或写入失败时抛出
     */
    public function write(string $className, string $directory, array $options = []): string
    {
        $directory = rtrim($directory, '/\\');

        if (!is_dir($directory)) {
            throw new RuntimeException("目标目录不存在：{$directory}");
        }

        $file = $directory . \DIRECTORY_SEPARATOR . $className . '.php';
        $bytes = file_put_contents($file, $this->generate($className, $options));

        if ($bytes === false) {
            throw new RuntimeException("写入中间件文件失败：{$file}");
        }

        return $file;
    }

    /**
     * 校验类名合法性（防止生成无效或路径穿越式类名）
     *
     * @param string $className 类名
     * @return void
     * @throws RuntimeException 类名含非法字符时抛出
     */
    private function assertValidClass(string $className): void
    {
        if ($className === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className)) {
            throw new RuntimeException(
                "非法的中间件类名「{$className}」；只能包含字母、数字与下划线，且须以字母或下划线开头"
            );
        }
    }
}
