<?php
declare(strict_types=1);

/**
 * 变量加载与 {{var}} 替换。
 *
 * 数据源：<项目根>/.vscode/settings.json
 *   {
 *     "rest-client.environmentVariables": {
 *       "$shared": {"host": "...", "merchantToken": "..."},
 *       "测试服":   {"host": "..."}          // 覆盖 $shared 的同名键
 *     }
 *   }
 *
 *  - vars(?string $env)     返回变量表；env 为 null/'' 时只用 $shared，
 *                           否则把同名环境的键值覆盖到 $shared 之上。
 *  - interpolate(text, vars) 把 {{var}} 替换成值；未知变量保留 {{var}} 原文。
 *  - warnings()             返回最近一次 vars() 调用产生的警告（文件缺失/解析失败/未知环境）。
 */
final class Env
{
    /** settings.json 中环境变量所在的键。 */
    public const SETTINGS_KEY = 'rest-client.environmentVariables';

    /** 契约中的 source 字段（默认相对路径）。 */
    public const DEFAULT_SOURCE = '.vscode/settings.json';

    private string $file;
    private string $source;

    /** @var array<string,mixed> 环境名 => 变量表（含 "$shared"）。 */
    private array $environments = [];

    /** 文件级警告（缺失/不可读/非法 JSON/缺少键），加载时确定。 */
    private string $fileWarning = '';

    /** @var list<string> 最近一次 vars() 产生的警告。 */
    private array $warnings = [];

    /**
     * @param string|null $file settings.json 路径；缺省为 <root>/.vscode/settings.json
     * @param string|null $root 项目根；缺省为 lib/ 的上一级
     */
    public function __construct(?string $file = null, ?string $root = null)
    {
        if ($file !== null) {
            $this->file = $file;
            $this->source = $file;
        } else {
            $base = rtrim(str_replace('\\', '/', $root ?? dirname(__DIR__)), '/');
            $this->file = $base . '/' . self::DEFAULT_SOURCE;
            $this->source = self::DEFAULT_SOURCE;
        }
        $this->load();
    }

    /** 契约中的 source 字段值。 */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * 可用环境名列表（不含 "$shared"），按 settings.json 中出现的顺序。
     *
     * @return list<string>
     */
    public function envs(): array
    {
        $names = [];
        foreach (array_keys($this->environments) as $name) {
            if ($name !== '$shared' && is_string($name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * 变量表：$shared 为基础，env 指定时叠加同名环境。
     * 未知环境不做覆盖，仅返回 $shared 并记 warning。
     *
     * @return array<string,mixed>
     */
    public function vars(?string $env = null): array
    {
        $warnings = [];
        if ($this->fileWarning !== '') {
            $warnings[] = $this->fileWarning;
        }

        $shared = $this->environments['$shared'] ?? [];
        if (!is_array($shared)) {
            $shared = [];
        }
        $vars = $shared;

        if ($env !== null && $env !== '') {
            if (array_key_exists($env, $this->environments) && is_array($this->environments[$env])) {
                $vars = array_merge($shared, $this->environments[$env]);
            } else {
                $warnings[] = '未知环境: ' . $env . '，仅使用 $shared 变量';
            }
        }

        $this->warnings = $warnings;
        return $vars;
    }

    /** 最近一次 vars() 产生的警告（无警告时为空数组）。 */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * 把 {{var}} 替换为变量值；未知变量保留 {{var}} 原文（含原有空白）。
     * 变量名两侧允许空白：{{ name }} 等价于 {{name}}。
     */
    public static function interpolate(string $text, array $vars): string
    {
        if ($text === '' || !str_contains($text, '{{')) {
            return $text;
        }
        $replaced = preg_replace_callback(
            '/\{\{([^{}]*)\}\}/',
            static function (array $m) use ($vars): string {
                $key = trim($m[1]);
                if ($key === '' || !array_key_exists($key, $vars)) {
                    return $m[0]; // 未知变量：保留原文
                }
                return self::stringify($vars[$key]);
            },
            $text
        );
        return $replaced ?? $text;
    }

    /** 非字符串变量值转成替换用的字符串（bool/null/数字/数组）。 */
    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json === false ? '' : $json;
        }
        return (string) $value;
    }

    private function load(): void
    {
        if (!is_file($this->file)) {
            $this->fileWarning = '变量文件不存在: ' . $this->source . '，返回空变量表';
            return;
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            $this->fileWarning = '变量文件读取失败: ' . $this->source . '，返回空变量表';
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->fileWarning = '变量文件不是合法 JSON: ' . $this->source . '，返回空变量表';
            return;
        }
        $table = $data[self::SETTINGS_KEY] ?? null;
        if (!is_array($table)) {
            $this->fileWarning = '变量文件缺少 ' . self::SETTINGS_KEY . ' 配置，返回空变量表';
            return;
        }
        /** @var array<string,mixed> $table */
        $this->environments = $table;
    }
}
