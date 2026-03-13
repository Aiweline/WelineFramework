# SSL 证书完整存储与动态恢复计划

**状态**：🟢 已完成（status: completed）
**完成度**：100%
**最后更新**：2026-03-13

---

## 现状分析

### 问题

1. **ACME 申请成功后磁盘有 6 个文件**（`cert.pem`, `chain.pem`, `csr.pem`, `domain.key`, `fullchain.pem`, `privkey.pem`），浏览器认可
2. **数据库只存了 3 个 PEM**：`cert_pem`（fullchain 内容）、`key_pem`（privkey 内容）、`chain_pem`（中间链）
3. **缺失的文件**：`csr.pem`（CSR）和 `domain.key`（原始域名密钥）**未存到数据库**
4. **恢复逻辑 `restoreCertificateFilesFromData`** 只恢复 4 个文件（fullchain、privkey、chain、cert），缺少 `csr.pem` 和 `domain.key`
5. **动态加载 `_resolveSniCert`** 只查内存缓存 + 磁盘目录，**无数据库回退**——删掉磁盘文件后重启 WLS，域名无证书可用
6. **自签证书** 只生成 `fullchain.pem` + `privkey.pem`，缺少 `chain.pem`、`cert.pem`、`csr.pem`、`domain.key`

### 6 个文件说明

| 文件 | 来源 | 用途 | 数据库现状 |
|------|------|------|-----------|
| `fullchain.pem` | ACME 下载 | SSL 握手：完整证书链 | ✅ `cert_pem` |
| `privkey.pem` | copy from `domain.key` | SSL 握手：私钥 | ✅ `key_pem` |
| `chain.pem` | 从 fullchain 提取 | 浏览器验证中间链 | ✅ `chain_pem` |
| `cert.pem` | 从 fullchain 提取叶子 | 独立叶子证书 | ❌ 可从 `cert_pem` 提取 |
| `csr.pem` | ACME 生成 | 证书签名请求 | ❌ **未存储** |
| `domain.key` | openssl 生成 | 原始域名密钥 | ❌ **未存储**（key_pem 是 privkey 的拷贝，实际相同） |

### 关键发现

- `domain.key` 和 `privkey.pem` 内容完全相同（`performAcmeChallenge` 中 `\copy($domainKeyPath, $privkeyPath)`）
- `cert.pem` 可从 `fullchain.pem` 提取（已有 `extractLeafCertFromFullchain`）
- 唯一真正缺失的新数据是 `csr.pem`

---

## 决策自审

### 决策 1：扩展数据库存储 `csr_pem` 和 `fullchain_pem`

| 问题 | 回答 |
|------|------|
| 为什么这么做？ | 确保从 DB 恢复时能完整还原所有 6 个文件 |
| 收益 | 删除磁盘证书目录后，仅靠 DB 即可恢复全部文件，浏览器可识别 |
| 缺陷/风险 | 增加 DB 存储（text 字段，每域名约 15KB） |
| 影响范围 | `SslCertificate` 模型（加字段）、`SslCertificateService`（存/读逻辑）、`worker_ssl.php`（动态恢复） |
| 安全隐患 | 私钥已经存 DB，CSR 安全性更低，无新增安全风险 |

**结论**：新增 `fullchain_pem`（单独存储，与现有 `cert_pem` 区分——目前 `cert_pem` 实际存的是 fullchain 内容，语义不清）和 `csr_pem`。`domain.key` 等于 `key_pem` 无需新字段。

**修正**：现有 `cert_pem` 实际存的就是 fullchain 内容，无需新增 `fullchain_pem`。只需新增 `csr_pem`。恢复时：
- `fullchain.pem` ← `cert_pem`
- `privkey.pem` ← `key_pem`  
- `domain.key` ← `key_pem`（相同内容）
- `chain.pem` ← `chain_pem`
- `cert.pem` ← 从 `cert_pem` 提取叶子
- `csr.pem` ← `csr_pem`（新字段）

### 决策 2：`_resolveSniCert` 增加数据库回退

| 问题 | 回答 |
|------|------|
| 为什么这么做？ | 磁盘无证书时自动从 DB 恢复，实现真正的"动态加载" |
| 收益 | 删除证书目录后重启 WLS 不影响 HTTPS 服务 |
| 缺陷/风险 | Worker 进程中查询 DB 增加一次开销（仅首次且缓存） |
| 影响范围 | `worker_ssl.php` 的 `_resolveSniCert` 函数 |
| 安全隐患 | 无（DB 内容与磁盘一致） |

---

## 实施步骤

### 阶段 1：扩展模型字段 🟢

1. `SslCertificate` 模型新增 `#[Col] csr_pem` (text, nullable)
2. 新增 `setCsrPem()` / `getCsrPem()` 方法
3. 更新 `toSafeArray()` 排除 `csr_pem`
4. 执行 `setup:upgrade` 同步数据库

### 阶段 2：保存完整证书到数据库 🟢

1. **ACME 申请成功后**（`requestCertificate` 约 L1829）：读取 `csr.pem` 内容存到 `csr_pem`
2. **自签证书**（`generateSelfSignedCertificate`）：导出 CSR PEM 存到 `csr_pem`；补全 `chain.pem`、`cert.pem`、`domain.key` 文件到磁盘
3. **`updateCertificateRecord`**：读取 `csr.pem` 存到字段
4. **续签**：同样保存 `csr_pem`

### 阶段 3：完善恢复逻辑 🟢

1. **`restoreCertificateFilesFromData`**：恢复 6 个文件
   - `fullchain.pem` ← `cert_pem`
   - `privkey.pem` ← `key_pem`
   - `domain.key` ← `key_pem`
   - `chain.pem` ← `chain_pem`（或从 cert_pem 提取）
   - `cert.pem` ← 从 cert_pem 提取叶子
   - `csr.pem` ← `csr_pem`

### 阶段 4：动态加载（核心） 🟢

1. **`_resolveSniCert` 增加 DB 回退**：
   - 原逻辑：内存缓存 → 磁盘文件 → null（失败）
   - 新逻辑：内存缓存 → 磁盘文件 → **DB 查询 → 恢复到磁盘 → 返回**
2. 需要在 worker_ssl.php 中引入一个轻量的 DB 查询函数（或通过 SslCertificateService）
3. 恢复成功后缓存到 `$sniServerCerts`，避免重复查库
4. 恢复失败则日志告警并返回 null
