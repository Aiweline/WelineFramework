# Weline_Framework_Http::integration::client_ip_keys - 客户端IP头Keys收集

## 事件说明

在解析客户端真实 IP 前触发，允许 CDN 模块等通过观察者注册用于解析真实 IP 的 `$_SERVER` keys。Framework 提供基础 keys（如 `HTTP_X_FORWARDED_FOR`、`REMOTE_ADDR`），CDN 驱动应 prepend 其专有 keys，以实现任意 CDN 供应商兼容，符合开闭原则（OCP）。

## 事件类型

**Integration Event** - 跨模块集成事件

## 触发时机

在 `ServerBag::getClientIp()` 中，按 keys 顺序解析 IP 之前。

## 数据格式

```php
[
    'keys' => ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', ...],  // 必填：$_SERVER keys 数组，按优先级排序
]
```

## 可用数据

### 必需字段

- **keys** (array) - 用于解析真实 IP 的 `$_SERVER` keys，按优先级排序。观察者应 `array_unshift` 追加其专有 keys。

### 可选字段

无。

## 使用场景

- Cloudflare：添加 `HTTP_CF_CONNECTING_IP`
- 阿里云 CDN：添加 `HTTP_ALI_CDN_REAL_IP`、`HTTP_X_REAL_IP` 等
- 腾讯云 CDN：添加相应真实 IP header
- 其他 CDN 供应商：各适配器注册其专有 header keys

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_Framework_Http::integration::client_ip_keys">
    <observer name="Weline_Cdn::client_ip_keys"
              instance="Weline\Cdn\Observer\ClientIpKeysObserver"
              disabled="false"
              shared="true"
              sort="10"/>
</event>
```

### Cdn 模块的观察者（分发到适配器）

Cdn 模块的 `ClientIpKeysObserver` 通过 `AdapterResolver::getAllAdapters()` 获取所有 CDN 适配器，调用各适配器的 `getRealIpHeaderKeys()`，有返回值则合并到 keys。适配器自行返回其专有 header，符合 DIP。

### CDN 适配器实现 getRealIpHeaderKeys()

```php
// 在 AdapterInterface 实现类中
public function getRealIpHeaderKeys(): array
{
    return ['HTTP_CF_CONNECTING_IP'];  // Cloudflare 专有；无则返回 []
}
```

## 注意事项

1. **Prepend 而非 Append**：CDN 专有 header 通常比通用 `X-Forwarded-For` 更可信，应使用 `array_unshift` 插入到数组最前
2. **格式**：keys 必须为 `$_SERVER` 格式，如 `HTTP_CF_CONNECTING_IP`（对应 Header `Cf-Connecting-Ip`）
3. **幂等**：同一 CDN 的 header 只应注册一次，避免重复添加

## 相关事件

- `Weline_Framework_Http::process_area` - HTTP 区域处理
