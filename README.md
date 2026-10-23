# 拾记

基于 PHP 与 MySQL 的单管理员聊天式笔记系统。按分组保存文字和附件，支持多设备同步、临时匿名投递，以及 Nextcloud Notes 客户端兼容接口。

界面使用本地 Tabler 样式和组件，适配桌面与手机。正式数据保存在服务器数据库和附件目录中，不使用 localStorage 保存业务数据。

## 依赖与许可

本项目无需 Composer 或 npm 构建。随包资源包括 Tabler 1.4.0、Bootstrap 5.3.7
和 Popper 定位组件；运行环境、可选服务及发布隐私清单见 [依赖说明](DEPENDENCIES.md)。
第三方资源的来源、版权及许可正文见 [第三方声明](THIRD_PARTY_NOTICES.md)，分发时请一并保留。
项目自身的许可证尚未选定，第三方 MIT 声明不代表本项目所有源码均已获 MIT 授权。

## 功能

- 分组新建、改名和删除：只有无消息的普通分组可删除，匿名收件箱不可删除。
- 文字、附件、图片预览、复制、多选删除与历史分页，支持粘贴图片。
- Enter 换行，Shift+Enter 发送；发送失败保留内容和待上传附件。
- 多设备轮询同步，默认每 5 秒检查更新，后台和网络异常时降低请求频率。
- 默认保持登录 30 天，同浏览器重新登录复用设备记录，支持撤销登录设备。
- Cloudflare 真实 IP 识别、登录 IP 审计和可选的自建 IP 归属地查询。
- 六位临时匿名链接，只允许投递到匿名收件箱，不提供历史读取权限。
- Nextcloud Notes 兼容接口，支持浏览器授权、文字笔记增删改查、分类映射与编辑冲突检测。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.4 或以上 |
| 数据库 | MySQL 8.0 或以上、utf8mb4；不支持 MariaDB、SQLite |
| 扩展 | PDO、pdo_mysql、fileinfo、mbstring、json、openssl、session |
| Office 附件 | 上传 docx、xlsx、pptx 时需要 zip 扩展 |
| Web 服务器 | Apache 2.4 + mod_rewrite，或 Nginx + PHP-FPM |
| 传输 | 生产环境必须使用 HTTPS |
| 写入权限 | 配置目录、附件目录和 PHP Session 存储目录可写 |
| 其他 | 安装器需要 PHP `link()`；IP 查询需要 `allow_url_fopen=On` |

建议的 PHP 上传配置：

```ini
upload_max_filesize = 10M
post_max_size = 22M
max_file_uploads = 5
```

Web 服务器的请求体大小限制也需匹配。不要使用 `777` 权限代替正确的目录所有者和权限配置。

## 新站安装

1. 创建独立的空 MySQL 数据库及用户，授予该数据库的建表、索引和数据读写权限。安装器不创建数据库本身或数据库账号。
2. 将部署包解压到网站根目录，保留所有 `.htaccess` 文件。新站不需要预建私有配置或手动导入 SQL。
3. 配置 HTTPS。Apache 需启用重写并允许 `.htaccess` 生效；Nginx 不读取 `.htaccess`，参考 [Nginx 示例](deploy/nginx.conf.example)，调整证书、网站根目录和 PHP-FPM socket。
4. 允许 PHP 写入 `config/` 和附件目录。附件优先存放在网站根目录外；使用默认 `storage/` 时，必须确认目录访问保护生效。
5. 打开 `https://你的域名/install.php`，检查环境并填写数据库、站点地址、时区、附件路径、保持登录天数、Cloudflare 开关和管理员账号。管理员密码至少 12 字符、最多 72 字节。
6. 如需显示归属地，填写 IP 服务地址和 Token；Token 留空不启用查询，不影响真实 IP 识别。提交安装后进入 `login.php` 登录。

安装器自动创建全部业务表，包括 Nextcloud 兼容、持久登录和 IP 缓存表；生成随机应用密钥及权限为 `0600` 的 `config/config.php`、`config/ip.php`。已有 IP 配置会保留，不回显或覆盖令牌。

安装完成自动锁定入口，已有管理员也会阻止重复初始化。建议生产安装完成后删除 `install.php`。不要通过删除配置或锁文件来重置已有站点。

失败时，安装器会回滚本次管理员数据并清理本次发布的配置，保留可重试的进度。MySQL 建表 DDL 不可事务回滚；应使用同一独立数据库重试，不与其他应用混用数据库。

### 子目录与代理

子目录部署时，站点地址必须包含目录，例如 `https://example.com/notes`，Nginx 路由也要相应调整。

