# Weline_Review AI Index

- `需求.md`：当前确认的万能评论、默认商品评论与后台审核需求。
- `开发日志.md`：`1.0.0` 实现、复审、测试和验收门禁记录。
- `Api/ReviewTypeProviderInterface.php`：评论类型、实体解析、字段与后端校验扩展契约。
- `Model/ProductReview.php`：默认电商商品评论独立表。
- `Model/ReviewMedia.php`：通用图片/视频上传票据与评论绑定。
- `Service/ReviewTypeRegistry.php`：评论类型注册入口。
- `Service/ProductReviewTypeProvider.php`：商品默认字段与校验。
- `Service/ReviewMediaService.php`：MIME、大小、数量、路径和票据校验。
- `Service/ReviewService.php`：表单、列表、提交聚合服务。
- `Service/ReviewAdminService.php`：后台统一列表、媒体聚合与审核状态写入。
- `Controller/Backend/Review.php`：评论后台列表、筛选与审核控制器。
- `extends/module/Weline_Framework/Query/ReviewQueryProvider.php`：BinQuery 前端入口。
- `view/hooks/Weline_Review/frontend/layouts/product-reviews/content.phtml`：可被主题覆盖的商品评论表现层。
- `view/templates/Backend/Review/index.phtml`：默认后台图文视频评论审核界面。
- `test/e2e/backend/Weline_Review-moderation.spec.js`：后台路由、筛选、媒体与审核闭环 E2E。
- `doc/运营/评论审核.md`：后台审核运营流程、权限与故障恢复说明。
