# 部件配置增强方案

## 1. ThemeData 部件专用函数

### 新增方法

```php
/**
 * 获取部件的meta_identify
 * @return string 例如：theme.frontend.widgets.Weline_Theme.footer-social
 */
public static function getWidgetIdentify(string $widgetModule, string $widgetCode, string $area = 'frontend'): string

/**
 * 获取部件单个参数值（支持多语言）
 * @param string $widgetModule 部件模块名
 * @param string $widgetCode 部件代码
 * @param string $paramName 参数名
 * @param string|null $locale 语言代码，null时使用当前语言
 * @param mixed $default 默认值
 * @return mixed
 */
public static function getWidgetParam(string $widgetModule, string $widgetCode, string $paramName, ?string $locale = null, $default = null)

/**
 * 设置部件单个参数值（支持多语言）
 * @param string $widgetModule 部件模块名
 * @param string $widgetCode 部件代码
 * @param string $paramName 参数名
 * @param mixed $value 参数值
 * @param string|null $locale 语言代码，null时使用当前语言
 * @return bool
 */
public static function setWidgetParam(string $widgetModule, string $widgetCode, string $paramName, $value, ?string $locale = null): bool

/**
 * 批量获取部件所有参数（支持多语言）
 * @param string $widgetModule 部件模块名
 * @param string $widgetCode 部件代码
 * @param string|null $locale 语言代码，null时使用当前语言
 * @return array 参数数组
 */
public static function getWidgetParams(string $widgetModule, string $widgetCode, ?string $locale = null): array

/**
 * 批量设置部件参数（支持多语言）
 * @param string $widgetModule 部件模块名
 * @param string $widgetCode 部件代码
 * @param array $params 参数数组
 * @param string|null $locale 语言代码，null时使用当前语言
 */
public static function setWidgetParams(string $widgetModule, string $widgetCode, array $params, ?string $locale = null): void

/**
 * 获取部件参数定义（不含值）
 */
public static function getWidgetParamDefinitions(string $widgetModule, string $widgetCode): array
```

### 实现策略

1. **identify规范**：`theme.{area}.widgets.{module}.{code}`
   - 例如：`theme.frontend.widgets.Weline_Theme.footer-social`

2. **缓存优化**：
   - 使用 ThemeData 的 `$performanceCache`
   - 一次性加载所有部件配置
   - 避免重复查询

3. **多语言处理**：
   - 可翻译字段：调用 `MetaTranslation`
   - 普通字段：调用 `MetaConfig`

## 2. UI 美化方案

### 配置面板结构

```html
<div class="widget-config-panel">
    <!-- 头部 -->
    <div class="config-header">
        <div class="config-widget-info">
            <i class="widget-icon"></i>
            <div class="widget-meta">
                <h4 class="widget-name"></h4>
                <p class="widget-desc"></p>
            </div>
        </div>
        <!-- 语言切换器 -->
        <div class="config-lang-switcher">
            <select class="form-select form-select-sm">
                <option value="">默认（全语言）</option>
                <option value="zh_Hans_CN">简体中文</option>
                <option value="en_US">English</option>
            </select>
        </div>
    </div>
    
    <!-- 配置表单 -->
    <form class="config-form">
        <!-- 分组1：基本信息 -->
        <div class="config-group">
            <h5 class="config-group-title">
                <i class="ri-information-line"></i>
                基本信息
            </h5>
            <div class="config-fields">
                <!-- 可翻译字段 -->
                <div class="config-field translatable-field">
                    <label class="config-label">
                        标题
                        <i class="ri-translate-2 text-info" title="此字段支持多语言"></i>
                    </label>
                    <input type="text" class="form-control" name="title" data-translatable="true">
                </div>
                
                <!-- 普通字段 -->
                <div class="config-field">
                    <label class="config-label">对齐方式</label>
                    <select class="form-select" name="alignment">
                        <option value="left">左对齐</option>
                        <option value="center">居中</option>
                        <option value="right">右对齐</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- 分组2：样式设置 -->
        <div class="config-group">
            <h5 class="config-group-title">
                <i class="ri-palette-line"></i>
                样式设置
            </h5>
            <div class="config-fields">
                <!-- ... -->
            </div>
        </div>
        
        <!-- 提交按钮 -->
        <div class="config-actions">
            <button type="submit" class="btn btn-primary">
                <i class="ri-save-line"></i> 保存配置
            </button>
            <button type="button" class="btn btn-outline-secondary btn-reset">
                <i class="ri-restart-line"></i> 重置
            </button>
        </div>
    </form>
</div>
```

### 样式特点

1. **分组展示**：按功能分组（基本信息、样式设置、链接配置等）
2. **图标标识**：
   - 可翻译字段显示 🌐 图标
   - 必填字段显示 * 标记
   - 分组使用对应图标
3. **现代UI**：
   - 卡片式设计
   - 柔和阴影和圆角
   - 悬停效果
   - 平滑过渡动画
4. **响应式**：适配不同屏幕尺寸

## 3. 多语言支持方案

### 前端实现

```javascript
// 语言切换器
const langSwitcher = document.getElementById('configLangSwitcher');
let currentLocale = null; // null表示默认（全语言）

langSwitcher.addEventListener('change', (e) => {
    currentLocale = e.target.value || null;
    reloadWidgetConfig(widgetElement, currentLocale);
});

// 加载配置时传递locale
async function loadWidgetConfig(widgetElement, locale = null) {
    const apiUrl = `${config.apiBase}/widget-config?layout_id=${layoutId}${locale ? '&locale=' + locale : ''}`;
    // ...
}

// 保存配置时传递locale
async function saveWidgetConfig(layoutId, configData, locale = null) {
    const apiUrl = `${config.apiBase}/save-widget-config`;
    const payload = {
        layout_id: layoutId,
        config: configData,
        locale: locale // 如果是null，保存为默认值；否则保存为指定语言的翻译
    };
    // ...
}
```

