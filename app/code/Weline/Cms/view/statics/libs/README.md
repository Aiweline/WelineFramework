# 第三方库文件说明

本目录包含 Cms 模块使用的第三方 JavaScript 库。这些库已从 CDN 下载到本地，以提高加载速度和稳定性。

## 已包含的库

### 1. CKEditor 5 (v39.0.1)
**目录**: `ckeditor5/`

**文件**:
- `ckeditor.js` - CKEditor 5 Classic 编辑器主文件
- `zh-cn.js` - 中文语言包

**用途**: 后台页面内容编辑器（富文本编辑）

**官方网站**: https://ckeditor.com/ckeditor-5/

**许可证**: GPL-2.0-or-later / Commercial

**使用位置**:
- `view/templates/Backend/Page/form.phtml`

---

### 2. html2canvas (v1.4.1)
**目录**: `html2canvas/`

**文件**:
- `html2canvas.min.js` - html2canvas 压缩版

**用途**: 后台页面预览截图功能

**官方网站**: https://html2canvas.hertzen.com/

**GitHub**: https://github.com/niklasvh/html2canvas

**许可证**: MIT

**使用位置**:
- `view/templates/Backend/Page/form.phtml`

---

### 3. whatwg-fetch (v3.6.2)
**目录**: `whatwg-fetch/`

**文件**:
- `fetch.umd.js` - Fetch API polyfill (UMD格式)

**用途**: 表单异步提交（为旧浏览器提供 Fetch API 支持）

**官方网站**: https://github.github.io/fetch/

**GitHub**: https://github.com/github/fetch

**许可证**: MIT

**使用位置**:
- 表单异步提交功能（如需要）

---

## 更新说明

如需更新这些库的版本，请：

1. 访问对应库的官方网站或 CDN
2. 下载新版本文件
3. 替换本目录中的对应文件
4. 更新本 README 文件中的版本号
5. 测试功能是否正常

## 更新历史

- **2025-10-21**: 初始版本
  - CKEditor 5: v39.0.1
  - html2canvas: v1.4.1
  - whatwg-fetch: v3.6.2
  - 从 CDN 迁移到本地文件

## 注意事项

### Google Tag Manager 和 Facebook Pixel
这些追踪代码保持从外部加载，原因：
1. 需要实时更新追踪逻辑
2. 官方要求从其服务器加载以保证数据准确性
3. 不建议本地化

相关文件：
- `view/templates/base/tracking.phtml`
- `view/templates/Frontend/Page/view.phtml`
- 各样式模板的 `header.phtml`

### 静态文件访问

在模板中使用以下方式引用这些库：

#### 方式1：使用 @static 标签（推荐）

```php
<!-- CKEditor 5 -->
<script src="@static(Weline_Cms::libs/ckeditor5/ckeditor.js)"></script>
<script src="@static(Weline_Cms::libs/ckeditor5/zh-cn.js)"></script>

<!-- html2canvas -->
<script src="@static(Weline_Cms::libs/html2canvas/html2canvas.min.js)"></script>

<!-- whatwg-fetch -->
<script src="@static(Weline_Cms::libs/whatwg-fetch/fetch.umd.js)"></script>
```

**@static 标签的优势**：
- ✅ 框架推荐的标准方式
- ✅ 自动处理模块路径和版本控制
- ✅ 支持缓存优化
- ✅ 更简洁易维护

#### 方式2：使用 getStaticFile() 方法

```php
<!-- CKEditor 5 -->
<script src="<?= $this->getStaticFile('libs/ckeditor5/ckeditor.js') ?>"></script>
<script src="<?= $this->getStaticFile('libs/ckeditor5/zh-cn.js') ?>"></script>

<!-- html2canvas -->
<script src="<?= $this->getStaticFile('libs/html2canvas/html2canvas.min.js') ?>"></script>

<!-- whatwg-fetch -->
<script src="<?= $this->getStaticFile('libs/whatwg-fetch/fetch.umd.js') ?>"></script>
```

`getStaticFile()` 方法会自动生成正确的静态文件 URL路径。

