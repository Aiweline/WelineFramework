# 常见错误速查表

快速查找常见错误的解决方案。

## 事件系统

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Argument could not be passed by reference` | dispatch 直接传数组字面量 | 先存入变量再传递 |
| 观察者未执行 | 事件未被触发 | 检查 dispatch 调用是否存在 |
| 事件数据为 null | 数据格式错误 | 使用 `['data' => [...]]` 包装 |

## PHP 类型

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Cannot pass by reference` | 引用参数传了字面量 | 使用变量 |
| `Type error` | 参数类型不匹配 | 检查类型声明 |
| `pagination(): Argument #1 ($page) must be of type int, string given` | GET/POST 参数为 string | 传参前用 `(int) $this->request->getParam('page', 1)` 等 |
| `Undefined method` | 方法不存在 | 检查类名和方法名 |
| `Undefined property` | 属性未声明或未初始化 | 检查属性声明和构造函数赋值 |
| `htmlspecialchars(): ... array given` | 配置值为数组但直接进入 `htmlspecialchars()` | 先做类型归一化，非 string/number 回退默认值 |
| `Value of type null is not callable` | 模板调用未注入的 `$getConfig` 等闭包 | 在渲染上下文注入闭包，或改用 `$component_config` |

## 数据库

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Unknown column` | 字段名错误 | 使用模型常量 |
| `Integrity constraint` | 外键/唯一约束冲突 | 检查数据完整性 |
| `column "count(*)" does not exist` | AbstractCompiler 将聚合函数当标识符加引号 | `quoteFieldExpression()` 需检测函数调用模式 |
| 删除提示成功但数据还在 | 只调用 `delete()` 未调用 `fetch()` | **必须**使用 `delete()->fetch()` |
| 删除/更新无效 | 准备了 SQL 但未执行 | 所有写操作后必须调用 `fetch()` |
| `Allowed memory size ... exhausted` | 全量扫描/清理一次性加载过多记录 | 使用分页清理与释放临时数组；必要时提高内存限制 |
| `groupBy()` 报错 | ORM 不支持 `groupBy()` 或链路中断 | 改用 `group('a, b')` |
| `HAVING must be type boolean` | having 需要完整布尔表达式 | 使用 `having('COUNT(*) > 1')` |
| 创建后 ID 为 0 | 保存未返回 ID | 保存后校验 `getId()`，必要时再查询一次 |

## ORM 操作规范 ⚠️ 重要

| 操作 | ✅ 正确写法 | ❌ 错误写法 |
|------|-----------|-----------|
| **删除** | `->where()->delete()->fetch()` | `->where()->delete()` (不执行) |
| **查询** | `->where()->select()->fetch()` | `->where()->fetch()` (跳过select) |
| **更新** | `->where()->update()->fetch()` | `->where()->update()` (不执行) |
| **插入** | `->setData()->save()` 或 `->insert()->fetch()` | `->insert()` (不执行) |

**核心规则**: Weline ORM 的 `delete()`, `update()`, `insert()` 只是准备 SQL，**必须调用 `fetch()` 才能执行**！

## 依赖注入

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Undefined property: $modelName` | 模型未在构造函数注入 | 添加构造函数参数和属性赋值 |
| `Call to member function on null` | 使用了未注入的依赖 | 检查构造函数是否包含该依赖 |
| 属性类型不匹配 | 注入类型与声明不符 | 确保参数类型与属性声明一致 |

## 国际化 (i18n) ⚠️ 重要

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| 翻译中显示 `%1` 原文 | 使用了 `%1` 格式（无花括号） | **必须使用 `%{1}` 格式** |
| 占位符未替换 | 占位符格式错误 | 使用 `%{1}`, `%{name}` 或 `%{}` |
| 翻译参数丢失 | 参数数量不匹配 | 确保参数数量与占位符数量一致 |

### i18n 占位符格式对照表 ⚠️ 极易出错

| ❌ 错误格式 | ✅ 正确格式 | 说明 |
|------------|-----------|------|
| `%1`, `%2`, `%3` | `%{1}`, `%{2}`, `%{3}` | 数字占位符**必须带花括号** |
| `__('错误：%1', $msg)` | `__('错误：%{1}', $msg)` | 单参数用 `%{1}` |
| `__('第 %1/%2 页', [$p, $t])` | `__('第 %{1}/%{2} 页', [$p, $t])` | 多参数用 `%{1}`, `%{2}` |

