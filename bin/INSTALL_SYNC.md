# install 脚本跨平台同步说明

**bin/install.bat**（Windows，纯 BAT + 内联 PowerShell，无 .ps1）  
**bin/install.sh**（Linux / macOS）

三端行为需保持一致。修改 **install.bat** 或 **install.sh** 时，请同步修改另一端的对应逻辑。安装后步骤（composer/env/setup/server）由 **setup/server_installer/run.php** 统一执行。

## 必须一致的项

| 项 | 说明 |
|----|------|
| 默认组件 | 无参数时安装 `php` + `pgsql`（不要只装 php 或只装 pgsql） |
| 安装目录 | `extend/server/php`、`extend/server/pgsql`、`extend/server/mysql` |
| PHP 版本来源 | 从项目根 `composer.json` 的 `require.php` 解析主次版本（如 ^8.4 → 8.4） |
| 已存在则跳过 | 若 `extend/server/php` 下已有 php 且主次版本与 composer 一致，则跳过下载，仅做 PATH |
| 版本提示文案 | 三种情况统一：① "matches required X" ② "Keeping existing" ③ "version check failed" |
| weline.env | 读取 `INSTALL_PGSQL_VERSION`、`INSTALL_MYSQL_VERSION`、可选 `INSTALL_PHP_VERSION`；缺省 pgsql=16、mysql=8.0 |
| 参数 | 支持 `--path-only`；组件名仅限 `php`、`pgsql`、`mysql` |
| pgsql 与 env.php | 处理 pgsql 后：若 `app/etc/env.php` 已存在则**红色警告并询问**是否覆盖数据库配置；确认后写入 `db.master` 并输出账户/密码/数据库/主机及创建示例 |
| weline.env 完整性 | **安装前**检查：若存在 weline.env，每行须为 `KEY=VALUE` 或 `#` 注释，否则红色警告并询问是否继续 |
| 下载失败提示 | 下载 PHP 等失败时，提示「若下载失败请检查网络或 VPN 配置」 |
| 安装后命令 | 安装结束后若 php 可用则执行：`php setup/server_installer/run.php`（内部完成 composer、env:check、env:install、setup:upgrade×2、server:stop、server:start） |

## 修改时检查

- [ ] 默认组件/参数解析是否一致
- [ ] 安装路径与 PATH 写入是否一致
- [ ] PHP 已存在 + 版本符合的跳过逻辑与提示是否一致
- [ ] weline.env 的 key 与默认值是否一致
- [ ] 新增组件或参数时，两端是否都加了
- [ ] pgsql 写入 env.php 与显示账户/密码逻辑是否一致（含 weline.env 中的 DB_*）
- [ ] env.php 已存在时是否红色询问、weline.env 完整性是否安装前检查、下载失败是否提示网络/VPN、安装后是否执行 composer + setup:upgrade×2 + server:stop + server:start
