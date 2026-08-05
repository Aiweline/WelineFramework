# Geo 模块文档

## 📚 文档目录

本目录包含 Weline_Geo 模块的完整文档。

### 核心文档

1. **[使用指南](使用指南.md)**
   - 完整的模块使用说明
   - 功能详解
   - 配置说明
   - 故障排查

2. **[快速入门指南](快速入门指南.md)**
   - 5分钟快速上手
   - 基础操作流程
   - 常见问题解答

3. **[API参考文档](API参考文档.md)**
   - 命令行工具
   - 服务类API
   - 模型API
   - 适配器API
   - 扩展开发

4. **[事件系统使用指南](事件系统使用指南.md)**
   - 事件系统概述
   - 事件定义和使用
   - 实际应用示例
   - 最佳实践

---

## 🎯 快速导航

### 新手入门

1. 阅读 [快速入门指南](快速入门指南.md) 了解基础操作
2. 查看 [使用指南](使用指南.md) 学习详细功能
3. 参考 [API参考文档](API参考文档.md) 进行开发

### 开发者

1. 查看 [API参考文档](API参考文档.md) 了解API接口
2. 学习如何添加自定义平台适配器
3. 查看 [事件系统使用指南](事件系统使用指南.md) 了解事件机制
4. 了解事件监听和扩展机制

---

## 📖 文档说明

### 使用指南

包含模块的完整使用说明，适合所有用户阅读：
- 安装和配置
- 平台管理
- Feed管理
- 推送功能
- 高级功能
- 安全说明
- 故障排查

### 快速入门指南

适合快速上手的简明教程：
- 5分钟完成第一个Feed推送
- 基础操作步骤
- 常见问题

### API参考文档

面向开发者的技术文档：
- 命令行工具使用
- 服务类API说明
- 模型API说明
- 适配器开发指南
- 扩展开发示例

## Website 表单扩展契约

Geo 通过 `Weline_Geo::website_save_after` 订阅
`Weline_Websites::website_save_after`。它在 Website 核心数据、域名、货币、语言和两项
start-page SystemConfig 已写入、但外层主库事务尚未提交时执行。

- 表单字段必须放在 `post_data.extensions.geo`；该区块缺失或为空时不修改 Geo 配置。
- 事件数据只在 `website_id` 字段缺失或值为 `null` 时表示无站点上下文。
- `website_id=0` 是合法的系统默认站点；负数、小数或其他非整数值会抛出，不得以真假值过滤 ID 0。
- Website 后台表单 Hook 必须为数字站点 ID 添加 `website_` DOM 前缀，并让每个 `label[for]` 精确匹配动态字段 ID；因此零号网站使用 `website_0_geo_protocol_*`，可被 Bootstrap 折叠组件和辅助技术安全引用。
- Website 后台表单读取 Geo 配置时同样接受 `website_id=0`；只有站点身份字段完全缺失时才使用新增表单的默认值。
- Observer 只保存该精确站点的 `llms_enabled`、`feed_enabled`、`auto_push`、
  `feed_id` 和 `llms_intro`；不查首个站点，不跨站 fallback。
- Observer 使用默认 `failure=critical`。任何 Geo 写入异常都必须上抛，使 Website 整单回滚；不得捕获后继续返回站点保存成功。
- 外部 Feed 推送、通知或网络请求不属于该事件，应在数据库提交后由独立流程执行。

```php
[
    'website_id' => 0,
    'post_data' => [
        'extensions' => [
            'geo' => [
                'llms_enabled' => '1',
                'feed_enabled' => '1',
                'auto_push' => '1',
                'feed_id' => 12,
                'llms_intro' => 'Example',
            ],
        ],
    ],
]
```

---

## 🔗 相关链接

- [模块README](../README.md)
- [WelineFramework文档](../../../docs/dev/开发文档.md)

---

## 📝 更新日志

- Geo 头部与协议渲染只依赖 `Weline\Seo\Api\Head`、`Weline\Seo\Api\Protocol` 的不可变上下文契约，不引用 Seo 内部 Service。

### v1.0.0 (2025-01-XX)

- 初始版本发布
- 支持5个主流AI搜索引擎平台
- 完整的Feed管理和推送功能
- 密钥加密存储
- 自动推送机制

---

## 💬 反馈与支持

如有问题或建议，请访问：
- 官网：aiweline.com
- 论坛：https://bbs.aiweline.com
- 邮箱：aiweline@qq.com
