# Theme 开发总指南

> 适用范围：WelineFramework 当前主题开发、布局开发、部件开发、主题覆盖、前端请求链路、Taglib 与可视化编辑器相关开发。

## 1. 先读什么

建议按下面顺序建立上下文，再动源码：

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `app/code/Weline/Theme/doc/README.md`
4. `app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`
5. `app/code/Weline/Theme/view/theme/README.md`
6. 按任务命中以下专项文档：
   - 主题继承/文件落点：`app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`
   - 布局：`app/code/Weline/Theme/doc/layout-discovery-guide.md`
   - 部件：`app/code/Weline/Theme/doc/部件开发指南.md`
   - Slot 属性：`app/code/Weline/Theme/doc/widget-slot-attributes.md`
   - Theme.js：`app/code/Weline/Theme/doc/Theme.js使用指南.md`
   - 浏览器业务请求：`app/code/Weline/Frontend/doc/Weline.Api使用指南.md`
   - Taglib：`app/code/Weline/Taglib/doc/README.md`

不要先读 `view/tpl`、`generated/`、旧计划文档或历史任务记录来猜当前规则。

## 2. 先判断你在改哪一层

### 2.1 Layout

适合做这些事：

- 页面骨架
- 区域分区
- `<w:slot>`、`<w:hook>`、`<w:block>`、`<w:widget>` 挂载点
- 页面级默认 `meta`
- 页面级默认 widget/slot seed

当前源路径：

- `app/code/Weline/Theme/view/theme/{area}/layouts/{layoutType}/{option}.phtml`
- `app/design/{Vendor}/{theme}/frontend/layouts/{layoutType}/{option}.phtml`
- `app/design/{Vendor}/{theme}/theme/frontend/layouts/{layoutType}/{option}.phtml`
- `app/design/{Vendor}/{theme}/view/theme/frontend/layouts/{layoutType}/{option}.phtml`

规则：

- `app/design` 可以覆盖 `Weline_Theme` 默认布局。
- 业务模块 `view/theme` 只能追加新布局，不能覆盖 `Weline_Theme` 默认布局。
- 同逻辑 key 的发现优先级固定为：`app/design` 主题链 -> `Weline_Theme/view/theme` -> 其他模块 `view/theme`。

### 2.2 Partial

适合做这些事：

- header/footer/sidebar/breadcrumb/head 等可复用片段
- layout 内部局部结构
- 由主题配置切换的局部模板

当前源路径：

- `app/code/Weline/Theme/view/theme/{area}/partials/{type}/{option}.phtml`
- 对应设计主题可在 `app/design/**/frontend/partials/...` 或 `view/theme/frontend/partials/...` 覆盖

不要把完整业务流程塞进 partial。

### 2.3 Component

适合做这些事：

- button/card/input/modal/table/badge 等基础可复用 UI 原语
- 表单控件、基础容器、通用展示单元

当前源路径：

- `app/code/Weline/Theme/view/theme/{area}/components/*.phtml`

组件要尽量保持“无业务、可组合、可复用”。

### 2.4 Widget

适合做这些事：

- 可视化编辑器要消费的可拖拽部件
- 运营可配置参数的内容块
- 需要 `@widget.*` / `@param` 元数据的模块化页面部件

当前源路径：

- 模板：`app/code/{Vendor}/{Module}/view/theme/{area}/widgets/{type}/{code}/default.phtml`
- 注册表：`app/code/{Vendor}/{Module}/extends/module/Weline_Widget/{ModuleName}/widget.php`

说明：

- 当前推荐注册方式是集中定义：`extends/module/Weline_Widget/{ModuleName}/widget.php`
- 旧的 `extends/Weline_Widget/...` 仍有兼容扫描，但不要再把它当首选模式
- 部件元数据优先来自模板头部的 `@widget.*` 与 `@param` 注释

### 2.5 Taglib

适合做这些事：

- 需要模板语义扩展时
- 需要 `<w:...>` 自定义标签时
- 需要把一套模板规则编译成统一输出时

不要为了一个普通布局片段就创建 Taglib。能用 layout / partial / component / widget 解决的，优先不用 Taglib。

### 2.6 Browser API / Theme.js

适合做这些事：

- 页面交互
- 浏览器侧数据刷新
- 前端 QueryProvider 调用
- 浏览器侧 stream / SSE 订阅

当前规则：

- 站内业务请求只能走 `theme.js -> Weline.Api.* -> worker/query-bin`
- 使用：
  - `Weline.Api.resource('provider')`
  - `Weline.Api.graph()`
  - `Weline.Api.stream()`
  - `Weline.Api.request()/get()/post()`（低层 helper）

禁止：

- 禁止 `fetch`
- 禁止 `XMLHttpRequest`
- 禁止 `$.ajax`
- 禁止 `axios`
- 禁止手写 `/api/framework/query-bin`
- 禁止手写业务 REST URL

## 3. 当前 Theme 目录的权威位置

默认主题源目录是：

- `app/code/Weline/Theme/view/theme/frontend`
- `app/code/Weline/Theme/view/theme/backend`

目录分工：

