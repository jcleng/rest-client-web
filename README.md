# rest-client-web — `.http` 实时预览与调试工作台

纯 PHP + 原生 JS 实现的 `.http`（VS Code REST Client 格式）预览/调试工具：
递归解析项目内所有 `.http` 文件，提供类 Swagger 的树形导航、请求详情、变量面板与
**Try it out**（服务端代理发送），界面每 5 秒自动感知文件变化并静默刷新。
无外部依赖：无 Composer、无 npm、无 CDN、无构建步骤。

## 启动

在项目根目录执行：

```bash
PHP_CLI_SERVER_WORKERS=20 php -S 127.0.0.1:8099
```

浏览器访问 `http://127.0.0.1:8099/` 即可。也兼容 FPM/Apache 部署（以项目根为 Web 根）。

> ⚠️ **安全提示（务必阅读）**
>
> - `api=vars` 会原样返回 `.vscode/settings.json` 中的**全部环境变量**（含 `merchantToken`、
>   `supplierToken`、`appKey` 等真实凭证）；
> - `api=send` 是一个通用 HTTP 代理，可从本机访问任意内网/外网地址；
>
> 因此**不要把该服务暴露到公网**，建议仅监听 `127.0.0.1`，不要使用 `0.0.0.0`。
> 如需临时分享，务必先自行加鉴权或改用隧道并限定访问人。

## 配置

### 扫描目录

**改 `config/httpviewer.config.json` 的 `dir` 字段即可**（缺省时回退到 `{"dir": ".", "exclude": []}`）：

```json
{
  "dir": ".",
  "exclude": [".git", "node_modules", ".superpowers", "assets", "lib", "config", "tests", "docs", "tools", "reports"]
}
```

- `dir`：扫描根目录，相对路径基于项目根解析，也可写绝对路径，但 `realpath` 后**必须位于项目根之内**（防目录穿越）；
- `exclude`：排除的**目录名**（不区分大小写，递归生效；`.` 开头的隐藏目录始终跳过）；
- 只有扩展名为 `.http` 的文件会被收录（大小写不敏感）。

生效链路：

| 环节 | 位置 | 说明 |
| --- | --- | --- |
| 读配置 | `lib/Config.php` `Config::load()` | 固定读 `<项目根>/config/httpviewer.config.json`，文件不存在则用默认值 |
| 解析 `dir` | `lib/Config.php` `resolveDir()` | 相对路径拼到项目根；绝对路径直接用；`realpath()` 校验必须落在项目根内，否则抛异常 |
| 使用 `dir` | `lib/Scanner.php` `tree()` | 以 `$config->dir()` 为扫描根，调用 `collect()` 递归收集 |
| 递归规则 | `lib/Scanner.php` `collect()` | 跳过隐藏目录与 `exclude` 命中的目录，只收 `.http` 文件 |
| 调用入口 | `index.php` `?api=tree` | `Config::load()` → `Scanner->tree()`；前端每 5 秒轮询一次 |

常用改法：

- 只扫某个子目录：`{"dir": "api"}` 或 `{"dir": "/abs/path/inside/project"}`；
- 需要扫到默认被排除的目录（如 `docs`）：从 `exclude` 中删除该项，或整体设为 `[]`；
- 项目根之外的目录不允许，会报 `配置目录不存在` 或 `配置目录越界（必须位于项目根目录内）`。

注意点：

1. `exclude` 只匹配目录名，不支持文件名/glob 排除；`dir` 指向的目录本身不受 `exclude` 影响，排除只作用于其子目录；
2. 项目根下直接放置的 `.http` 文件始终会被扫到（如 `http.http`）；
3. 解析结果按「文件 mtime + size」签名缓存，进程内静态缓存 + 系统临时目录 JSON 缓存（文件名含 `md5(dir)`，不同 `dir` 互不干扰）；
4. 单个文件解析失败不阻断整体，该文件节点带 `error` 字段且 `groups` 为空数组。

### 变量来源

取自 `.vscode/settings.json` 的 `rest-client.environmentVariables`，
`$shared` 为公共变量，同名环境键覆盖之。

```json
    "rest-client.environmentVariables": {
        "$shared": {
            "host": "https://xxx.xxx.com",
            "contentType": "application/json",
            "supplierToken": "",
            "merchantToken": "",
            "appKey": ""
        },
        "Mock环境": {
            "host": "https://console-mock.apipost.cn/mock/"
        },
        "127.0.0.1:8505": {
            "host": "http://127.0.0.1:8505",
            "merchantToken": ""
        }
    }
```

## API 一览

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| `index.php?api=tree` | GET | 解析树：`{ok, root, version, count, files[]}`；`version` 为文件 mtime+size 签名，变化即有文件增删改；每个请求对象含 `id/name/varName/method/url/path/headers/body/notes/raw/line`（`raw` 为该请求块逐字原文，含 `###` 标题与注释） |
| `index.php?api=vars` | GET | 变量表：`{ok, vars, source, environments[], env?, warning?}`；`?env=<名称>` 叠加对应环境；`environments` 为环境名列表（不含 `$shared`） |
| `index.php?api=send` | POST | 服务端请求代理：入参 `{method, url, headers, body, timeout, env?}`；出参 `{ok, status, statusText, headers, body, durationMs, truncated}`；`{{var}}` 按变量表替换后发送；响应体超 512KB 截断并置 `truncated:true`；参数非法返回 400，网络失败返回 502，错误体为 `{ok:false, error}` |

静态资源：`assets/style.css`、`assets/app.js`（页面模板内联于 `index.php`）。

## 界面功能

- **树形导航**：文件 → 分组 → 请求三级折叠；全局搜索（名称/method/路径/varName，命中自动展开）；
- **请求详情**：方法徽章、URL（`{{var}}` 高亮并带真实值 tooltip）、请求头表格、body 预览
  （JSON 自动格式化 + token 着色）、备注、**原始 `.http` 逐字片段**（含文件与行号，可复制）；
- **Try it out**：环境切换（下拉，选中后重拉变量并刷新 URL/请求头）、method/URL/请求头（可增删行）/
  body/超时均可编辑，`{{var}}` 发送前替换；响应视图含状态码徽章（2xx 绿 / 4xx 橙 / 5xx 红）、耗时、
  响应头表格、响应体（JSON 高亮、超长折叠、复制、截断提示），错误显示可读横幅；
- **变量面板**：变量键值列表，长值折叠、可复制（值不会明文常驻展示）；
- **实时刷新**：每 5 秒轮询 `version`，变化时静默刷新树并保持选中项与滚动位置；
- **主题**：浅色/深色切换（localStorage 记忆）。
