<?php
declare(strict_types=1);

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/FileVars.php';

/**
 * HTTP 请求代理（供 api=send 使用）。
 *
 * 职责：
 *  1. 校验 method / url / headers / timeout（非法 -> SendProxyException httpCode=400）；
 *  2. 用调用方给定的变量表对 url、header 值、body 做 {{var}} 替换；
 *  3. 发送请求（优先 curl 扩展，缺失时回退 streams），记录 durationMs；
 *  4. 响应体超过 512KiB 截断并置 truncated=true；响应头收集为 {"Name": "value"}；
 *     网络失败 -> SendProxyException httpCode=502；
 *  5. 重定向：仅同主机跟随，最多 3 次；跨主机不跟随（原样返回 302），超过上限也原样返回 302。
 *
 * 典型用法（index.php）：
 *   $request  = SendProxy::prepare($decodedBody, (new Env())->vars($env));
 *   $response = (new SendProxy())->send($request);
 *
 * 变量优先级：环境变量（$shared + env 覆盖） < 文件级 `@var`（请求体 fileVars 字段，
 * 文件级更具体、覆盖环境变量）；fileVars 缺省 / null / 非数组时按空表处理。
 */
final class SendProxyException extends RuntimeException
{
    /** 建议的 HTTP 状态码（400 参数非法 / 502 网络失败）。 */
    public readonly int $httpCode;

    public function __construct(string $message, int $httpCode = 400)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
}

final class SendProxy
{
    /** 响应体截断阈值：512KiB。 */
    public const MAX_BODY_BYTES = 512 * 1024;

    /** 默认超时（秒）。 */
    public const DEFAULT_TIMEOUT = 15;

    /** 超时下限（秒）。 */
    public const MIN_TIMEOUT = 1;

    /** 超时上限（秒）。 */
    public const MAX_TIMEOUT = 60;

    /** 同主机重定向上限。 */
    public const MAX_REDIRECTS = 3;

    /**
     * 校验请求参数并做 {{var}} 替换。
     *
     * @param mixed                $input     已解析的 JSON 请求体（非数组 -> 400）
     *                                       可选字段 fileVars（对象）：文件级变量，优先于 $vars
     * @param array<string,mixed>  $vars      变量表（Env::vars() 的结果）
     * @param array<string,mixed>|null $fileVars 显式文件级变量；null 时取 $input['fileVars']
     *
     * @return array{method:string,url:string,headers:array<string,string>,body:string,timeout:int}
     *
     * @throws SendProxyException 参数非法（httpCode=400）
     */
    public static function prepare(mixed $input, array $vars, ?array $fileVars = null): array
    {
        if (!is_array($input)) {
            throw new SendProxyException('请求体必须是 JSON 对象');
        }

        // 文件级变量（缺陷 A）：优先于环境变量；缺省/null/非数组按空表处理（向后兼容）。
        $rawFileVars = $fileVars ?? ($input['fileVars'] ?? []);
        $vars = FileVars::merge($vars, self::normalizeFileVars($rawFileVars));

        $method = $input['method'] ?? null;
        if (!is_string($method) || preg_match('/^[A-Z]+$/', $method) !== 1) {
            throw new SendProxyException('method 非法（必须为大写字母，如 GET/POST）');
        }

        $url = $input['url'] ?? null;
        if (!is_string($url)) {
            throw new SendProxyException('url 必须是字符串');
        }
        $url = Env::interpolate(trim($url), $vars);
        if ($url === '') {
            throw new SendProxyException('url 不能为空');
        }
        self::assertUrl($url);

        $headers = self::prepareHeaders($input['headers'] ?? [], $vars);
        $body = self::prepareBody($input['body'] ?? null, $vars);

        return [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => self::normalizeTimeout($input['timeout'] ?? null),
        ];
    }

    /**
     * 规范化 fileVars：非数组返回空表；只保留字符串键（JSON 数组解出的整数键丢弃）。
     *
     * @return array<string,mixed>
     */
    private static function normalizeFileVars(mixed $fileVars): array
    {
        if (!is_array($fileVars)) {
            return [];
        }
        $clean = [];
        foreach ($fileVars as $key => $value) {
            if (is_string($key)) {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    /**
     * @param mixed                $rawHeaders
     * @param array<string,mixed>  $vars
     *
     * @return array<string,string>
     *
     * @throws SendProxyException
     */
    private static function prepareHeaders(mixed $rawHeaders, array $vars): array
    {
        if ($rawHeaders === null) {
            return [];
        }
        if (!is_array($rawHeaders) || ($rawHeaders !== [] && array_is_list($rawHeaders))) {
            throw new SendProxyException('headers 必须是对象（键为请求头名称）');
        }

        $headers = [];
        foreach ($rawHeaders as $name => $value) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            // 防头注入：请求头名称/值中的 CR/LF（含被折叠的空白）一律剔除。
            $name = str_replace(["\r", "\n"], '', $name);
            if ($name === '') {
                continue;
            }
            if (is_array($value)) {
                $parts = [];
                foreach ($value as $item) {
                    $parts[] = self::scalarToString($item, '请求头 ' . $name);
                }
                $value = implode(', ', $parts);
            } else {
                $value = self::scalarToString($value, '请求头 ' . $name);
            }
            $headers[$name] = self::stripHeaderBreaks(Env::interpolate($value, $vars));
        }
        return $headers;
    }

    /**
     * @param array<string,mixed> $vars
     *
     * @throws SendProxyException
     */
    private static function prepareBody(mixed $body, array $vars): string
    {
        if ($body === null) {
            $body = '';
        } elseif (is_array($body) || is_object($body)) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $body = $encoded === false ? '' : $encoded;
        } else {
            $body = self::scalarToString($body, 'body');
        }
        return Env::interpolate($body, $vars);
    }

    /** @throws SendProxyException */
    private static function scalarToString(mixed $value, string $label): string
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
        throw new SendProxyException($label . ' 的值类型不支持');
    }

