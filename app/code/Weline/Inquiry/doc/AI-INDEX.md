# Weline_Inquiry AI 索引

## 模块边界

`Weline_Inquiry` 是独立的通用询盘表单模块。它不依赖 PageBuilder，也不依赖 `Weline_Product`；产品上下文只允许作为调用方附加的可选数据。

## 入口

- 后台表单：`Weline\Inquiry\Controller\Backend\Inquiry`
- 后台提交记录：`Weline\Inquiry\Controller\Backend\Submission`（站点范围列表、打开时标菜单已读、`postReply` 站内回复、`getThread` 线程）
- 前台 API：`inquiry` QueryProvider
- 模板标签：`<w:inquiry code="..." />`
- Widget：`Weline_Inquiry/inquiry_form`

## 核心服务

- `FormSchemaService`：标准化、校验、生成字段 schema。
- `FormVersionService`：草稿、发布和不可变版本快照。
- `LocalizedFormResolver`：按 当前语言 → 表单默认语言 → 系统回退语言 合并翻译。
- `SubmissionService`：服务端字段校验、幂等提交、快照归档和站点归属。
- `InquiryWebsiteScopeService`：WebsiteData → 请求 website_id → Host 域名 → ScopeIdentity 的站点解析。
- `InquiryMailReplySlotService`：运营本人邮箱优先、超管/无本人邮箱可选后台邮箱，并生成 mailto。
- `InquiryRenderer`：inline/modal/trigger 的安全前台渲染。
- `SubmissionMenuSignalPublisher` + `InquirySubmissionMenuSignalProvider`：新询盘写后台侧栏未读消息（`Weline_Inquiry::submissions`），provider 提供未读条数与深链；普通运营按当前站点过滤。

## 安全边界

- 发布必须具有默认语言的完整可提交定义。
- 提交使用 CSRF、服务端 schema 校验、陷阱字段与幂等键。
- 附件仅保存已验证的上传票据引用；文件内容由存储模块处理。
- 询盘必须归属站点；普通运营只能查看/回复当前站点，超管可切换范围。
- 站内回复忽略前端传入的收件人，始终使用询盘 payload 中的邮箱；发件账号按运营本人邮箱或显式选择校验。
- 实例 CSS 会移除危险协议与 `</style>`；实例 JS 仅在 SystemConfig 开关和后台 ACL 都通过时输出，不使用 `eval`。

## 验证

先执行模块 Unit/Integration 测试，再运行：

```bash
php bin/w taglib:collect Weline_Inquiry
php bin/w e2e:run --module=Weline_Inquiry --project=chromium --workers=1
```
