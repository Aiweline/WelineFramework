# WeShop Search

## 概览

`WeShop/Search` 现在默认使用 **OpenSearch** 作为搜索引擎，同时保留 `Meilisearch`、`MySQL`、`Elasticsearch`、`Algolia` 的兼容支持。

模块安装后会优先读取：

- `app/code/WeShop/Search/etc/env.php` 中的模块默认配置
- `app/etc/env.php` 中的 `search` 运行时配置
- 数据库中的搜索引擎配置记录

当数据库里还没有激活的搜索引擎配置时，模块会自动按 `env.php` 默认值初始化为 `OpenSearch`。

## 环境安装

Search 模块已接入环境依赖安装链路：

```bash
php bin/w env:check
php bin/w env:install opensearch -y
```

如果通过安装入口统一执行：

```bash
php setup/server_installer/run.php
```

环境安装会自动完成以下动作：

1. 根据当前环境选择 OpenSearch 官方发行包
2. 下载并解压到 `extend/server/opensearch`
3. 生成 `extend/server/opensearch/config/opensearch.yml`
4. 将 `app/etc/env.php` 中的 `search.default_engine` 设置为 `opensearch`
5. 写入 `search.engines.opensearch` 的连接信息、版本号、安装目录、配置文件路径，以及可选的数据 / 日志目录

默认下载版本可通过项目根目录 `weline.env` 控制：

```dotenv
INSTALL_OPENSEARCH_VERSION=3.5.0
SEARCH_DEFAULT_ENGINE=opensearch
SEARCH_DEFAULT_SCOPE=default
SEARCH_OPENSEARCH_INSTALL_DIR=extend/server/opensearch
SEARCH_OPENSEARCH_DATA_DIR=D:/WelineRuntime/opensearch-data
SEARCH_OPENSEARCH_LOG_DIR=D:/WelineRuntime/opensearch-logs
SEARCH_OPENSEARCH_HOST=http://127.0.0.1
SEARCH_OPENSEARCH_PORT=9200
SEARCH_OPENSEARCH_INDEX=products
SEARCH_OPENSEARCH_TIMEOUT=5
SEARCH_OPENSEARCH_CLUSTER_NAME=weline-search
SEARCH_OPENSEARCH_NODE_NAME=weline-search-node
SEARCH_OPENSEARCH_BIND_HOST=127.0.0.1
```

说明：

- Windows 默认下载 `opensearch-{version}-windows-x64.zip`
- Linux 默认下载 `opensearch-{version}-linux-{arch}.tar.gz`
- 若当前平台没有默认官方稳定发行包，可在 `weline.env` 中设置 `SEARCH_OPENSEARCH_DOWNLOAD_URL`
- `SEARCH_OPENSEARCH_INSTALL_DIR` 默认固定为 `extend/server/opensearch`
- `SEARCH_OPENSEARCH_DATA_DIR` / `SEARCH_OPENSEARCH_LOG_DIR` 可指向其他磁盘，避免项目盘空间不足时 OpenSearch 因磁盘水位阻塞建索引
- `SEARCH_OPENSEARCH_CONFIG_FILE`、`SEARCH_OPENSEARCH_DATA_DIR`、`SEARCH_OPENSEARCH_LOG_DIR` 支持相对路径和绝对路径
- 临时下载与解压目录默认使用系统临时目录下的 `weline-opensearch`，也可通过 `SEARCH_OPENSEARCH_TMP_DIR` / `SEARCH_OPENSEARCH_DOWNLOAD_DIR` / `SEARCH_OPENSEARCH_EXTRACT_DIR` 覆盖

## `app/etc/env.php` 配置示例

```php
<?php
return [
    'search' => [
        'default_scope' => 'default',
        'default_engine' => 'opensearch',
        'engines' => [
            'opensearch' => [
                'host' => 'http://127.0.0.1',
                'port' => 9200,
                'index' => 'products',
                'username' => '',
                'password' => '',
                'timeout' => 5,
                'version' => '3.5.0',
                'install_dir' => 'extend/server/opensearch',
                'config_file' => 'extend/server/opensearch/config/opensearch.yml',
                'data_dir' => 'D:/WelineRuntime/opensearch-data',
                'log_dir' => 'D:/WelineRuntime/opensearch-logs',
            ],
        ],
    ],
];
```

## 搜索扩展架构

Search 模块现在通过两个入口开放真实搜索能力：

1. **文档扩展点**

- Search 模块声明了 `extends/module/WeShop_Search/Document`
- 其他模块实现 `WeShop\Search\Api\SearchDocumentProviderInterface` 后，即可向统一索引写入自己的搜索文档
- 当前已接入：
  - `WeShop_Product` 商品文档提供者
  - `WeShop_Catalog` 分类文档提供者

2. **统一查询器**

- Search 模块通过 `w_query('search', ...)` 提供统一查询与索引入口
- 可用操作：
  - `search`
  - `suggest`
  - `rebuildIndex`
  - `indexEntity`
  - `deleteEntity`
  - `providers`

示例：

```php
w_query('search', 'rebuildIndex', ['provider' => 'product', 'force' => true]);
w_query('search', 'indexEntity', ['provider' => 'category', 'entity_id' => 15]);
w_query('search', 'suggest', ['keyword' => 'Apple', 'limit' => 5]);
```

## 后台配置

后台入口：

- `/search/backend/engine/index`

支持：

- 新增或编辑不同作用域的搜索引擎配置
- 默认优先展示并测试 OpenSearch 配置
- 测试当前表单中正在编辑的引擎连接

## 常用命令

```bash
# 构建搜索索引
php bin/w search:index

# 强制重建索引
php bin/w search:index --force

# 仅重建商品索引
php bin/w search:index --provider=product --force

# 仅同步单个分类
php bin/w search:index --provider=category --entity_id=15

# 写入索引设置
php bin/w search:index --configure

# 重建扩展注册表（新增文档 provider 后执行）
php bin/w extends:rebuild

# 检查环境依赖
php bin/w env:check

# 安装 OpenSearch 环境依赖
php bin/w env:install opensearch -y
```

## 参考

- OpenSearch 安装文档：https://docs.opensearch.org/latest/install-and-configure/install-opensearch/tar/
- OpenSearch 发行包页面：https://opensearch.org/artifacts/by-version/
