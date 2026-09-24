# 已知文案集合的批量翻译

已知列表、树或卡片集合的源词时，使用现有 `TranslationResolverInterface` 对象的可选 `BatchTranslationResolverInterface` 能力：

```php
if ($resolver instanceof BatchTranslationResolverInterface) {
    $labels = $resolver->translateMany($sources, $localeCode, $preferredModules);
}
```

返回值按去除首尾空白后的源词索引，空词忽略、重复词去重。模块 CSV 优先级与单条 `translate` 一致；英文同文翻译是有效结果，非中文语言中的中文同文占位继续查词典。仅未在模块解析的词按 200 条分组调用现有 `DictionaryRepositoryInterface::getEntries`，不循环 `getEntry`。未命中返回源词，数据库异常不伪装成成功缺词。

该方法沿用单条 `translate` 的显式语言语义，不替代 `translateForScope` 的作用域和语言回退契约。第三方单条解析器无需实现此可选接口，调用方必须检查能力。

Theme 的动态标签列表使用 `WidgetI18n::prefetchLabels($sources)` 后照常调用 `WidgetI18n::label`。预取把原词及必要的首字母大写别名一起交给批量解析器，再填入已有 RequestContext 文案 memo；不新增进程静态缓存。已命中的请求词不重复预取。参数文案预取原始占位串，后续 label 按当次参数替换。没有请求上下文或解析器不支持批量时预取不执行，原单条行为保持。

搜索类型下拉通过 `HeaderCommerceData::prefetchSearchTypeLabels($types)` 在渲染前收集整棵树。其它消费者应在掌握完整集合的边界预取，不能在每个节点上调用单元素批量接口。无需为了纯读取方式优化修改文案、CSV 或重新采集词条。
