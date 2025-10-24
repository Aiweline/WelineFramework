# PageBuilder Editor.js 集成更新日志

## 版本 1.1.0 - 2024年12月

### 新增功能

#### 1. ✅ Editor.js 所见即所得编辑器集成

**描述**：替换传统富文本编辑器，使用 Editor.js 块编辑器，内容以 JSON 格式存储。

**优势**：
- 📝 **现代化编辑体验**：基于块的编辑方式，更直观易用
- 🗂️ **结构化存储**：JSON 格式存储，便于数据处理和迁移
- 🌍 **多语言友好**：JSON 结构完美支持多语言翻译
- 🎨 **丰富的内容类型**：支持 14+ 种块类型（标题、段落、列表、表格、引用等）
- 🔧 **高度可扩展**：可轻松添加自定义块类型

**技术实现**：
- 使用 Editor.js 2.28+ 版本
- CDN 方式引入核心库和工具包
- 自定义中文界面翻译
- 表单提交时自动序列化为 JSON

**文件变更**：
- `view/templates/Backend/Index/form.phtml` - 集成 Editor.js 编辑器
- `view/statics/Backend/js/page-editor.js` - 编辑器封装类（供未来扩展使用）

#### 2. ✅ 默认语言选择功能

**描述**：为每个页面添加默认语言设置，明确页面的主要语言版本。

**功能细节**：
- 🌐 页面创建/编辑时选择默认语言
- 🔄 默认语言自动勾选为支持翻译的语言
- 📊 数据库新增 `default_locale` 字段（VARCHAR 10）

**应用场景**：
- 多语言网站确定页面的原始语言
- SEO 优化：明确页面的主要目标市场
- 翻译管理：知道哪个版本是源版本

**文件变更**：
- `Model/Page.php` - 添加 `fields_DEFAULT_LOCALE` 常量和数据库字段
- `Controller/Backend/Index.php` - 处理 `default_locale` 参数保存
- `view/templates/Backend/Index/form.phtml` - 添加默认语言选择器

#### 3. ✅ 占位符格式修复

**描述**：将翻译占位符从 `%1` 格式改为 `%{1}` 格式。

**原因**：
- 符合 WelineFramework 的翻译规范
- 避免与其他格式化语法冲突
- 提高代码可读性和维护性

**修改位置**：
- ✅ `Controller/Backend/Index.php` - "页面还有以下语言未翻译：%{1}"
- ✅ `view/templates/Backend/Index/form.phtml` - "预览 (%{1})"
- ✅ `view/templates/Backend/Index/translate.phtml` - "翻译到 %{1}" 和 "翻译内容（%{1}）"
- ✅ `view/templates/Frontend/Page/view.phtml` - "此页面还没有 %{1} 的翻译版本"

#### 4. ✅ 示例页面内容

**描述**：基于提供的参考网页（Money Calendar）创建完整的示例内容。

**示例文件**：
- 📄 `doc/example-money-calendar-page.json` - 完整的 Editor.js JSON 数据
- 📖 `doc/README-EXAMPLES.md` - 详细的使用指南和最佳实践

**示例特点**：
- 使用 10+ 种不同的块类型
- 包含标题、段落、列表、表格、引用、警告框、检查清单等
- 展示了营销页面的典型结构
- 提供了多语言翻译示例和工作流程

**内容结构**：
```
- 主标题 (H1)
- 介绍段落
- 关键优势列表
- 工作流程说明
- 机构对比表格
- 免责声明警告框
- 客户评价引用
- 功能检查清单
- 常见问题解答
- 行动号召
```

---

## 技术架构

### Editor.js 块类型支持

