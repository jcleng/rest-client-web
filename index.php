<?php
declare(strict_types=1);

/**
 * 入口路由。
 *  - GET  ?api=tree     -> 解析树 JSON
 *  - GET  ?api=vars     -> .vscode 变量表 JSON（可选 env=<名称>）
 *  - POST ?api=send     -> 服务端请求代理 JSON
 *                          变量全部由服务端解析：env 指定环境，file 指定 .http 相对路径
 *                          （服务端自行读取该文件的 `@var`，前端只传模板不传变量值）
 * 异常统一返回 {"ok":false,"error":...}（HTTP 400/404/405/500/502）。
 */

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/FileVars.php';
require_once __DIR__ . '/lib/HttpParser.php';
require_once __DIR__ . '/lib/Scanner.php';
require_once __DIR__ . '/lib/Env.php';
require_once __DIR__ . '/lib/SendProxy.php';

$api = isset($_GET['api']) && is_string($_GET['api']) ? $_GET['api'] : '';

if ($api === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo pageHtml();
    exit;
}

// api=send 的响应体可能是任意二进制，编码时替换非法 UTF-8 字节，避免整体 JSON 失败。
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

try {
    if ($api === 'tree') {
        $config = Config::load();
        $payload = (new Scanner($config))->tree();
    } elseif ($api === 'vars') {
        $payload = handleVars();
    } elseif ($api === 'send') {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        $payload = handleSend();
    } else {
        http_response_code(404);
        $payload = ['ok' => false, 'error' => '未知的 API: ' . $api];
    }
} catch (SendProxyException $e) {
    http_response_code($e->httpCode);
    $payload = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    http_response_code(500);
    $payload = ['ok' => false, 'error' => $e->getMessage()];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, $jsonFlags);

/**
 * ?api=vars：返回变量表（$shared 叠加 env），source 为变量文件相对路径。
 *
 * @return array<string,mixed>
 */
function handleVars(): array
{
    $env = $_GET['env'] ?? null;
    if (!is_string($env)) {
        $env = null;
    }

    $envLoader = new Env();
    $vars = $envLoader->vars($env);

    $payload = [
        'ok' => true,
        'vars' => $vars,
        'source' => $envLoader->source(),
    ];
    if ($env !== null && $env !== '') {
        $payload['env'] = $env;
    }
    $warnings = $envLoader->warnings();
    if ($warnings !== []) {
        $payload['warning'] = implode('; ', $warnings);
    }
    // Task 4 前端环境下拉所需的环境名列表（附加字段，不影响既有契约）。
    $payload['environments'] = $envLoader->envs();

    return $payload;
}

/**
 * ?api=send：校验并代理一次 HTTP 请求。
 *
 * 变量解析全部在服务端完成（前端只传模板与 file/env 名字）：
 *   环境变量（settings.json，$shared + env 覆盖） < 文件级 @var（file 指向的 .http 文件）。
 *
 * @return array<string,mixed>
 */
function handleSend(): array
{
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod !== 'POST') {
        http_response_code(405);
        return ['ok' => false, 'error' => 'api=send 仅支持 POST'];
    }

    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        http_response_code(400);
        return ['ok' => false, 'error' => '请求体必须是合法 JSON 对象'];
    }

    $env = $decoded['env'] ?? null;
    if (!is_string($env)) {
        $env = null;
    }

    $envLoader = new Env();
    $vars = $envLoader->vars($env);

    // 文件级变量：由 file（扫描根内的相对路径）在服务端读取，前端不参与变量解析。
    $config = Config::load();
    $fileVars = [];
    $fileRel = $decoded['file'] ?? null;
    if (is_string($fileRel) && trim($fileRel) !== '') {
        $abs = $config->resolveScannedFile($fileRel);
        if ($abs === null) {
            http_response_code(400);
            return ['ok' => false, 'error' => 'file 非法（必须是扫描目录内的 .http 相对路径）: ' . $fileRel];
        }
        $fileVars = FileVars::extractFile($abs);
    }

    $request = SendProxy::prepare($decoded, $vars, $fileVars);
    $response = (new SendProxy())->send($request);
    // 契约要求响应头为对象：空数组时 json_encode 会得到 []，此处强转保证恒为 {}。
    $response['headers'] = (object) $response['headers'];

    $warnings = $envLoader->warnings();
    if ($warnings !== []) {
        $response['warning'] = implode('; ', $warnings);
    }

    return $response;
}

function pageHtml(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API 工作台</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div id="app"></div>
<script src="assets/app.js" defer></script>
</body>
</html>
HTML;
}
