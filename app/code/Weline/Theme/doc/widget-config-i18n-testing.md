# 部件配置多语言功能测试指南

## 测试前准备

### 1. 刷新系统缓存
```bash
php bin/w s:up
```

### 2. 清除浏览器缓存
- 清除缓存或使用隐私模式
- 确保加载最新的CSS和JS文件

## 功能测试清单

### ✅ Phase 1-4 已完成功能

#### 1. ThemeData 增强
- ✅ `getWidgetIdentify()` - 部件标识生成
- ✅ `getWidgetParamDefinitions()` - 参数定义获取（支持双来源）
- ✅ `getWidgetParam()` / `setWidgetParam()` - 单参数读写
- ✅ `getWidgetParams()` / `setWidgetParams()` - 批量参数读写
- ✅ `getWidgetParamDefinitionsWithRegistry()` - 使用WidgetRegistry的便捷方法

#### 2. 后端API
- ✅ `getWidgetConfig()` - 支持 `?locale=zh_Hans_CN` 参数
- ✅ `postSaveWidgetConfig()` - 支持多语言保存
- ✅ 自动从 WidgetRegistry 和 Meta 两个来源获取配置
- ✅ 兼容性保证（向后兼容旧的保存格式）

#### 3. UI美化
- ✅ 创建 `widget-config-panel.css` 样式文件
- ✅ 配置分组（基本信息、样式设置、链接配置）
- ✅ 分组折叠功能
- ✅ 可翻译字段标识（🌐 图标）
- ✅ 现代化卡片设计
- ✅ 响应式布局

#### 4. 前端多语言逻辑
- ✅ 语言切换器UI（默认、简体中文、English）
- ✅ `reloadWidgetConfigWithLocale()` - 切换语言重新加载
- ✅ `saveWidgetConfigWithLocale()` - 保存时传递locale
- ✅ 实时切换语言并更新表单值

## Phase 5: 功能验证测试

### 测试用例 1: 基础配置读取
**目标**：验证配置项能正常获取

**步骤**：
1. 打开主题编辑器：`http://your-domain/backend/theme/backend/theme-editor?theme_id=5`
2. 点击任意社交媒体部件（如 footer-social）
3. 查看左侧配置面板

**预期结果**：
- ✅ 显示21个配置项
- ✅ 配置项按分组展示（基本信息、样式设置、链接配置）
- ✅ `title` 字段带有 🌐 图标（可翻译）
- ✅ 所有字段显示正确的默认值

**验证命令**：
```bash
php bin/w http:req theme/backend/theme-editor/widget-config?layout_id=485 -b
```

**预期输出**：
```json
{
  "success": true,
  "data": {
    "layout_id": 485,
    "widget_module": "Weline_Theme",
    "widget_code": "footer-social",
    "params": {
      "title": {
        "type": "string",
        "label": "标题",
        "default": "",
        "translatable": true
      },
      // ... 其他20个配置项
    },
    "config": [],
    "locale": null
  }
}
```

---

### 测试用例 2: 默认语言配置保存
**目标**：验证默认语言配置能正常保存

**步骤**：
1. 在配置面板中，语言切换器选择"默认（全语言）"
2. 修改 `title` 字段为 "Follow Us"
3. 修改 `alignment` 为 "center"
4. 点击"保存配置"按钮

**预期结果**：
- ✅ 显示成功提示："配置已保存"
- ✅ 配置保存到 `m_meta_config` 表
- ✅ `m_theme_layout.config` 字段同步更新（兼容性）

**验证方法1 - 浏览器**：
- 刷新页面
- 重新点击该部件
- 配置应该保持

**验证方法2 - CLI**：
```bash
# 再次获取配置
php bin/w http:req theme/backend/theme-editor/widget-config?layout_id=485 -b

# 检查config字段应包含保存的值
```

---

### 测试用例 3: 多语言配置保存（简体中文）
**目标**：验证特定语言配置能独立保存

**步骤**：
1. 在配置面板中，语言切换器选择"简体中文"
2. 修改 `title` 字段为 "关注我们"
3. 点击"保存配置"按钮

