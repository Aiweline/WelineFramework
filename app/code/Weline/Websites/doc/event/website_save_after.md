# 网站保存后事件

## 事件名称

`Weline_Websites::website_save_after`

## 触发时机

`add`、`edit`、`quickSave` 在 Website 核心、域名、货币、语言和两个
start-page SystemConfig 已写入、但外层主库事务尚未提交时触发。SEO/Geo
表单扩展必须在这个事件中完成同库写入；之后 Controller 才读取 after 快照并生成
`Weline_Framework::resource_changed`。

## 事件目的

允许其他模块把必须与 Website 一起成功或一起回滚的同库关联数据接入保存流程。
缓存清理、WLS 广播、网络请求和通知等外部副作用不得在此事件中执行，应走
ResourceChange 的 sync/async 消费或 afterCommit 调度。

## 事件数据结构

事件以数组形式传参：

```php
[
    'website_id' => 1,  // 网站ID（必填，整数）
    'website' => $website,  // 网站对象（可选）
    // 其他相关数据...
]
```

## 观察者实现规范

### 1. 观察者类

- 必须实现 `Weline\Framework\Event\ObserverInterface`。
- 从事件中读取网站数据并执行相应操作：

```php
public function execute(\Weline\Framework\Event\Event $event): void
{
    $data = $event->getData();
    if (!array_key_exists('website_id', $data) || $data['website_id'] === null) {
        return;
    }
    $websiteId = (int)$data['website_id'];
    if ($websiteId < 0) {
        throw new \InvalidArgumentException(__('website_id 不能为负数'));
    }
    
    // 仅保存必须与 Website 一起提交的同库关联数据
    // ...
}
```

### 2. 事件配置

在模块 `etc/event.xml` 中注册观察者，例如：

```xml
<event name="Weline_Websites::website_save_after">
    <observer name="WeShop_Store::website_save_after"
              instance="WeShop\Store\Observer\WebsiteSaveAfter"
              disabled="false"
              shared="true"
              sort="100"/>
</event>
```

## 错误与事务语义

- Observer 默认是 `failure=critical`。任何异常会标记外层事务 rollback-only 并上抛，Controller 必须返回失败，不得记录 warning 后继续。
- 需要失败隔离的非核心观察者可显式配置 `failure="isolated"`；其写入会在 savepoint 中回滚。
- 耗时或外部操作应订阅 `Weline_Framework::resource_changed` 并使用 `delivery="async"`，不得延长 Website 主事务。
- `website_id=0` 是有效的 `default` 站点；只有字段缺失或 `null` 才表示没有站点上下文。

## Extension payload convention

Website edit form extension modules should write fields under `extensions[{module_code}]`.
`Weline_Websites` passes the raw post data through `post_data` and does not parse SEO/GEO fields itself.

Example:

```php
[
    'website_id' => 1,
    'website' => $websiteData,
    'post_data' => [
        'extensions' => [
            'seo' => ['robots_enabled' => '1'],
            'geo' => ['llms_enabled' => '1'],
        ],
    ],
    'address_list' => [],
    'action' => 'add|edit|quick_save',
]
```
