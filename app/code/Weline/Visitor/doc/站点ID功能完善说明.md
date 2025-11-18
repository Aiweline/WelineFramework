# 像素记录站点ID功能完善说明

## 概述

本次更新完善了像素记录模型中的站点ID功能，确保在整个像素系统中能够正确区分和管理不同站点的数据。

## 修改内容

### 1. API接口 (`app/code/Weline/Visitor/Api/Rest/V1/Pixel.php`)

#### 站点ID获取逻辑增强
- **优先级顺序**：
  1. 从请求数据中的 `websiteId` 获取
  2. 从请求数据中的 `siteId` 获取
  3. 从 `$_SERVER['WELINE_WEBSITE_ID']` 获取
  4. 默认值为 `0`

- **类型转换**：确保站点ID转换为整数类型，符合数据库字段类型要求

```php
# 获取站点ID（优先从请求数据获取，其次从SERVER变量获取，最后默认为0）
$websiteId = 0;
if (isset($post['websiteId']) && $post['websiteId'] !== '') {
    $websiteId = (int)$post['websiteId'];
} elseif (isset($post['siteId']) && $post['siteId'] !== '') {
    $websiteId = (int)$post['siteId'];
} elseif (isset($_SERVER['WELINE_WEBSITE_ID']) && $_SERVER['WELINE_WEBSITE_ID'] !== '') {
    $websiteId = (int)$_SERVER['WELINE_WEBSITE_ID'];
}
```

### 2. Observer事件处理

#### LoginPixel (`app/code/Weline/Visitor/Observer/LoginPixel.php`)
- 从 `$_SERVER['WELINE_WEBSITE_ID']` 获取站点ID并转换为整数
- 在像素数据中同时提供 `websiteId` 和 `siteId` 字段以兼容前端
- 确保站点ID正确传递到加密数据中

#### RegisterPixel (`app/code/Weline/Visitor/Observer/RegisterPixel.php`)
- 从 `$_SERVER['WELINE_WEBSITE_ID']` 获取站点ID并转换为整数
- 在像素数据中同时提供 `websiteId` 和 `siteId` 字段以兼容前端
- 确保站点ID正确传递到加密数据中

```php
// 获取站点ID（从SERVER变量获取，确保是整数）
$websiteId = 0;
if (isset($_SERVER['WELINE_WEBSITE_ID']) && $_SERVER['WELINE_WEBSITE_ID'] !== '') {
    $websiteId = (int)$_SERVER['WELINE_WEBSITE_ID'];
}

$pixelData = [
    // ...
    'websiteId' => $websiteId,
    'siteId' => $websiteId, // 同时提供siteId字段以兼容前端
    // ...
];
```

### 3. 前端像素脚本 (`app/code/Weline/Visitor/view/taglib/js/pixel.phtml`)

#### 站点ID获取增强
- 使用 `parseInt()` 确保站点ID是整数类型
- 优先从Cookie `WELINE_WEBSITE_ID` 获取
- 其次从 `window.site.site_id` 获取
- 默认值为 `0`
- 在 `init` 和 `initData` 中同时提供 `websiteId` 和 `siteId` 字段

```javascript
init: {
    // ...
    siteId: parseInt(window.site.site_id) || parseInt(getCookie('WELINE_WEBSITE_ID')) || 0,
    websiteId: parseInt(getCookie('WELINE_WEBSITE_ID')) || parseInt(window.site.site_id) || 0,
    // ...
}
```

### 4. Pixel模型 (`app/code/Weline/Visitor/Model/Pixel.php`)

#### 新增查询方法

##### `getUnDeaPixels(?int $websiteId = null): array`
- 获取未处理的像素记录
- 支持可选的站点ID参数，如果提供则只获取该站点的记录
- 保持向后兼容（不传参数时获取所有站点的记录）

```php
// 获取所有站点的未处理记录
$allPixels = Pixel::getUnDeaPixels();

// 获取指定站点的未处理记录
$websitePixels = Pixel::getUnDeaPixels(1);
```

##### `getPixelsByWebsiteId(int $websiteId, array $conditions = []): array`
- 根据站点ID获取像素记录
- 支持额外的查询条件
- 灵活的条件格式支持

```php
// 获取指定站点的所有记录
$pixels = Pixel::getPixelsByWebsiteId(1);

// 获取指定站点且未处理的记录
$pixels = Pixel::getPixelsByWebsiteId(1, [
    'cron_deal' => 0
]);

// 使用操作符
$pixels = Pixel::getPixelsByWebsiteId(1, [
    'value' => ['value' => 100, 'operator' => '>=']
]);
```

##### `getPixelsByWebsiteIdAndEvent(int $websiteId, string $event): array`
- 根据站点ID和事件名获取像素记录
- 用于统计特定站点特定事件的记录

