# PageBuilder 多语言配置系统

## ✅ 已完成的功能

### 1. 配置优先级系统

配置加载按以下优先级：
1. **当前语言的页面配置**（最高优先级）
2. **默认语言的页面配置**
3. **模板默认配置**（最低优先级）

### 2. API 增强

#### `styleConfig` API

**URL**: `pagebuilder/backend/page/styleConfig`

**参数**:
- `style_code`: 样式模板代码（必填）
- `page_id`: 页面ID（选填）
- `locale`: 语言代码（选填）

**逻辑**:
```php
if (有 page_id 和 locale) {
    返回该页面该语言的配置
} else if (有 page_id) {
    返回该页面默认语言的配置
} else {
    返回模板的默认配置
}
```

**返回数据结构**:
```json
{
    "success": true,
    "data": {
        "layout": {
            "configs": {
                "layout.logo_position": {
                    "key": "layout.logo_position",
                    "label": "Logo位置",
                    "type": "select",
                    "default": "left",
                    "value": "center",  // ← 页面配置值（如果有）
                    "options": ["left", "center", "right"]
                }
            }
        }
    },
    "page_settings": {...},  // 调试信息
    "locale": "zh_Hans_CN"
}
```

### 3. 前端语言切换器

**位置**: 可视化配置工具栏中间（设备切换按钮右侧）

**功能**:
- 显示页面支持的所有语言
- 默认选中页面的默认语言
- 切换语言时自动重新加载配置和预览

**交互流程**:
```
用户选择语言
    ↓
switchVisualLanguage(locale)
    ↓
更新 currentVisualLanguage
    ↓
loadVisualStyleConfig()  ← 传递 locale
    ↓
refreshVisualPreview()   ← 传递 locale
```

### 4. 多语言保存

#### 配置存储结构

```json
{
    "zh_Hans_CN": {
        "layout.logo_position": "center",
        "style.background_color": "#ffffff"
    },
    "en_US": {
        "layout.logo_position": "left",
        "style.background_color": "#f0f0f0"
    }
}
```

#### autoSave API

**参数**:
```json
{
    "page_id": 1,
    "locale": "zh_Hans_CN",
    "style_config": {
        "layout.logo_position": "center",
        "style.background_color": "#ffffff"
    }
}
```

**逻辑**:
```php
if (有 locale) {
    // 按语言保存
    $settings[$locale] = array_merge($settings[$locale], $newConfig);
} else {
    // 通用配置（兼容旧版本）
    $settings = array_merge($settings, $newConfig);
}
```

---

## 📊 完整数据流

### 加载配置

```
打开可视化配置
    ↓
初始化变量
    currentVisualLanguage = 页面默认语言
    visualPageId = 页面ID
    ↓
loadVisualStyleConfig()
    ↓
构建URL: styleConfig?style_code=default&page_id=1&locale=zh_Hans_CN
    ↓
后端：
    1. 加载模板配置定义
    2. 加载页面的 settings[locale]
    3. 将页面配置合并到 config.value
    ↓
前端：
    renderVisualConfig(configGroups)
    ↓
渲染表单：
    currentValue = config.value || config.default
```

### 切换语言

```
用户选择语言: en_US
    ↓
switchVisualLanguage('en_US')
    ↓
currentVisualLanguage = 'en_US'
    ↓
loadVisualStyleConfig()  ← 重新加载 en_US 的配置
    ↓
refreshVisualPreview()   ← 刷新预览（带 locale 参数）
```

### 自动保存

```
用户修改配置
    ↓
触发 input/change 事件
    ↓
autoSaveVisualConfig() (1秒防抖)
    ↓
收集所有配置值
    ↓
POST autoSave
    body: {
        page_id: 1,
        locale: 'zh_Hans_CN',
        style_config: {...}
    }
    ↓
后端：
    settings[zh_Hans_CN] = 合并新配置
    ↓
保存到数据库
    ↓
刷新预览
```

---

## 🔍 调试信息

### 前端 Console 日志

打开可视化配置时，查看控制台：

```javascript
// 初始化
📋 可视化配置初始化 {
    visualPageId: 1,
    pageIdType: "number",
    pageIdValid: true,
    currentLanguage: "zh_Hans_CN",
    defaultLanguage: "zh_Hans_CN"
}

// 加载配置
🔄 正在加载样式配置: {
    styleCode: "default",
    pageId: 1,
    locale: "zh_Hans_CN",
    url: ".../styleConfig?style_code=default&page_id=1&locale=zh_Hans_CN"
}

// API 返回
📦 API返回结果: {
    success: true,
    data: {...},
    page_settings: {...},
    locale: "zh_Hans_CN"
}

// 渲染配置
✅ layout.logo_position: center (来自页面配置)
⚪ style.background_color: #ffffff (使用模板默认值)

// 保存配置
💾 自动保存配置 {
    pageId: 1,
    locale: "zh_Hans_CN",
    configKeys: ["layout.logo_position", "style.background_color"],
    requestData: {...}
}

// 切换语言
🌐 切换语言: en_US
```

