# WeShop 搜索模块

## 概述

WeShop 搜索模块提供了强大的搜索引擎功能，支持多种搜索引擎驱动，默认使用 **Meilisearch** 搜索引擎。

## 功能特性

- ✅ **驱动模式**：支持类似数据库驱动的搜索引擎驱动机制
- ✅ **Meilisearch 集成**：默认使用 Meilisearch，提供快速、相关且容错的搜索体验
- ✅ **自动索引**：产品保存/删除时自动同步搜索索引
- ✅ **搜索优化**：优化的数据结构提高搜索命中率
- ✅ **多作用域支持**：支持不同环境（default, production, staging等）的配置隔离
- ✅ **搜索历史**：记录用户搜索历史和热门搜索词

## 安装要求

### Meilisearch 安装

1. **使用 Docker（推荐）**：
```bash
docker run -d \
  --name meilisearch \
  -p 7700:7700 \
  -v $(pwd)/meili_data:/meili_data \
  getmeili/meilisearch:latest
```

2. **使用 Homebrew（macOS）**：
```bash
brew update && brew install meilisearch
meilisearch
```

3. **使用包管理器（Linux）**：
```bash
# Ubuntu/Debian
curl -L https://install.meilisearch.com | sh
./meilisearch

# 或使用 systemd 服务
sudo systemctl start meilisearch
```

### PHP SDK 安装

```bash
composer require meilisearch/meilisearch-php
```

## 配置

### 1. 默认配置

模块安装时会自动创建默认的 Meilisearch 配置：
- **服务地址**：`http://127.0.0.1:7700`
- **索引名称**：`products`
- **API Key**：无（可根据需要配置）

### 2. 后台配置

访问：`/search/backend/engine/index` 进行搜索引擎配置管理。

### 3. 配置文件

配置存储在数据库表 `weshop_search_engine_config` 中，支持：
- 多作用域配置
- 优先级设置
- 启用/禁用控制

## 使用

### 1. 索引产品数据

#### 使用控制台命令

```bash
# 索引所有产品
php bin/w search:index

# 索引指定产品
php bin/w search:index --product_id=1

# 强制重新索引（删除所有现有索引）
php bin/w search:index --force

# 配置索引设置（搜索字段、过滤字段等）
php bin/w search:index --configure

# 完整操作（配置 + 强制重新索引）
php bin/w search:index --configure --force
```

#### 自动索引

产品保存或删除时会自动同步索引，无需手动操作。

### 2. 搜索产品

```php
use WeShop\Search\Service\SearchService;
use Weline\Framework\Manager\ObjectManager;

/** @var SearchService $searchService */
$searchService = ObjectManager::getInstance(SearchService::class);

// 搜索产品
$result = $searchService->searchProducts('关键词', [
    'category_id' => 1,
    'price_min' => 100,
    'price_max' => 1000,
    'order_by' => 'price',
    'order_dir' => 'asc',
], 1, 20);

// 获取搜索建议
$suggestions = $searchService->getSearchSuggestions('关键词', 10);

// 获取热门搜索词
$popularKeywords = $searchService->getPopularKeywords(10);
```

### 3. 获取搜索引擎实例

```php
use WeShop\Search\Service\SearchEngineFactory;
use Weline\Framework\Manager\ObjectManager;

// 获取默认搜索引擎
$engine = SearchEngineFactory::create();

// 获取指定作用域的搜索引擎
$engine = SearchEngineFactory::create('production');

// 根据类型创建引擎
$engine = SearchEngineFactory::createEngineByType('meilisearch');
```

## 驱动机制

### 驱动注册

搜索引擎驱动通过 `SearchEngineDriverRegistry` 进行注册，类似数据库驱动机制。

驱动映射文件：`generated/search/driver.php`

### 创建自定义驱动

1. 实现 `SearchEngineInterface` 接口
2. 在 `Setup/InstallData.php` 中注册驱动
3. 在 `SearchEngineFactory` 中添加支持

示例：

```php
namespace YourModule\Search\Engine;

use WeShop\Search\Api\SearchEngineInterface;

class YourEngine implements SearchEngineInterface
{
    // 实现接口方法
}
```

## 索引优化

### 数据结构优化

产品索引包含以下优化字段：

1. **searchable_text**：合并多个字段的可搜索文本，提高命中率
   - 产品名称
   - SKU/SPU
   - 描述
   - 分类名称
   - Meta 关键词

2. **category_ids**：产品分类ID数组，用于过滤

3. **category_names**：产品分类名称数组，用于搜索

### 索引配置

Meilisearch 索引配置包括：

- **可搜索字段**：name, sku, spu, handle, short_description, description, searchable_text, category_names, meta_keywords
- **过滤字段**：category_ids, price, status, stock
- **排序字段**：price, product_id, name
- **排名规则**：words, typo, proximity, attribute, sort, exactness

## 事件

### 搜索事件

- `WeShop_Search::search_before` - 搜索前触发
- `WeShop_Search::search_after` - 搜索后触发

### 产品事件监听

- `WeShop_Product::product_save_after` - 产品保存后自动索引
- `WeShop_Product::product_delete_after` - 产品删除后自动清理索引

## 性能优化建议

1. **批量索引**：使用控制台命令批量索引，避免逐个索引
2. **异步索引**：对于大量数据，建议使用队列异步处理
3. **定期重建**：定期执行 `--force` 重新索引，保持数据一致性
4. **监控索引状态**：通过 Meilisearch 仪表板监控索引状态

## 故障排除

### Meilisearch 连接失败

1. 检查 Meilisearch 服务是否运行：`curl http://127.0.0.1:7700/health`
2. 检查防火墙设置
3. 检查 API Key 配置（如果启用了认证）

### 索引失败

1. 检查产品数据是否完整
2. 检查 Meilisearch 服务状态
3. 查看错误日志：`var/log/`

### 搜索无结果

1. 确认产品已索引：`php bin/w search:index --product_id=1`
2. 检查索引配置：`php bin/w search:index --configure`
3. 查看 Meilisearch 索引状态

## 参考文档

- [Meilisearch 官方文档](https://www.meilisearch.com/docs)
- [Meilisearch PHP SDK](https://php-sdk.meilisearch.com/)
- [Weline Framework 开发文档](../../../docs/dev/开发文档.md)
