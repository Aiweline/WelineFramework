# Weline_Filters

## 模块定位

本文件由 `prepare_project` 的自动修复流程创建。模块能力以当前源码、测试和后续人工维护的专题文档为准。

## 知识维护约定

- 长期事实写入本模块 `doc/`。
- 不在本文复制全局规则或客户端规则。
- 无法由当前证据确认的行为必须标记待确认。

## 动态筛选标签预取

`StorefrontFilterPanelService::buildPanel()` 从既有范围缓存取得最终面板后，统一收集价格标签、属性组名及全部选项标签（包含折叠选项），交给 Framework `Parser::prefetchWords()`。因此面板缓存命中而当前 Worker 词典未热时，也能复用中央词缓存的批量 L2 读取与最多 200 词一批的数据库回退。

预取不修改面板内容、不新增缓存池或缓存键。模板继续调用 `__()`，保留请求覆盖、模块词典、公共词典及语言回退优先级。部门名称、URL、属性值标识不属于这批翻译集合。

行为验证：`php vendor/bin/phpunit --bootstrap app/autoload.php --no-configuration app/code/Weline/Filters/Test/Unit/Service/StorefrontFilterPanelPrefetchTest.php`。测试通过真实服务缓存命中路径与 Parser、内存词典 Provider，检查 207 词的批量/负缓存与覆盖语义；实际 WLS 页面耗时由运行环境验收。
