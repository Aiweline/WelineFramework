# Result - certificate-cron-invalid-cert-reapply

## Outcome

- `websites_pool_certificate_maintenance` now performs managed-certificate verification before the existing request queue on every 10-minute run.
- When a domain certificate is invalid, the verify step can immediately roll the pool row back into a requestable state, and the same cron execution continues into `DomainPoolCertificateRequest`.
- Added focused unit coverage for the maintenance cron ordering and error-tolerance path.

## Changed Files

- `app/code/Weline/Websites/Cron/WebsitesPoolCertificateMaintenance.php`
- `app/code/Weline/Websites/Test/Unit/Cron/WebsitesPoolCertificateMaintenanceTest.php`

## Verification

- `php -l app/code/Weline/Websites/Cron/WebsitesPoolCertificateMaintenance.php`
- `php -l app/code/Weline/Websites/Test/Unit/Cron/WebsitesPoolCertificateMaintenanceTest.php`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Websites/Test/Unit/Cron/WebsitesPoolCertificateMaintenanceTest.php --colors=never`
- Result: syntax checks passed; PHPUnit passed `2` tests / `6` assertions with one existing PHPUnit deprecation notice.

## Remaining Risks

- Real cron/runtime integration was not executed against a live Websites database in this turn.
- The maintenance cron now scans the verify phase every 10 minutes, so runtime cost should be watched if the pool becomes very large.

## Next Resume Step

- If needed, run `php bin/w cron:test --task=websites_pool_certificate_maintenance --domain=<fqdn> -v` against a live domain to confirm same-run rollback plus re-application end to end.
