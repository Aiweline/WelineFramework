# PRJ-MIG-00 L4 执行卡

## 1. 当前判定

- 状态：**`ACCEPTED`（2026-07-25）**。clone + 持久化 journal + rollout gate + **按能力 shadow 适配**全部满足；生产 cutover 不在本卡范围内（见 `RES-MIG-PROD-CUTOVER`）。
- 共享 PostgreSQL `weline`：**preflight 硬拒绝**；仅允许只读 `pg_dump` 作为 clone 源。
- 隔离 clone：`mig:foundation clone-create|destroy|list` 可用。
- Journal：`mig:foundation journal-list|journal-verify` 可用（`var/mig/checkpoints`）。

## 2. TASK-MIG-FOUNDATION

| Slice | 状态 | 路径 |
|---|---|---|
| `M00-A` Manifest | DONE | `Framework/Database/Migration/MigrationManifest.php` |
| `M00-B` Fingerprint denylist | DONE | `.../Service/DatabaseFingerprintGuard.php` |
| `M00-C` Checkpoint+journal | **DONE（文件持久化）** | `MigrationCheckpointService` + `MigrationCheckpointJournalStore`；Model additive |
| `M00-D` Rollout gate | DONE | `SystemConfig/Api/CommerceRolloutGateInterface.php` + Service |
| `M00-E` Shadow API | **DONE（按能力挂载）** | gate `shadow` + 各能力 `*ShadowComparator`（见下）；**不**另造无 MOD 依据的统一 SPI（`DEF-MIG-M00E-01`） |
| `M00-F` CLI | DONE | `mig:foundation help\|preflight\|clone-*\|journal-*` |
| `M00-G` Clone lifecycle | DONE | `MigrationCloneService` + Registry + Handle |

### M00-E 源码证据（2026-07-25 复核）

| 能力 | Comparator | Gate |
|---|---|---|
| Order | `Weline/Order/Service/OrderShadowComparator.php` | OrderCutoverGate / shadow |
| Tax | `Weline/Tax/Service/TaxShadowComparator.php` | CommerceRolloutGate |
| Search | `Weline/Search/Service/SearchShadowComparator.php` | CommerceRolloutGate |
| Vendor | `Weline/Vendor/Service/VendorShadowComparator.php` | CommerceRolloutGate |
| Subscription | `Weline/Subscription/Service/SubscriptionShadowComparator.php` | CommerceRolloutGate |
| B2B | `Weline/B2B/Service/B2BShadowComparator.php` | CommerceRolloutGate |
| CustomerAsset | `Weline/CustomerAsset/Service/CustomerAssetShadowComparator.php` | CommerceRolloutGate |

R4 `MOD-MIG-FOUNDATION` 文件清单仅含 Checkpoint + `CommerceRolloutGate`；「pure shadow API」从未作为独立统一 SPI 写入 MOD。业务观察窗按能力交付，符合 DEC-025。

证据：

- 契约：`dev/ai/codex/tasks/2026-07-24/2026-07-24-1012-task-mig-foundation/`
- Clone：`dev/ai/codex/tasks/2026-07-24/2026-07-24-1019-task-mig-foundation-clone/`
- Journal：`dev/ai/codex/tasks/2026-07-24/2026-07-24-2150-task-mig-foundation-journal/`
- M00-E 收口：`00-项目群主计划.md` §6.1 `DEF-MIG-M00E-01`

## 3. 约束（仍有效）

1. ~~持久化 journal 与 fresh-connection verify~~ → **DONE**
2. ~~pure shadow 业务适配器挂载~~ → **DONE（按能力）**
3. **任何 MIG apply 禁止共享库**；必须先 `clone-create`，结束后 `clone-destroy`
4. **生产 apply / mode=on**：见残余 `RES-MIG-PROD-CUTOVER`，须独立授权，本卡不覆盖

## 4. 退出证据（已有）

- TEST-MIG-FOUNDATION-01：canonical/`weline` 硬拒绝；真实 clone create→allowlist preflight→destroy
- TEST-MIG-FOUNDATION-02：篡改 manifest → `migration_manifest_tampered` 零写拒绝
- Journal：跨进程 `verifyFresh` OK；篡改落盘 manifest → verify fail
- P1–P4 各 MIG 在隔离 clone 上 ACCEPTED；共享库 apply 硬拒绝

## 5. 解锁 / 收官

- 历史上解锁的 `TASK-MIG-P2-*` / `P3-*` / `P4-*` 均已在隔离 clone 完成。
- 本项目 `PRJ-MIG-00` → **ACCEPTED**；唯一程序外残余为 `RES-MIG-PROD-CUTOVER`。
