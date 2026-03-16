# WeShop_Motor 摩托车主题开发计划

**状态**：🟢 已完成（status: completed）  
**完成度**：100%  
**最后更新**：2026-03-14

> 总计划：[motor_theme_development_d75caf28.plan.md](../../../../../.cursor/plans/motor_theme_development_d75caf28.plan.md)

## 结构约定（对应 Theme 继承）

- **`app/design/WeShop/motor/`** 对应继承 **`app/code/Weline/Theme/view/theme/`**。
- `motor/frontend/` 与 `Theme/view/theme/frontend/` 结构一致：仅使用 **layouts/**、**widgets/**、**partials/**、**components/**、**assets/**，无 `pages/`。
- 页面级内容均放在 **layouts/** 下（如 `layouts/homepage/default.phtml`、`layouts/category/default.phtml`、`layouts/product/default.phtml`），与 Theme 模块的布局目录一一对应。

---

## 阶段一：基础注册与色系 🟢 已完成

|| 任务 | 状态 |
|------|------|
| 创建 register.php | 🟢 已完成 |
| 创建 motor.css（含暗色模式变量） | 🟢 已完成 |
| 创建 doc/开发/plan.md、task.md | 🟢 已完成 |

## 阶段二：布局定制 🟢 已完成

|| 任务 | 状态 |
|------|------|
| 创建 base.phtml | 🟢 已完成 |
| Tailwind 扩展 motor 品牌色 | 🟢 已完成 |

## 阶段三：视觉与组件 🟢 已完成

|| 任务 | 状态 |
|------|------|
| Header 覆盖（深色工业风格） | 🟢 已完成 |
| Footer 覆盖（深色工业风格） | 🟢 已完成 |
| 组件样式（motor-btn/card/badge/divider） | 🟢 已完成 |

## 阶段四：页面覆盖（按 Theme 结构） 🟢 已完成

|| 任务 | 状态 |
|------|------|
| 首页布局 layouts/homepage/default.phtml（含首页完整内容） | 🟢 已完成 |
| 商品列表布局 layouts/category/default.phtml | 🟢 已完成 |
| 商品详情布局 layouts/product/default.phtml | 🟢 已完成 |
| 原 pages/ 内容已合并至 layouts/，frontend/pages/ 已废弃（见 frontend/pages/README.md） | 🟢 已完成 |

## 阶段五：国际化与脚本 🟢 已完成

|| 任务 | 状态 |
|------|------|
| 创建 i18n/zh_Hans_CN.csv | 🟢 已完成 |
| 创建 i18n/en_US.csv | 🟢 已完成 |
| 创建 frontend/assets/js/motor.js | 🟢 已完成 |

## 设计决策

### 色系方案
- 主色：#e31837（摩托红）
- 暗色背景：#1a1a1a（工业黑）
- 字体：Oswald + Rajdhani（运动/科技感）
- 风格：硬朗、低圆角(2px)、大写字母间距、碳纤纹理感

### 页面特色
- **首页**：全屏 Hero Banner、品牌标签滚动条、三列分类卡片、热门优惠、促销横幅、品牌展示、服务优势
- **产品详情页**：工业风产品展示、数量选择器、标签页切换、相关产品推荐
- **商品列表页**：分类 Banner、侧边筛选、网格/列表切换、快速加购

### JavaScript 功能
- MotorTheme：滚动效果、移动端菜单、返回顶部、图片懒加载
- MotorToast：前台 Toast 通知组件（success/error/warning/info）
