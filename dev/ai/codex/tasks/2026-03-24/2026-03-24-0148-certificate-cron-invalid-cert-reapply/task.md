# Task: certificate-cron-invalid-cert-reapply

- Task ID: 2026-03-24-0148-certificate-cron-invalid-cert-reapply
- Started: 2026-03-24 01:48
- Status: completed
- Owner: Codex
- Source: user: 申请证书的定时任务优化下，只要域名的证书是无效的，就需要申请

## Goal

- Make `websites_pool_certificate_maintenance` re-apply a domain certificate whenever the managed certificate is invalid, instead of waiting for the daily health cron.

## Scope

- In scope:
- Update the high-frequency Websites certificate maintenance cron flow
- Reuse the existing certificate verify/request pipeline instead of rewriting request logic
- Add targeted unit coverage for the maintenance cron orchestration
- Out of scope:
- Changing certificate request internals
- Changing DNS cutover rules or domain pool lifecycle semantics
- Reworking the daily `websites_certificate_health_daily` schedule

## Constraints

- Prefer the smallest safe change in `Weline_Websites`
- Keep the daily health cron available as a supplemental sweep
- Validate with targeted lint and PHPUnit only unless broader runtime checks are needed

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Websites/Cron/WebsitesPoolCertificateMaintenance.php`
- `app/code/Weline/Websites/Cron/DomainPoolCertificateVerify.php`
- `app/code/Weline/Websites/Cron/DomainPoolCertificateRequest.php`
- `app/code/Weline/Websites/Test/Unit/Cron/WebsitesPoolCertificateMaintenanceTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
