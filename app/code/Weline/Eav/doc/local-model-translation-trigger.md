# EAV LocalModel 翻译触发范围

`EavLocalModelTranslationTrigger` 只处理 `etc/event.xml` 已登记的 EAV 元数据模型：`EavEntity`、`EavAttribute`、`EavAttribute\Set`、`EavAttribute\Group`、`EavAttribute\Option`。

框架模型保存事件通过 `Event.data` 中的 `DataObject.model` 提供实际模型。观察器先核对模型类型，再设置请求 memo 和执行原有队列入口。Product 属性值等无关模型不会触发翻译候选扫描，也不会占用 memo；同一请求后续的有效 EAV 保存仍可入队，多个有效保存继续合并为一次检查。

此边界与 XML 登记范围对齐，不改变 I18n 队列、幂等入队或候选收集逻辑。队列入口的候选检查可能同步读取 LocalModel 数据，因此不能作为所有业务模型保存后的通用操作。

隔离回归覆盖“无关 Product 保存 → 有效 EAV 保存 → 第二次有效保存”，并从 XML 读取已登记模型验证正例，不执行真实数据库操作或翻译入队：

```bash
php vendor/bin/phpunit app/code/Weline/Eav/Test/Unit/Observer/EavLocalModelTranslationTriggerOwnershipTest.php --no-progress
```