| 块类型 | 工具包 | 用途 | 状态 |
|--------|--------|------|------|
| Header | @editorjs/header | 标题 (H1-H6) | ✅ |
| Paragraph | @editorjs/paragraph | 段落文本 | ✅ |
| List | @editorjs/list | 列表 | ✅ |
| Quote | @editorjs/quote | 引用 | ✅ |
| Code | @editorjs/code | 代码块 | ✅ |
| Delimiter | @editorjs/delimiter | 分隔线 | ✅ |
| Warning | @editorjs/warning | 警告框 | ✅ |
| Table | @editorjs/table | 表格 | ✅ |
| Image | @editorjs/image | 图片 | ⚠️ 需配置上传 |
| Embed | @editorjs/embed | 嵌入媒体 | ✅ |
| Link | @editorjs/link | 链接预览 | ✅ |
| Raw | @editorjs/raw | 原始 HTML | ✅ |
| Checklist | @editorjs/checklist | 检查清单 | ✅ |

### 数据库变更

**表名**：`guolairen_page_builder_page`

**新增字段**：
```sql
ALTER TABLE `guolairen_page_builder_page` 
ADD COLUMN `default_locale` VARCHAR(10) NULL COMMENT '默认语言代码';
```

**字段说明**：
- 字段名：`default_locale`
- 类型：`VARCHAR(10)`
- 可空：是
- 默认值：NULL
- 示例值：`en_US`, `zh_CN`, `zh_Hans_CN`

### 内容存储格式

**旧格式**（纯文本/HTML）：
```
<h1>标题</h1>
<p>段落内容</p>
<ul><li>列表项</li></ul>
```

**新格式**（Editor.js JSON）：
```json
{
  "time": 1734556800000,
  "blocks": [
    {
      "type": "header",
      "data": {
        "text": "标题",
        "level": 1
      }
    },
    {
      "type": "paragraph",
      "data": {
        "text": "段落内容"
      }
    },
    {
      "type": "list",
      "data": {
        "style": "unordered",
        "items": ["列表项"]
      }
    }
  ],
  "version": "2.28.2"
}
```

---

## 使用指南

### 创建新页面

1. 导航到：**后台 → 内容管理 → 页面构建器**
2. 点击"新建页面"
3. 填写基本信息：
   - **页面句柄**：URL 友好的标识符（如：`about-us`）
   - **页面类型**：选择合适的类型
   - **页面名称**：显示名称
   - **页面标题**：浏览器标题
4. 选择**默认语言**（必填）
5. 在 **Editor.js 编辑器**中添加内容：
   - 点击 `+` 按钮添加新块
   - 选择块类型（标题、段落、列表等）
   - 输入内容
   - 拖拽调整块的顺序
6. 配置 SEO 信息
7. 选择需要翻译的语言
8. 保存页面

### 翻译页面内容

1. 编辑已创建的页面
2. 在"多语言设置"卡片中，找到未翻译的语言
3. 点击"翻译"按钮
4. 左侧显示原文，右侧输入译文
5. Editor.js 支持相同的块编辑体验
6. 保存翻译

### 使用示例内容

#### 方法 1：手动创建
参考 `doc/example-money-calendar-page.json`，在编辑器中手动添加相应的块。

#### 方法 2：浏览器控制台
```javascript
// 打开编辑页面，在浏览器控制台执行
fetch('/path/to/example-money-calendar-page.json')
  .then(r => r.json())
  .then(data => pageEditor.render(data));
```

#### 方法 3：开发者 API
```php
$content = file_get_contents(__DIR__ . '/doc/example-money-calendar-page.json');
$page->setData(Page::fields_CONTENT, $content)->save();
```

---

## 前端显示

### JSON 转 HTML

前端展示时，Editor.js JSON 需要转换为 HTML。有两种方式：

#### 方式 1：使用 JavaScript 渲染（推荐）

```javascript
// 使用 Editor.js 的只读模式
const editor = new EditorJS({
  holder: 'page-content',
  data: <?= json_encode($pageContent) ?>,
  readOnly: true
});
```

#### 方式 2：服务端转 HTML

```php
// 在 Helper 或 Service 中添加方法
public function renderEditorJsToHtml(string $jsonContent): string
{
    $data = json_decode($jsonContent, true);
    $html = '';
    
    foreach ($data['blocks'] as $block) {
        switch ($block['type']) {
            case 'header':
                $level = $block['data']['level'];
                $html .= "<h{$level}>{$block['data']['text']}</h{$level}>";
                break;
            case 'paragraph':
                $html .= "<p>{$block['data']['text']}</p>";
                break;
            // ... 其他块类型
        }
    }
    
    return $html;
}
```