### 后端日志

查看 `var/log/dev.log`:

```
🔍 解析后的数据
    pageId: 1
    locale: zh_Hans_CN
    styleConfigKeys: [...]

💾 按语言保存配置
    locale: zh_Hans_CN
    configCount: 5
    mergedCount: 12
```

---

## 🎯 使用场景

### 场景 1：不同语言使用不同 Logo

```
zh_Hans_CN:
    logo.image_url = "/media/logo-cn.png"
    
en_US:
    logo.image_url = "/media/logo-en.png"
```

### 场景 2：不同语言使用不同颜色

```
zh_Hans_CN:
    style.background_color = "#ff0000" (红色)
    
en_US:
    style.background_color = "#0000ff" (蓝色)
```

### 场景 3：共享通用配置

如果某些配置在所有语言中相同，可以：
1. 为每种语言都设置一遍（推荐）
2. 或者只设置默认语言的配置

---

## 🔧 技术实现

### 后端关键代码

#### getStyleConfig()

```php
// 获取页面的语言配置
if ($pageId > 0 && $locale) {
    $allSettings = $page->getStyleSetting();
    
    if (isset($allSettings[$locale])) {
        $pageSettings = $allSettings[$locale];
    } else {
        // 降级到默认语言
        $defaultLocale = $page->getData('default_locale');
        $pageSettings = $allSettings[$defaultLocale] ?? [];
    }
}

// 合并到配置定义
foreach ($configGroups as $groupKey => &$group) {
    foreach ($group['configs'] as $configKey => &$config) {
        if (isset($pageSettings[$configKey])) {
            $config['value'] = $pageSettings[$configKey];
        }
    }
}
```

#### autoSave()

```php
if ($locale) {
    // 按语言保存
    $currentSettings[$locale] = array_merge(
        $currentSettings[$locale] ?? [],
        $styleConfig
    );
} else {
    // 通用配置
    $currentSettings = array_merge($currentSettings, $styleConfig);
}
```

### 前端关键代码

#### 加载配置

```javascript
let url = baseUrl + '?style_code=' + styleCode;
if (visualPageId) {
    url += '&page_id=' + visualPageId;
}
if (currentVisualLanguage) {
    url += '&locale=' + currentVisualLanguage;
}
```

#### 渲染配置

```javascript
const currentValue = config.value !== undefined 
    ? config.value 
    : (config.default || '');
```

#### 保存配置

```javascript
const requestData = {
    page_id: visualPageId,
    style_config: config,
    locale: currentVisualLanguage || defaultLanguage
};
```

---

## 🚀 测试步骤

### 1. 基础测试

1. 编辑页面，打开可视化配置
2. 查看语言切换器是否显示
3. 查看控制台，确认当前语言
4. 修改配置，查看是否自动保存
5. 刷新页面，确认配置已保存

### 2. 多语言测试

1. 选择默认语言（如中文）
2. 修改 Logo 位置为"居中"
3. 切换到另一种语言（如英文）
4. 修改 Logo 位置为"左侧"
5. 切换回中文，确认显示"居中"
6. 切换到英文，确认显示"左侧"

### 3. 预览测试

1. 选择语言并修改配置
2. 点击"刷新"按钮
3. 确认预览页面使用当前语言的配置
4. 切换语言，确认预览自动更新

---

## 📝 数据库结构

### page 表

| 字段 | 类型 | 说明 |
|------|------|------|
| page_id | int | 页面ID |
| default_locale | varchar | 默认语言 |
| locales | text | 支持的语言（JSON数组） |
| style_setting | text | 样式配置（JSON） |

### style_setting 数据格式

```json
{
    "zh_Hans_CN": {
        "layout.logo_position": "center",
        "style.background_color": "#ffffff",
        "size.height": "80"
    },
    "en_US": {
        "layout.logo_position": "left",
        "style.background_color": "#f0f0f0",
        "size.height": "90"
    }
}
```

---

## ⚠️ 注意事项

1. **向后兼容**: 如果 `style_setting` 不是按语言分组的（旧数据），系统会将其视为通用配置
2. **默认值降级**: 如果某语言没有配置，会自动使用默认语言的配置
3. **实时同步**: 切换语言时会立即重新加载配置和预览
4. **防抖机制**: 自动保存有1秒延迟，避免频繁请求

---

## 🎉 总结

多语言配置系统已完整实现，支持：

✅ 按语言独立配置
✅ 配置优先级（语言配置 > 默认语言 > 模板默认）
✅ 实时切换和预览
✅ 自动保存
✅ 完整的调试日志

用户可以为每种语言设置不同的样式配置，实现真正的多语言定制化！

