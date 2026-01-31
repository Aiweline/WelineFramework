# AI 生成组件目录

此目录用于存放 AI 自动生成的可视化编辑组件。

## 目录结构

```
_ai_generated/
├── components/
│   ├── component.json      # AI 组件配置清单（自动更新）
│   ├── header/             # 头部组件
│   ├── content/            # 内容组件
│   ├── footer/             # 底部组件
│   └── widget/             # 小部件组件
└── README.md
```

## 工作原理

1. **数据库优先**: AI 组件的配置和模板内容存储在数据库中
2. **实体文件缓存**: 渲染时，系统会将数据库中的模板内容同步到此目录
3. **按需更新**: 只有当数据库内容变更时，才会更新实体文件
4. **自动清理**: 删除数据库中的组件时，会自动清理对应的实体文件

## 注意事项

- **请勿手动编辑此目录下的文件**，它们会被系统自动覆盖
- 如需修改 AI 组件，请通过后台管理界面或 API 进行
- `component.json` 文件会自动更新，无需手动维护

## API 接口

AI 组件相关的 API 接口：

- `POST /backend/visual/api/ai-component/generate` - 生成组件
- `POST /backend/visual/api/ai-component/save` - 保存组件
- `GET /backend/visual/api/ai-component/list` - 获取组件列表
- `POST /backend/visual/api/ai-component/sync` - 同步实体文件
- `POST /backend/visual/api/ai-component/cleanup` - 清理无效文件

## 版本信息

- 模板版本: 1.0.0
- 兼容 PageBuilder: 2.1.0+