### 后端实现

```php
// ThemeEditor::getWidgetConfig()
public function getWidgetConfig()
{
    $layoutId = (int)$this->request->getParam('layout_id', 0);
    $locale = $this->request->getParam('locale', null); // null表示获取默认值
    
    // 获取部件信息
    $widgetLayout = $this->themeLayout->reset()->load($layoutId);
    $widgetModule = $widgetLayout->getData('widget_module');
    $widgetCode = $widgetLayout->getData('widget_code');
    
    // 使用ThemeData获取参数定义
    $params = ThemeData::getWidgetParamDefinitions($widgetModule, $widgetCode);
    
    // 使用ThemeData获取参数值（含多语言）
    $config = ThemeData::getWidgetParams($widgetModule, $widgetCode, $locale);
    
    return $this->fetchJson([
        'success' => true,
        'data' => [
            'layout_id' => $layoutId,
            'widget_module' => $widgetModule,
            'widget_code' => $widgetCode,
            'params' => $params,
            'config' => $config,
            'locale' => $locale,
        ],
    ]);
}

// ThemeEditor::postSaveWidgetConfig()
public function postSaveWidgetConfig()
{
    $layoutId = (int)$this->request->getParam('layout_id', 0);
    $configData = $this->request->getParam('config', []);
    $locale = $this->request->getParam('locale', null);
    
    // 获取部件信息
    $widgetLayout = $this->themeLayout->reset()->load($layoutId);
    $widgetModule = $widgetLayout->getData('widget_module');
    $widgetCode = $widgetLayout->getData('widget_code');
    
    // 使用ThemeData保存参数（含多语言）
    ThemeData::setWidgetParams($widgetModule, $widgetCode, $configData, $locale);
    
    // 更新layout表的config字段（仅在默认语言时）
    if ($locale === null) {
        $widgetLayout->setData('config', json_encode($configData));
        $widgetLayout->save();
    }
    
    return $this->fetchJson([
        'success' => true,
        'message' => __('配置已保存'),
    ]);
}
```

## 4. 数据流程

### 读取流程

1. **前端请求** → `/widget-config?layout_id=123&locale=zh_Hans_CN`
2. **后端处理**：
   - 从 `m_theme_layout` 获取部件基本信息
   - 调用 `ThemeData::getWidgetParamDefinitions()` 获取参数定义
   - 调用 `ThemeData::getWidgetParams()` 获取参数值（含多语言）
3. **ThemeData 内部**：
   - 遍历参数定义
   - 对于 `translatable=true` 的字段：
     - 调用 `getParamTranslation()` → `MetaTranslation::getTranslatedValueWithScope()`
     - 从 `i18n_dictionary` 表查询翻译值
   - 对于普通字段：
     - 调用 `MetaConfig::getConfig()` 查询配置值
4. **缓存优化**：
   - 优先从 `$performanceCache` 读取
   - 避免重复查询数据库

### 保存流程

1. **前端提交** → `/save-widget-config` + `{layout_id, config, locale}`
2. **后端处理**：
   - 获取部件信息
   - 调用 `ThemeData::setWidgetParams()` 保存参数（含多语言）
3. **ThemeData 内部**：
   - 获取参数定义
   - 对于 `translatable=true` 的字段：
     - 调用 `setParamTranslation()` → 写入 `i18n_dictionary`
   - 对于普通字段：
     - 调用 `MetaConfig::setConfig()` 写入 `m_meta_config`
   - 清除缓存
4. **layout表更新**：
   - 仅在 `locale=null`（默认语言）时更新 `m_theme_layout.config` 字段

## 5. 实现步骤

### Phase 1: ThemeData 增强（优先）
- [x] 添加 `getWidgetIdentify()`
- [ ] 添加 `getWidgetParam()`
- [ ] 添加 `setWidgetParam()`
- [ ] 添加 `getWidgetParams()`
- [ ] 添加 `setWidgetParams()`
- [ ] 添加 `getWidgetParamDefinitions()`

### Phase 2: 后端API（依赖Phase 1）
- [ ] 修改 `ThemeEditor::getWidgetConfig()` 支持locale
- [ ] 创建 `ThemeEditor::postSaveWidgetConfig()` 支持多语言保存

### Phase 3: 前端UI美化（可并行）
- [ ] 设计新的配置面板HTML/CSS
- [ ] 添加分组和折叠功能
- [ ] 添加多语言切换器
- [ ] 优化表单字段渲染（支持translatable标识）

### Phase 4: 前端逻辑（依赖Phase 2/3）
- [ ] 实现语言切换逻辑
- [ ] 修改配置加载逻辑（支持locale）
- [ ] 修改配置保存逻辑（支持locale）
- [ ] 添加可翻译字段的视觉标识

### Phase 5: 测试与验证
- [ ] 测试默认语言的读写
- [ ] 测试多语言切换
- [ ] 测试可翻译字段和普通字段
- [ ] 测试缓存机制

## 6. 注意事项

1. **identify规范**：必须使用 `theme.{area}.widgets.{module}.{code}` 格式
2. **缓存一致性**：保存后必须清除相关缓存
3. **默认值回退**：多语言查询时，如果翻译不存在，回退到默认值
4. **性能优化**：批量操作时使用 `getWidgetParams()` 而非多次调用 `getWidgetParam()`
5. **兼容性**：现有的配置保存在 `m_theme_layout.config`，需要保持兼容
