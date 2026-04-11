# PageBuilder 架构重构日志

## 版本 2.0.0 - 2025-10-16

### 重大变更

#### 1. 移除页面内容编辑器

**变更内容：**
- ❌ 删除了 TinyMCE 富文本编辑器
- ❌ 删除了多语言内容编辑 Tab
- ❌ 删除了 `content` 字段的编辑功能
- ✅ 专注于样式配置参数

**影响范围：**
- `view/templates/Backend/Index/form.phtml`（第195-315行）
- 页面编辑界面布局

**原因：**
- 简化页面管理流程
- 专注于样式参数配置
- 避免内容与样式混杂

#### 2. 引入 I18n Local 翻译系统

**变更内容：**
- ✅ 每个配置项支持多语言翻译
- ✅ 使用 `<local>` 标签管理翻译
- ✅ 翻译数据存储在 `LocalDescription.config` 字段
- ✅ 支持 `config.{groupName}.{fieldName}` 路径格式

**示例：**

```html
<local 
    model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
    field="config" 
    id="1" 
    name="config.header.background_color"
    value="#ffffff">
    <button type="button" class="btn btn-sm btn-outline-secondary">
        <i class="mdi mdi-translate"></i> 翻译
    </button>
</local>
```

**翻译路径示例：**
- `config.header.logo_position` - Header 的 Logo 位置
- `config.content.max_width` - Content 的最大宽度
- `config.footer.show_copyright` - Footer 是否显示版权

#### 3. 更新界面布局

**变更内容：**
- 卡片标题：`页面样式` → `样式与配置`
- 说明文字更新，强调配置来自模板扫描
- 每个配置项下方显示翻译按钮

**配置说明（新）：**
1. 配置从样式模板（header/footer/content.phtml）自动扫描生成
2. 每个配置项支持多语言翻译，点击"翻译"按钮可为不同语言设置不同的值
3. 切换样式时，之前的配置会被保留

### 数据结构

#### LocalDescription.config 新字段格式

```json
{
  "config": {
    "header.background_color": "#ff0000",
    "header.text_color": "#ffffff",
    "header.height": "80",
    "content.max_width": "1200",
    "content.padding": "40",
    "content.background_color": "#ffffff",
    "footer.show_copyright": "yes",
    "footer.show_publish_time": "yes"
  }
}
```

### 配置优先级系统

```
🥇 LocalDescription.config.{configKey}  (翻译配置 - 最高优先级)
    ↓ 覆盖
🥈 Page.style_setting[styleCode][configKey]  (页面配置 - 中等优先级)
    ↓ 覆盖
🥉 Style.parseStyleConfig()[configKey].default  (默认配置 - 最低优先级)
```

### 修改的文件

#### 1. view/templates/Backend/Index/form.phtml

**删除：**
- 第195-315行：整个页面内容编辑卡片
  - TinyMCE 编辑器初始化代码
  - 多语言 Tab 切换界面
  - content 字段的编辑区域

**修改：**
- 第137行：标题 `页面样式` → `样式与配置`
- 第159行：说明文字更新
- 第180-183行：配置说明更新
- 第1190-1206行：为每个配置项添加翻译按钮（使用 local 标签）

#### 2. 新增文档

- `view/templates/style/新架构说明.md`
  - 完整的架构说明
  - 数据存储结构
  - 配置优先级
  - Local 标签用法
  - 多语言场景示例

### 使用场景

#### 场景 1：创建新页面

1. 填写基本信息（名称、标题、句柄等）
2. 选择样式模板（如 `default`）
3. 系统自动扫描并显示配置项（Header、Content、Footer）
4. 填写配置项的默认值
5. 保存页面

#### 场景 2：多语言配置

1. 编辑现有页面
2. 在配置项下方点击"翻译"按钮
3. 选择要翻译的语言（如英文、日文等）
4. 为每个语言设置不同的配置值
   - 中文：Header 背景 `#ff0000`（红色）
   - 英文：Header 背景 `#0066cc`（蓝色）
