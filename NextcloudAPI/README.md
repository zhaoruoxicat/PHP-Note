# Nextcloud Notes 兼容接口

本目录为拾记提供 Nextcloud Notes REST 协议兼容层，复用现有账号、分组和消息数据。它不是完整 Nextcloud 服务，不需要另行安装 Nextcloud。

完整部署说明见[项目 README](../README.md)。本文中的域名、账号和数据均为示例。

## 兼容范围

- 支持 Notes API `v1`，声明版本 `1.1`；同时提供旧 `v0.2` 笔记与设置接口路径。
- 支持笔记列表、分类过滤、单篇读取、新建、修改和删除。
- 支持 ETag 条件读取，以及通过 `If-Match` 检测编辑冲突。
- 支持 `/status.php` 服务器检测、OCS 能力发现及 Login Flow v2 浏览器授权。
- 支持设置读取和提交，但返回的是数据库后端的固定虚拟设置。

不支持 WebDAV、独立 Nextcloud 账号 App 的 SSO、外部图片或附件接口、分块列表同步，也不声明完整 API 1.2 兼容性。不同客户端仍需单独验证，不保证所有 Nextcloud 功能可用。

`/status.php` 和能力发现中返回的数字版本用于协议兼容，不代表安装了对应版本的 Nextcloud。

## 部署与升级

新站使用项目安装器即可，所有相关表会自动创建。

已有站点升级步骤：

1. 备份数据库、附件和私有配置。
2. 导入 [migration.sql](migration.sql)，创建笔记元数据和授权流程表。
3. 若尚未升级持久登录功能，再导入 [upgrade-persistent-login.sql](../database/upgrade-persistent-login.sql)，当前授权逻辑需要登录审计等表。
4. 更新项目程序文件，包括根目录入口、`app/` 与本目录；保留现有私有配置和附件，不要重新安装。
5. 更新 Apache/Nginx 路由，刷新静态资源缓存，必要时重载 PHP OPcache。

上述 SQL 可重复执行以创建缺失表，但不能自动修复结构不同的已有表。务必选中程序实际使用的数据库。

Apache 需启用 `mod_rewrite` 并让根目录 `.htaccess` 生效。Nginx 不读取 `.htaccess`，需合并 [Nginx 示例](../deploy/nginx.conf.example) 中的兼容路由，并调整 HTTPS、PHP-FPM socket 和部署路径。代理必须保留 `Authorization` 请求头。

## 客户端连接

服务器地址填写网站根地址：

```text
https://note.example.com
```

子目录部署填写完整子目录，例如 `https://example.com/notes`。**不要追加 `/NextcloudAPI`。** 该目录只是服务器实现代码的存放位置。

登录时，客户端打开浏览器，用户以现有拾记账号密码登录并明确授权。客户端获得独立凭据，不获得用户输入的原始密码。也支持直接通过 HTTP Basic Auth 使用原账号密码访问接口。

`config/config.php` 的 `site_url` 必须正确，包括 HTTPS 和子目录，因为授权地址由该配置生成，不使用未经信任的 Host 请求头。

## 接口路径

下列路径相对于站点地址；部署在子目录时需加上该目录前缀。

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| GET | `/status.php` | 无需登录的服务器检测 |
| GET | `/ocs/v1.php/cloud/capabilities` | 能力发现，另支持 OCS v2 路径 |
| POST | `/index.php/login/v2` | 发起客户端授权 |
| GET、POST | `/index.php/login/v2/flow/{grant}` | 浏览器登录和确认授权 |
| POST | `/index.php/login/v2/poll` | 轮询并领取客户端凭据 |
| GET | `/index.php/apps/notes/api/v1/notes` | 笔记列表，可用 `category` 过滤 |
| POST | `/index.php/apps/notes/api/v1/notes` | 创建笔记 |
| GET、PUT、DELETE | `/index.php/apps/notes/api/v1/notes/{id}` | 读取、修改、删除笔记 |
| GET、PUT | `/index.php/apps/notes/api/v1/settings` | 读取、提交虚拟设置 |

提供的路由也支持不带 `index.php` 的对应路径。笔记与设置可将 `v1` 替换为 `v0.2`。旧 `/NextcloudAPI/status.php` 检测路径也被兼容，但不能据此将客户端服务器地址设置为该目录。

笔记接口直接返回 JSON 对象或数组，不使用拾记网页 API 的 `success/data` 包装。

### 请求格式

笔记和设置的 POST/PUT 支持：

- `application/json`。
- `application/x-www-form-urlencoded`，包括显式解析的 PUT 请求体。
- POST 另支持不含文件的 `multipart/form-data` 字段。

表单中的 `favorite` 会转换为布尔值，`modified` 转换为整数 Unix 秒数。数值型小数和科学计数法时间戳也会归一化；非数字、负数、非有限数和超范围值仍会拒绝。

写请求携带异源 `Origin` 或跨站浏览器 `Sec-Fetch-Site` 时会被拒绝；原生客户端不携带这些头时可以正常访问。

### 设置返回值

```json
{
  "notesPath": "Notes",
  "fileSuffix": ".md"
}
```

