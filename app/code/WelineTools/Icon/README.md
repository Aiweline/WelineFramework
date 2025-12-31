# 图标工具模块 (WelineTools_Icon)

## 功能说明

图标工具模块提供了完整的图标处理功能，包括：

- 📤 **图标上传**：支持 PNG、JPG、GIF、WebP、SVG、BMP 格式
- 🔄 **格式转换**：支持转换为 ICO、PNG、JPG、WebP、GIF 格式
- 🗜️ **图片压缩**：可调节压缩质量（10-100%）
- 📐 **尺寸调整**：支持自定义宽度和高度
- 🎨 **ICO 制作**：自动生成多尺寸 ICO 文件（16x16 到 256x256）

## 访问地址

访问路径：`/icon/icon/index`

## 功能特性

### 1. 图标上传
- 支持拖拽上传
- 支持点击选择文件
- 自动验证文件类型和大小
- 实时预览上传的图片

### 2. 转换为 ICO
- 自动生成多个尺寸的 ICO 文件
- 支持尺寸：16x16, 32x32, 48x48, 64x64, 128x128, 256x256
- 适合制作网站 favicon

### 3. 格式转换
- 支持转换为多种格式
- 保持图片质量
- 支持透明背景（PNG、GIF）

### 4. 图片压缩
- 可调节压缩质量
- 显示压缩前后文件大小对比
- 显示节省的空间百分比

### 5. 尺寸调整
- 支持自定义宽度和高度
- 自动保持宽高比（如果只设置一个值）
- 支持等比缩放

## 技术实现

### 依赖扩展
- **Imagick**（推荐）：提供更好的图片处理能力，支持 ICO 格式
- **GD**（备选）：基础图片处理功能

### 文件结构
```
app/code/WelineTools/Icon/
├── Controller/
│   └── Icon.php              # 图标工具控制器
├── Service/
│   └── IconProcessor.php     # 图标处理服务类
├── view/
│   └── templates/
│       └── Icon/
│           └── index.phtml    # 前端界面
├── etc/
│   └── env.php               # 路由配置
└── register.php              # 模块注册文件
```

### API 接口

#### 1. 上传文件
- **路径**: `/icon/icon/upload`
- **方法**: POST
- **参数**: `file` (文件)
- **返回**: JSON 格式，包含文件信息

#### 2. 转换格式
- **路径**: `/icon/icon/convert`
- **方法**: POST
- **参数**: 
  - `source_path`: 源文件路径
  - `target_format`: 目标格式 (ico, png, jpg, webp, gif)
  - `width`: 宽度（可选）
  - `height`: 高度（可选）
  - `quality`: 质量（可选，1-100）
- **返回**: JSON 格式，包含转换后的文件信息

#### 3. 压缩图片
- **路径**: `/icon/icon/compress`
- **方法**: POST
- **参数**: 
  - `source_path`: 源文件路径
  - `quality`: 压缩质量（1-100）
- **返回**: JSON 格式，包含压缩后的文件信息

## 使用示例

### 前端使用
访问 `/icon/icon/index` 即可使用图形界面进行操作。

### API 调用示例

```javascript
// 上传文件
const formData = new FormData();
formData.append('file', fileInput.files[0]);

fetch('/icon/icon/upload', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => {
    console.log('上传成功:', data);
});

// 转换为 ICO
const convertData = new FormData();
convertData.append('source_path', '/media/icon/uploads/2024/01/01/icon_xxx.png');
convertData.append('target_format', 'ico');

fetch('/icon/icon/convert', {
    method: 'POST',
    body: convertData
})
.then(response => response.json())
.then(data => {
    console.log('转换成功:', data);
});
```

## 注意事项

1. **文件大小限制**：最大支持 10MB 的文件上传
2. **格式支持**：输入格式支持 PNG、JPG、GIF、WebP、SVG、BMP
3. **ICO 格式**：需要 Imagick 扩展支持，如果没有安装会使用 GD 库（功能受限）
4. **文件存储**：上传的文件存储在 `pub/media/icon/uploads/` 目录下
5. **转换文件**：转换后的文件存储在 `converted/` 或 `compressed/` 子目录下

## 系统要求

- PHP 7.4+
- Imagick 扩展（推荐）或 GD 扩展
- WelineFramework 框架

## 作者

秋枫雁飞（Aiweline）
- 邮箱：aiweline@qq.com
- 网址：aiweline.com
- 论坛：https://bbs.aiweline.com

