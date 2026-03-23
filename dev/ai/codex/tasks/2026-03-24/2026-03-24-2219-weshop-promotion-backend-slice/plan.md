# Plan - weshop promotion backend slice

## Outcome

- deliver a production-ready Promotion backend slice where marketing admins get a coupon dashboard, the controllers delegate to a repository/service layer, and validation/testing guard the new flow

## Steps

- [x] Clarify scope: menu, dashboard controllers, repository/service hardening, template, tests, and verification
- [x] Implement PromotionCouponRepository + PromotionCouponManagementService, backend menu entry/template, and refactor backend controllers
- [x] Add targeted PHPUnit for the new management service and ensure translation/template coverage
- [ ] Run PHP lint and PHPUnit validation
- [ ] Update progress/result/memory once validation completes

## Verification Targets

- [ ] Unit / phpunit (`PromotionCouponManagementServiceTest`)
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
