# 事件: Weline_Websites::domain::purchase_success

## 概述

域名购买成功后触发，可用于通知、自动 DNS 解析、证书申请等后续操作。

## 触发时机

`DomainPurchaseService::createAndProcessOrder()` 中，当单个域名通过适配器 API 购买成功后触发。每成功购买一个域名触发一次。

## 事件数据

```php
$eventData = [
    'data' => [
        'domain' => 'example.com',          // 购买成功的域名
        'order_id' => 123,                   // 购买订单 ID
        'website_id' => 0,                   // 绑定的站点 ID（0=不绑定）
        'auto_create_site' => 'no',          // 是否自动创建站点（yes/no）
    ],
];
```

## 监听示例

在 `etc/event.xml` 中注册观察者：

```xml
<event name="Weline_Websites::domain::purchase_success">
    <observer name="YourModule::on_domain_purchased" 
              instance="YourModule\Observer\OnDomainPurchased"/>
</event>
```

观察者实现：

```php
class OnDomainPurchased implements \Weline\Framework\Event\Observer\ObserverInterface
{
    public function execute(\Weline\Framework\Event\Event $event)
    {
        $data = $event->getData('data');
        $domain = $data['domain'];
        // 自动配置 DNS 解析、申请证书等
    }
}
```
