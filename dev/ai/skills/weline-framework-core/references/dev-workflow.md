# WelineFramework 开发工作流

源自 `dev/ai/AI-开发与测试指南.md`。

## 开发前

- 检查 `app/code` 中现有模块模式
- 从源码验证框架 API，不要假设 Laravel/Symfony/Magento 风格辅助函数存在
- 专业化任务从 `dev/ai/skills` 选择最匹配技能

## 常用命令

```bash
php bin/w setup:upgrade
php bin/w setup:upgrade --route
php bin/w http:request /
php bin/w http:request admin -b
php bin/w http:request rest/v1/module/action -api
php bin/w http:request admin -b --filter=Warning
php bin/w http:request admin -b --filter=Fatal
```

## 验证期望

- 验证核心流程、UI 可用性、数据一致性、错误处理
- 前端相关变更可行时进行浏览器级验证
- 新控制器工作应在路由升级后通过 `http:request` 检查

## 常见陷阱

- 缺失或格式错误的 `register.php`
- 错误的事件文件路径或 observer 命名空间
- 控制器/model 类名冲突
- Taglib 属性包含原始 PHP 输出
- PHP 8.2+ 中的 null 不安全调用
