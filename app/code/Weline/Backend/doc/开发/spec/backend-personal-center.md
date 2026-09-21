# 后台个人中心

## 背景

后台管理员需要自助个人中心：资料、头像（可选图预览）、语言、密码分开管理，交互对齐前台个人中心侧栏切换。

## 方案

- 布局：左侧身份 + 导航，右侧单栏目面板（仿前台 account dashboard）
- 路由：`system/backend/profile` / `avatar` / `language` / `password`
- 头像：`WelineMedia` Block 选图 + **组件自带预览**（`preview=1`）；禁止再叠自定义预览框；禁止 URL 文本框当主链
- 展示：库内存相对路径（如 `backend/avatar/x.png`）；侧栏/顶栏经 `Image::pathToMediaUrl` 转可访问 URL，禁止把相对路径直接塞进 `<img src>`
- 强引用：页首 `@static(Weline_FileManager::js/w-scope.js)`；PHP `ConfigMediaReferenceTemplates::config` 写入显式 `identity`（`sc.backend.profile_avatar`，code=`backend_profile_avatar/{username}`）；`identity_root=config` + `strong_ref=1`
- 个人语言：`BackendUserConfig`；优先级不变

## 原型结论

对比单页堆叠 vs 分栏切换：采用 **分栏切换（Variant A）**。见 `doc/evidence/prototype-backend-account-shell.html`。

## EARS

- WHEN 管理员打开个人中心 THEN 系统 SHALL 展示侧栏并可切换栏目
- WHEN 管理员在头像页选择媒体 THEN 系统 SHALL 预览并允许保存
- WHEN 管理员保存某一栏目 THEN 系统 SHALL 只更新该栏目字段

## UC-01 切换栏目

1. 打开基本资料 → 点「头像设置」
2. 期望：进入头像页，侧栏高亮头像

## UC-02 选图预览

1. 头像页打开媒体库选图
2. 期望：预览区更新；保存后侧栏头像同步
