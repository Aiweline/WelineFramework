# WeShop_Motor 任务清单

**最后更新**：2026-03-13

## 已完成

- [x] 创建 `register.php`，注册 `WeShop_Motor`，parent: `weshop-default`
- [x] 创建 `frontend/assets/css/motor.css`，定义 `[data-theme="motor"]` 全量颜色变量
- [x] motor.css 包含暗色模式变量（`.dark [data-theme="motor"]`）
- [x] motor.css 包含专属组件样式（`.motor-btn-primary`、`.motor-card`、`.motor-badge`、`.motor-divider`）
- [x] 创建 `doc/开发/plan.md` 与 `task.md`
- [x] 创建 `frontend/layouts/base.phtml`（Oswald/Rajdhani 字体、data-theme="motor"、Tailwind 扩展 motor 品牌色、motor.css 引入）
- [x] 创建 `frontend/partials/header/default.phtml`（深色工业风格头部，含顶部通知栏、摩托车导航、移动端菜单）
- [x] 创建 `frontend/partials/footer/default.phtml`（深色风格底部，含摩托车专用分类导航、Newsletter）
- [x] 执行 `setup:upgrade` 验证主题注册成功
- [x] 所有用户可见文案使用 `__()` 国际化

## 后续可选

- [ ] 覆盖首页布局（homepage.phtml）增加摩托车特色 banner
- [ ] 覆盖产品列表/详情页布局
- [ ] 添加摩托车特色 PageBuilder 部件（车型轮播、品牌专区等）
- [ ] 响应式细节优化与无障碍检查