**推荐：使用命名占位符更清晰**
```php
// 最佳实践：命名占位符
__('用户 %{name} 有 %{count} 条消息', ['name' => $name, 'count' => $count])
```

**注意**: 代码库中存在大量历史遗留的 `%1` 格式代码，**不要参考这些错误示例**！

## 框架约定

| 场景 | 正确做法 |
|------|----------|
| 触发事件 | `$data = [...]; dispatch('name', $data);` |
| 获取事件数据 | `$event->getData('data')` |
| Hook 依赖事件 | 确保事件在 Hook 渲染前触发 |
| **模块升级** | 修改 Model 的 `upgrade()` 后必须更新 `register.php` 版本号 |
| **依赖注入** | 控制器/服务使用的模型必须在构造函数注入 |
| **i18n 占位符** | 使用 `%{1}` 或 `%{name}`，**绝对不要用 `%1`** |
| 主题安装报“父主题不存在” | 子主题 `theme register` 先于父主题安装，且未按 `parent` 做依赖排序 | 在 `Weline\Theme\Register\Installer` 中按 `parent` 拓扑排序后安装，父主题先装 |
| `@static` 在 Windows DEV 下输出裸 `/statics/...` | 路径前缀匹配大小写敏感，盘符大小写不一致导致匹配失败 | 路径比较在 Windows 下使用大小写不敏感匹配（`getUrlPath()`） |
| 控制器声明 `: string` 但 `redirect()` 触发 `string|null` 告警 | 静态分析将 `redirect()` 推断为可空返回 | 在 `return` 处显式 `(string)$this->redirect(...)`，确保签名一致 |

## 进程管理

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Call to undefined function posix_killpg()`（macOS） | PHP 构建未提供 `posix_killpg`，代码直接调用导致 Fatal | 用 `posix_kill(-$pgid, $signal)` 向进程组发信号，失败再降级 `posix_kill($pid, $signal)` |
| `Expected type 'Socket'. Found 'resource'.`（Intelephense） | socket 变量在 PHP 版本/扩展桩下被推断为 resource | 统一用兼容 ID 方法（object→`spl_object_id`，resource→`get_resource_id`），并避免直接依赖单一 socket 类型假设 |
| `server:start` 报“端口 xxx 被占用”（Mac/Linux 直连多 Worker） | 直连 `SO_REUSEPORT` 路径错误地按连续端口范围检查，或 Worker 端口段含非框架占用未自动跳过 | 直连复用模式只检查主端口；非框架占用时自动切换到下一个可用端口/端口段（主端口、Worker 段、HTTP Redirect） |
| `Socket 创建失败 ... Permission denied`（Mac/Linux，Worker 端口 443/444 等） | 1）直连模式端口语义错误导致 Worker 误绑定递增端口；2）非 root 绑定 `<1024` 端口未提前进行 sudo 引导 | 1）直连模式统一使用主端口 + `SO_REUSEPORT`；2）`server:start` 入口检测特权端口并自动 `sudo` 重启（触发密码输入） |
| HTTPS 实例访问 `http://host:port/...` 不跳转（Windows Dispatcher） | Dispatcher 仅透传 TCP，未在入口识别明文 HTTP 并执行重定向 | 在 Dispatcher 入口先识别协议：HTTPS 模式 + 明文 HTTP 时直接返回同端口 `301 Location: https://host:port/...` |
| `Call to undefined function stream_socket_recv()`（Worker SSL） | 在 `worker_ssl.php` 里误用不存在的 stream API | 改为 `stream_socket_recvfrom($conn, ..., STREAM_PEEK)`；修改后重启服务并用 HTTP/HTTPS 各回归一次 |
| WLS 子进程不断累积为孤儿进程 | 1）子进程具备复活 Master 能力导致控制面分散；2）Windows 非阻塞瞬时 PID 不可靠；3）缺少 `epoch + launch_id` 代际隔离 | 收口为 Master 单主控；禁用子进程复活；IPC 增加 `epoch + launch_id` 校验；引入 reconcile + orphan sweeper，旧代际进程统一回收 |
| WLS 启动后频繁 `register_timeout` + 整组重启风暴 | 1）周期 orphan sweeper 在主循环执行重型 kill 扫描，阻塞 IPC poll；2）`register_timeout` 配置过短（低于启动宽限）导致误判 | 周期扫尾默认改为轻量（仅 stale pid 清理）；重型按前缀 kill 仅在 full restart 后执行；`register_timeout` 至少与 `startupGracePeriod` 一致 |

