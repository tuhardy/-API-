# 网关数据测试台

用于验证设备回调是否到达服务器，并查看每次请求的原始内容和解析结果。系统按四个原有 PHP 脚本划分功能，采用 **原生 PHP + SQLite 索引 + 独立原始文件**，适合低并发设备联调。

当前版本不包含短信发送、任务管理、Redis、队列、MySQL、Vue、Node.js 或后台 worker。

## 1. 最高优先级：原始数据目录只读

原始资料目录：

```text
C:\Users\EDY\Desktop\API服务器\原始数据
```

开发、测试、部署和后续历史导入必须始终遵守：

1. **不得修改、覆盖、追加、移动、重命名或删除 `原始数据` 中的任何文件。**
2. 不在该目录创建程序、数据库、日志、缓存、上传文件或临时文件。
3. 需要使用的脚本、协议文档或历史日志，必须先复制到 `message-system` 内的工作目录，再处理副本。
4. 正式程序不读取或写入该目录，设备新数据只写入本项目的 `storage`。
5. `reference/legacy-php` 中的四个旧脚本仅是未修改的参考副本，不执行、不部署、不公开。
6. 测试只使用 `tests/fixtures` 中的脱敏样例，不依赖原始目录。

已复制的参考资料及 SHA-256 清单位于 `reference/`。历史日志目前**未复制、未导入**。

## 2. 已实现功能

### 四个独立入口和页面

| 功能 | 设备回调入口 | 原始列表 | 支持的主要解析内容 |
|---|---|---|---|
| 短信接收 | `public/sms_forward.php` | 短信原始记录 | 旧文本格式及 Gateway 2.4 `recv-sms` JSON |
| 状态报告 | `public/sms_status_report.php` | 状态报告原始记录 | TID、发送计数、`sdr`/`fdr`、号码、端口和失败原因 |
| 端口状态 | `public/status_note.php` | 端口状态原始记录 | MAC、端口、SIM、IMEI、IMSI、ICCID、运营商、信号、流量等 |
| 彩信接收 | `public/mms_receive.php` | 彩信原始记录 | `Emms-*` 请求头、SMIL、正文、图片及其他附件 |

页面层级固定为：

```text
四个脚本入口 → 原始数据接收列表 → 单条记录的独立解析页面
```

列表首先显示最近接收状态、记录总数、保存成功/失败数和原始记录。点击记录 ID、原始摘要或“查看解析”后，进入独立页面，依次查看：

1. 原始请求体，支持复制和下载；二进制显示十六进制摘要。
2. 请求方法、URL、来源 IP、Content-Type、可获取请求头、时间和 SHA-256。
3. 解析状态、字段、表格、文本和彩信附件。

原始保存状态与解析状态相互独立：**无效 JSON 或无法识别的格式仍可以先成功保存，随后才在解析页显示解析失败。**

### 原始数据保护

- 请求体在解析前逐字节保存为 `body.bin`，包括空请求体和彩信二进制内容。
- 请求元数据保存为 `request.json`，SQLite 只保存接收索引，不替代原始文件。
- 每条记录使用随机 32 位十六进制 ID 和独立目录，以独占创建方式写文件，不覆盖已有记录。
- 重复请求生成新 ID，不去重、不更新或覆盖旧记录。
- 默认最大请求体为 20 MB；超限返回 HTTP 413，存储失败返回 HTTP 500。
- 成功保存后返回 `Data has been logged.`。
- 彩信附件在查看时从原始 MIME 中按需提取，不修改 `body.bin`，也不对附件二进制使用 `trim`。
- 原始文件不自动删除、不自动轮转；可在原始列表中勾选本页记录，手动批量永久删除。
- 批量删除同时移除数据库索引、`body.bin`、`request.json` 和为空的记录目录，未选中的记录不受影响。

存储结构：

```text
storage/
├── app.sqlite
├── logs/                         # 仅在接收错误等情况下产生
└── raw/{channel}/{Y/m/d}/{id}/
    ├── body.bin
    └── request.json
```

