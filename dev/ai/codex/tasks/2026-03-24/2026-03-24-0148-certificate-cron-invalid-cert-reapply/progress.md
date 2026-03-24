# Progress - certificate-cron-invalid-cert-reapply

- 2026-03-24 01:48 Created the task workspace.
- 2026-03-24 09:50 Confirmed the regression path: `websites_pool_certificate_maintenance` only processed request queues every 10 minutes, while invalid managed-certificate detection had been moved to the daily `websites_certificate_health_daily` cron.
- 2026-03-24 09:55 Updated `WebsitesPoolCertificateMaintenance` to run certificate verification before certificate request on every high-frequency maintenance pass, and added overridable helper methods so the orchestration can be unit tested.
- 2026-03-24 09:57 Added `WebsitesPoolCertificateMaintenanceTest` covering the verify-before-request order and the fallback behavior where request execution still proceeds if the verify step throws.
- 2026-03-24 09:58 Verified with `php -l` on the touched PHP files and targeted PHPUnit (`2` tests / `6` assertions, plus one existing PHPUnit deprecation notice).