## 模块升级

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `upgrade()` 方法不执行 | `register.php` 版本号未更新 | 递增版本号（如 1.0.5 → 1.0.6） |
| 数据库字段未添加 | 模块版本未变化 | 更新版本号后运行 `php bin/w s:up` |
| 升级逻辑被跳过 | 框架比较版本号决定是否升级 | 每次有 upgrade 改动必须更新版本 |
| `Call to undefined method columnExist()` | 方法名错误 | 使用 `$setup->hasField()` 检查字段 |
| `addColumn()` 直接在 setup 调用失败 | 需要先获取 alterTable | 使用 `$setup->alterTable()->addColumn()` |
| `Argument #3 ($type) must be of type string` | alterTable 和 createTable 签名不同 | alterTable: `(name, after, type, len, opt, comment)` |

## alterTable vs createTable 区别

**createTable 签名**（安装时）：
```php
->addColumn(name, type_constant, length, options, comment)
```

**alterTable 签名**（升级时）：
```php
->addColumn(name, after_column, type_string, length, options, comment)
->alter()  // 执行修改
```

---

## 开发技巧

### CLI 命令查找

| 技巧 | 说明 | 示例 |
|------|------|------|
| **命令缩写** | `php bin/w` 支持命令缩写匹配 | `php bin/w c:f` = `cache:flush` |
| **模糊搜索** | 输入部分命令名会列出匹配项 | `php bin/w static` 列出所有 static 相关命令 |
| **帮助信息** | 单独运行列出所有命令 | `php bin/w` |

### 常用命令缩写

| 缩写 | 完整命令 | 用途 |
|------|----------|------|
| `c:f` | `cache:flush` | 清理缓存 |
| `s:up` | `setup:upgrade` / `system:upgrade` | 系统升级 |
| `s:c` | `static:compile` | 编译静态资源 |
| `http:req` | `http:request` | HTTP 请求测试 |

### ORM save() 查询状态叠加导致 NOT NULL / 主键冲突

| 症状 | 原因 | 修复 |
|------|------|------|
| `SQLSTATE[23502]: Not null violation` on save() | `checkUpdateOrInsert()` 未 `clearQuery()`，前序操作（load/find）的 WHERE 叠加导致查不到已有记录，误走 INSERT 分支 | 框架已修复：`AbstractModel::checkUpdateOrInsert()` 三处操作前均加 `clearQuery()` |
| `SQLSTATE[23505]: Unique violation` on save() | 同理，查不到已有记录导致 INSERT 冲突 | 同上 |
| `clone + load() + save()` 偶发插入而非更新 | query 对象共享状态 | 同上；或业务层在 save() 前手动 `$model->getQuery()->clearQuery()` |

### WLS 状态泄漏

| 错误 | 原因 | 解决方案 |
|---|---|---|
| 页面标题显示上个请求的标题 | Template 单例 `_data` 跨请求残留 | `Template::resetInstance()` + 注册到 StateManager |
| 模板路径指向上个模块 | Template 单例 `view_dir` 等残留 | 同上 |
| backend/frontend 区域判断错误 | `State::$is_backend` 残留 | `registerStaticReset(State::class, 'is_backend', false)` + 清除 ObjectManager 实例 |
| 单例类 `??` 操作符不覆盖已有值 | WLS 下单例跨请求保留，`??` 跳过赋值 | 重置单例实例，或改用无条件赋值 |

### HTTP 请求测试（查看页面源码）

当需要检查页面 HTML 内容时，使用 `http:req` 命令：

```bash
# 基本用法：请求页面
php bin/w http:req "/path/to/page"

# 搜索页面中的特定内容
php bin/w http:req "/path/to/page" "filter=关键词"

# 搜索并显示上下 5 行上下文
php bin/w http:req "/path/to/page" "filter=关键词" -n=5

# 带参数的 URL
php bin/w http:req "/catalog/category?price=100-200" "filter=error"

# 测试后端页面（需登录）
php bin/w http:req "admin/dashboard" -b --login -u=admin -p=123456
```

**使用场景**：
- 检查页面是否包含预期的 HTML 元素
- 搜索页面中的调试信息、错误信息
- 验证模板渲染结果
- 测试 API 响应
