<?php

declare(strict_types=1);

namespace Weline\HelpPay\Extends\Module\Weline_Faq\FaqPageProvider;

use Weline\Faq\Api\FaqPageProviderInterface;

final class HelpPayPrivacyFaqPageProvider implements FaqPageProviderInterface
{
    public function pageCode(): string { return 'help-pay-privacy'; }
    public function slug(): string { return 'help-pay-privacy'; }
    public function title(): string { return (string) __('帮我付隐私与地址'); }
    public function summary(): string { return (string) __('店面不展示收货地址；账单地址说明'); }
    public function sortOrder(): int { return 41; }
    public function isEnabled(): bool { return true; }
    public function template(): string { return 'Weline_HelpPay::templates/frontend/faq/help-pay-privacy.phtml'; }
    public function group(): string { return (string) __('帮我付'); }
}
