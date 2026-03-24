# Plan - weshop-auth-theme-live-acceptance

## Outcome

- Produce one verified post-commit WeShop continuation wave grounded in live port `9982` checks and focused on the highest-value remaining auth/theme acceptance gaps.

## Steps

- [x] Create the task workspace and align scope with the post-commit continuation goal
- [x] Run live route/runtime probes on port `9982` for key WeShop auth/storefront paths
- [x] Run parallel audits for auth acceptance gaps and default-theme hook/slot coverage gaps
- [x] Implement the best next commit-sized WeShop fixes from the combined findings
- [ ] Run targeted verification, update docs/memory, and prepare the next commit

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / live probes
- [ ] Browser / e2e or explicit documented gap
