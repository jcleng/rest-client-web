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
  "dir": "http",
  "exclude": [".git", "node_modules", ".superpowers", "assets", "lib", "config", "tests", "docs", "tools", "reports"]
}
```

> 当前 `dir` 指向项目根下的 `http/` 目录（Docker 部署时该目录就是挂载点：`-v "$(pwd)/http:/app/http"`）。
> 注意 `realpath` 必须落在项目根内，容器里不能直接写 `/http`，挂载点必须在 `/app` 下。

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
2. 项目根下直接放置的 `.http` 文件只有在 `dir` 为 `.` 时才会被扫到；当前 `dir` 为 `http`，只扫 `http/` 目录内的 `.http` 文件；
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

### 变量解析链路

变量解析**全部在 PHP 侧完成**，前端只负责渲染与传参：

```
PHP 读 .http（api=tree，含文件级 @var）+ settings.json（api=vars，环境变量）
      ↓  渲染到界面（变量面板、URL 真实值预览）
用户在顶部切换「环境」 → 前端 fetchVars(env) 重取变量表 → 重渲染
      ↓  发送时前端只上送 { url/headers/body 模板, file, env }
PHP api=send 自行读 file 指向的 .http 取文件级变量，插值后代理发送
```

优先级（由低到高）：**文件级 `@var` < 环境变量（`$shared` + env 覆盖）**。

即：`.http` 里的 `@host=...` 只是**默认值**，用户在顶部选中某个环境后，环境里的同名键会覆盖它。
文件里定义了、环境里没有的键（如 `@token2`、`@admintoken`）仍然生效。

- 前端不做变量替换，上送的 `url`/`headers`/`body` 始终是含 `{{var}}` 的模板；
- `file` 参数由 `Config::resolveScannedFile()` 校验：必须是扫描目录内的相对路径，
  绝对路径、`..` 越界、非 `.http` 扩展名、不存在的文件一律 400；
- 界面上「实际请求」预览与详情区 URL 行展示的是前端按同一规则算出的真实地址，仅供查看。

## API 一览

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| `index.php?api=tree` | GET | 解析树：`{ok, root, version, count, files[]}`；`version` 为文件 mtime+size 签名，变化即有文件增删改；每个请求对象含 `id/name/varName/method/url/path/headers/body/notes/raw/line`（`raw` 为该请求块逐字原文，含 `###` 标题与注释） |
| `index.php?api=vars` | GET | 变量表：`{ok, vars, source, environments[], env?, warning?}`；`?env=<名称>` 叠加对应环境；`environments` 为环境名列表（不含 `$shared`） |
| `index.php?api=send` | POST | 服务端请求代理：入参 `{method, url, headers, body, timeout, file?, env?}`（`url`/`headers`/`body` 传**模板原文**，`file` 为扫描目录内的 `.http` 相对路径）；出参 `{ok, status, statusText, headers, body, durationMs, truncated}`；`{{var}}` 由**服务端**按「文件级 `@var` < 环境变量」解析后发送（界面选的环境优先于文件里写死的值）；`file` 非法（绝对路径 / `..` 越界 / 非 `.http` / 不存在）返回 400；响应体超 512KB 截断并置 `truncated:true`；网络失败返回 502，错误体为 `{ok:false, error}` |

静态资源：`assets/style.css`、`assets/app.js`（页面模板内联于 `index.php`）。

## 测试

```bash
php tests/run.php     # 变量优先级、file 参数安全校验、prepare 插值（零依赖）
```

## 界面功能

- **树形导航**：文件 → 分组 → 请求三级折叠；全局搜索（名称/method/路径/varName，命中自动展开）；
- **请求详情**：方法徽章、URL（主显替换后的真实地址，副行保留 `{{var}}` 模板）、请求头表格、
  body 预览（JSON 自动格式化 + token 着色）、备注、**原始 `.http` 逐字片段**（含文件与行号，可复制）；
- **环境切换**：顶部 header 全局下拉（环境名来自 `settings.json`），切换后重取变量表并整页重渲染，
  选择由 `localStorage` 记忆；记忆的环境名若已不存在会自动回落到默认；
- **Try it out**：method/URL/请求头（可增删行）/body/超时均可编辑；
  URL 输入框显示替换后的真实地址并**随环境切换动态变更**（下方小字为原始模板），
  手工编辑后该请求不再跟随环境；`{{var}}` 由服务端在发送时解析；响应视图含状态码徽章
  （2xx 绿 / 4xx 橙 / 5xx 红）、耗时、响应头表格、响应体（JSON 高亮、超长折叠、复制、截断提示），
  错误显示可读横幅；
- **变量面板**：变量键值列表，长值折叠、可复制（值不会明文常驻展示）；
- **实时刷新**：每 5 秒轮询 `version`，变化时静默刷新树并保持选中项与滚动位置；
- **主题**：浅色/深色切换（localStorage 记忆）。

- `.http`实例

```http
@url=https://testagent1.test.com/prod
### 服务商登录
# 分组: 服务商
POST {{url}}/supplier/login
Content-Type: application/json

{
    "email": "j.c.leng@foxmail.com",
    "password": "666666",
    "code": "666666"
}

```
