<?php

declare(strict_types=1);

namespace Weline\HelpPay\Extends\Module\Weline_Faq\FaqPageProvider;

use Weline\Faq\Api\FaqPageProviderInterface;

final class HelpPayPayerFaqPageProvider implements FaqPageProviderInterface
{
    public function pageCode(): string { return 'help-pay-payer'; }
    public function slug(): string { return 'help-pay-payer'; }
    public function title(): string { return (string) __('代付人如何付款'); }
    public function summary(): string { return (string) __('打开链接、选物流与支付、不能用优惠'); }
    public function sortOrder(): int { return 42; }
    public function isEnabled(): bool { return true; }
    public function template(): string { return 'Weline_HelpPay::templates/frontend/faq/help-pay-payer.phtml'; }
    public function group(): string { return (string) __('帮我付'); }
}
