# Hook: Weline_Websites::backend::partials::domain::tabs

## 概述

在域名管理页面的 Tab 导航区域注入新的标签页按钮。

## 使用方式

在你的模块中创建文件：
```
view/hooks/Weline_Websites/backend/partials/domain/tabs.phtml
```

## 模板示例

```phtml
<?php
/**
 * @hook-priority 150
 * @hook-sort-order 0
 * @hook-solo false
 */
?>
<button class="btn domain-tab"
        type="button"
        id="domain-your-feature-tab"
        data-bs-toggle="tab"
        data-bs-target="#domain-your-feature"
        role="tab"
        aria-controls="domain-your-feature"
        aria-selected="false">
    <i class="mdi mdi-your-icon me-1"></i>
    <span class="d-none d-sm-inline-block">
        <?= __('你的功能') ?>
    </span>
</button>
```

## 配合 Hook

必须同时实现 `Weline_Websites::backend::partials::domain::tabs-content` Hook，提供对应的 Tab 内容面板。