TLS 在反向代理终止时，必须由可信服务器配置向 PHP 提供正确的 HTTPS 状态。程序不直接信任客户端发送的 `X-Forwarded-*`。

上线前确认 `config/`、`app/`、`storage/`、`database/`、`scripts/` 等私有路径无法直接访问，应返回 403 或 404。

## 已有站点升级

1. 先备份数据库、附件和私有配置。
2. 按需导入下列升级 SQL；新站自动安装已包含这些表，不必重复导入。
3. 更新程序及 Web 路由，保留已有 `config/config.php`、`config/ip.php` 和附件。
4. 刷新浏览器静态资源缓存，必要时清理 CDN 缓存、重载 PHP OPcache，再检查功能。

| 升级内容 | SQL 文件 |
| --- | --- |
| Nextcloud 元数据和客户端授权 | [NextcloudAPI/migration.sql](NextcloudAPI/migration.sql) |
| 浏览器设备、登录审计和 IP 缓存 | [database/upgrade-persistent-login.sql](database/upgrade-persistent-login.sql) |

这些脚本使用 `CREATE TABLE IF NOT EXISTS`，可重复执行以创建缺失表，但不会修复结构不同的已有表。必须选中程序实际使用的数据库。

已有站点不通过安装入口升级，也不需要重新安装。干净部署包不包含真实数据库配置、IP Token、已上传文件或用户数据。

## 配置与登录

| 文件 | 用途 |
| --- | --- |
| `config/config.php` | 数据库、站点地址、密钥、附件路径及业务限制 |
| `config/ip.php` | IP 服务地址、Token、超时和缓存时间 |
| `config/*.example.php` | 配置示例，不是生效配置 |

安装器自动生成私有配置。高级部署可用 `SHIJI_CONFIG` 指定主配置位置；IP 配置仍读取项目内的 `config/ip.php`。不要公开或提交私有配置。

常用主配置项：

| 配置项 | 默认值 / 说明 |
| --- | --- |
| `site_url` | 实际站点地址，客户端授权使用它生成 URL |
| `remember_seconds` | 2592000，网页登录有效期 30 天 |
| `cloudflare_real_ip` | `true`，启用可信 Cloudflare 来源识别 |
| `poll_seconds` | 5，同步间隔秒数 |
| `max_file_bytes` | 单文件 10 MiB |
| `max_attachments` | 每条消息最多 5 个附件 |
| `max_text_length` | 100000 个字符 |
| `sync_retention_days` | 14，同步事件保留天数 |

网页登录使用 HttpOnly Cookie 保存随机凭据，数据库仅存其哈希，不在 Cookie 中保存账号密码。PHP Session 文件被清理后，仍可通过有效凭据恢复会话；每次请求仍校验设备授权。

有效期从密码登录起算，不是无限滚动延期。退出登录、移除设备或修改密码会使旧凭据失效；修改密码会撤销全部设备，包括当前设备与 Nextcloud 客户端。

独立的一年有效随机 Cookie 用于复用浏览器设备记录，本身不授予登录权限。清除 Cookie、无痕模式或更换浏览器会被视为新设备；升级前缺少标识的旧记录无法可靠自动合并。

## 真实 IP 与归属地

仅当连接来源属于 `app/ClientIp.php` 内的可信 Cloudflare 网段时，程序才读取合法的 `CF-Connecting-IP`，否则使用 `REMOTE_ADDR`。IPv4/IPv6 均支持，不默认信任回环、内网来源或任意转发头。

若 Web 服务器已经重写真实 IP，或还有额外代理，应先检查服务器配置。Cloudflare 网段列表需随维护更新，程序不会每次请求都下载它。

IP 服务私有配置示例：

```php
<?php
return [
    'endpoint' => 'https://ip.example.com/ip.php',
    'token' => 'YOUR_IP_API_TOKEN',
    'timeout_seconds' => 2,
    'cache_seconds' => 604800,
];
```

`ip.example.com` 和 `YOUR_IP_API_TOKEN` 均为占位符，需替换为自己的服务地址和令牌；不启用时令牌留空。

服务器通过 HTTPS GET 发送 `ip` 和 `token` 参数。响应需包含 `success: true`，以及 `data` 中与查询地址一致的 `ip`、`country`、`region`、`city`、`isp` 等字段。

- 公网 IP 优先使用数据库缓存，成功结果默认缓存七天。
- 密码登录和 Nextcloud 授权记录 IP 与归属地快照；打开设备列表时查询最近 IP 的归属地。
- 内网地址、未填写 Token、超时或失败时只显示 IP，不阻止登录。
- 旧登录快照不会自动补齐，最近归属地可在重新打开设备列表时查询。
- 前端仅在有数据时显示归属地行；接口有数据但页面不显示时，检查 JS 缓存。

