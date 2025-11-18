# 邮件发送系统更新日志

## 2025-10-24 - v2.1 优化更新

### ⚡ 性能优化

#### 1. SMTP连接超时设置
- ✅ **超时时间**: 设置为15秒
- ✅ **更快失败**: 避免长时间等待无响应的服务器
- ✅ **提升效率**: 快速识别并切换到可用账号

**技术细节**:
```php
$this->mailer->Timeout = 15;  // PHPMailer超时
$this->mailer->SMTPOptions['timeout'] = 15;  // Socket超时
```

---

## 2025-10-24 - 重大功能更新

### 🎉 新增功能

#### 1. 断点续发与智能去重
- ✅ **自动去重**: 已发送的邮件会被自动跳过，避免重复发送
- ✅ **断点续发**: 发送中断后自动从上次位置继续
- ✅ **单发和批发都支持**: 无论是单个收件人还是批量发送都会启用这些功能

#### 2. 失败重试机制（最多3次）
- ✅ **智能重试**: 失败的邮件会自动记录并重试
- ✅ **最大失败次数**: 每个邮箱最多失败3次
- ✅ **永久失败列表**: 失败超过3次的邮箱移到永久失败列表，不再重试
- ✅ **重试时机**: 
  - 断点续发时自动重试
  - 账号切换时自动重试

#### 3. 智能账号切换
- ✅ **自动切换**: 发送失败时自动切换到下一个SMTP账号
- ✅ **显示切换信息**: 切换时显示新账号信息
- ✅ **即时重试**: 切换账号后立即用新账号重试失败的邮件
- ✅ **负载均衡**: 自动在多个账号间轮换，避免单个账号过载

#### 4. 增强的显示信息
- ✅ **发送者信息**: 显示当前使用的SMTP账号
  ```
  📤 From: Marketing Team <marketing@company.com>
  ```
- ✅ **收件人信息**: 清晰显示收件人
  ```
  [1/100] 📧 To: Grant Hoerr <ghoerr@mqplp.com>
  ```
- ✅ **重试进度**: 显示当前第几次尝试
  ```
  📧 Retrying: user@example.com (attempt 2/3)
  ```
- ✅ **成功/失败状态**: 清晰标识发送结果
  ```
  ✓ Success with new account on attempt 2!
  ✗ Still failed, will retry again (next attempt: 3/3)
  ```

---

### 📁 新增文件

#### 核心功能文件
1. **`BreakpointManager.php`** - 断点和失败管理器
   - 管理断点续发
   - 跟踪失败邮件
   - 记录已发送邮件

#### 工具脚本
2. **`check_breakpoint.php`** - 断点诊断工具
   - 查看当前发送状态
   - 检查失败邮件
   - 显示统计信息

3. **`clear_history.php`** - 历史记录清理工具
   - 清除断点文件
   - 清除失败记录
   - 清除已发送记录

#### 文档
4. **`BREAKPOINT_GUIDE.md`** - 断点续发功能指南
5. **`AUTO_RETRY_GUIDE.md`** - 智能账号切换与自动重试指南
6. **`FAILURE_RETRY_POLICY.md`** - 失败重试策略说明
7. **`DISPLAY_FORMAT_EXAMPLE.md`** - 显示格式示例
8. **`CHANGELOG.md`** - 更新日志（本文件）

#### 数据文件
9. **`emails/sent_emails.json`** - 已发送邮件记录
10. **`emails/failed_emails.json`** - 失败邮件记录（待重试）
11. **`emails/permanent_failed_emails.json`** - 永久失败邮件记录
12. **`emails/breakpoint.json`** - 断点数据

---

### 🔧 配置变更

#### BreakpointManager 常量
```php
const MAX_RETRY_ATTEMPTS = 3;  // 允许最多3次失败（初始发送 + 2次重试）
```

#### Send 类新增属性
```php
private array $lastFailedBatch = [];  // 存储最后失败的批次用于重试
```

---

### 📊 新增命令行参数

1. **`--fresh`** - 强制重新发送模式
   ```bash
   php bin/w mail:send --to="recipients.xlsx" --subject="..." --fresh=1
   ```
   - 忽略已发送记录
   - 向所有收件人重新发送

2. **`--resume`** - 断点续发控制（默认启用）
   ```bash
   php bin/w mail:send --to="recipients.xlsx" --subject="..." --resume=0
   ```
   - `--resume=1`（默认）: 启用断点续发
   - `--resume=0`: 禁用断点续发，从头开始

3. **`--preview`** - 预览文本（批量发送不再必需）
   ```bash
   php bin/w mail:send --to="recipients.xlsx" --subject="..." --preview="..."
   ```
   - 如果不提供，会自动使用主题作为预览文本

---

### 🐛 Bug 修复

1. **修复未定义变量错误**
   - 修复了 `$delayMs` 未定义的错误
   - 位置: Send.php 第 543 行

2. **优化重试逻辑**
   - 确保失败超过3次的邮箱移到永久失败列表
   - 永久失败的邮箱不再参与自动重试

---

### 💡 使用示例

#### 基本发送（自动去重）
```bash
php bin/w mail:send --to="emails/recipients.xlsx" --subject="Newsletter"
```

#### 强制重新发送所有邮件
```bash
php bin/w mail:send --to="emails/recipients.xlsx" --subject="Newsletter" --fresh=1
```

#### 查看发送状态
```bash
php app/code/GuoLaiRen/Smtp/Console/Mail/check_breakpoint.php
```

#### 清理历史记录
```bash
php app/code/GuoLaiRen/Smtp/Console/Mail/clear_history.php
```

---

### 📈 性能改进

1. **减少重复发送**: 自动去重功能避免重复发送，节省资源
2. **智能重试**: 只重试可能成功的邮件，避免浪费时间
3. **负载均衡**: 多账号轮换，提高发送效率和成功率

---

### 🔐 安全性提升

1. **数据持久化**: 所有记录都保存在JSON文件中，不会丢失
2. **故障恢复**: 任何中断都可以从断点恢复，保证数据完整性
3. **限流保护**: 遵守SMTP限制，避免账号被封禁

---

### 📖 文档完善

提供了完整的文档：
- 功能使用指南
- 故障排查说明
- 最佳实践建议
- 实际案例演示

---

### 🎯 兼容性

- ✅ 向后兼容：现有命令仍然可以正常工作
- ✅ 可选功能：新功能默认启用，可以通过参数禁用
- ✅ 渐进增强：逐步添加新功能，不影响现有流程

---

### 🚀 未来计划

- [ ] Web界面管理工具
- [ ] 实时发送进度展示
- [ ] 邮件发送统计报表
- [ ] 自动邮箱验证功能
- [ ] 批量邮箱有效性检查

---

### 📞 支持

如需帮助：
1. 查看相关文档（见上方"新增文件"部分）
2. 运行诊断工具：`php app/code/GuoLaiRen/Smtp/Console/Mail/check_breakpoint.php`
3. 查看在线帮助：`php bin/w mail:send --help`

---

## 总结

本次更新极大地提升了邮件发送系统的可靠性、智能化程度和用户体验。通过断点续发、智能去重、失败重试和账号切换等功能，确保邮件发送过程更加稳定和高效。

**核心价值**：
- 📊 **可靠性**: 不会因为中断而丢失进度
- 🎯 **智能化**: 自动处理各种异常情况
- 💰 **经济性**: 避免重复发送，节省资源
- 🚀 **效率**: 多账号负载均衡，提升速度
- 🔍 **可追踪**: 完整的发送记录和统计

