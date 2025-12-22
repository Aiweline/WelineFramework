# Weline Geo定位模块

## 📚 文档导航

- [使用指南](./使用指南.md) - 详细的使用教程和实际应用场景
- [通道选择指南](./通道选择指南.md) - 各通道详细说明、官网链接和选择建议
- [API文档](#api文档) - 完整的API参考文档

## 概述

Weline Geo定位模块提供了完整的定位功能，包括浏览器原生定位API和IP地址定位。模块通过hook机制自动注入到前端布局中，支持按需加载，不阻塞页面渲染。

## 功能特性

- **浏览器定位**：使用navigator.geolocation API获取精确位置
- **IP定位**：通过IP地址获取位置信息（降级方案）
- **智能定位**：自动选择最佳定位方式（优先浏览器定位，失败则使用IP定位）
- **位置缓存**：减少重复请求，提升性能
- **位置监听**：支持实时监听位置变化
- **错误处理**：完善的错误处理和降级机制
- **兼容性**：支持HTTPS和HTTP环境

## 安装和配置

### 1. 模块注册

模块已通过`register.php`自动注册，无需手动配置。

### 2. 模块加载

模块通过hook机制自动注入到前端布局中，在`body-end` hook中声明模块：

```phtml
<!-- app/code/Weline/Geo/view/hooks/Weline_Theme--frontend--layouts--base--body-end.phtml -->
<script>
    if (typeof Weline !== 'undefined' && Weline.declare) {
        Weline.declare('geo');
    }
</script>
```

### 3. 模块配置

#### 3.1 定位通道选择指南

Geo定位模块支持多个免费和付费的定位服务通道，每个通道都有不同的特点和限制。以下是各通道的详细说明：

##### 1. ip-api.com（推荐，默认启用）

- **官网**: [http://ip-api.com](http://ip-api.com)
- **文档**: [http://ip-api.com/docs/api:json](http://ip-api.com/docs/api:json)
- **特点**:
  - ✅ 完全免费，无需注册和API Key
  - ✅ 稳定性高，响应速度快
  - ✅ 提供详细的地理位置信息
  - ⚠️ 速率限制：45请求/分钟（免费版）
  - ⚠️ 仅支持HTTP（不支持HTTPS）
- **适用场景**: 中小型应用，请求量不大的场景
- **优先级**: 1（最高）

##### 2. geojs.io（推荐，默认启用）

- **官网**: [https://www.geojs.io](https://www.geojs.io)
- **文档**: [https://www.geojs.io/docs/v1/endpoints/geo/](https://www.geojs.io/docs/v1/endpoints/geo/)
- **特点**:
  - ✅ 完全免费，无需注册和API Key
  - ✅ 无配额限制
  - ✅ 支持HTTPS
  - ✅ 响应速度快
  - ⚠️ 数据精度相对较低
- **适用场景**: 需要高可用性的应用，请求量较大的场景
- **优先级**: 2

##### 3. ipwhois.app（默认启用）

- **官网**: [https://ipwhois.app](https://ipwhois.app)
- **文档**: [https://ipwhois.io/documentation](https://ipwhois.io/documentation)
- **特点**:
  - ✅ 免费，无需API Key
  - ✅ 提供IP地址的详细信息
  - ⚠️ 有配额限制（具体限制需查看官网）
  - ⚠️ 响应速度中等
- **适用场景**: 作为备用通道，提供额外的fallback选项
- **优先级**: 3

##### 4. ipinfo.io（可选，需要API Key）

- **官网**: [https://ipinfo.io](https://ipinfo.io)
- **文档**: [https://ipinfo.io/developers](https://ipinfo.io/developers)
- **特点**:
  - ✅ 免费版：50,000请求/月
  - ✅ 稳定性高，数据准确
  - ✅ 支持HTTPS
  - ✅ 提供详细的IP信息
  - ⚠️ 需要注册账号获取API Key
  - ⚠️ 免费版有配额限制
- **适用场景**: 需要更高配额或更准确数据的应用
- **优先级**: 4
- **注册**: [https://ipinfo.io/signup](https://ipinfo.io/signup)

##### 5. ipapi.co（可选，需要API Key）

- **官网**: [https://ipapi.co](https://ipapi.co)
- **文档**: [https://ipapi.co/documentation/](https://ipapi.co/documentation/)
- **特点**:
  - ✅ 免费版：1,000请求/天
  - ✅ 稳定性高
  - ✅ 支持HTTPS
  - ✅ 提供详细的地理位置和网络信息
  - ⚠️ 需要注册账号获取API Key
  - ⚠️ 免费版配额较小
- **适用场景**: 低请求量的应用，或作为备用通道
- **优先级**: 5
- **注册**: [https://ipapi.co/signup/](https://ipapi.co/signup/)

#### 3.2 后端通道配置

在 `app/code/Weline/Geo/etc/env.php` 中配置各个定位通道：

```php
return [
    'router' => 'geo',
    'geo' => [
        'providers' => [
            // ip-api.com - 完全免费，无需API Key
            'ip-api.com' => [
                'enabled' => true,
                'priority' => 1,
                'timeout' => 5
            ],
            // geojs.io - 完全免费，无需API Key
            'geojs.io' => [
                'enabled' => true,
                'priority' => 2,
                'timeout' => 5
            ],
            // ipwhois.app - 免费，无需API Key
            'ipwhois.app' => [
                'enabled' => true,
                'priority' => 3,
                'timeout' => 5
            ],
            // ipinfo.io - 需要API Key（可选）
            'ipinfo.io' => [
                'enabled' => false,
                'priority' => 4,
                'api_key' => '',  // 配置API Key后启用
                'timeout' => 5
            ],
            // ipapi.co - 需要API Key（可选）
            'ipapi.co' => [
                'enabled' => false,
                'priority' => 5,
                'api_key' => '',  // 配置API Key后启用
                'timeout' => 5
            ]
        ],
        'timeout' => 5,  // 默认超时时间（秒）
        'retry' => 1     // 每个通道重试次数
    ]
];
```

**Fallback机制**：
- 系统会按优先级顺序尝试各个通道
- 如果某个通道失败，自动尝试下一个通道
- 如果所有通道都失败，返回错误信息

**选择建议**：
- **小型应用**: 使用默认的3个免费通道（ip-api.com, geojs.io, ipwhois.app）即可
- **中型应用**: 如果请求量较大，建议注册ipinfo.io获取更高配额
- **大型应用**: 考虑使用付费版本或自建定位服务

#### 3.2 前端配置（可选）

如果需要自定义IP定位API地址，可以在主题配置中设置：

```javascript
window.__WelineThemeConfig = {
    geo: {
        ipApiUrl: '/geo/rest/v1/frontend/location/ip'  // 自定义IP定位API地址
    }
};
```

## API文档

### 浏览器定位API

#### `WelineGeo.getCurrentPosition(options)`

获取当前位置（使用浏览器原生定位API）。

**参数：**
- `options` (Object, 可选) - 定位选项
  - `enableHighAccuracy` (boolean) - 是否启用高精度，默认：`false`
  - `timeout` (number) - 超时时间（毫秒），默认：`10000`
  - `maximumAge` (number) - 最大缓存时间（毫秒），默认：`60000`

**返回值：** Promise<Object> - 位置信息对象

**示例：**
```javascript
// 基本使用
const position = await WelineGeo.getCurrentPosition();

// 使用选项
const position = await WelineGeo.getCurrentPosition({
    enableHighAccuracy: true,
    timeout: 5000,
    maximumAge: 60000
});

console.log('纬度:', position.latitude);
console.log('经度:', position.longitude);
console.log('精度:', position.accuracy);
```

**位置对象结构：**
```javascript
{
    latitude: 39.9042,        // 纬度
    longitude: 116.4074,      // 经度
    accuracy: 65,             // 精度（米）
    altitude: null,            // 海拔（米）
    altitudeAccuracy: null,    // 海拔精度（米）
    heading: null,            // 方向（度）
    speed: null,              // 速度（米/秒）
    timestamp: 1234567890,     // 时间戳
    source: 'browser'         // 定位来源
}
```

#### `WelineGeo.watchPosition(callback, options)`

监听位置变化。

**参数：**
- `callback` (Function) - 位置变化回调函数
  - `position` (Object) - 位置信息对象
  - `error` (Error, 可选) - 错误对象
- `options` (Object, 可选) - 定位选项（同getCurrentPosition）

**返回值：** number - 监听器ID

**示例：**
```javascript
const watchId = WelineGeo.watchPosition((position, error) => {
    if (error) {
        console.error('定位错误:', error.message);
        return;
    }
    console.log('位置更新:', position);
}, {
    enableHighAccuracy: true
});

// 清除监听
WelineGeo.clearWatch(watchId);
```

#### `WelineGeo.clearWatch(watchId)`

清除位置监听。

**参数：**
- `watchId` (number) - 监听器ID

**示例：**
```javascript
const watchId = WelineGeo.watchPosition((position) => {
    console.log('位置:', position);
});

// 5秒后清除监听
setTimeout(() => {
    WelineGeo.clearWatch(watchId);
}, 5000);
```

### IP定位API

#### `WelineGeo.getLocationByIP()`

通过IP地址获取位置信息。

**返回值：** Promise<Object> - 位置信息对象

**示例：**
```javascript
try {
    const position = await WelineGeo.getLocationByIP();
    console.log('国家:', position.country);
    console.log('城市:', position.city);
    console.log('纬度:', position.latitude);
    console.log('经度:', position.longitude);
} catch (error) {
    console.error('IP定位失败:', error.message);
}
```

**位置对象结构：**
```javascript
{
    latitude: 39.9042,        // 纬度
    longitude: 116.4074,      // 经度
    accuracy: null,           // 精度（IP定位通常为null）
    country: '中国',          // 国家
    countryCode: 'CN',        // 国家代码
    region: '北京',           // 地区/省份
    city: '北京市',           // 城市
    timezone: 'Asia/Shanghai', // 时区
    timestamp: 1234567890,     // 时间戳
    source: 'ip'              // 定位来源
}
```

### 智能定位API

#### `WelineGeo.getLocation(options)`

智能定位，自动选择最佳定位方式。优先使用浏览器定位，失败则自动降级到IP定位。

**参数：**
- `options` (Object, 可选) - 定位选项（同getCurrentPosition）

**返回值：** Promise<Object> - 位置信息对象

**示例：**
```javascript
try {
    const position = await WelineGeo.getLocation({
        enableHighAccuracy: true,
        timeout: 5000
    });
    console.log('定位成功:', position);
    console.log('定位来源:', position.source); // 'browser' 或 'ip'
} catch (error) {
    console.error('定位失败:', error.message);
}
```

### 工具方法

#### `WelineGeo.clearCache()`

清除所有位置缓存。

**示例：**
```javascript
WelineGeo.clearCache();
```

#### `WelineGeo.setCacheDuration(duration)`

设置缓存时长。

**参数：**
- `duration` (number) - 缓存时长（毫秒），默认：`300000`（5分钟）

**示例：**
```javascript
// 设置缓存时长为10分钟
WelineGeo.setCacheDuration(10 * 60 * 1000);
```

#### `WelineGeo.isGeolocationSupported()`

检查浏览器是否支持Geolocation API。

**返回值：** boolean

**示例：**
```javascript
if (WelineGeo.isGeolocationSupported()) {
    console.log('浏览器支持定位功能');
} else {
    console.log('浏览器不支持定位功能，将使用IP定位');
}
```

## 使用示例

### 示例1：获取当前位置

```javascript
// 使用浏览器定位
try {
    const position = await WelineGeo.getCurrentPosition({
        enableHighAccuracy: true,
        timeout: 5000
    });
    console.log('当前位置:', position.latitude, position.longitude);
} catch (error) {
    console.error('定位失败:', error.message);
    // 降级到IP定位
    try {
        const ipPosition = await WelineGeo.getLocationByIP();
        console.log('IP定位:', ipPosition.city);
    } catch (ipError) {
        console.error('IP定位也失败:', ipError.message);
    }
}
```

### 示例2：智能定位（推荐）

```javascript
// 自动选择最佳定位方式
try {
    const position = await WelineGeo.getLocation({
        enableHighAccuracy: true,
        timeout: 5000
    });
    
    if (position.source === 'browser') {
        console.log('使用浏览器定位，精度:', position.accuracy, '米');
    } else {
        console.log('使用IP定位，城市:', position.city);
    }
} catch (error) {
    console.error('所有定位方式都失败:', error.message);
}
```

### 示例3：监听位置变化

```javascript
// 监听位置变化（适用于导航等场景）
const watchId = WelineGeo.watchPosition((position, error) => {
    if (error) {
        console.error('定位错误:', error.message);
        return;
    }
    
    console.log('位置更新:');
    console.log('  纬度:', position.latitude);
    console.log('  经度:', position.longitude);
    console.log('  精度:', position.accuracy, '米');
    
    // 更新地图标记
    updateMapMarker(position);
}, {
    enableHighAccuracy: true,
    maximumAge: 0  // 不使用缓存，每次都获取最新位置
});

// 页面卸载时清除监听
window.addEventListener('beforeunload', () => {
    WelineGeo.clearWatch(watchId);
});
```

### 示例4：在表单中自动填充地址

```javascript
// 页面加载时自动获取位置并填充表单
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const position = await WelineGeo.getLocation();
        
        // 根据位置信息填充表单
        if (position.city) {
            document.getElementById('city').value = position.city;
        }
        if (position.region) {
            document.getElementById('region').value = position.region;
        }
        if (position.country) {
            document.getElementById('country').value = position.country;
        }
    } catch (error) {
        console.warn('无法自动填充地址:', error.message);
    }
});
```

## 错误处理

模块提供了完善的错误处理机制：

```javascript
try {
    const position = await WelineGeo.getCurrentPosition();
} catch (error) {
    switch (error.message) {
        case '用户拒绝了定位权限':
            // 提示用户允许定位权限
            alert('请允许浏览器定位权限以使用此功能');
            break;
        case '位置信息不可用':
            // 降级到IP定位
            const ipPosition = await WelineGeo.getLocationByIP();
            break;
        case '定位请求超时':
            // 增加超时时间重试
            const position = await WelineGeo.getCurrentPosition({
                timeout: 30000
            });
            break;
        default:
            console.error('定位失败:', error.message);
    }
}
```

## 缓存机制

模块内置了位置缓存机制，默认缓存时长为5分钟：

- **浏览器定位缓存**：5分钟
- **IP定位缓存**：5分钟

缓存可以减少重复请求，提升性能。如果需要强制获取最新位置，可以：

```javascript
// 清除缓存后重新获取
WelineGeo.clearCache();
const position = await WelineGeo.getCurrentPosition();
```

或者设置缓存时长为0：

```javascript
WelineGeo.setCacheDuration(0);
```

## 浏览器兼容性

- **Geolocation API**：支持所有现代浏览器（Chrome, Firefox, Safari, Edge）
- **HTTPS要求**：某些浏览器在HTTP环境下可能限制定位功能
- **降级方案**：如果浏览器不支持或定位失败，自动使用IP定位

## 注意事项

1. **权限请求**：浏览器定位需要用户授权，首次使用时会弹出权限请求
2. **HTTPS要求**：某些浏览器在HTTP环境下可能限制定位功能，建议使用HTTPS
3. **隐私保护**：定位信息涉及用户隐私，请妥善处理和使用
4. **性能考虑**：频繁获取位置可能影响性能，建议使用缓存机制
5. **IP定位精度**：IP定位精度较低，通常只能定位到城市级别

## 版本信息

- **当前版本**：1.0.0
- **最后更新**：2024年

## 技术支持

如有问题或建议，请联系：
- 邮箱：aiweline@qq.com
- 网址：aiweline.com
- 论坛：https://bbs.aiweline.com