```php
// 获取指定站点的登录事件记录
$loginPixels = Pixel::getPixelsByWebsiteIdAndEvent(1, 'login');
```

##### `countPixelsByWebsiteId(int $websiteId, array $conditions = []): int`
- 统计指定站点的像素记录数量
- 支持额外的查询条件
- 返回记录总数

```php
// 统计指定站点的所有记录数
$count = Pixel::countPixelsByWebsiteId(1);

// 统计指定站点且未处理的记录数
$count = Pixel::countPixelsByWebsiteId(1, ['cron_deal' => 0]);
```

##### `countPixelsByWebsiteIdAndEvent(int $websiteId, string $event): int`
- 统计指定站点的事件数量
- 用于快速获取事件统计

```php
// 统计指定站点的登录事件数量
$loginCount = Pixel::countPixelsByWebsiteIdAndEvent(1, 'login');
```

##### `getAllWebsiteIds(): array`
- 获取所有站点ID列表（去重）
- 用于获取系统中所有有像素记录的站点

```php
// 获取所有站点ID
$websiteIds = Pixel::getAllWebsiteIds();
// 返回: [0, 1, 2, 3, ...]
```

##### `getEventsByWebsiteId(int $websiteId): array`
- 获取指定站点的所有事件列表（去重）
- 用于获取某个站点触发的所有事件类型

```php
// 获取指定站点的所有事件
$events = Pixel::getEventsByWebsiteId(1);
// 返回: ['click', 'login', 'register', 'view-item', ...]
```

## 数据流

### 前端像素数据发送
```
前端页面 → pixel.phtml → 获取站点ID（Cookie/window.site）→ 发送到API
```

### 后端事件触发
```
用户登录/注册 → Observer → 从SERVER获取站点ID → 加密发送到API
```

### API处理
```
API接口 → Pixel.php → 优先从请求数据获取，其次从SERVER获取 → 保存到数据库
```

## 数据库字段

### `w_pixel` 表
- **字段名**：`website_id`
- **类型**：`INTEGER`
- **约束**：`NOT NULL`
- **默认值**：`0`
- **索引**：`idx_website_id`（已存在）

## 兼容性

### 字段名兼容
- 同时支持 `websiteId` 和 `siteId` 两种字段名
- API接口自动识别两种字段名
- Observer和前端都同时提供两种字段

### 向后兼容
- `getUnDeaPixels()` 方法保持向后兼容
- 不传站点ID参数时，行为与之前一致
- 现有代码无需修改即可正常工作

## 使用示例

### 1. 前端发送像素数据
```javascript
// 站点ID会自动从Cookie或window.site获取
WelinePixel.send({
    eventName: 'click',
    // websiteId 和 siteId 会自动填充
});
```

### 2. 后端查询特定站点的记录
```php
// 获取站点ID为1的所有像素记录
$pixels = \Weline\Visitor\Model\Pixel::getPixelsByWebsiteId(1);

// 获取站点ID为1的登录事件记录
$loginPixels = \Weline\Visitor\Model\Pixel::getPixelsByWebsiteIdAndEvent(1, 'login');

// 获取站点ID为1的未处理记录
$unDealPixels = \Weline\Visitor\Model\Pixel::getUnDeaPixels(1);
```

### 3. 在Cron任务中使用
```php
// 处理所有站点的记录（默认行为）
$unDeaPixels = \Weline\Visitor\Model\Pixel::getUnDeaPixels();

// 只处理特定站点的记录
$websiteId = 1;
$unDeaPixels = \Weline\Visitor\Model\Pixel::getUnDeaPixels($websiteId);
```

## 注意事项

1. **站点ID类型**：所有站点ID都必须是整数类型，系统会自动转换
2. **默认值**：如果无法获取站点ID，默认使用 `0`
3. **索引优化**：`website_id` 字段已有索引，查询性能良好
4. **多站点支持**：系统完全支持多站点数据区分和管理

## 测试建议

1. **前端测试**：
   - 测试Cookie中有站点ID的情况
   - 测试window.site中有站点ID的情况
   - 测试两者都没有的情况（应使用默认值0）

2. **后端测试**：
   - 测试Observer事件触发时站点ID是否正确传递
   - 测试API接口接收不同来源的站点ID
   - 测试查询方法是否正确过滤站点ID

3. **数据库测试**：
   - 验证站点ID是否正确保存到数据库
   - 验证查询方法返回的数据是否正确过滤

## 总结

本次更新完善了像素记录模型中的站点ID功能，确保：
- ✅ 前端正确获取和传递站点ID
- ✅ 后端事件正确处理站点ID
- ✅ API接口正确接收和保存站点ID
- ✅ 模型提供便捷的查询方法
- ✅ 完全支持多站点数据区分
- ✅ 保持向后兼容性

所有代码已通过语法检查，无错误。

