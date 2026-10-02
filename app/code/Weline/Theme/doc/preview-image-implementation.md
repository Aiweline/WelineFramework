# 主题预览图功能实现记录（历史）

> ⚠️ **历史实现记录（2026-10-02 迁入 doc/）**
>
> 1. 本文讲的是**主题预览图（截图）生成**，与「预览三态（可视化编辑器 / 版本预览 / 正式）」是**不同功能**，此前因命名相近易被混淆。
> 2. 原位置在模块根 `app/code/Weline/Theme/PREVIEW_IMAGE_IMPLEMENTATION.md`，违反"模块根不放非契约文档"约定，现迁入 `doc/`。
> 3. 原有「手动执行步骤」段落教用户**手写 `ALTER TABLE`**、并称"暂时无法通过命令行升级" + 手工安装 Chrome —— 该指引**已失效**，现已替换为指向 `Setup/Upgrade.php` 的正确升级方式。
> 4. 相关内容现行入口：`Console/Theme/GeneratePreviews.php`（`php bin/w theme:generate-previews`）。
>
> 追溯记录：`dev/audit/theme-legacy-audit-20261002.md`。

---
# 主题预览图功能实现文档

## 实现内容

### 1. 修复重复主题名字问题

**问题分析：**
- 在 `Installer.php` 中，检查主题是否存在时只使用 `module_name` 唯一性
- 当不同模块的主题有相同名字时，会重复插入

**修复方案：**
在 `app/code/Weline/Theme/Register/Installer.php` 的 `installTheme` 方法中，添加了主题名重复检查：

```php
// 检查主题名是否重复（不同module_name）
$this->welineTheme->clearData()->clearQuery();
$this->welineTheme->load(WelineTheme::schema_fields_NAME, $param['name']);
if ($this->welineTheme->getId() && $this->welineTheme->getModuleName() !== $module_name) {
    $this->printing->warning(__('主题名 "%{1}" 已被模块 "%{2}" 占用，跳过安装', [
        $param['name'],
        $this->welineTheme->getModuleName()
    ]));
    return;
}
```

### 2. 添加 preview_image 字段

**修改的文件：**
- `app/code/Weline/Theme/Model/WelineTheme.php` - 添加 `preview_image` 字段声明和 getter/setter 方法
- `app/code/Weline/Theme/Setup/Upgrade.php` - 数据库升级脚本

**字段信息：**
- 字段名：`preview_image`
- 类型：`VARCHAR(255)`
- 允许NULL：是
- 位置：在 `path` 字段之后
- 注释：预览图片路径

**数据库升级命令：**
```sql
ALTER TABLE weline_theme ADD COLUMN preview_image VARCHAR(255) NULL DEFAULT NULL COMMENT '预览图片路径' AFTER path;
```

### 3. 创建主题预览图片生成服务

**文件：** `app/code/Weline/Theme/Service/ThemePreviewGenerator.php`

**主要功能：**
- `generatePreviewImage()` - 生成主题预览图
- `getPreviewImagePath()` - 获取预览图存储路径
- `getPreviewUrl()` - 获取主题预览URL
- `captureScreenshot()` - 使用 Chrome 浏览器截图
- `getChromePath()` - 获取 Chrome/Chromium 可执行文件路径
- `optimizeImage()` - 优化图片大小
- `deletePreviewImage()` - 删除主题预览图

**依赖：**
- 需要系统安装 Chrome 或 Chromium 浏览器
- 支持 Windows、Linux、macOS 平台

### 4. 批量生成命令

**文件：** `app/code/Weline/Theme/Console/Theme/GeneratePreviews.php`

**使用方法：**
```bash
# 生成 frontend 预览图
php bin/w theme:generate-previews

# 生成 frontend 和 backend 两个区域的预览图
php bin/w theme:generate-previews --area

# 强制重新生成已存在的预览图
php bin/w theme:generate-previews --force

# 同时使用两个参数
php bin/w theme:generate-previews --area --force
```

### 5. 更新主题列表控制器

**文件：** `app/code/Weline/Theme/Controller/Backend/Index.php`

**新增方法：**
- `postGeneratePreviewImage()` - 生成单个主题的预览图（AJAX）
- `postGenerateAllPreviews()` - 批量生成所有主题的预览图（AJAX）

### 6. 更新主题列表模板

**文件：** `app/code/Weline/Theme/view/templates/backend/index.phtml`

**新功能：**
- 显示主题预览图片
- 无预览图时显示占位符
- 单个主题生成预览图按钮
- 批量生成预览图按钮
- 生成进度显示
- AJAX 操作反馈
## 字段与依赖安装（现行方式）

> 原「手动执行步骤」已删除（教用户手写 `ALTER TABLE`、手工装 Chrome，均已失效）。

- **数据库字段**：由模块升级流程负责，执行 `php bin/w setup:upgrade -m Weline_Theme` 即可；不要再手工 `ALTER TABLE`。
- **浏览器依赖**：由运行环境提供（Playwright/Chromium 由仓库测试链安装），无需用户手工安装。
- **验证**：`php bin/w theme:generate-previews`，随后在后台主题列表刷新查看。
## 使用说明

### 生成单个主题预览图

1. 在主题列表页面，找到没有预览图的主题
2. 点击主题卡片上的相机图标按钮
3. 等待生成完成（约3-5秒）
4. 刷新页面查看预览图

### 批量生成所有主题预览图

1. 点击页面右上角的"批量生成预览图"按钮
2. 在弹窗中等待生成完成
3. 生成完成后会自动刷新页面

### 更新已有预览图

使用 `--force` 参数强制重新生成：

```bash
php bin/w theme:generate-previews --force
```

## 注意事项

1. **浏览器路径**：如果系统中 Chrome 不在默认路径，需要修改 `ThemePreviewGenerator.php` 中的 `getChromePath()` 方法
2. **截图超时**：如果截图超时，可以增加浏览器启动超时时间
3. **权限问题**：确保 PHP 有执行浏览器的权限
4. **网络问题**：确保可以访问本地服务器（127.0.0.1）

## 未来改进

1. 添加图片裁剪功能
2. 支持自定义截图尺寸
3. 添加预览图预览功能
4. 支持远程服务器截图
