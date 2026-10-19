<?php
declare(strict_types=1);

require_once __DIR__ . '/FileVars.php';

/**
 * 递归扫描配置目录下的 .http 文件，输出 api=tree 契约结构。
 *
 * 缓存：以「文件 mtime + size」为签名，签名未变的文件复用上次解析结果。
 * 缓存分两层：进程内静态缓存 + 系统临时目录中的 JSON 缓存（跨请求复用）。
 * 单个文件解析失败不阻断整体，该文件节点带 error 字符串且 groups 为空数组。
 * 每个文件节点另带 `vars` 字段（文件级 `@var=value` 变量表，无定义时为 {}）。
 */
final class Scanner
{
    /** 缓存格式版本，解析逻辑变更时递增（v3：文件节点新增 vars 字段）。 */
    public const CACHE_VERSION = 3;

    private Config $config;

    /** @var array{files:int,parsed:int,cached:int,errors:int} */
    private array $stats = ['files' => 0, 'parsed' => 0, 'cached' => 0, 'errors' => 0];

    /**
     * 进程内缓存：key = root\0rel，value = ['sig' => string, 'requests' => array, 'error' => ?string, 'vars' => array]
     *
     * @var array<string,array{sig:string,requests:list<array<string,mixed>>,error:?string,vars:array<string,string>}>
     */
    private static array $memory = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 最近一次 tree() 的统计（用于验证缓存命中）。
     *
     * @return array{files:int,parsed:int,cached:int,errors:int}
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * @return array{ok:bool,root:string,version:string,count:int,files:list<array<string,mixed>>}
     */
    public function tree(): array
    {
        $this->stats = ['files' => 0, 'parsed' => 0, 'cached' => 0, 'errors' => 0];
        clearstatcache();

        $root = $this->config->dir();
        $files = $this->collect($root);
        $this->stats['files'] = count($files);

        $persistent = $this->readCache($root);
        $versionParts = [];
        $nodes = [];
        $count = 0;
        $dirty = false;
        $nextCache = [];

        foreach ($files as $rel) {
            $abs = $root . '/' . $rel;
            $mtime = (int) (@filemtime($abs) ?: 0);
            $size = (int) (@filesize($abs) ?: 0);
            $sig = $mtime . ':' . $size;
            $versionParts[] = $rel . '|' . $sig;

            $key = $root . "\0" . $rel;
            if (isset(self::$memory[$key]) && self::$memory[$key]['sig'] === $sig) {
                $entry = self::$memory[$key];
                $this->stats['cached']++;
            } elseif (isset($persistent[$rel]) && is_array($persistent[$rel])
                && ($persistent[$rel]['sig'] ?? null) === $sig) {
                $entry = [
                    'sig' => $sig,
                    'requests' => is_array($persistent[$rel]['requests'] ?? null) ? $persistent[$rel]['requests'] : [],
                    'error' => isset($persistent[$rel]['error']) && is_string($persistent[$rel]['error'])
                        ? $persistent[$rel]['error'] : null,
                    'vars' => is_array($persistent[$rel]['vars'] ?? null) ? $persistent[$rel]['vars'] : [],
                ];
                self::$memory[$key] = $entry;
                $this->stats['cached']++;
            } else {
                $entry = $this->parseFile($abs);
                $entry['sig'] = $sig;
                self::$memory[$key] = $entry;
                $this->stats['parsed']++;
                $dirty = true;
            }

            $nextCache[$rel] = [
                'sig' => $entry['sig'],
                'requests' => $entry['requests'],
                'error' => $entry['error'],
                'vars' => $entry['vars'],
            ];

            // 文件级变量表（JetBrains `@var=value` 方言）；无定义时为 {}（强转 object 保证 JSON 编码为对象）。
            $node = ['rel' => $rel, 'mtime' => $mtime, 'vars' => (object) $entry['vars']];
            if ($entry['error'] !== null) {
                $node['error'] = $entry['error'];
                $node['groups'] = [];
                $this->stats['errors']++;
            } else {
                $node['groups'] = $this->groupRequests($rel, $entry['requests']);
                $count += count($entry['requests']);
            }
            $nodes[] = $node;
        }

        if ($dirty || $persistent === []) {
            $this->writeCache($root, $nextCache);
        }

        return [
            'ok' => true,
            'root' => $root,
            'version' => md5(implode("\n", $versionParts)),
            'count' => $count,
            'files' => $nodes,
        ];
    }

