# AI一键生成内容使用指南

## 概述

PageBuilder模块集成了AI一键生成内容功能，可以在编辑/添加页面和可视化编辑器中使用AI自动生成页面内容和模板配置。

## 功能特性

1. **页面内容生成**：根据页面描述自动生成标题、SEO信息和页面内容
2. **模板配置生成**：根据页面信息自动生成模板所需的所有文字配置项
3. **智能提示词**：自动构建优化的提示词，包含页面现有信息
4. **JSON解析回填**：自动解析AI返回的JSON并填充到相应的输入框

## 使用场景

### 场景1：编辑/添加页面时生成内容

在页面编辑表单中：

1. 点击基本信息卡片右上角的 **"AI生成内容"** 按钮
2. 在弹出的对话框中输入页面描述
3. 点击 **"生成内容"** 按钮
4. AI将根据描述和页面现有信息生成内容
5. 生成的内容会自动填充到相应的输入框：
   - `title` - 页面标题
   - `meta_title` - SEO标题
   - `meta_description` - SEO描述
   - `meta_keywords` - SEO关键词
   - `content` - 页面内容（主页类型不生成此字段）

### 场景2：可视化编辑器中生成模板配置

在可视化配置界面中：

1. 点击工具栏右侧的 **"AI生成配置"** 按钮
2. 系统自动读取页面数据（无需弹窗）
3. AI根据页面信息和模板要求生成所有文字配置项
4. 生成的内容会自动填充到相应的配置字段
5. 配置会自动保存

## 技术实现

### API接口

#### 1. 生成页面内容

**URL**: `/pagebuilder/backend/ai-generate/page-content`

**方法**: POST

**参数**:
- `description`: 页面描述（必填）
- `page_type`: 页面类型（可选，默认page）
- `title`: 页面标题（可选）
- `meta_title`: SEO标题（可选）
- `meta_description`: SEO描述（可选）
- `meta_keywords`: SEO关键词（可选）
- `handle`: 页面句柄（可选）
- `style_code`: 模板代码（可选）

**响应**:
```json
{
    "success": true,
    "data": {
        "title": "生成的页面标题",
        "meta_title": "生成的SEO标题",
        "meta_description": "生成的SEO描述",
        "meta_keywords": "生成的SEO关键词",
        "content": "生成的HTML内容（主页类型不包含此字段）"
    }
}
```

#### 2. 生成模板配置

**URL**: `/pagebuilder/backend/ai-generate/template-config`

**方法**: POST

**参数**:
- `page_id`: 页面ID（必填）
- `style_code`: 模板代码（必填）

**响应**:
```json
{
    "success": true,
    "data": {
        "texts.nav_home": "Home",
        "texts.nav_about": "About",
        ...
    }
}
```

### 提示词构建

#### 页面内容生成提示词

系统会自动构建包含以下信息的提示词：
- 页面描述（用户输入）
- 页面类型
- 现有字段值（title, meta_title等）
- 页面句柄
- 模板代码

提示词要求AI返回JSON格式，包含：
- `title`: 页面标题
- `meta_title`: SEO标题（50-60字符）
- `meta_description`: SEO描述（150-160字符）
- `meta_keywords`: SEO关键词
- `content`: HTML内容（主页类型不包含）

#### 模板配置生成提示词

系统会自动构建包含以下信息的提示词：
- 页面标题、句柄、类型
- 模板代码和名称
- SEO描述
- 页面内容摘要
- 需要生成的所有配置项列表

提示词要求AI返回JSON格式，包含所有配置项的键值对。

### JSON解析

系统会自动处理AI返回的内容：
1. 尝试提取JSON（支持markdown代码块格式）
2. 修复常见的JSON格式问题（如尾随逗号）
3. 解析JSON并验证格式
4. 将解析后的数据填充到相应的输入框

## 注意事项

1. **AI服务配置**：使用前需要在后台配置AI模型（系统服务 → AI服务 → 模型管理）
2. **页面类型判断**：主页类型（home/homepage）不会生成content字段
3. **字段优先级**：如果页面已有字段值，AI会基于这些值进行优化和完善
4. **JSON格式要求**：AI必须返回有效的JSON格式，否则会解析失败
5. **网络请求**：生成功能需要网络请求，请确保网络连接正常

## 故障排除

### 生成失败
- 检查AI服务是否已配置
- 检查AI模型是否已启用
- 检查网络连接是否正常
- 查看浏览器控制台的错误信息

### JSON解析失败
- 检查AI返回的内容格式
- 查看控制台的错误信息
- 尝试重新生成

### 内容未填充
- 检查输入框的ID是否正确
- 检查字段名称是否匹配
- 查看浏览器控制台的错误信息

## 示例

### 页面内容生成示例

**输入描述**：
```
创建一个关于我们页面，介绍公司历史、团队和使命
```

**生成结果**：
- 标题：关于我们
- SEO标题：关于我们 - 了解我们的历史、团队和使命
- SEO描述：我们是一家致力于...的公司，拥有多年的行业经验...
- 内容：完整的HTML内容，包含标题、段落、列表等

### 模板配置生成示例

**页面信息**：
- 标题：Teen Patti Master
- 类型：page
- 模板：tpmst

**生成结果**：
- `texts.nav_home`: "Home"
- `texts.nav_about`: "About"
- `texts.advantages_title`: "Our Advantages"
- ...（所有texts分组的配置项）

## 技术支持

如有问题，请参考：
- AI模块文档：`app/code/Weline/Ai/doc/`
- PageBuilder模块文档：`app/code/GuoLaiRen/PageBuilder/README.md`
