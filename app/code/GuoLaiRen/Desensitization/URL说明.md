# 模块URL访问说明

## URL结构

根据您提供的URL格式：
```
http://127.0.0.1:9981/LfuTIVLAj0JkmmiFHq0GI2lkkHc189CE/CNY/zh_Hans_CN/guolairen_desensitization/backend/rule
```

**URL各部分解析**：
- `127.0.0.1:9981` - 服务器地址
- `LfuTIVLAj0JkmmiFHq0GI2lkkHc189CE` - 管理员路由别名（从config文件中读取）
- `CNY` - 货币前缀
- `zh_Hans_CN` - 语言前缀
- `guolairen_desensitization` - 模块后台路由别名（在env.php中配置）
- `backend` - 后台区域标识
- `rule` - 控制器名称（对应Controller/Backend/Rule.php）

## 可访问的页面列表

### 1. 脱敏规则管理
**URL**: `/guolairen_desensitization/backend/rule`
**完整URL示例**: `http://127.0.0.1:9981/LfuTIVLAj0JkmmiFHq0GI2lkkHc189CE/CNY/zh_Hans_CN/guolairen_desensitization/backend/rule`
**功能**: 管理脱敏规则，支持增删改查、启用/禁用、规则测试

### 2. 脱敏记录
**URL**: `/guolairen_desensitization/backend/log`
**完整URL示例**: `http://127.0.0.1:9981/LfuTIVLAj0JkmmiFHq0GI2lkkHc189CE/CNY/zh_Hans_CN/guolairen_desensitization/backend/log`
**功能**: 查看脱敏操作日志

### 3. 敏感检测
**URL**: `/guolairen_desensitization/backend/detect`
**完整URL示例**: `http://127.0.0.1:9981/LfuTIVLAj0JkmmiFHq0GI2lkkHc189CE/CNY/zh_Hans_CN/guolairen_desensitization/backend/detect`
**功能**: 检测内容中的敏感信息

### 4. AI润色
**URL**: `/guolairen_desensitization/backend/rewrite`
**完整URL示例**: `http://127.0.0.1:9981/LfuTIVLAj0JkmmiFHq0GI2lkkHc189CE/CNY/zh_Hans_CN/guolairen_desensitization/backend/rewrite`
**功能**: 使用AI对脱敏后的内容进行润色

### 5. 脱敏测试
**URL**: `/guolairen_desensitization/backend/test`
**完整URL示例**: `http://127.0.0.1:9981/LfuTIVLAj0JkmmiFHq0GI2lkkHc189CE/CNY/zh_Hans_CN/guolairen_desensitization/backend/test`
**功能**: 测试脱敏功能，支持多种脱敏方法

### 6. 模块配置
**URL**: `/guolairen_desensitization/backend/config`
**完整URL示例**: `http://127.0.0.1:9981/LfuTIVLAj0JkmmiFHq0GI2lkkHc189CE/CNY/zh_Hans_CN/guolairen_desensitization/backend/config`
**功能**: 配置适配器参数

## 菜单访问

也可以通过后台菜单访问：
1. 登录后台
2. 点击左侧菜单"数据脱敏"
3. 选择对应的子菜单项

## 常见问题

### Q: 页面显示404错误
**A**: 检查以下几点：
1. 模块是否已启用
2. 路由别名是否正确配置在 `etc/env.php` 中
3. 运行 `php bin/w setup:upgrade` 重新注册路由
4. 清除缓存

### Q: 页面显示"模板文件不存在"
**A**: 
1. 确认模板文件存在于 `view/templates/Backend/` 目录下
2. 检查文件权限

### Q: 页面显示数据库错误
**A**:
1. 确认数据库表已创建
2. 运行 `php bin/w setup:upgrade`
3. 检查数据库连接配置

## 配置说明

### 路由别名配置

在 `app/code/GuoLaiRen/Desensitization/etc/env.php` 中：

```php
return [
    // 模块路由别名配置
    'router' => [
        'alias' => 'desensitization',
        'backend_alias' => 'guolairen_desensitization',  // 这个就是URL中的路由别名
    ],
    
    // ...
];
```

如果需要修改路由别名：
1. 修改 `backend_alias` 的值
2. 运行 `php bin/w setup:upgrade`
3. 使用新的URL访问

## 测试建议

建议按以下顺序测试各个页面：

1. ✅ **脱敏规则管理** - 基础功能，添加几条测试规则
2. ✅ **脱敏测试** - 使用测试页面验证功能
3. ✅ **敏感检测** - 测试检测功能
4. ✅ **AI润色** - 测试AI功能（需要配置AI模型）
5. ✅ **脱敏记录** - 查看操作日志
6. ✅ **模块配置** - 配置适配器参数

如果遇到任何问题，请提供具体的错误信息。