    /**
     * 递归收集 .http 文件（相对 root，使用 / 分隔），跳过隐藏目录与 exclude 目录。
     *
     * @return list<string>
     */
    private function collect(string $root): array
    {
        $exclude = [];
        foreach ($this->config->exclude() as $name) {
            $exclude[strtolower($name)] = true;
        }

        $result = [];
        $walk = function (string $dir, string $prefix) use (&$walk, &$result, $exclude): void {
            $entries = @scandir($dir);
            if ($entries === false) {
                return;
            }
            sort($entries, SORT_STRING);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $abs = $dir . '/' . $entry;
                if (is_dir($abs)) {
                    if ($entry[0] === '.' || is_link($abs) || isset($exclude[strtolower($entry)])) {
                        continue;
                    }
                    $walk($abs, $prefix . $entry . '/');
                    continue;
                }
                if (is_file($abs) && strtolower((string) pathinfo($entry, PATHINFO_EXTENSION)) === 'http') {
                    $result[] = $prefix . $entry;
                }
            }
        };
        $walk($root, '');
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @return array{sig:string,requests:list<array<string,mixed>>,error:?string,vars:array<string,string>}
     */
    private function parseFile(string $abs): array
    {
        // 文件级变量与请求解析相互独立：请求解析失败（如非法 UTF-8）时仍尽力提取变量。
        $vars = FileVars::extractFile($abs);
        try {
            return ['sig' => '', 'requests' => HttpParser::parseFile($abs), 'error' => null, 'vars' => $vars];
        } catch (Throwable $e) {
            return ['sig' => '', 'requests' => [], 'error' => $e->getMessage(), 'vars' => $vars];
        }
    }

    /**
     * 按 group 字段分组，并生成契约形态的请求对象（id 为 `<rel>#<行号>`）。
     *
     * @param list<array<string,mixed>> $requests
     * @return list<array{name:string,requests:list<array<string,mixed>>}>
     */
    private function groupRequests(string $rel, array $requests): array
    {
        $groups = [];
        $index = [];
        foreach ($requests as $request) {
            $groupName = (string) ($request['group'] ?? '未分组');
            if (!isset($index[$groupName])) {
                $index[$groupName] = count($groups);
                $groups[] = ['name' => $groupName, 'requests' => []];
            }
            $groups[$index[$groupName]]['requests'][] = [
                'id' => $rel . '#' . $request['line'],
                'name' => (string) ($request['name'] ?? ''),
                'varName' => (string) ($request['varName'] ?? ''),
                'method' => (string) ($request['method'] ?? ''),
                'url' => (string) ($request['url'] ?? ''),
                'path' => (string) ($request['path'] ?? ''),
                'headers' => (object) ($request['headers'] ?? []),
                'body' => (string) ($request['body'] ?? ''),
                'notes' => (string) ($request['notes'] ?? ''),
                'raw' => (string) ($request['raw'] ?? ''),
                'line' => (int) ($request['line'] ?? 0),
            ];
        }
        return $groups;
    }

    private function cachePath(string $root): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . '/pi-httpviewer-cache-' . md5($root) . '.json';
    }

    /**
     * @return array<string,array{sig?:string,requests?:mixed,error?:mixed}>
     */
    private function readCache(string $root): array
    {
        $path = $this->cachePath($root);
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)
            || ($data['v'] ?? null) !== self::CACHE_VERSION
            || !isset($data['files'])
            || !is_array($data['files'])) {
            return [];
        }
        return $data['files'];
    }

    /**
     * @param array<string,array{sig:string,requests:list<array<string,mixed>>,error:?string,vars:array<string,string>}> $files
     */
    private function writeCache(string $root, array $files): void
    {
        $payload = json_encode(
            ['v' => self::CACHE_VERSION, 'files' => $files],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            return;
        }
        @file_put_contents($this->cachePath($root), $payload, LOCK_EX);
    }
}
