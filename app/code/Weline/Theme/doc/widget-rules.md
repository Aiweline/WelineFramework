# 部件规则系统

本文档详细描述了主题编辑器中部件(Widget)与插槽(Slot)的规则系统，包括匹配规则、操作规则和排序规则。

## 1. 核心概念

### 1.1 部件 (Widget)

部件是可复用的UI组件，可以放置在主题的不同位置。每个部件由以下核心信息组成：

| 属性 | 类型 | 说明 |
|------|------|------|
| `code` | string | 部件唯一标识符 |
| `name` | string | 部件显示名称 |
| `type` | string | 部件类型（header/footer/content/sidebar等） |
| `module` | string | 所属模块 |
| `template` | string | 模板文件路径 |

### 1.2 插槽 (Slot)

插槽是布局模板中预定义的部件放置位置。插槽通过HTML属性定义其行为：

| 属性 | 说明 |
|------|------|
| `data-wslot` | 插槽ID（必需） |
| `data-wslot-name` | 插槽显示名称 |
| `data-wslot-accept` | 接受的部件代码列表（逗号分隔） |
| `data-wslot-reject` | 拒绝的部件代码列表（逗号分隔） |
| `data-wslot-exclusive` | 是否独占插槽（true/false） |
| `data-wslot-multiple` | 是否允许多个部件（true/false） |

### 1.3 区域 (Area)

区域是布局的逻辑分区，定义在 `ThemeLayout` 模型中：

| 区域代码 | 说明 |
|----------|------|
| `header` | 头部区域 |
| `banner` | 横幅区域 |
| `left_sidebar` | 左侧栏 |
| `content` | 内容区域 |
| `right_sidebar` | 右侧栏 |
| `footer` | 底部区域 |

### 1.4 页面类型 (Page Type)

不同页面类型可以有不同的布局配置：

| 页面类型 | 说明 |
|----------|------|
| `home` | 首页 |
| `category` | 分类页 |
| `product` | 产品页 |
| `product_list` | 产品列表页 |
| `cms` | CMS页面 |
| `cart` | 购物车 |
| `checkout` | 结算页 |
| `account` | 账户中心 |
| `search` | 搜索页 |
| `default` | 默认布局 |

---

## 2. 部件属性定义

部件在 `widget.php` 文件中定义，支持以下属性：

### 2.1 位置属性 (position)

```php
'position' => ['header', 'content']  // 允许放置的位置列表
```

位置到区域的映射关系：

| position 值 | 允许的区域 |
|-------------|-----------|
| `header` | header |
| `footer` | footer |
| `sidebar` | left_sidebar, right_sidebar |
| `content` | content, banner |
| `banner` | banner |
| `all` / `*` | 所有区域 |

### 2.2 插槽属性 (slot)

```php
'slot' => 'logo'  // 指定放入的插槽ID
```

当部件定义了 `slot` 属性时，只能放入匹配的插槽。

### 2.3 独占属性 (exclusive)

```php
'exclusive' => true  // 是否独占插槽
```

- `true`: 该部件放入插槽后会替换现有部件
- `false`: 可以与其他部件共存（默认）

### 2.4 兼容属性 (compatible)

```php
'compatible' => true  // 是否与其他部件兼容
```

- `true`: 可以与其他兼容部件放在同一位置
- `false`: 排斥其他部件（默认）

### 2.5 页面类型限制 (page_types)

```php
'page_layouts' => ['homepage', 'category']  // 适用的布局目录名
'page_layouts' => ['*']               // 所有布局
```

### 2.6 容器属性 (is_container)

```php
'is_container' => true  // 是否为容器型部件
```

容器型部件可以包含内部插槽，允许嵌套其他部件。

### 2.7 容器插槽定义 (slots)

```php
'slots' => [
    'left' => [
        'name' => '左侧插槽',
        'accept' => ['logo', 'search'],
        'max' => 1
    ],
    'right' => [
        'name' => '右侧插槽',
        'accept' => ['*'],
        'max' => 3
    ]
]
```

---

## 3. 匹配规则

部件能否放入某个插槽，需要满足以下所有条件：

### 3.1 位置匹配

```
部件的 position 数组必须包含目标区域对应的位置值
或者 position 包含 '*' / 'all'
```

**示例：**
- 部件 `position: ['header']` 可以放入 `header` 区域
- 部件 `position: ['sidebar']` 可以放入 `left_sidebar` 或 `right_sidebar`
- 部件 `position: ['*']` 可以放入任何区域

### 3.2 插槽匹配

```
如果部件定义了 slot 属性，必须与目标插槽ID匹配
如果插槽定义了 accept 列表，部件代码必须在列表中
如果插槽定义了 reject 列表，部件代码不能在列表中
```

**示例：**
```html
<div data-wslot="logo" data-wslot-accept="logo,brand-logo">
```
只接受 `logo` 或 `brand-logo` 部件。

### 3.3 兼容性匹配

```
同一位置槽中的部件必须都是 compatible=true
或者只有一个 compatible=false 的部件
```

### 3.4 页面类型匹配

```
部件的 page_types 必须包含当前页面类型
或者 page_types 包含 '*'
```