---

## 迁移指南

### 从旧版本升级

如果你有使用旧版富文本编辑器的页面：

1. **备份数据库**
   ```bash
   php bin/w db:backup
   ```

2. **运行升级命令**
   ```bash
   php bin/w setup:upgrade -m GuoLaiRen_PageBuilder
   ```

3. **迁移现有内容**（可选）
   
   创建迁移脚本将 HTML 转换为 Editor.js 格式：
   
   ```php
   use GuoLaiRen\PageBuilder\Model\Page;
   
   $pages = $pageModel->select()->fetch()->getItems();
   
   foreach ($pages as $page) {
       $htmlContent = $page->getData('content');
       
       // 简单转换：将 HTML 包装为 raw 块
       $editorData = [
           'time' => time() * 1000,
           'blocks' => [
               [
                   'type' => 'raw',
                   'data' => [
                       'html' => $htmlContent
                   ]
               ]
           ],
           'version' => '2.28.2'
       ];
       
       $page->setData('content', json_encode($editorData))->save();
   }
   ```

---

## 最佳实践

### 内容组织

1. **使用标题层级**：H1 作为主标题，H2 作为章节标题，H3 作为子章节
2. **适当分段**：使用 delimiter 分隔不同主题
3. **突出重点**：使用 warning 块强调重要信息
4. **列表优于段落**：关键点使用列表而非长段落

### 多语言管理

1. **明确默认语言**：创建页面时必须选择默认语言
2. **完整翻译**：不要只翻译部分内容
3. **保持结构一致**：翻译时保持块的数量和类型一致
4. **本地化适配**：根据目标语言调整内容（如货币、单位等）

### 性能优化

1. **合理使用图片**：使用 WebP 格式，启用懒加载
2. **控制块数量**：单页面建议不超过 50 个块
3. **缓存渲染结果**：对于静态内容，缓存 HTML 输出

### SEO 优化

1. **合理使用标题**：每页只有一个 H1
2. **填写元信息**：完善 meta_title, meta_description, meta_keywords
3. **语义化内容**：使用正确的块类型（如 quote 而非 paragraph + 样式）

---

## 故障排除

### 编辑器无法加载

**问题**：Editor.js 编辑器区域显示空白

**解决方案**：
1. 检查浏览器控制台是否有 JavaScript 错误
2. 确认 CDN 资源是否加载成功
3. 检查网络连接

### 内容保存失败

**问题**：提交表单时提示保存失败

**解决方案**：
1. 检查 `content` 字段长度限制（TEXT 类型，最大 65535 字节）
2. 如内容过大，考虑升级为 MEDIUMTEXT 或 LONGTEXT
3. 检查 JSON 是否有效

### 翻译页面显示错误

**问题**：翻译页面打开时 Editor.js 加载原文失败

**解决方案**：
1. 确认原文内容是有效的 JSON 格式
2. 检查 `local_description` 表是否有对应语言记录
3. 查看浏览器控制台的错误信息

---

## 未来计划

### v1.2.0 (计划中)

- [ ] 图片上传功能集成
- [ ] 自定义块：CTA 按钮块
- [ ] 自定义块：表单嵌入块
- [ ] 拖拽式页面构建器
- [ ] 页面模板系统

### v1.3.0 (计划中)

- [ ] A/B 测试支持
- [ ] 页面版本历史
- [ ] 协作编辑功能
- [ ] 页面性能分析

---

## 相关链接

- [Editor.js 官方文档](https://editorjs.io/)
- [Editor.js GitHub](https://github.com/codex-team/editor.js)
- [WelineFramework 文档](https://weline.cn)
- [PageBuilder 模块文档](./README.md)
- [示例内容说明](./README-EXAMPLES.md)

---

## 技术支持

如有问题，请通过以下方式联系：

- 📧 Email: support@example.com
- 💬 论坛：https://forum.weline.cn
- 🐛 Issues: https://github.com/your-repo/issues

---

**更新日期**：2024年12月
**维护者**：PageBuilder Team
**许可证**：MIT