    /** 剔除请求头值中的 CR/LF（防头注入；变量插值后再做一次）。 */
    private static function stripHeaderBreaks(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    /** url 必须是可解析的 http/https 绝对地址。 @throws SendProxyException */
    private static function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new SendProxyException('url 无法解析（需为 http/https 绝对地址）: ' . $url);
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SendProxyException('url 协议必须是 http 或 https: ' . $url);
        }
        if (trim((string) $parts['host']) === '') {
            throw new SendProxyException('url 缺少主机名: ' . $url);
        }
    }

    /** timeout：默认 15s，非数字回退默认值，夹在 [1, 60]。 */
    public static function normalizeTimeout(mixed $value): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return self::DEFAULT_TIMEOUT;
        }
        $timeout = (int) $value;
        return max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, $timeout));
    }

    /**
     * 响应体截断（按字节，保证不切坏 UTF-8 字符）。
     *
     * @return array{body:string,truncated:bool}
     */
    public static function truncate(string $body, ?int $limit = null): array
    {
        $limit = $limit ?? self::MAX_BODY_BYTES;
        if ($limit < 0) {
            $limit = 0;
        }
        if (strlen($body) <= $limit) {
            return ['body' => $body, 'truncated' => false];
        }
        $cut = substr($body, 0, $limit);
        return ['body' => self::trimIncompleteUtf8Tail($cut), 'truncated' => true];
    }

    /**
     * 若截断处恰好切在 UTF-8 多字节序列中间，则最多回退 3 字节到字符边界。
     *
     * 只做 O(1) 的尾部检查：
     *  - 尾部是 ASCII / 完整序列 / 连续字节（二进制数据）-> 原样返回，
     *    保证二进制响应能保留尽可能接近 limit 的数据；
     *  - 尾部是不完整的多字节序列 -> 回退到该序列的起始字节。
     */
    private static function trimIncompleteUtf8Tail(string $value): string
    {
        $length = strlen($value);
        if ($length === 0) {
            return $value;
        }

        $max = min(4, $length);
        for ($i = 1; $i <= $max; $i++) {
            $byte = ord($value[$length - $i]);
            if ($byte < 0x80) {
                return $value; // ASCII 字节：边界完整
            }
            if ($byte >= 0xC0) {
                // 前导字节：判断其声明的序列长度是否已被完整包含
                $needed = match (true) {
                    $byte >= 0xF0 && $byte <= 0xF4 => 4,
                    $byte >= 0xE0 && $byte <= 0xEF => 3,
                    $byte >= 0xC2 && $byte <= 0xDF => 2,
                    default => 0, // 0xC0/0xC1/0xF5-0xFF：非法前导，按二进制原样保留
                };
                if ($needed === 0 || $needed === $i) {
                    return $value;
                }
                return substr($value, 0, $length - $i);
            }
            // 0x80-0xBF：继续字节，向前继续找前导字节
        }

        return $value; // 4 字节内找不到前导字节 -> 二进制/无效 UTF-8，原样保留
    }

    /**
     * 发送已 prepare() 的请求，返回契约响应。
     *
     * @param array<string,mixed> $request prepare() 的返回值
     *
     * @return array{ok:bool,status:int,statusText:string,headers:array<string,string>,body:string,durationMs:int,truncated:bool}
     *
     * @throws SendProxyException 网络失败（httpCode=502）
     */
    public function send(array $request): array
    {
        $method = (string) ($request['method'] ?? 'GET');
        $url = (string) ($request['url'] ?? '');
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $body = (string) ($request['body'] ?? '');
        $timeout = self::normalizeTimeout($request['timeout'] ?? null);

        $result = function_exists('curl_init')
            ? $this->sendWithCurl($method, $url, $headers, $body, $timeout)
            : $this->sendWithStreams($method, $url, $headers, $body, $timeout);

        $rawBody = (string) ($result['body'] ?? '');
        $truncated = self::truncate($rawBody);

        return [
            'ok' => true,
            'status' => (int) ($result['status'] ?? 0),
            'statusText' => (string) ($result['statusText'] ?? ''),
            'headers' => is_array($result['headers'] ?? null) ? $result['headers'] : [],
            'body' => $truncated['body'],
            'durationMs' => (int) round((float) ($result['durationMs'] ?? 0)),
            'truncated' => $truncated['truncated'],
        ];
    }

    /**
     * curl 实现。
     *
     * @param array<string,string> $headers
     *
     * @return array{status:int,statusText:string,headers:array<string,string>,body:string,durationMs:float}
     *
     * @throws SendProxyException
     */
    private function sendWithCurl(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $current = $url;
        $redirects = 0;
        $originalHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        while (true) {
            $ch = curl_init();
            if ($ch === false) {
                throw new SendProxyException('curl 初始化失败', 502);
            }

            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            $responseHeaders = [];
            $options = [
                CURLOPT_URL => $current,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                        return $length;
                    }
                    $pos = strpos($line, ':');
                    if ($pos !== false) {
                        $name = trim(substr($line, 0, $pos));
                        if ($name !== '') {
                            $responseHeaders[$name] = trim(substr($line, $pos + 1));
                        }
                    }
                    return $length;
                },
            ];
            if ($headerLines !== []) {
                $options[CURLOPT_HTTPHEADER] = $headerLines;
            }
            // 纵深防御：只允许 http/https（含重定向目标）。
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
                $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
            } else {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            if ($body !== '' || in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $options);

            $start = microtime(true);
            $rawBody = curl_exec($ch);
            $durationMs = (microtime(true) - $start) * 1000;

            if ($rawBody === false) {
                $errno = curl_errno($ch);
                $error = curl_error($ch);
                curl_close($ch);
                throw new SendProxyException('网络请求失败: ' . $error . ' (curl ' . $errno . ')', 502);
            }

            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            $response = [
                'status' => $status,
                'statusText' => self::statusText($status),
                'headers' => $responseHeaders,
                'body' => (string) $rawBody,
                'durationMs' => $durationMs,
            ];

            if ($status < 300 || $status >= 400) {
                return $response;
            }

            $target = $responseHeaders['Location'] ?? $redirectUrl;
            if ($target === '') {
                return $response;
            }
            $resolved = self::resolveLocation($current, $target);
            $targetHost = strtolower((string) parse_url($resolved, PHP_URL_HOST));
            if ($targetHost !== $originalHost || $redirects >= self::MAX_REDIRECTS) {
                // 跨主机或超过上限：不跟随，原样返回该重定向响应。
                return $response;
            }
            $redirects++;
            $current = $resolved;
            if ($status === 303) {
                $method = 'GET';
                $body = '';
            }
        }
    }

    /**
     * streams 回退实现（curl 扩展缺失时），行为与 curl 分支等价。
     *
     * @param array<string,string> $headers
     *
     * @return array{status:int,statusText:string,headers:array<string,string>,body:string,durationMs:float}
     *
     * @throws SendProxyException
     */
    private function sendWithStreams(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $current = $url;
        $redirects = 0;
        $originalHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        while (true) {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            $http = [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => (float) $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ];
            if ($body !== '') {
                $http['content'] = $body;
            }

            $start = microtime(true);
            $rawBody = @file_get_contents($current, false, stream_context_create(['http' => $http]));
            $durationMs = (microtime(true) - $start) * 1000;

            if ($rawBody === false) {
                $error = error_get_last();
                throw new SendProxyException(
                    '网络请求失败: ' . (is_array($error) ? (string) ($error['message'] ?? '') : '') . ' (' . $current . ')',
                    502
                );
            }

            $responseHeaders = [];
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int) $m[1];
                    $responseHeaders = []; // 以最后一个响应为准
                    continue;
                }
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = trim(substr($line, 0, $pos));
                    if ($name !== '') {
                        $responseHeaders[$name] = trim(substr($line, $pos + 1));
                    }
                }
            }

            $response = [
                'status' => $status,
                'statusText' => self::statusText($status),
                'headers' => $responseHeaders,
                'body' => (string) $rawBody,
                'durationMs' => $durationMs,
            ];

            if ($status < 300 || $status >= 400 || !isset($responseHeaders['Location'])) {
                return $response;
            }
            $resolved = self::resolveLocation($current, $responseHeaders['Location']);
            $targetHost = strtolower((string) parse_url($resolved, PHP_URL_HOST));
            if ($targetHost !== $originalHost || $redirects >= self::MAX_REDIRECTS) {
                return $response;
            }
            $redirects++;
            $current = $resolved;
            if ($status === 303) {
                $method = 'GET';
                $body = '';
            }
        }
    }

    /** 相对 Location 解析为绝对地址。 */
    public static function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        $pos = strrpos($path, '/');
        $dir = $pos === false ? '/' : substr($path, 0, $pos + 1);
        return $origin . $dir . $location;
    }

    /** 常见状态码的 reason phrase。 */
    public static function statusText(int $status): string
    {
        static $map = [
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
            304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            405 => 'Method Not Allowed', 408 => 'Request Timeout', 409 => 'Conflict',
            413 => 'Payload Too Large', 415 => 'Unsupported Media Type', 422 => 'Unprocessable Entity',
            429 => 'Too Many Requests', 500 => 'Internal Server Error', 501 => 'Not Implemented',
            502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
        ];
        return $map[$status] ?? '';
    }
}