### 3.5 匹配流程图

```
┌─────────────────────────────────────────────────────────────┐
│                      匹配判断流程                            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  1. 检查 position 是否匹配目标区域                           │
│     ├─ 不匹配 → 拒绝                                        │
│     └─ 匹配 → 继续                                          │
│                                                             │
│  2. 检查 page_types 是否包含当前页面类型                     │
│     ├─ 不包含 → 拒绝                                        │
│     └─ 包含 → 继续                                          │
│                                                             │
│  3. 检查插槽 accept 列表                                     │
│     ├─ 有列表且部件不在列表中 → 拒绝                         │
│     └─ 无列表或部件在列表中 → 继续                           │
│                                                             │
│  4. 检查插槽 reject 列表                                     │
│     ├─ 部件在拒绝列表中 → 拒绝                               │
│     └─ 部件不在拒绝列表中 → 继续                             │
│                                                             │
│  5. 检查兼容性                                               │
│     ├─ 不兼容 → 拒绝                                        │
│     └─ 兼容 → 允许放置                                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 4. 独占规则

### 4.1 独占插槽

独占插槽只能容纳一个部件。常见的独占插槽包括：

| 插槽ID | 说明 |
|--------|------|
| `logo` | Logo 位置 |
| `search` | 搜索框位置 |
| `main-nav` | 主导航 |
| `user-area` | 用户区域 |
| `cart` | 购物车 |
| `language` | 语言切换 |
| `currency` | 货币切换 |
| `copyright` | 版权信息 |

### 4.2 独占部件

独占部件放入插槽时会替换现有部件：

```php
'exclusive' => true
```

### 4.3 独占容器

独占容器只接受特定类型的部件，但容器内部的同类型部件可以排序：

```php
'is_container' => true,
'exclusive' => true,
'slots' => [
    'items' => [
        'accept' => ['product-card'],  // 只接受产品卡片
        'max' => 10                     // 最多10个
    ]
]
```

---

## 5. 排序规则

### 5.1 同层排序原则

排序只能在同一层级内进行：

1. **同一 slot_id 内的部件**可以互相排序
2. **同一 area 内且无 slot_id 的部件**可以互相排序
3. **不同层级的部件**不能排序

### 5.2 排序适用范围

| 场景 | 是否支持排序 |
|------|-------------|
| 独占插槽（单个部件） | ❌ 无需排序 |
| 非独占插槽（多个部件） | ✅ 支持排序 |
| 独占容器内的同类型部件 | ✅ 支持排序 |
| 不同区域的部件 | ❌ 不支持 |

### 5.3 排序数据结构

```javascript
// sort_order 字段用于存储排序顺序
{
    layout_id: 123,
    slot_id: 'content-main',
    sort_order: 0  // 越小越靠前
}
```

### 5.4 排序 API

**更新单个部件排序：**
```
POST /theme/backend/theme-editor/move-widget
{
    layout_id: 123,
    area: 'content',
    sort_order: 2
}
```

**批量更新排序：**
```
POST /theme/backend/theme-editor/update-sort
{
    sort_data: {
        123: 0,
        124: 1,
        125: 2
    }
}
```

---

## 6. 操作规则

### 6.1 删除操作

删除部件后，恢复插槽原始内容：

1. 调用删除 API 移除数据库记录
2. 刷新插槽区域，显示布局模板中的原始HTML内容
3. 如果布局模板中有默认部件配置，则显示默认部件

### 6.2 替换操作

点击替换按钮的处理流程：

1. 选中当前部件所在的插槽
2. 右侧部件库滚动到顶部
3. 高亮显示所有符合该插槽条件的部件
4. 用户选择新部件后，替换现有部件

### 6.3 上下移动操作

仅对非独占插槽内的部件有效：

1. 获取同层部件列表
2. 交换当前部件与上/下部件的 sort_order
3. 调用 API 保存新排序
4. 更新界面显示

### 6.4 拖拽排序操作

1. 开始拖拽时，记录源部件信息
2. 拖拽过程中，检查目标位置是否允许（同层检查）
3. 放置时，计算新的排序顺序
4. 调用 API 批量更新排序
5. 更新界面显示

---

## 7. Hover 操作按钮

### 7.1 按钮类型

| 插槽类型 | 显示的按钮 |
|----------|-----------|
| 独占插槽 | 删除、替换 |
| 非独占插槽 | 删除、替换、上移、下移 |

### 7.2 按钮位置

操作按钮显示在部件右上角，hover 时显示：

```
┌─────────────────────────────────────┐
│                    [↑][↓][🔄][🗑]    │  ← 操作按钮
├─────────────────────────────────────┤
│                                     │
│           部件内容                   │
│                                     │
└─────────────────────────────────────┘
```

### 7.3 按钮图标

| 按钮 | 图标 | 功能 |
|------|------|------|
| 上移 | `ri-arrow-up-line` | 与上方部件交换位置 |
| 下移 | `ri-arrow-down-line` | 与下方部件交换位置 |
| 替换 | `ri-refresh-line` | 触发部件选择 |
| 删除 | `ri-delete-bin-line` | 删除部件 |

---

## 8. 示例

### 8.1 独占插槽部件定义

```php
// Logo 部件 - 独占 logo 插槽
return [
    'name' => 'Logo',
    'type' => 'header',
    'code' => 'logo',
    'position' => ['header'],
    'slot' => 'logo',
    'exclusive' => true,
    'page_types' => ['*'],
    'template' => 'Weline_Theme::widgets/header/logo.phtml',
];
```

### 8.2 非独占部件定义

```php
// 产品卡片 - 可在内容区域排序
return [
    'name' => '产品卡片',
    'type' => 'product',
    'code' => 'product-card',
    'position' => ['content'],
    'exclusive' => false,
    'compatible' => true,
    'page_layouts' => ['homepage', 'category'],
    'template' => 'Weline_Theme::widgets/product/card.phtml',
];
```

### 8.3 容器部件定义

```php
// Header 容器 - 包含多个插槽
return [
    'name' => 'Header 容器',
    'type' => 'container',
    'code' => 'header-container',
    'position' => ['header'],
    'is_container' => true,
    'exclusive' => true,
    'page_types' => ['*'],
    'slots' => [
        'logo' => [
            'name' => 'Logo',
            'accept' => ['logo', 'brand-logo'],
            'max' => 1
        ],
        'nav' => [
            'name' => '导航',
            'accept' => ['main-nav', 'mega-menu'],
            'max' => 1
        ],
        'user-area' => [
            'name' => '用户区域',
            'accept' => ['user-menu', 'cart-mini', 'language-switcher'],
            'max' => 5
        ]
    ],
    'template' => 'Weline_Theme::widgets/container/header.phtml',
];
```

### 8.4 插槽 HTML 示例

```html
<!-- 独占插槽 -->
<div data-wslot="logo" 
     data-wslot-name="Logo" 
     data-wslot-exclusive="true"
     data-wslot-accept="logo,brand-logo">
    <!-- 默认内容 -->
    <img src="default-logo.png" alt="Logo">