目录日期和数据库接收时间以 UTC 保存，页面默认按 `Asia/Shanghai` 显示。

## 3. 环境要求

- PHP 8.2 或更高版本；本机验证版本为 PHP 8.5.9。
- PHP 扩展：`pdo_sqlite`（同时需要 PHP 自带的 PDO）。
- 一个可写的私有存储目录。
- 无需 Composer、npm 或数据库服务。

检查环境：

```bash
php -v
php -m
```

`php -m` 的输出应包含 `PDO` 和 `pdo_sqlite`。如果 CLI 与 Web 使用不同 PHP 配置，两边都要启用 SQLite。

本机 PHP 的扩展目录不在默认配置中，因此本机命令需要附加：

```bash
php \
  -d extension_dir="D:\php\php-8.5.9-Win32-vs17-x64\ext" \
  -d extension=pdo_sqlite \
  ...
```

## 4. 本机启动

在本 README 所在目录执行：

```bash
php -S 127.0.0.1:8765 -t public
```

本机当前环境使用完整命令：

```bash
php \
  -d extension_dir="D:\php\php-8.5.9-Win32-vs17-x64\ext" \
  -d extension=pdo_sqlite \
  -S 127.0.0.1:8765 -t public
```

打开 `http://127.0.0.1:8765/`。PHP 内置服务器仅用于本机联调，不作为公网生产服务器。

首次访问会自动创建 SQLite 表。设备回调地址为：

```text
http://服务器地址/sms_forward.php
http://服务器地址/sms_status_report.php
http://服务器地址/status_note.php
http://服务器地址/mms_receive.php
```

## 5. 配置

通过环境变量配置，无需修改代码：

| 环境变量 | 默认值 | 用途 |
|---|---|---|
| `TEST_CONSOLE_STORAGE` | 项目内 `storage` | 私有存储根目录，数据库和原始文件都位于其中 |
| `TEST_CONSOLE_TIMEZONE` | `Asia/Shanghai` | 页面显示时区 |
| `TEST_CONSOLE_ADMIN_USER` | `admin` | 后台 HTTP Basic Auth 用户名 |
| `TEST_CONSOLE_ADMIN_PASSWORD` | 空 | 后台 HTTP Basic Auth 密码；空值表示不启用认证，仅限本机 |
| `TEST_CONSOLE_CALLBACK_TOKEN` | 空 | 四个设备回调共用的可选令牌 |

其他限制在 `config/config.php` 中：请求体上限 20 MB、每页 20 条、文本原始内容页面显示上限 2 MB。

启用回调令牌后，设备可发送请求头：

```text
X-Callback-Token: 你的令牌
```

也兼容 `?token=你的令牌`，但查询参数可能进入访问日志，优先使用请求头。回调令牌与后台密码是两套独立认证。

**公网或局域网共享部署前必须设置后台密码，并启用 HTTPS。** 请求头、来源 IP 和原始内容可能包含手机号、设备标识等敏感数据，应限制存储目录和备份的访问权限。

PowerShell 配置示例：

```powershell
$env:TEST_CONSOLE_ADMIN_USER = "admin"
$env:TEST_CONSOLE_ADMIN_PASSWORD = "替换为强密码"
$env:TEST_CONSOLE_CALLBACK_TOKEN = "替换为独立回调令牌"
```

## 6. 正式部署

1. 将整个项目放到 Web 文档根目录之外或按项目目录部署，但 **Web 根目录只能指向 `public`**。
2. 使用 Nginx/Apache + PHP-FPM，或现有支持 PHP 的 Web 服务；不要使用 PHP 内置服务器承载公网流量。
3. 确保 PHP 进程对 `TEST_CONSOLE_STORAGE` 有创建目录和读写文件的权限。
4. 确保 `storage`、`app`、`config`、`reference`、`docs` 和 `tests` 不能通过 HTTP 访问。项目附带的 `.htaccess` 只是 Apache 下的辅助限制，不能代替正确的 Web 根目录配置。
5. 配置 HTTPS；设备来源 IP 固定时，可在 Web 服务器或防火墙增加来源限制。
6. 将四个设备回调 URL 指向新入口。上线已有同名文件时，先另行备份旧文件，不覆盖原项目资料。

