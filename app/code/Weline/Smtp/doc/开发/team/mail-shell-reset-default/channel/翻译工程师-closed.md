# channel — 翻译工程师 → PM

from: Team:翻译工程师:  
to: @项目经理  
date: 2026-09-23  
re: plan_id=i18n-reset **closed**  
notify_pm: true

## 交付

1. 约定 confirm 源串已写入 `Weline_Smtp` 模块 CSV（仅 zh+en）：
   - 源串：`确定重置为默认模板？将丢失自定义正文以及页头/页尾改动。`
   - `i18n/zh_Hans_CN.csv`：同字身份译
   - `i18n/en_US.csv`：`Reset to the default template? Custom body and header/footer changes will be lost.`
2. 已执行 `php bin/w i18n:collect Weline_Smtp`（exit 0）。

## 前端对齐（须）

截至本席交付时，`view/Backend/Template/edit.phtml` 重置按钮**尚未**落盘 `confirm`/`__()`。前端须用**同一源串**（字面一致）：

```php
__('确定重置为默认模板？将丢失自定义正文以及页头/页尾改动。')
```

禁止改写标点或措辞，否则字典键对不上。

@项目经理：本席已交付，请检查并更新 SESSION。
