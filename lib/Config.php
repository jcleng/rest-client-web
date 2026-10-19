<?php
declare(strict_types=1);

/**
 * 配置加载与目录安全校验。
 *
 * 配置文件默认位于 <项目根>/config/httpviewer.config.json：
 *   {"dir": ".", "exclude": [".git", "node_modules", ...]}
 *
 * 安全约束：dir 解析后（realpath）必须位于项目根目录之内，否则抛异常，防目录穿越。
 */
final class Config
{
    /** 配置缺失时的默认排除目录。 */
    public const DEFAULT_EXCLUDE = [
        '.git',
        'node_modules',
        '.superpowers',
        'assets',
        'lib',
        'config',
        'tests',
        'docs',
        'tools',
        'reports',
    ];

    private string $root;
    private string $dir;
    /** @var list<string> */
    private array $exclude;

    /**
     * @param array<string,mixed> $data 已解析的配置数组（缺省回退默认值）
     * @param string|null         $root 项目根目录，缺省为 lib/ 的上一级
     */
    public function __construct(array $data = [], ?string $root = null)
    {
        $this->root = self::resolveRoot($root);
        $this->applyData($data);
    }

    /**
     * 从配置文件加载；文件不存在时回退默认配置。
     */
    public static function load(?string $configFile = null, ?string $root = null): self
    {
        $resolvedRoot = self::resolveRoot($root);
        $file = $configFile ?? $resolvedRoot . '/config/httpviewer.config.json';
        return new self(self::readJson($file), $resolvedRoot);
    }

    /**
     * 直接以数组构造（便于测试与嵌入）。
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, ?string $root = null): self
    {
        return new self($data, $root);
    }

    /** 扫描目录（绝对路径，已通过越界校验）。 */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * 排除目录名列表（不区分大小写比较）。
     *
     * @return list<string>
     */
    public function exclude(): array
    {
        return $this->exclude;
    }

    /** 项目根目录（绝对路径）。 */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * 校验一个「扫描根内的 .http 文件」相对路径，返回绝对路径；非法返回 null。
     *
     * 供 api=send 的 file 参数使用：前端只传相对路径，由服务端自行读取该文件的
     * 文件级变量（不在前端解析变量）。
     *
     * 拒绝：非字符串/空串、绝对路径（含 Windows 盘符）、扩展名不是 .http、
     * realpath 失败、realpath 落在扫描根之外（防目录穿越）。
     */
    public function resolveScannedFile(mixed $rel): ?string
    {
        if (!is_string($rel) || trim($rel) === '') {
            return null;
        }
        $rel = str_replace('\\', '/', trim($rel));
        // 绝对路径（/x 或 C:/x）一律拒绝：只接受扫描根内的相对路径
        if (str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:/#', $rel) === 1) {
            return null;
        }
        if (strtolower((string) pathinfo($rel, PATHINFO_EXTENSION)) !== 'http') {
            return null;
        }
        $real = realpath($this->dir . '/' . $rel);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        $base = $this->dir;
        if ($real === $base || !str_starts_with($real, $base . '/')) {
            return null;
        }
        return $real;
    }

    private static function resolveRoot(?string $root): string
    {
        $candidate = $root ?? dirname(__DIR__);
        $real = realpath($candidate);
        if ($real === false) {
            throw new RuntimeException('项目根目录不存在: ' . $candidate);
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        return $real === '' ? '/' : $real;
    }

    /**
     * @return array<string,mixed>
     */
    private static function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('无法读取配置文件: ' . $file);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('配置文件不是合法 JSON: ' . $file);
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function applyData(array $data): void
    {
        $dir = $data['dir'] ?? '.';
        if (!is_string($dir) || trim($dir) === '') {
            throw new InvalidArgumentException('配置项 dir 必须是非空字符串');
        }
        $this->dir = $this->resolveDir($dir);

        $exclude = $data['exclude'] ?? self::DEFAULT_EXCLUDE;
        if (!is_array($exclude)) {
            throw new InvalidArgumentException('配置项 exclude 必须是数组');
        }
        $clean = [];
        foreach ($exclude as $item) {
            if (is_string($item) && $item !== '') {
                $clean[] = $item;
            }
        }
        $this->exclude = array_values(array_unique($clean));
    }

    private function resolveDir(string $dir): string
    {
        $dir = str_replace('\\', '/', trim($dir));
        $isAbsolute = str_starts_with($dir, '/')
            || preg_match('#^[A-Za-z]:/#', $dir) === 1;
        $candidate = $isAbsolute ? $dir : $this->root . '/' . $dir;

        $real = realpath($candidate);
        if ($real === false) {
            throw new RuntimeException('配置目录不存在: ' . $dir);
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        if ($real === '') {
            $real = '/';
        }

        if ($this->root === '/') {
            return $real;
        }
        if ($real !== $this->root && !str_starts_with($real, $this->root . '/')) {
            throw new RuntimeException('配置目录越界（必须位于项目根目录内）: ' . $dir);
        }
        return $real;
    }
}