5. 保存翻译
6. 前台访问时，不同语言显示不同配置

#### 场景 3：切换样式

1. 编辑页面
2. 从下拉框选择不同的样式模板
3. 系统自动加载新样式的配置项
4. 填写新样式的配置
5. 保存后，原样式的配置被保留
6. 再次切换回原样式时，配置自动恢复

### API 变更

#### Controller/Backend/Index.php

无需变更，已经支持：
- `getStyleConfig()` - 获取样式配置（AJAX）
- `postCreate()` - 保存 `style_setting` 到 Page
- `postEdit()` - 合并 `style_setting` 到 Page

#### Controller/Frontend/Page.php

无需变更，已经支持：
- 三层配置合并（默认 → 页面 → 翻译）
- 传递 `$style` 变量到模板

### 向后兼容性

#### 保留字段
- `Page.content` 字段仍然存在于数据库
- 当前不使用，未来可以重新启用

#### 数据迁移
- 现有页面数据不受影响
- `style_setting` 字段继续使用
- 翻译数据存储在 `LocalDescription.config` 的新节点中

### 测试结果

✅ 后端页面列表访问正常
- URL: `pagebuilder/backend/index/index`
- 状态码: 200
- 响应时间: 545.48ms

✅ 页面编辑界面访问正常
- URL: `pagebuilder/backend/index/edit`
- 状态码: 200
- 响应时间: 1144.74ms

### 升级指南

#### 对于开发者

1. **样式模板开发**
   - 继续在 `.phtml` 文件顶部定义 `@fields_start ... @fields_end`
   - 配置会自动扫描并生成表单字段
   - 每个字段自动支持翻译

2. **配置使用**
   ```php
   // 在模板中使用配置
   $style = $this->getData('style') ?: [];
   $bgColor = $style['header.background_color'] ?? '#ffffff';
   ```

3. **翻译配置**
   - 使用 I18n Local 系统的标签库
   - 路径格式：`config.{groupName}.{fieldName}`
   - 存储在 `LocalDescription.config` 字段

#### 对于管理员

1. **创建页面**
   - 选择样式模板
   - 配置样式参数
   - 不再需要编辑 HTML 内容

2. **多语言支持**
   - 点击配置项下方的"翻译"按钮
   - 为不同语言设置不同的配置值
   - 系统自动根据访客语言显示对应配置

3. **样式切换**
   - 切换样式时，原配置自动保留
   - 再次切换回来时，配置自动恢复

### 未来规划

#### 短期（v2.1）
- [ ] 配置项分组折叠/展开
- [ ] 配置模板预设（快速套用配置方案）
- [ ] 配置项的条件显示（依赖关系）

#### 中期（v2.2）
- [ ] 更多字段类型（日期、文件上传、富文本等）
- [ ] 配置验证规则（正则、范围等）
- [ ] 配置导入/导出

#### 长期（v3.0）
- [ ] 可视化配置编辑器
- [ ] 实时预览
- [ ] 配置版本管理

### 注意事项

1. **翻译路径命名**
   - 必须使用 `config.` 前缀
   - 格式严格：`config.{groupName}.{fieldName}`
   - 例如：`config.header.background_color`

2. **模板语法**
   - 模板中使用 PHP 数组语法：`$style['header.background_color']`
   - 不能使用 `{{}}` 语法（那是用户输入内容的变量替换）
   - 始终提供默认值：`?? '#ffffff'`

3. **配置扫描**
   - 配置从 `.phtml` 文件的 `@fields_start` 块扫描
   - 修改配置定义后，重新选择样式即可刷新
   - 动态同步：新增/删除字段自动同步

### 相关文档

- `view/templates/style/新架构说明.md` - 完整架构文档
- `view/templates/style/README-配置系统.md` - 配置系统说明
- `view/templates/style/优先级示例.md` - 优先级示例
- `view/templates/style/快速参考.md` - 快速参考手册

---

**版本：** 2.0.0  
**日期：** 2025-10-16  
**状态：** ✅ 已完成并测试

