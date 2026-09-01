<?php

declare(strict_types=1);

namespace Weline\Payment\Interface;

/**
 * 支付供应商客户指南扩展点。
 *
 * 每个接入 Weline_Payment 的供应商应实现此接口，并提供 **phtml 模板**（非 PHP 拼 HTML）：
 * - 客户支付指南页：`guide.phtml`
 * - 支付政策页：`policy.phtml`
 * - 用户协议页：`agreement.phtml`（PayPal Developer「User agreement URL」等同意页链接）
 *
 * 正文必须写在 phtml 中，并使用 **`<lang>` / `@lang()`** 标签输出（不要用 `<?= __('…') ?>`），
 * 以便 `i18n:collect` 收集词条并由 I18n AI 翻译。
 * Guide 类只负责元数据（标题、摘要、模板 code、布局），不要在此类中拼接大段 HTML 正文。
 *
 * 模板路径约定：
 * view/templates/Frontend/guide/payment/{method_code}/{guide_template_code}.phtml
 *
 * @see app/code/Weline/Payment/doc/payment-customer-guide-i18n.md
 */
interface PaymentCustomerGuideInterface
{
    public function getMethodCode(): string;

    public function getProviderCode(): string;

    public function getTitle(): string;

    public function getSummary(): string;

    public function getGuideTitle(): string;

    public function getPolicyTitle(): string;

    public function getAgreementTitle(): string;

    /**
     * 相对 view/templates/Frontend/guide/payment/{method_code}/ 下的模板文件名（不含 .phtml）。
     */
    public function getGuideTemplateCode(): string;

    /**
     * 相对 view/templates/Frontend/guide/payment/{method_code}/ 下的模板文件名（不含 .phtml）。
     */
    public function getPolicyTemplateCode(): string;

    /**
     * 相对 view/templates/Frontend/guide/payment/{method_code}/ 下的模板文件名（不含 .phtml）。
     */
    public function getAgreementTemplateCode(): string;

    /**
     * 指南页 Theme 布局类型，例如 help、policy。
     */
    public function getGuideLayoutType(): string;

    /**
     * 政策页 Theme 布局类型，例如 policy、help。
     */
    public function getPolicyLayoutType(): string;

    /**
     * 用户协议页 Theme 布局类型，例如 policy、help。
     */
    public function getAgreementLayoutType(): string;

    public function getSortOrder(): int;
}
