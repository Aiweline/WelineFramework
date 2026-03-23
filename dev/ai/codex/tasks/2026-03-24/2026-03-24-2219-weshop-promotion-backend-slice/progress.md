# Progress - weshop promotion backend slice

- 2026-03-23 22:19 Created the task workspace.
- 2026-03-24 08:xx Added PromotionCouponRepository/PromotionCouponManagementService to encapsulate summary logic and coupon persistence, and translated new dashboard strings.
- 2026-03-24 08:xx Refactored the backend coupon controllers to delegate to the service, added a marketing menu entry under `Weline_Backend::marketing_group`, and built a dashboard template that renders summary and recent coupons.
- 2026-03-24 08:xx Added a unit test for `PromotionCouponManagementService`.
- 2026-03-24 08:xx Validated all new PHP files with `php -l` and ran `phpunit` against `PromotionCouponManagementServiceTest`.