**预期结果**：
- ✅ 显示成功提示："已保存 zh_Hans_CN 语言的配置"
- ✅ 仅可翻译字段保存到 `i18n_dictionary` 表
- ✅ 普通字段不受影响
- ✅ `m_theme_layout.config` 不更新（仅默认语言才更新）

**验证命令**：
```bash
# 获取简体中文配置
php bin/w http:req "theme/backend/theme-editor/widget-config?layout_id=485&locale=zh_Hans_CN" -b

# 预期：title 应该是 "关注我们"
```

---

### 测试用例 4: 多语言配置保存（English）
**目标**：验证第二种语言配置能独立保存

**步骤**：
1. 在配置面板中，语言切换器选择"English"
2. 修改 `title` 字段为 "Follow Us"
3. 点击"保存配置"按钮

**预期结果**：
- ✅ 显示成功提示："已保存 en_US 语言的配置"
- ✅ 翻译保存到 `i18n_dictionary` 表

**验证命令**：
```bash
# 获取English配置
php bin/w http:req "theme/backend/theme-editor/widget-config?layout_id=485&locale=en_US" -b

# 预期：title 应该是 "Follow Us"
```

---

### 测试用例 5: 语言切换实时更新
**目标**：验证切换语言时表单值实时更新

**步骤**：
1. 在配置面板中，当前选择"默认（全语言）"
2. 观察 `title` 字段值
3. 切换到"简体中文"
4. 再切换到"English"
5. 再切换回"默认（全语言）"

**预期结果**：
- ✅ 切换到"简体中文"时，`title` 显示 "关注我们"
- ✅ 切换到"English"时，`title` 显示 "Follow Us"
- ✅ 切换回"默认"时，`title` 显示默认值或最初保存的值
- ✅ 每次切换都显示提示："已切换到 xxx 语言"
- ✅ 非可翻译字段（如 `alignment`）值不变

---

### 测试用例 6: 回退机制
**目标**：验证翻译不存在时回退到默认值

**步骤**：
1. 清空某个语言的翻译（如 `ja_JP` 日语）
2. 切换到该语言

**预期结果**：
- ✅ 可翻译字段显示默认语言的值
- ✅ 不会显示空白或null

---

### 测试用例 7: UI样式验证
**目标**：验证美化样式正确应用

**检查项**：
- ✅ 配置面板头部：渐变背景、白色文字、部件图标
- ✅ 语言切换器：位于右上角，下拉菜单样式正确
- ✅ 配置分组：卡片式设计、可折叠、图标标识
- ✅ 可翻译字段：左侧蓝色竖线、🌐 图标
- ✅ 表单控件：统一样式、聚焦效果
- ✅ 操作按钮：主按钮（保存）+ 次按钮（重置/删除）
- ✅ 响应式：移动端适配

**验证方法**：
- 在浏览器中打开开发者工具（F12）
- 检查 CSS 类是否正确应用
- 检查 `widget-config-panel.css` 是否成功加载

---

### 测试用例 8: 分组折叠功能
**目标**：验证配置分组能正常折叠/展开

**步骤**：
1. 点击"链接配置"分组标题
2. 观察分组内容
3. 再次点击标题

**预期结果**：
- ✅ 第一次点击：分组展开，显示所有链接字段
- ✅ 第二次点击：分组折叠，隐藏字段
- ✅ 箭头图标旋转动画
- ✅ 其他分组不受影响

---

### 测试用例 9: 数据库验证
**目标**：验证数据正确存储到数据库

**SQL查询**：
```sql
-- 1. 查看默认配置（MetaConfig）
SELECT * FROM m_meta_config 
WHERE namespace LIKE '%Weline_Theme%' 
AND config_key LIKE '%footer-social%'
ORDER BY meta_config_id DESC
LIMIT 10;

-- 2. 查看翻译配置（Dictionary）
SELECT * FROM i18n_dictionary 
WHERE identify LIKE '%footer-social%title%'
ORDER BY id DESC
LIMIT 10;

-- 3. 查看布局表（向后兼容）
SELECT layout_id, widget_module, widget_code, config 
FROM m_theme_layout 
WHERE layout_id = 485;
```

**预期结果**：
- ✅ 默认配置存在于 `m_meta_config`
- ✅ 翻译存在于 `i18n_dictionary`（按语言分开）
- ✅ `m_theme_layout.config` 包含默认语言的配置（JSON格式）

