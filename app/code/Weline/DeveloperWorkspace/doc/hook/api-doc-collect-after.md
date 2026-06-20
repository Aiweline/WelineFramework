# API 文档收集扩展点

DeveloperWorkspace 的 API 文档管理界面通过 `ApiDocCollector` 汇总接口文档。

基础 API 文档仍由 `Weline_Api` 的 `ApiDocService` 扫描生成；扩展模块不得直接修改或硬编码到 `ApiDocService`。需要向 `/dev/tool/docs/api` 或自动导入流程追加文档时，监听：

```xml
<event name="Weline_DeveloperWorkspace::api_doc_collect_after">
    <observer name="Vendor_Module::api_doc_contributor"
              instance="Vendor\Module\Observer\ApiDocContributor"
              disabled="false"
              shared="true"
              sort="100"/>
</event>
```

观察者从事件中读取 `apis`，追加后再写回：

```php
public function execute(\Weline\Framework\Event\Event &$event): void
{
    $apis = $event->getData('apis');
    if (!is_array($apis)) {
        $apis = [];
    }

    $apis['Vendor Module'][] = [
        'module' => 'Vendor_Module',
        'version' => 'v1',
        'class' => 'VendorModuleDocs',
        'method' => 'example',
        'route' => [
            'method' => 'DOC',
            'path' => '/example',
            'is_backend' => false,
        ],
        'document' => [
            'summary' => '示例文档',
            'description' => '通过 DeveloperWorkspace API 文档收集事件追加。',
            'tags' => ['Example'],
            'category' => 'Example',
            'deprecated' => false,
        ],
        'parameters' => [],
        'responses' => [],
        'example' => [],
    ];

    $event->setData('apis', $apis);
}
```

新增或修改事件监听后，执行：

```bash
php bin/w event:rebuild
```
