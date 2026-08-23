# Weline_Tax — AI INDEX

- Docs：[`README.md`](README.md)、[`tax-engine.md`](tax-engine.md)
- Models：`TaxClass`、`TaxRule`、`TaxRuleSetLkg`
- Api：`TaxEngineInterface`（拥有 canonical `SCHEMA_VERSION`）、
  `CheckoutTaxAdvisorInterface`、`TaxShadowQuoteSourceInterface`
- P3B-001 Service：`TaxScopeConfig`、`TaxEngine`、`TaxShadowComparator`、`TaxLkgStore`、`TaxConflictException`
- P3B-002 Service：`CheckoutTaxAdvisor`；当前 `etc/module.php` 发布 `TaxEngineInterface => TaxEngine` 与 `CheckoutTaxAdvisorInterface => CheckoutTaxAdvisor`
- MIG-P3B Service：`TaxMigrationService`、`TaxRolloutGate`；Checkout adapter：`CheckoutTaxShadowQuoteSource`
- Console：`commerce:migrate-p3b-tax`（`preflight/apply/verify/allowlist/rollback`）
- SystemConfig：`extends/module/Weline_SystemConfig/Config/backend/tax.phtml`
- Tests：`TaxEngineAndShadowTest`、`TaxCurrentSourceDatabaseIntegrationTest`（TEST-P3B-01）；Checkout `CheckoutTaxIntegrationTest`（TEST-P3B-02/03/04）；`TaxMigrationServiceTest`、`TaxRolloutGateTest`（TASK-MIG-P3B）

<!-- weline:module-doc-baseline:start -->
## 固定模块文档

- [功能现状](功能现状.md)：当前版本、代码能力面、主要入口与未验证边界。
- [需求](需求.md)：已确认需求、文档基线与待确认产品语义。
- [开发日志](开发日志.md)：目标版本进度、证据和交付状态。
<!-- weline:module-doc-baseline:end -->
