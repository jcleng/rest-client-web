<?php
declare(strict_types=1);

/**
 * 提取 .http 文件的文件级变量定义（JetBrains HTTP Client 风格）。
 *
 * 规则（见 task-5 简报「缺陷 A」）：
 *   - 定义行形如 `@name=value`，键名做 trim，值两侧 trim；
 *     支持值内含空格（如 `Bearer xxx`），正则为 `^@([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$`；
 *   - 只收未被注释的定义：行首（允许前导空白）是 `#` 的 `# @url=...` 视为注释，忽略；
 *   - 变量名重复时后定义者覆盖先定义者（文件内就近生效）；
 *   - 整个文件内多行收集。
 */
final class FileVars
{
    /** 定义行：@name=value。 */
    private const DEF_RE = '/^@([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/';

    /**
     * 从文件内容提取文件级变量。
     *
     * @return array<string,string>
     */
    public static function extract(string $content): array
    {
        if ($content === '') {
            return [];
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $vars = [];
        foreach (explode("\n", $content) as $line) {
            // 注释行（行首允许前导空白）：# @url=... 忽略
            $trimmed = ltrim($line);
            if ($trimmed !== '' && $trimmed[0] === '#') {
                continue;
            }
            if (preg_match(self::DEF_RE, $line, $m) !== 1) {
                continue;
            }
            $varName = trim($m[1]);
            if ($varName === '') {
                continue;
            }
            // 值两侧 trim；内部空格（如 Bearer xxx）保留
            $vars[$varName] = trim($m[2]);
        }
        return $vars;
    }

    /**
     * 从文件读取并提取；读取失败返回空表。
     *
     * @return array<string,string>
     */
    public static function extractFile(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }
        return self::extract($content);
    }

    /**
     * 合并变量表：文件级变量优先于环境变量（文件级覆盖环境）。
     *
     * @param array<string,mixed> $envVars   环境变量（$shared 叠加 env 后的结果）
     * @param array<string,mixed> $fileVars  文件级变量
     * @return array<string,mixed>
     */
    public static function merge(array $envVars, array $fileVars): array
    {
        if ($fileVars === []) {
            return $envVars;
        }
        return array_merge($envVars, $fileVars);
    }
}