后台页面、原始下载和彩信附件均经过可选 Basic Auth；设备回调不使用后台认证，以免阻断设备请求。

## 7. 测试

### 单元与存储测试

```bash
php -d extension_dir="D:\php\php-8.5.9-Win32-vs17-x64\ext" \
  -d extension=pdo_sqlite tests/run.php
```

覆盖四类解析、中文与 Emoji、无效 JSON、空请求、重复请求不覆盖、原始文件字节一致性、MIME 二进制附件和 SQLite 查询。

### 实际 HTTP 验证

先在一个终端启动使用隔离存储的服务器：

```bash
TEST_CONSOLE_STORAGE="$PWD/storage/http-smoke" php \
  -d extension_dir="D:\php\php-8.5.9-Win32-vs17-x64\ext" \
  -d extension=pdo_sqlite \
  -S 127.0.0.1:8765 -t public
```

再在另一个终端执行：

```bash
php -d extension_dir="D:\php\php-8.5.9-Win32-vs17-x64\ext" \
  -d extension=pdo_sqlite tests/http_verify.php
```

HTTP 测试默认使用 `storage/http-smoke`，也可通过 `TEST_CONSOLE_STORAGE` 和 `TEST_CONSOLE_URL` 指定与服务器一致的存储和地址。每次测试会生成四条脱敏样例和一条无效 JSON 记录，并在最后验证批量删除其中两条短信记录；其余测试记录保留。不会清理旧测试记录，也不应指向正式存储。

截图位于 `tests/screenshots/`。

## 8. 保留期限与批量删除

**默认没有到期时间，记录会一直保留，系统不自动清理。** 实际可保留时长取决于磁盘空间和备份策略，应定期监控 `storage` 的占用。

需要手动清理时，在任一原始数据列表中：

1. 勾选一条或多条记录；表头复选框可选择本页全部记录。
2. 点击“删除选中”，核对数量并确认永久删除。
3. 系统删除所选记录的数据库索引、原始请求体、请求元数据和为空的记录目录。

批量选择只作用于当前页，不会删除其他页或其他三个功能的数据。删除操作使用 POST 和 CSRF 令牌，结果会显示实际成功及失败数量。**删除后无法通过系统恢复，操作前应先完成必要备份。** 原始资料目录 `原始数据` 永远不在删除范围内。

## 9. 备份与恢复

必须同时备份：

- `storage/app.sqlite`：记录索引。
- `storage/raw/`：全部原始请求体和请求元数据。
- 如存在，`storage/logs/`：接收错误日志。

最简单的低并发备份方式是先暂停设备写入并停止 PHP/Web 服务，再复制整个存储目录，最后恢复服务。运行中直接复制 SQLite 可能遗漏 WAL 中的数据；如需在线备份，应使用 SQLite 一致性备份工具，并同时备份原始文件。

恢复时将数据库和原始文件放回同一个 `TEST_CONSOLE_STORAGE`，保持目录结构并确保 PHP 可读写。不要只恢复数据库，否则列表仍有索引但原始文件不可用。

## 10. 当前边界

- 尚未实现历史日志导入；旧日志不会自动出现在新列表中。
- 不提供短信发送、设备远程控制、自动刷新、多用户角色或统计报表。
- 不提供按时间自动清理；磁盘空间需要由部署方监控，手动批量删除需逐页勾选并确认。
- EIMS 3.5 与 Gateway 2.4 是不同协议；当前只实现上述四个现有回调所需的格式。

前端设计说明见 [docs/FRONTEND_DESIGN.md](docs/FRONTEND_DESIGN.md)，方案决策记录见 [docs/TEST_CONSOLE_OPTIONS.md](docs/TEST_CONSOLE_OPTIONS.md)。