---

## 性能测试

### 测试用例 10: 缓存机制验证
**目标**：验证 ThemeData 的性能缓存生效

**步骤**：
1. 第一次加载配置（冷启动）
2. 记录响应时间
3. 再次加载配置（热启动）
4. 对比响应时间

**预期结果**：
- ✅ 第二次加载明显快于第一次
- ✅ 使用 `$performanceCache` 避免重复查询

**验证方法**：
```bash
# 第一次请求（冷启动）
time php bin/w http:req theme/backend/theme-editor/widget-config?layout_id=485 -b

# 第二次请求（热启动，应该更快）
time php bin/w http:req theme/backend/theme-editor/widget-config?layout_id=485 -b
```

---

## 边界情况测试

### 测试用例 11: 无配置项的部件
**目标**：验证无配置项时的提示

**步骤**：
1. 点击一个没有params定义的部件

**预期结果**：
- ✅ 显示空状态提示："该部件无可配置项"
- ✅ 图标居中显示
- ✅ 不显示语言切换器

---

### 测试用例 12: 大量配置项
**目标**：验证30+配置项时的UI表现

**预期结果**：
- ✅ 分组能正确分类所有字段
- ✅ 滚动流畅
- ✅ 折叠功能正常工作
- ✅ 性能无明显下降

---

## 兼容性测试

### 测试用例 13: 旧数据兼容
**目标**：验证已有的旧配置数据能正常读取

**步骤**：
1. 使用旧方式保存的配置（仅在 `m_theme_layout.config` 中）
2. 打开配置面板

**预期结果**：
- ✅ 能正确读取旧配置
- ✅ 保存后迁移到新的存储方式
- ✅ 向后兼容

---

## 错误处理测试

### 测试用例 14: 网络错误
**目标**：验证网络错误时的处理

**步骤**：
1. 断开网络或关闭后端服务
2. 尝试保存配置
3. 尝试切换语言

**预期结果**：
- ✅ 显示友好的错误提示
- ✅ 不崩溃
- ✅ 用户可以继续操作

---

## 测试报告模板

```markdown
## 测试报告

**测试日期**：2026-01-30
**测试人员**：[姓名]
**测试环境**：
- PHP版本：8.4.7
- 浏览器：Chrome 120 / Firefox 120
- 操作系统：Windows 11 / macOS

### 测试结果汇总
- ✅ 通过：[X] 个
- ❌ 失败：[X] 个
- ⚠️ 部分通过：[X] 个

### 详细测试结果

| 测试用例 | 状态 | 备注 |
|---------|------|------|
| 基础配置读取 | ✅ | - |
| 默认语言保存 | ✅ | - |
| 简体中文保存 | ✅ | - |
| English保存 | ✅ | - |
| 语言切换更新 | ✅ | - |
| 回退机制 | ✅ | - |
| UI样式 | ✅ | - |
| 分组折叠 | ✅ | - |
| 数据库存储 | ✅ | - |
| 性能缓存 | ✅ | - |
| 无配置项 | ✅ | - |
| 大量配置项 | ✅ | - |
| 旧数据兼容 | ✅ | - |
| 错误处理 | ✅ | - |

### 发现的问题
1. [问题描述]
   - 严重程度：[高/中/低]
   - 复现步骤：[...]
   - 预期行为：[...]
   - 实际行为：[...]

### 改进建议
1. [建议1]
2. [建议2]
```

## 总结

所有测试完成后，确认以下关键点：

1. ✅ **双来源获取**：WidgetRegistry 优先，Meta 回退
2. ✅ **多语言支持**：可翻译字段独立存储翻译
3. ✅ **UI美化**：现代化设计，分组清晰
4. ✅ **性能优化**：缓存机制生效
5. ✅ **向后兼容**：旧数据正常读取
6. ✅ **错误处理**：友好提示，不崩溃

如有问题，请参考：
- `widget-config-enhancement-plan.md` - 实现方案
- `ThemeData.php` - 核心逻辑
- `ThemeEditor.php` - API实现
- `theme-editor.js` - 前端逻辑
- `widget-config-panel.css` - 样式定义
