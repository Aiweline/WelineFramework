# Weline_Theme::frontend::partials::footer::above

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::footer::above`
- **显示名称**：页脚上方扩展
- **区域**：frontend
- **类型**：partials
- **组件**：footer
- **位置**：above

## 功能说明

位于前台 Footer partial 的 `footer-above` 槽内。默认（`<else/>`）渲染 Theme `trust-badges` 部件，使所有带 Footer 的布局自动携带信任徽章。其它模块可实现本 Hook 覆盖默认内容，或向 `footer-above` 槽追加部件。

## 触发位置

`view/theme/frontend/partials/footer/default.phtml` → `<w:slot id="footer-above">`

## 相关

- 规格：`doc/开发/spec/layout-footer-above-and-page-bottom-slots.md`
- 部件：`view/theme/frontend/widgets/content/trust-badges/default.phtml`
