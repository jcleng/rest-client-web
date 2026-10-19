<?php
declare(strict_types=1);

/**
 * 变量解析链路测试（零依赖，直接 php tests/run.php 运行）。
 *
 * 覆盖：
 *   1. Config::resolveScannedFile —— api=send 的 file 参数安全校验；
 *   2. FileVars::merge —— 环境变量 < 文件级 @var；
 *   3. SendProxy::prepare —— 用显式 fileVars 插值 url/headers/body。
 */

require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/Env.php';
require_once __DIR__ . '/../lib/FileVars.php';
require_once __DIR__ . '/../lib/SendProxy.php';

$pass = 0;
$fail = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label\n       期望: " . var_export($expected, true) . "\n       实际: " . var_export($actual, true) . "\n";
    }
}

$root = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
$config = new Config([], $root);

echo "== 1. Config::resolveScannedFile 安全校验 ==\n";
check('项目根下的 .http 可解析', $config->resolveScannedFile('http2.http'), $root . '/http2.http');
check('扫描根为子目录时，根目录文件不可达（../ 逃逸）', (new Config(['dir' => 'config'], $root))->resolveScannedFile('../http2.http'), null);
check('反斜杠形式的 ../ 逃逸同样被拒', (new Config(['dir' => 'config'], $root))->resolveScannedFile('..\\http2.http'), null);
check('拒绝 .. 越界', (new Config(['dir' => 'config'], $root))->resolveScannedFile('../../etc/passwd'), null);
check('拒绝绝对路径', (new Config([], $root))->resolveScannedFile('/etc/passwd'), null);
check('拒绝 Windows 绝对路径', (new Config([], $root))->resolveScannedFile('C:/x.http'), null);
check('拒绝非 .http 扩展名', (new Config([], $root))->resolveScannedFile('index.php'), null);
check('不存在的文件', (new Config([], $root))->resolveScannedFile('nope.http'), null);
check('空字符串', (new Config([], $root))->resolveScannedFile(''), null);
check('null', (new Config([], $root))->resolveScannedFile(null), null);
check('反斜杠分隔符可解析', (new Config([], $root))->resolveScannedFile('http2.http'), realpath(__DIR__ . '/../http2.http'));

echo "== FileVars::merge（文件级 < 环境变量）==\n";
$env = ['host' => 'ENV_HOST', 'url' => 'ENV_URL', 'token' => 'ENV_TOKEN'];
$file = ['host' => 'FILE_HOST', 'url' => 'FILE_URL'];
check('环境变量覆盖同名文件级 @var（用户界面选择优先）', FileVars::merge($env, $file)['host'], 'ENV_HOST');
check('文件级独有的键保留（环境里没有）', FileVars::merge($env, ['onlyInFile' => 'FILE_ONLY'])['onlyInFile'], 'FILE_ONLY');
check('两边都有的键以环境为准（url）', FileVars::merge($env, $file)['url'], 'ENV_URL');
check('环境变量兜底（文件里没定义）', FileVars::merge($env, [])['token'], 'ENV_TOKEN');
check('空文件级不改变结果', FileVars::merge($env, []), $env);
check('空环境变量时文件级生效', FileVars::merge([], $file)['host'], 'FILE_HOST');
check('两者都空', FileVars::merge([], []), []);

echo "== SendProxy::prepare：环境变量优先于文件级 @var ==\n";
$req = SendProxy::prepare([
    'method' => 'POST',
    'url' => '{{host}}/supplier/register',
    'headers' => ['Authorization' => 'Bearer {{token}}'],
    'body' => '{"u":"{{url}}"}',
], ['host' => 'https://env.example.com', 'token' => 'ENV_T'],
   ['host' => 'https://file.example.com', 'url' => 'https://file-only.example.com']);
check('环境变量覆盖文件级 host', $req['url'], 'https://env.example.com/supplier/register');
check('header 用环境值', $req['headers']['Authorization'], 'Bearer ENV_T');
check('文件级独有的键仍生效', $req['body'], '{"u":"https://file-only.example.com"}');

echo "== 关键场景：文件里 @host 固定，用户切环境应以后者为准 ==\n";
$req2 = SendProxy::prepare(
    ['method' => 'GET', 'url' => '{{host}}/supplier/login'],
    ['host' => 'https://线上正式.example.com'],          // 用户界面选的环境
    ['host' => 'https://文件里写死的.example.com']        // .http 里的 @host
);
check('用户选的环境胜出', $req2['url'], 'https://线上正式.example.com/supplier/login');

echo "== prepare 的 fileVars 缺省/非法输入 ==\n";
$req2 = SendProxy::prepare(['method' => 'GET', 'url' => '{{u}}/x'], ['u' => 'https://env.example.com']);
check('无 fileVars 时用环境值', $req2['url'], 'https://env.example.com/x');

echo "\n通过 $pass / 失败 $fail\n";
exit($fail === 0 ? 0 : 1);
