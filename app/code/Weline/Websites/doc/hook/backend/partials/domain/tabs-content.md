# Hook: Weline_Websites::backend::partials::domain::tabs-content

## 概述

在域名管理页面的 Tab 内容区域注入新的标签页内容面板。

## 使用方式

在你的模块中创建文件：
```
view/hooks/Weline_Websites/backend/partials/domain/tabs-content.phtml
```

## 模板示例

```phtml
<?php
/**
 * @hook-priority 150
 * @hook-sort-order 0
 * @hook-solo false
 */
$this->request->addModule('YourModule_Name');
\Weline\Framework\Phrase\Parser::$loaded = false;
?>
<div class="tab-pane fade"
     id="domain-your-feature"
     role="tabpanel"
     aria-labelledby="domain-your-feature-tab">
    <div class="p-3">
        <!-- 你的功能内容 -->
        <h5><?= __('你的功能标题') ?></h5>
    </div>
</div>
```

## 配合 Hook

必须同时实现 `Weline_Websites::backend::partials::domain::tabs` Hook，提供对应的 Tab 按钮。
Tab ID 必须与 tabs Hook 中 `data-bs-target` 指向的 ID 一致。
