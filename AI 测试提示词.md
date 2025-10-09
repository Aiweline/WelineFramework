# AI 测试指导

## 测试原则
- 测试应该覆盖主要功能流程
- 测试应该验证用户界面的可用性
- 测试应该检查数据的一致性和完整性
- 测试应该验证错误处理机制

## 测试类型指导

### 功能测试
- 验证核心功能是否按预期工作
- 检查用户交互流程是否顺畅
- 确认数据处理逻辑正确

### 界面测试
- 验证页面元素是否正确显示
- 检查按钮和链接是否可点击
- 确认表单提交功能正常

### 数据测试
- 验证数据加载和显示
- 检查数据筛选和搜索功能
- 确认数据更新和保存操作

### 错误处理测试
- 测试异常情况下的系统行为
- 验证错误信息的显示
- 检查系统恢复能力

### HTTP请求测试
使用 `http:request` 命令可以快速测试路由和页面响应，验证系统的可访问性。该命令使用Guzzle HTTP客户端，支持自动登录、智能Cookie管理和并发测试。

#### 基本请求测试
```bash
# 测试前端首页是否可访问
php bin/w http:request /

# 测试后端页面（自动登录，自动添加admin密钥）
php bin/w http:request admin -b                    # 后台首页
php bin/w http:request admin/login -b              # 登录页（公共页面，无需登录）
php bin/w http:request ai/backend/model -b         # AI模型管理页面

# 测试API接口（自动登录，自动使用api_admin密钥）
php bin/w http:request rest -api
php bin/w http:request rest/v1/module/action -api

# 自定义登录凭据
php bin/w http:request admin/dashboard -b -u=myuser -p=mypass

# 测试特定路由
php bin/w http:request module/controller/action
```

**核心特性**：
- ✅ **自动登录**：使用 `-b` 或 `-api` 参数时自动登录，无需手动指定`--login`
- ✅ **智能Cookie管理**：自动保存和复用cookie（`var/http_request_cookies.txt`），过期自动重新登录
- ✅ **自动密钥添加**：`-b` 参数自动添加admin密钥，`-api` 参数自动添加api_admin密钥
- ✅ **并发测试**：支持多线程并发请求，用于压力测试
- ✅ **详细性能统计**：显示响应时间、吞吐量、成功率等指标

#### 响应内容验证和PHP错误检测
```bash
# 搜索响应中是否包含特定关键词
php bin/w http:request / --filter=welcome

# 显示匹配内容的上下文（默认3行，可用-n指定）
php bin/w http:request / --filter=title -n=5

# 搜索PHP错误（非常有用！）
php bin/w http:request ai/backend/model -b --filter=Warning     # 搜索Warning错误
php bin/w http:request admin/dashboard -b --filter=Fatal        # 搜索Fatal错误
php bin/w http:request admin -b --filter=Notice                 # 搜索Notice错误
php bin/w http:request admin -b --filter=Undefined              # 搜索Undefined变量错误

# 在后端页面中搜索关键词
php bin/w http:request admin/dashboard -b --filter=用户数
```

#### API接口测试
```bash
# 测试API GET接口（自动登录，自动添加api_admin密钥）
php bin/w http:request rest/v1/user/list -api

# 测试API POST接口
php bin/w http:request rest/v1/user/create -api -m=POST -d='{"name":"test","email":"test@example.com"}'

# 测试API PUT接口
php bin/w http:request rest/v1/user/update -api --method=PUT --data='{"id":1,"name":"updated"}'

# 添加自定义请求头
php bin/w http:request rest/v1/data -api -H="Accept: application/json" -H="Authorization: Bearer token123"
```

#### 并发压力测试
```bash
# 并发测试前端页面100次
php bin/w http:request / -C -t=100

# 并发测试后台页面50次（自动复用cookie）
php bin/w http:request admin -b -C -t=50

# 并发测试API接口
php bin/w http:request rest/v1/data -api -C -t=100
```