拾记将笔记存储在数据库中，没有可供客户端修改的文件系统笔记目录。因此设置提交会归一化为上述固定值，而不是改变服务器附件路径。

## 授权与凭据

1. 客户端匿名 POST 发起授权，获得浏览器地址和轮询 Token。
2. 用户打开浏览器地址，登录并点击授权按钮；网页表单受 CSRF 校验保护。
3. 客户端轮询凭据接口，待授权时返回 404，成功时返回一次 `server`、`loginName`、`appPassword`。
4. 客户端使用获得的账号和独立密码，通过 HTTP Basic Auth 请求笔记接口。

轮询 Token 可来自表单、JSON 或 URL 查询参数，兼容 iOS 客户端的参数形式；同时提供时请求体中的值优先。

授权请求有效期为 20 分钟；确认授权后需在 5 分钟内领取凭据。成功领取仅允许一次，过期、已领取或未授权时返回 404。

数据库存储授权 Token 的哈希。待领取的随机客户端密码使用 `app_key` 派生密钥进行 AES-256-GCM 加密，领取后删除临时记录，不存储用户原始密码。

客户端凭据有效期为 30 天，在网页“已登录设备”中以 Nextcloud 前缀显示，可独立撤销；修改账号密码也会撤销它。直接使用原始密码的 Basic 请求不会单独生成设备凭据。

授权 URL、轮询 Token 和客户端密码均为秘密。不要粘贴到日志、截图或聊天中。Nginx 示例对授权流程关闭访问日志；Apache 需在虚拟主机中设置访问日志排除规则，同时注意包含 Token 的查询参数。

## 数据映射

| 拾记数据 | 客户端数据与行为 |
| --- | --- |
| 消息 ID、正文 | 笔记 ID、content，共享原始数据 |
| 分组名称 | category，缺失时创建普通分组 |
| 空分类 | 映射到普通分组“未分类”，返回该名称 |
| 分类变更 | 移动原消息，不复制内容 |
| 分组改名 | 该分组所有笔记分类及 ETag 随之变化 |
| 标题、收藏、修改时间 | 保存在 `nextcloud_note_meta` 中 |
| 带附件消息 | 接口只读，包括禁止删除，不导出附件 |

补充规则：

- 客户端可以创建空文字笔记；网页历史保留原始创建时间。
- `v1` 标题可单独编辑；`v0.2` 提交正文时以首行生成标题。
- 分类中的 `/` 按平面分组名称保存，不建立文件夹层级。
- 同名分组会导致分类写入歧义，应先在网页重命名重复分组。
- 包含匿名收件箱在内的分组可以读取，移动消息不会重命名或删除系统收件箱。
- 客户端从笔记汇总分类，因此空分组可能不显示。删除分组仍需在网页操作，且只允许空的普通分组。
- 列表始终返回完整笔记；`pruneBefore` 会校验但不用于裁剪，避免遗漏同一秒内的变更。不支持分块分页。

## 调试示例

读取笔记：

```sh
curl --user admin \
  'https://note.example.com/index.php/apps/notes/api/v1/notes'
```

创建笔记：

```sh
curl --user admin -H 'Content-Type: application/json' -X POST \
  --data '{"title":"示例笔记","content":"正文","category":"工作"}' \
  'https://note.example.com/index.php/apps/notes/api/v1/notes'
```

curl 会提示输入密码，不要在命令或 URL 中直接写真实密码。状态检测可无需认证访问 `/status.php`；未认证访问笔记接口应返回 401，而不是网页 HTML。

| 现象 | 检查方向 |
| --- | --- |
| 404 | 路由是否覆盖客户端使用的版本、设置及授权路径 |
| 302、HTML 或内容类型错误 | 请求是否错误落入普通网页登录入口 |
| 400 | PHP 日志中的 `Shiji NextcloudAPI validation:` 及具体字段错误编号 |
| 401 | 账号密码、凭据到期或撤销情况，以及代理是否传递 Authorization |
| 403 | 带附件笔记只读限制，或跨站写入校验 |
| 412 | 编辑版本冲突，响应包含服务器当前笔记，需合并或重新读取 |
| 415 | 请求格式或文件上传不受支持 |
| 500 | PHP 错误日志、数据库表及升级 SQL 是否完整 |

## 验证

PHP 8.4 环境下，无需数据库可执行：

```sh
php tests/nextcloud_status.php
php tests/nextcloud_request.php
```

数据库测试必须使用 MySQL 8 独立测试库，数据库名以 `_test` 结尾。应用 `database/install.sql` 后，从项目根目录执行：

```sh
SHIJI_CONFIG=/absolute/path/test-config.php php tests/nextcloud_api.php
SHIJI_CONFIG=/absolute/path/test-config.php php tests/nextcloud_login.php
SHIJI_CONFIG=/absolute/path/test-config.php php tests/integration.php
```

不要对生产数据库运行测试。干净部署包不包含测试文件；源码测试也不替代实际客户端的授权、同步、新建、编辑、删除及冲突验证。

## 协议参考

- [Nextcloud Notes API](https://github.com/nextcloud/notes/blob/main/docs/api/v1.md)
- [Nextcloud Login Flow](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/LoginFlow/index.html)
