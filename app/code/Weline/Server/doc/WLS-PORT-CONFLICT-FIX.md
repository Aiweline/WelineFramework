# WLS Master 启动失败问题修复报告

**日期**: 2026-04-02  
**问题**: Master 进程启动后立即退出，导致整个 WLS 服务无法启动  
**状态**: ✅ 已解决

---

## 问题现象

1. 执行 `php bin/w server:start` 后，Master 进程启动但立即退出
2. 日志显示：
   ```
   [Master@default] Master PID 53220 已注册到索引
   [Master@default] Master PID 索引已移除
   ```
3. 没有任何子进程（Worker、Dispatcher）启动
4. 服务器状态显示所有进程都已停止

---

## 根本原因

**IPC 控制端口冲突**

Master 进程在启动时需要绑定一个 IPC 控制端口用于进程间通信。端口号计算公式：
```
control_port = 20000 + main_port + project_offset
```

对于本项目：
- main_port = 8443
- project_offset = 6452
- 计算得到：control_port = 34895

问题：
1. 之前的 Master 进程异常退出时，端口 34895 没有正确释放
2. Windows 系统的 TCP 连接进入 CLOSE_WAIT/FIN_WAIT_2 状态，端口仍被占用
3. 新的 Master 尝试绑定端口 34895 时失败，抛出异常：
   ```
   IPC control port 34895 is unavailable. 
   Please free it or set a fixed server.control_port.
   ```
4. 异常发生在 `bootstrapControlPlane()` 阶段，早于 `startAll()`
5. Master 在 finally 块中清理并退出

---

## 诊断过程

### 1. 添加异常捕获

修改 `app/code/Weline/Server/Service/MasterProcess.php`：

```php
public function run(): void
{
    try {
        // ... 启动逻辑
    } catch (\Throwable $e) {
        $this->log(__('Master 启动失败: %{1}', [$e->getMessage()]));
        WlsLogger::error_('[Master] 启动异常: ' . $e->getMessage(), ['exception' => $e]);
        throw $e;
    } finally {
        $this->unregisterMasterPid();
    }
}
```

### 2. 查看错误日志

```bash
tail var/log/wls/default/error-2026-04-02.log
```

发现关键错误：
```
[Master@default] [ERROR] [Master] 启动异常: IPC control port 34895 is unavailable.
```

### 3. 检查端口占用

```bash
netstat -ano | grep 34895
```

发现端口被多个僵尸连接占用（CLOSE_WAIT、FIN_WAIT_2 状态）。

---

## 解决方案

在 `app/etc/env.php` 中添加固定的控制端口配置：

```php
'server' => [
    'control_port' => 35000,
],
```

**优点**：
1. 避免端口冲突（使用固定端口而非动态计算）
2. 端口号可控，便于防火墙配置
3. 多项目部署时可以明确分配端口

**配置说明**：
- 端口范围：建议使用 30000-40000 之间的端口
- 多实例部署：每个实例需要配置不同的控制端口
- 端口检查：启动前会自动检查端口是否可用

---

## 修复验证

### 启动测试

```bash
php bin/w server:start
```

输出：
```
✅ Weline Server 启动完成！
```

### 状态检查

```bash
php bin/w server:status
```

结果：
```
● Master (PID: 53180) 运行中
├─ HTTP Worker #1 (端口：24897) ● 运行中
├─ HTTP Worker #2 (端口：24898) ● 运行中
└─ Dispatcher #1 (端口：8443) ● 运行中

状态：全部运行中 (3/3)
```

### 功能测试

```bash
curl -k https://p11005ce4.weline.local:8443/ -I
```

返回：
```
HTTP/1.1 200 OK
Content-Type: text/html; charset=utf-8
```

---

## 相关修复

在解决此问题的过程中，还修复了以下问题：

### 1. Worker 预热逻辑错误

**问题**：Worker 自己预热自己，在延迟 SSL 模式下导致握手失败。

**修复**：
- 移除 Worker 自己预热自己的逻辑
- 改由 Dispatcher 在收到 ADD_WORKER 消息后主动预热 Worker
- 文件：`app/code/Weline/Server/bin/worker_ssl.php`

### 2. Dispatcher 预热实现

**问题**：Dispatcher 盲目信任 Master 的 ADD_WORKER 消息，没有验证连通性。

**修复**：
- 在 `PassthroughCore::addWorkerPort()` 中添加 `warmupWorker()` 调用
- Dispatcher 主动向 Worker 发送测试请求
- 只有预热成功的 Worker 才被加入负载池
- 文件：`app/code/Weline/Server/Dispatcher/PassthroughCore.php`

### 3. IPC 消息类型错误

**问题**：直接使用 `json_encode()` 而非 `ControlMessage::encode()`。

**修复**：统一使用 `ControlMessage::encode()` 编码 IPC 消息。

### 4. 连接池资源泄漏

**问题**：连接失败时没有清理资源。

**修复**：在连接失败分支中添加 `$conn->close()`。

---

## 最佳实践建议

### 1. 配置固定控制端口

在生产环境中，建议在 `app/etc/env.php` 中明确配置控制端口：

```php
'server' => [
    'control_port' => 35000,  // 根据实际情况调整
],
```

### 2. 多实例部署

如果需要在同一台机器上运行多个 WLS 实例：

```php
// 实例 1
'server' => [
    'control_port' => 35000,
],

// 实例 2
'server' => [
    'control_port' => 35001,
],
```

### 3. 端口规划

建议的端口分配方案：
- 主端口（HTTPS）：8443, 9443, 10443...
- Worker 端口：24897-24898, 25897-25898...
- 控制端口：35000, 35001, 35002...
- Session Server：26422
- Memory Service：26423

### 4. 故障排查

如果遇到启动失败：

1. 查看错误日志：
   ```bash
   tail -100 var/log/wls/default/error-$(date +%Y-%m-%d).log
   ```

2. 检查端口占用：
   ```bash
   netstat -ano | grep <port>
   ```

3. 查看进程状态：
   ```bash
   php bin/w server:status --all
   ```

4. 清理残留进程：
   ```bash
   php bin/w server:stop --all
   ```

---

## 总结

通过配置固定的 IPC 控制端口，成功解决了 Master 进程启动失败的问题。同时修复了 Worker 预热逻辑和 Dispatcher 连通性验证等相关问题，使整个 WLS 系统能够稳定运行。

**关键要点**：
- IPC 控制端口冲突是 Master 启动失败的根本原因
- 配置固定端口可以避免动态计算导致的冲突
- 预热逻辑应该由 Dispatcher 执行，而非 Worker 自己预热自己
- 完善的异常捕获和日志记录对于问题诊断至关重要