**并发测试输出示例**：
```
正在执行并发请求...
目标URL: http://127.0.0.1:9981/
并发次数: 100
进度: ████████████████████ 100% (100/100)

并发测试完成！
==================
总请求数: 100
成功数: 98
失败数: 2
总耗时: 5932.45ms
平均耗时: 59.32ms
吞吐量: 16.86 请求/秒

响应时间统计:
- 最快: 45.23ms
- 最慢: 892.15ms
- 平均: 143.56ms
- 中位数: 128.34ms

HTTP状态码分布:
- 200 OK: 98次 (98.00%)
- 500 Internal Server Error: 2次 (2.00%)
```

#### 测试建议
- **路由测试**：在添加新控制器后，使用 `http:request` 命令快速验证路由是否正确配置
- **响应验证**：使用 `--filter` 参数检查页面是否包含预期的内容
- **PHP错误检测**：使用 `--filter=Warning` 或 `--filter=Fatal` 快速定位PHP错误
- **错误排查**：查看响应状态码、响应时间和响应头，快速定位问题
- **API测试**：测试RESTful API的各种HTTP方法
- **并发测试**：使用 `-C -t=100` 进行压力测试，验证系统并发性能
- **Cookie管理**：命令自动管理cookie，无需手动处理，大幅简化测试流程

#### 常见测试场景
```bash
# 1. 验证新创建的控制器路由
php bin/w setup:upgrade --route        # 先更新路由
php bin/w http:request mymodule/mycontroller/index

# 2. 检查后台管理页面是否有PHP错误
php bin/w http:request admin/module/list -b --filter=Warning

# 3. 测试后台页面渲染和JS执行
php bin/w http:request ai/backend/model -b --filter=Fatal

# 4. 测试表单提交
php bin/w http:request admin/form/save -b -m=POST -d='{"field":"value"}'

# 5. 快速定位错误页面
php bin/w http:request problematic/page --filter=error -n=10

# 6. 验证多语言内容
php bin/w http:request / --filter=欢迎

# 7. 并发压力测试
php bin/w http:request / -C -t=100

# 8. 测试cookie过期自动重新登录
php bin/w http:request admin -b    # 第一次自动登录
# 等待cookie过期或删除cookie文件
php bin/w http:request admin -b    # 第二次自动检测过期并重新登录
```

#### PHP错误检测最佳实践
```bash
# 开发过程中定期检测PHP错误
php bin/w http:request admin -b --filter=Warning
php bin/w http:request admin -b --filter=Fatal
php bin/w http:request admin -b --filter=Undefined

# 检测特定页面的错误
php bin/w http:request ai/backend/model -b --filter=Warning -n=10

# 在部署前进行全面检测
php bin/w http:request admin -b --filter="Warning\|Fatal\|Notice"
```

## I18n国家管理功能测试要点

### 数据安装功能
- **"安装所有国家"**：将全球国家数据批量插入数据库
- **"强制安装"**：清空现有数据后重新安装
- 验证数据是否正确写入`countries`表和`countries_locale_name`表
- 检查默认状态：`IS_INSTALL = 1`，`IS_ACTIVE = 0`

### 数据筛选功能
- **"已激活"**：过滤`IS_INSTALL = 1 AND IS_ACTIVE = 1`的记录（已安装且已激活）
- **"已安装未激活"**：过滤`IS_INSTALL = 1 AND IS_ACTIVE = 0`的记录（已安装但未激活）
- **"已安装"**：过滤`IS_INSTALL = 1`的记录（所有已安装的国家，包括激活和未激活）
- **"全部"**：显示所有记录，无过滤条件

### 状态管理功能
- **激活国家**：更新`IS_ACTIVE`字段为1
- **取消激活**：更新`IS_ACTIVE`字段为0
- 验证状态变更是否正确保存到数据库

## 测试报告要求
- 记录测试过程中发现的问题
- 提供问题复现步骤
- 建议解决方案
- 评估问题严重程度