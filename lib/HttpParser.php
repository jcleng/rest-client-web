<?php
declare(strict_types=1);

/**
 * 单个 .http 文件（VS Code REST Client 格式）解析器。
 *
 * 规则：
 *  - 行首 `###`（可带标题）分块；第一个 `###` 之前的内容（文件头注释）忽略；
 *  - 块内第一个大写方法行 `METHOD url` 决定该请求；找不到则跳过该块；
 *  - 块内 `#` 注释：`# 分组: x` -> 分组；`# @name x` -> varName；
 *    `# 待填参数: x` 及其余注释 -> notes（保留原文、换行拼接）；
 *    标题优先取 `###` 后文字，缺失时取首个普通注释行；
 *  - 请求行后：缩进行为 URL 续行；`Header: value` 为请求头（大小写不敏感去重，
 *    保留首次出现的原始 key）；空行之后全部为 body（兼容 multipart/form-data）。
 *
 * 返回的每个请求数组携带字段 `raw`（该请求块逐字原文，含 `###` 标题行与注释，
 * 换行已归一为 \n）与内部字段 `group`（由 Scanner 负责分组后剔除）。
 */
final class HttpParser
{
    /** 大写方法行：METHOD url（url 不含空白）。 */
    private const REQUEST_RE = '/^([A-Z][A-Z0-9_-]*)[ \t]+(\S+)\s*$/';
    /** RFC 7230 field-name（token）+ 可选值。 */
    private const HEADER_RE = '/^([A-Za-z0-9!#$%&\'*+.^_`|~-]+):[ \t]?(.*)$/';

    /**
     * 解析文件内容。
     *
     * @param string $content 文件全文
     * @param string $rel     相对路径（仅用于调用方生成 id，本方法不写回）
     * @return list<array<string,mixed>> 请求块数组
     */
    public function parse(string $content, string $rel = ''): array
    {
        if (preg_match('//u', $content) !== 1) {
            throw new RuntimeException('文件不是有效的 UTF-8 编码');
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);
        $total = count($lines);

        $requests = [];
        $i = 0;
        while ($i < $total) {
            if (!str_starts_with($lines[$i], '###')) {
                $i++;
                continue;
            }
            $titleLine = $lines[$i];
            $title = trim(substr($titleLine, 3));
            $i++;

            $blockLines = [];
            while ($i < $total && !str_starts_with($lines[$i], '###')) {
                $blockLines[] = ['line' => $i + 1, 'text' => $lines[$i]];
                $i++;
            }

            // 保留该请求块的逐字原文（含 ### 标题行与注释），供详情区「原始片段」展示。
            $rawParts = [$titleLine];
            foreach ($blockLines as $bl) {
                $rawParts[] = $bl['text'];
            }

            $request = $this->parseBlock($title, $blockLines);
            if ($request !== null) {
                $request['raw'] = implode("\n", $rawParts);
                $requests[] = $request;
            }
        }
        return $requests;
    }

    /**
     * 从文件读取并解析。
     *
     * @return list<array<string,mixed>>
     */
    public static function parseFile(string $path, string $rel = ''): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('无法读取 .http 文件: ' . $path);
        }
        return (new self())->parse($content, $rel);
    }

    /**
     * @param list<array{line:int,text:string}> $block
     * @return array<string,mixed>|null
     */
    private function parseBlock(string $title, array $block): ?array
    {
        $group = null;
        $varName = '';
        $notes = [];
        $name = $title;
        $method = null;
        $url = '';
        $requestLine = 0;
        $requestIndex = -1;

        foreach ($block as $index => $entry) {
            $text = $entry['text'];
            if (preg_match(self::REQUEST_RE, $text, $m) === 1) {
                $method = $m[1];
                $url = $m[2];
                $requestLine = $entry['line'];
                $requestIndex = $index;
                break;
            }
            if ($text !== '' && $text[0] === '#') {
                $this->consumeComment($text, $group, $varName, $notes, $name);
            }
        }

        if ($method === null) {
            return null;
        }

        $headers = [];
        $seenHeaders = [];
        $body = '';
        $count = count($block);
        $j = $requestIndex + 1;

        while ($j < $count) {
            $text = $block[$j]['text'];

            if (trim($text) === '') {
                $j++;
                break;
            }
            if ($text[0] === ' ' || $text[0] === "\t") {
                // URL 续行（REST Client 多行 query）
                $url .= trim($text);
                $j++;
                continue;
            }
            if (preg_match(self::HEADER_RE, $text, $m) === 1) {
                $key = trim($m[1]);
                $lower = strtolower($key);
                if (!isset($seenHeaders[$lower])) {
                    $seenHeaders[$lower] = true;
                    $headers[$key] = $m[2];
                }
                $j++;
                continue;
            }
            // 非请求头、非续行的行：视为 body 起始（容错）
            break;
        }

        if ($j < $count) {
            $bodyLines = [];
            for (; $j < $count; $j++) {
                $bodyLines[] = $block[$j]['text'];
            }
            $body = rtrim(implode("\n", $bodyLines));
        }

        return [
            'name' => $name,
            'varName' => $varName,
            'method' => $method,
            'url' => $url,
            'path' => $this->extractPath($url),
            'headers' => $headers,
            'body' => $body,
            'notes' => implode("\n", $notes),
            'line' => $requestLine,
            'group' => ($group !== null && $group !== '') ? $group : '未分组',
        ];
    }

    /**
     * @param list<string> $notes
     */
    private function consumeComment(
        string $text,
        ?string &$group,
        string &$varName,
        array &$notes,
        string &$name
    ): void {
        if (preg_match('/^#\s*分组\s*[:：]\s*(.*)$/u', $text, $m) === 1) {
            $group = trim($m[1]);
            return;
        }
        if (preg_match('/^#\s*@name\s+(\S+)/', $text, $m) === 1) {
            $varName = $m[1];
            return;
        }

        $plain = trim((string) preg_replace('/^#+\s*/', '', $text));
        if ($name === '' && $plain !== '' && preg_match('/^待填参数\s*[:：]/u', $plain) !== 1) {
            $name = $plain;
            return;
        }
        $notes[] = rtrim($text);
    }

    /**
     * 去掉 `{{host}}` 前缀取 path；否则用 parse_url；无法取得时返回原串。
     */
    private function extractPath(string $url): string
    {
        if (preg_match('#^\{\{[^}]+\}\}(/.*)?$#', $url, $m) === 1) {
            return $m[1] ?? '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            return $path;
        }
        return $url;
    }
}
