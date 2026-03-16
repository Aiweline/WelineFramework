# WeShop_Motor 任务清单

**最后更新**：2026-03-14

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
- [x] 创建 `frontend/layouts/homepage/default.phtml`（首页布局，含完整内容：setLayout base、Welcome 弹窗、Hero、分类卡片、Hot Deals、促销横幅、品牌展示、Why Choose Us，与 Theme 结构一致）
- [x] 创建 `frontend/layouts/category/default.phtml`（商品列表布局：分类 Banner、侧边筛选、网格/列表切换、快速加购）
- [x] 创建 `frontend/layouts/product/default.phtml`（商品详情布局：工业风展示、数量选择器、标签页、相关产品）
- [x] 原 `frontend/pages/` 内容已合并至 `layouts/`，`frontend/pages/` 已废弃（见 `frontend/pages/README.md`）
- [x] 创建 `i18n/zh_Hans_CN.csv`（中文翻译）
- [x] 创建 `i18n/en_US.csv`（英文翻译）
- [x] 创建 `frontend/assets/js/motor.js`（MotorTheme 对象、MotorToast 前台通知组件）

## 后续可选

- [ ] 添加购物车布局 `layouts/cart/default.phtml`
- [ ] 添加结算布局 `layouts/checkout/default.phtml`
- [ ] 添加用户相关布局（登录、注册、账户，如 `layouts/account/default.phtml` 等）
- [ ] 添加摩托车特色 PageBuilder 部件（车型轮播、品牌专区等）
- [ ] 响应式细节优化与无障碍检查
- [ ] 添加主题预览图片和演示数据

## 文件结构

（与 Theme/view/theme/frontend 结构一致：layouts/、partials/、assets/，无 pages/ 作为正式结构）

```
app/design/WeShop/motor/
├── register.php
├── doc/
│   └── 开发/
│       ├── plan.md
│       └── task.md
├── i18n/
│   ├── zh_Hans_CN.csv
│   └── en_US.csv
├── frontend/
│   ├── assets/
│   │   ├── css/
│   │   │   └── motor.css
│   │   └── js/
│   │       └── motor.js
│   ├── layouts/
│   │   ├── base.phtml
│   │   ├── homepage/
│   │   │   └── default.phtml
│   │   ├── category/
│   │   │   └── default.phtml
│   │   └── product/
│   │       └── default.phtml
│   ├── partials/
│   │   ├── header/
│   │   │   └── default.phtml
│   │   └── footer/
│   │       └── default.phtml
│   └── pages/                    # 已废弃，内容已合并至 layouts/
│       └── README.md
```