</div>

<!-- 非独占插槽 -->
<div data-wslot="content-main" 
     data-wslot-name="主内容区" 
     data-wslot-multiple="true"
     data-wslot-accept="*">
    <!-- 默认内容 -->
    <p>欢迎来到我们的网站</p>
</div>
```

---

## 9. 前端判断逻辑

### 9.1 判断是否为独占插槽

```javascript
function isExclusiveSlot(slotId, widgetCode) {
    const exclusiveSlots = [
        'logo', 'search', 'main-nav', 'user-area', 'cart',
        'language', 'currency', 'copyright', 'top-bar',
        'footer-links', 'footer-social', 'footer-newsletter',
        'header-container', 'footer-container'
    ];
    
    const exclusiveWidgets = [
        'logo', 'main-nav', 'search-box', 'footer-copyright',
        'header-container', 'footer-container'
    ];
    
    return exclusiveSlots.includes(slotId) || 
           exclusiveWidgets.includes(widgetCode);
}
```

### 9.2 判断是否可排序

```javascript
function canSortWidgets(widget1, widget2) {
    const slot1 = widget1.dataset.slotId;
    const slot2 = widget2.dataset.slotId;
    const area1 = widget1.dataset.area;
    const area2 = widget2.dataset.area;
    
    // 同一插槽内可排序
    if (slot1 && slot1 === slot2) {
        return true;
    }
    
    // 同一区域且无插槽可排序
    if (!slot1 && !slot2 && area1 === area2) {
        return true;
    }
    
    return false;
}
```

---

## 10. 后端验证逻辑

### 10.1 区域推断

当前端传入的 `area` 是插槽名而非标准区域时，后端自动推断：

```php
private function inferAreaFromSlot(string $slotOrArea): string
{
    $headerSlots = ['logo', 'search', 'main-nav', 'user-area', 'cart', 'language'];
    $footerSlots = ['copyright', 'footer-links', 'footer-social'];
    
    if (in_array($slotOrArea, $headerSlots)) {
        return 'header';
    }
    if (in_array($slotOrArea, $footerSlots)) {
        return 'footer';
    }
    return 'content';
}
```

### 10.2 独占判断

```php
$exclusiveSlots = [
    'logo', 'search', 'main-nav', 'user-area', 'cart',
    'language', 'currency', 'copyright', 'top-bar',
    'footer-links', 'footer-social', 'footer-newsletter',
    'header-container', 'footer-container'
];

$isExclusive = in_array($slotId, $exclusiveSlots, true);
```

---

## 附录：相关文件

| 文件 | 说明 |
|------|------|
| `app/code/Weline/Theme/Model/ThemeLayout.php` | 布局模型，定义区域和页面类型 |
| `app/code/Weline/Theme/Service/WidgetPositionResolver.php` | 位置解析器 |
| `app/code/Weline/Theme/Service/ThemeLayoutService.php` | 布局服务 |
| `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php` | 编辑器控制器 |
| `app/code/Weline/Widget/Service/WidgetRegistry.php` | 部件注册表 |
| `app/code/Weline/Theme/view/statics/js/theme-editor.js` | 前端编辑器逻辑 |