- `layouts/`：页面骨架
- `partials/`：局部片段
- `components/`：基础 UI 原语
- `widgets/`：编辑器部件
- `variables/`：主题 token
- `colors/`：色盘覆盖
- `assets/css`：area 级公共样式
- `assets/js/theme.js`：统一前端运行时入口

`Weline_Frontend::theme/...`、`theme.json`、`design/frontend/default` 这类旧文档示例不能再当现行开发规范。

## 4. CSS 与样式约定

默认主题 CSS 契约：

- 共享默认主题组件类使用 `w-` 前缀
- 典型类包括：`w-btn`、`w-card`、`w-form-control`、`w-table`、`w-badge`、`w-alert`、`w-modal`
- 主题 token 使用：
  - 前端：`--weline-theme-*`
  - 后端：`--backend-theme-*`
- 组件变量应引用主题 token，不要重新发明第二套不兼容变量体系

推荐：

- 业务结构可额外带模块自己的 BEM 类
- 默认主题能力优先复用 `w-*`

禁止：

- 新增无前缀全局基础组件类来替代 `w-*`
- 在可复用组件里大量硬编码颜色、边框、圆角、阴影

## 5. 布局与部件的边界

layout 只负责：

- 页面骨架
- 区域与 slot
- 默认占位
- 结构级 meta / param

layout 不负责：

- 复杂交互
- 业务查询
- 前端直连接口
- 权限/库存/价格等业务计算

widget 负责：

- 可配置、可复用、可拖拽的业务展示单元
- 参数化内容块
- 编辑器消费的元数据与 slot 协议

component 负责：

- 基础 UI 原子能力
- 无状态或轻状态的通用渲染单元

## 6. Slot / Widget 当前规则

优先掌握：

- `<w:slot ...>`：模板级 slot 暴露
- `data-wslot` 系列属性：编辑器识别的 DOM slot 标记
- `@widget.*`：部件元数据
- `@param`：部件配置字段

重点规则：

- `position` / `page_layouts` / `slot` / `supports` 表示部件允许出现的位置和协议
- `default_injections` 只表示“建议默认放在哪里”
- `accept="*"` 表示接受所有部件
- `accept="a,b,c"` 是硬白名单

规范文档：

- `app/code/Weline/Theme/doc/部件开发指南.md`
- `app/code/Weline/Theme/doc/widget-slot-attributes.md`
- `app/code/Weline/Theme/doc/widget-rules.md`
- `app/code/Weline/Widget/doc/开发指南.md`

## 7. 严禁直接修改的东西

绝对不要改：

- `generated/`
- `view/tpl/`
- 编译后的模板输出
- 扫描/收集后的生成注册表

正确做法：

- 从 `view/tpl` 反查真实源模板
- 改 `view/theme`、`app/design`、`extends/module/...`、Taglib、Hook、配置或生成链路

## 8. 什么时候需要什么验证

### 改了布局 / partial / 组件 / widget 模板

- 至少做 `php -l <file>`
- 如影响真实页面，补最接近的 `php bin/w http:request <route>`
- 用户可见改动最终要有 Browser 冒烟，除非环境阻塞

### 改了 Controller / 路由

- `php bin/w setup:upgrade --route`
- 再做路由或 HTTP 验证

### 改了 Widget 注册 / 元数据 / 编辑器消费路径

- 验证扫描或注册刷新流程
- 必要时验证可视化编辑器或对应 QueryProvider 输出

### 改了浏览器侧请求逻辑

- 确认最终代码走 `Weline.Api.*`
- 不得保留 raw ajax/fetch fallback

### 需要运行 WLS

- 只能起独立测试实例
- 使用 `9502+`
- 结束后必须 stop

## 9. 最常见的错误做法

- 按旧文档在 `Frontend/doc/README.md` 里照着写 `$.ajax`（禁止）
- 把 `Weline_Frontend::theme/...` 当当前主题主路径
- 直接改 `view/tpl`
- 在 layout 里塞业务流程与交互脚本
- 不区分 layout / partial / component / widget 的职责
- 不写 `@widget.*` / `@param` 就想让编辑器自动识别
- 直接在浏览器侧拼 query-bin URL
- 把 `app/design` 覆盖与模块 `view/theme` 追加混为一谈

## 10. 推荐给 AI / 开发者的最短路径

如果需求是“开发主题”或“改页面 UI”，建议先问自己：

1. 这是页面骨架还是局部片段？
2. 这是基础组件还是可视化编辑器部件？
3. 这次改动属于默认主题，还是某个设计主题覆盖？
4. 有没有浏览器业务请求？如果有，是否走了 `Weline.Api.*`？
5. 最终验证入口是什么？

然后对应去读：

- layout：`layout-discovery-guide.md`
- widget：`部件开发指南.md`
- request：`Weline.Api使用指南.md`
- overall：本文件

## 11. 相关文档

- `app/code/Weline/Theme/doc/README.md`
- `app/code/Weline/Theme/view/theme/README.md`
- `app/code/Weline/Theme/doc/layout-discovery-guide.md`
- `app/code/Weline/Theme/doc/部件开发指南.md`
- `app/code/Weline/Theme/doc/widget-slot-attributes.md`
- `app/code/Weline/Theme/doc/Theme.js使用指南.md`
- `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`
- `app/code/Weline/Taglib/doc/README.md`
