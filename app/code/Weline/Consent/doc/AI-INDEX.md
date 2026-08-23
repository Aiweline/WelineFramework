# Weline_Consent AI Index

## Production contracts

- `Api/ConsentRepositoryInterface.php` — durable Website/visitor/category state and audit boundary.
- `Api/ConsentRecordingPolicyInterface.php` — Scope-aware new-grant policy.
- `Api/ConsentVisitorIdentityInterface.php` — trusted browser identity boundary.
- `Service/OrmConsentRepository.php` — ORM current state plus append-only audit.
- `Service/SystemConfigConsentRecordingPolicy.php` — `Weline_Consent/backend/recording_enabled`.
- `Service/ConsentVisitorIdentity.php` — random HttpOnly `weline_consent_vid` issuance.
- `Service/ConsentService.php` — validation, grant, withdrawal and banner rules.
- `extends/module/Weline_Framework/Query/ConsentQueryProvider.php` — frontend bin-query entry.
- `view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml` — official Theme body-end banner Hook.

## Invariants

- `website_id=0` is valid.
- Browser callers cannot submit or select `visitor_key`.
- Required categories cannot be withdrawn.
- Recording disabled rejects new grants but never deletes existing records or audit.
- Runtime failures keep the privacy banner visible.

## Validation

- Unit: `Test/Unit/Service/ConsentServiceTest.php`,
  `Test/Unit/Query/ConsentQueryProviderTest.php`.
- E2E: `Test/e2e/frontend/Weline_Consent-accept.spec.js`.

<!-- weline:module-doc-baseline:start -->
## 固定模块文档

- [功能现状](功能现状.md)：当前版本、代码能力面、主要入口与未验证边界。
- [需求](需求.md)：已确认需求、文档基线与待确认产品语义。
- [开发日志](开发日志.md)：目标版本进度、证据和交付状态。
<!-- weline:module-doc-baseline:end -->
