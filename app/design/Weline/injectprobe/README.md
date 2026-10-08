# injectprobe 主题

## 主题信息

- **主题名称**: injectprobe
- **版本**: 1.0.0
- **描述**: 注入探针：故意裁掉 float 层，验收 required 固化/注入
- **父主题**: Default 默认主题
## 目录结构

```
injectprobe/
├── register.php
├── frontend/
│   ├── colors/_*.css
│   ├── variables/_*.css
│   ├── assets/css/{brand}.css
│   ├── layouts/
│   └── partials/
└── backend/                  # 可选
```

> WARNING：旧 `view/templates` 脚手架已过时。权威流程见 `dev/ai-command/ai/主题开发.md` Mode B 与样例 `app/design/Weline/hanfu/`。
> 禁止同 key 覆盖 `assets/css/theme.css` / `assets/js/theme.js`。

## 使用说明

1. 安装主题：
   ```bash
   php bin/w setup:upgrade
   # 或 php bin/w theme:install -t injectprobe
   php bin/w theme:listing
   ```

2. 在「网站信息 → 店面主题」绑定本主题（theme:active / is_active_* 已退役）。
   正式店面权威为 websites_theme_application；未配置时回落 Theme 注册 Default。

## 探针约定

- **故意裁掉** `frontend/partials/footer/default.phtml` 内 `#w-storefront-float-layer`（`injectprobe:floatless`），用于验收 required 固化/运行期注入客服与进店音乐。
- **不要**用残缺 `<main>` 覆盖 `layouts/homepage`：design 主题的 homepage 会整页替换 `Weline_Theme` 壳，缺 `<html>/<head>` 会导致店面 `worker_scope_response_incomplete_html` 503。首页请回落父主题（当前放在 `frontend/layouts/_disabled/homepage`）。

## 开发说明

- 主题路径: `app/design/Weline/injectprobe/`
- 模块名称: `Weline_Injectprobe`
- 继承自: `Default 默认主题` 主题
