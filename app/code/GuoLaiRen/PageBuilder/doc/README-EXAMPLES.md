# PageBuilder 示例内容

本目录包含用于 PageBuilder 模块的示例内容和模板。

## 示例页面

### 1. Money Calendar 示例 (`example-money-calendar-page.json`)

这是一个基于金融交易日历的营销页面示例，展示了如何使用 Editor.js 创建丰富的内容。

**特点：**
- 使用多种 Editor.js 块类型（标题、段落、列表、表格、引用等）
- 包含营销文案和号召性用语
- 展示了如何组织复杂的页面结构
- JSON 格式存储，易于多语言翻译

**使用的 Editor.js 块类型：**
- `header` - 标题（H1, H2）
- `paragraph` - 段落文本（支持粗体、斜体等格式）
- `list` - 有序和无序列表
- `table` - 表格数据展示
- `quote` - 引用/推荐语
- `warning` - 警告提示框
- `checklist` - 可勾选清单
- `delimiter` - 分隔线

## 如何使用示例

### 方法 1：通过后台创建新页面

1. 登录后台管理系统
2. 导航到：**内容管理 → 页面构建器**
3. 点击"新建页面"
4. 填写基本信息（句柄、类型、名称、标题）
5. 在内容编辑器中，可以：
   - 手动添加块
   - 或复制 `example-money-calendar-page.json` 的 `blocks` 数组内容

### 方法 2：导入 JSON 数据

在编辑页面时，打开浏览器控制台，执行：

```javascript
// 读取 JSON 文件内容
fetch('/path/to/example-money-calendar-page.json')
  .then(response => response.json())
  .then(data => {
    // 初始化编辑器并加载数据
    if (pageEditor) {
      pageEditor.render(data);
    }
  });
```

### 方法 3：通过 API 创建（开发者）

```php
use GuoLaiRen\PageBuilder\Model\Page;

$pageModel = ObjectManager::getInstance(Page::class);

$exampleContent = file_get_contents(__DIR__ . '/example-money-calendar-page.json');

$page = $pageModel->clearData()
    ->setData(Page::fields_HANDLE, 'money-calendar')
    ->setData(Page::fields_TYPE, Page::TYPE_CUSTOM)
    ->setData(Page::fields_NAME, 'Money Calendar')
    ->setData(Page::fields_TITLE, 'Secret Money Calendar - Trading Strategy')
    ->setData(Page::fields_CONTENT, $exampleContent)
    ->setData(Page::fields_DEFAULT_LOCALE, 'en_US')
    ->setData(Page::fields_STATUS, Page::STATUS_PUBLISHED)
    ->save(true);
```

## 自定义示例内容

### 创建中文版本

将 `example-money-calendar-page.json` 复制为 `example-money-calendar-page-zh.json`，然后翻译所有文本内容：

```json
{
  "blocks": [
    {
      "type": "header",
      "data": {
        "text": ""秘密财富日历" 揭示即将爆发的 3 只股票",
        "level": 1
      }
    },
    {
      "type": "paragraph",
      "data": {
        "text": "有一个<b>秘密交易日历……</b>"
      }
    }
    // ... 其他块的翻译
  ]
}
```

### 添加图片

在 Editor.js 中添加图片块：

```json
{
  "type": "image",
  "data": {
    "file": {
      "url": "https://example.com/images/money-calendar.jpg"
    },
    "caption": "Money Calendar Dashboard",
    "withBorder": false,
    "stretched": false,
    "withBackground": false
  }
}
```

### 嵌入视频

添加 YouTube 视频：

```json
{
  "type": "embed",
  "data": {
    "service": "youtube",
    "source": "https://www.youtube.com/watch?v=VIDEO_ID",
    "embed": "https://www.youtube.com/embed/VIDEO_ID",
    "width": 560,
    "height": 315,
    "caption": "Watch our introduction video"
  }
}
```

## 多语言翻译工作流

1. **创建主页面**（默认语言：英文）
   - 使用 `example-money-calendar-page.json` 内容
   - 设置 `default_locale` 为 `en_US`
   - 选择需要支持的语言（如：中文、西班牙语）

2. **翻译页面内容**
   - 在编辑页面点击对应语言的"翻译"按钮
   - Editor.js 会加载原文内容
   - 逐块翻译文本内容
   - 保存翻译版本

3. **前端显示**
   - 系统会根据用户语言自动显示对应翻译版本
   - 如果翻译不存在，显示默认语言版本

## Editor.js 块类型参考

| 块类型 | 用途 | 示例 |
|--------|------|------|
| header | 标题（H1-H6） | 页面主标题、章节标题 |
| paragraph | 段落文本 | 正文内容 |
| list | 列表 | 功能列表、步骤说明 |
| quote | 引用 | 客户推荐、名言警句 |
| code | 代码块 | 技术文档、示例代码 |
| delimiter | 分隔线 | 内容分段 |
| warning | 警告框 | 重要提示、免责声明 |
| table | 表格 | 数据对比、价格表 |
| image | 图片 | 产品图、截图 |
| embed | 嵌入内容 | 视频、社交媒体 |
| linkTool | 链接预览 | 外部链接展示 |
| raw | 原始HTML | 自定义HTML代码 |
| checklist | 检查清单 | 功能清单、任务列表 |

## 最佳实践

1. **结构化内容**：使用标题层级（H1, H2, H3）组织内容
2. **视觉分隔**：使用 delimiter 分隔不同主题
3. **重点突出**：使用粗体、列表突出关键信息
4. **引导行动**：使用 warning 或特殊段落作为 CTA
5. **多媒体丰富**：适当使用图片、视频增强表现力
6. **移动友好**：避免使用过宽的表格或图片

## 技术说明

- **存储格式**：JSON（数据库 TEXT 字段）
- **编辑器版本**：Editor.js 2.28+
- **支持的工具**：14+ 种块类型
- **国际化**：完整中文界面支持
- **浏览器兼容**：Chrome, Firefox, Safari, Edge

## 相关资源

- [Editor.js 官方文档](https://editorjs.io/)
- [Editor.js 工具列表](https://github.com/editor-js)
- [WelineFramework 文档](https://weline.cn)