## Nextcloud Notes 客户端

服务器地址填写网站根地址，例如 `https://example.com`；子目录部署填写 `https://example.com/notes`。**不要追加 `/NextcloudAPI`。**

已实现服务器检测、Notes `v1` 和旧 `v0.2` 兼容路径、设置读取、笔记增删改查、ETag 冲突检测及 Login Flow v2。浏览器授权复用现有账号密码，客户端获得独立凭据，有效期 30 天，可在“已登录设备”中撤销。

数据映射与限制：

- 一条文字消息对应一篇笔记，原分组对应分类；修改分类会移动消息，分类不存在时创建分组。
- 空分类映射到“未分类”；空分组可能不显示在客户端。同名分组会造成分类写入歧义，需先重命名。
- 带附件消息在兼容接口中只读，不提供附件上传下载；标题、收藏与修改时间单独存储。
- 设置中的 `notesPath` 固定为 `Notes`，`fileSuffix` 固定为 `.md`，不代表实际磁盘目录。
- 这不是完整 Nextcloud 服务，不支持 WebDAV、独立 Nextcloud 账号 App 的 SSO 或所有客户端功能，需按实际客户端版本验证。

接口详情见 [NextcloudAPI/README.md](NextcloudAPI/README.md)。授权前轮询返回 404 属正常状态，但浏览器授权页应能打开，接口不应跳转到普通网页登录页。

## 运维与排错

建议每小时使用与网站匹配的 PHP CLI 执行：

```sh
php /var/www/shiji/scripts/cleanup.php
```

脚本清理回收队列、过期临时和孤儿附件、旧同步事件及部分过期授权记录。备份时同时保存数据库、附件和私有配置，避免记录与文件不一致。

生产环境关闭网页错误显示，日志写入 Web 根目录外的可写文件：

```ini
display_errors = Off
log_errors = On
error_reporting = E_ALL
error_log = /www/wwwlogs/shiji-php-error.log
```

| 现象 | 排查方向 |
| --- | --- |
| 安装失败 | 环境检查、数据库权限、空数据库、目录权限与 PHP `link()` |
| 首页或 API 返回 500 | 查看 PHP 错误日志，而非仅访问日志；确认所需升级表已创建 |
| 客户端收到 HTML 或 302 | 检查请求版本及路由是否正确转发，而非落入 `login.php` |
| 客户端请求 404 | 检查 Apache/Nginx 路由，授权前的轮询 404 除外 |
| 新建、编辑返回 400 | 查找 `Shiji NextcloudAPI validation:`，根据错误编号定位字段 |
| 归属地不显示 | 检查 Token、allow_url_fopen、公网 IP、缓存及 `/api.php?action=devices` 返回值 |
| 更新后界面仍旧 | 检查浏览器和 CDN 的 JS/CSS 缓存，当前资源引用没有自动版本参数 |

不要在日志或截图中泄露数据库密码、Cookie、IP Token、客户端密码、完整授权 URL 或轮询令牌。Apache 需单独配置访问日志，避免记录匿名码和授权秘密；Nginx 示例也应按实际部署审核。

## 开发与验证

源码中的 `demo.html` 是独立静态演示，正式站点不依赖它。`router.php` 和 `tests/` 仅用于开发，干净部署包不包含这些文件。

```sh
php -S 127.0.0.1:8080 router.php
```

访问 `http://127.0.0.1:8080/install.php`。仅本地回环访问允许 HTTP，生产不能据此关闭 HTTPS 要求。

无需数据库的检查：

```sh
php tests/installer_validation.php
php tests/client_ip.php
php tests/ip_location.php
php tests/nextcloud_status.php
php tests/nextcloud_request.php
```

数据库测试必须使用名称以 `_test` 结尾的独立数据库，先应用 `database/install.sql`，再用 `SHIJI_CONFIG` 指向测试配置：

```sh
SHIJI_CONFIG=/absolute/path/test-config.php php tests/integration.php
SHIJI_CONFIG=/absolute/path/test-config.php php tests/nextcloud_api.php
SHIJI_CONFIG=/absolute/path/test-config.php php tests/nextcloud_login.php
SHIJI_CONFIG=/absolute/path/test-config.php php tests/persistent_login.php
```

不要对生产数据库运行测试。前端检查入口为 `tests/browser.html`，使用测试接口样本，不替代真实 PHP/MySQL 验证；语法树检查也不替代 `php -l`。部署前应实际验证安装、浏览器关闭后的登录恢复、设备撤销、附件、同步及客户端授权。
