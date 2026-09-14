<?php

declare(strict_types=1);

namespace Weline\HelpPay\Extends\Module\Weline_Faq\FaqPageProvider;

use Weline\Faq\Api\FaqPageProviderInterface;
use Weline\Theme\Helper\WidgetI18n;

final class HelpPayRulesFaqPageProvider implements FaqPageProviderInterface
{
    public function pageCode(): string { return 'help-pay-rules'; }
    public function slug(): string { return 'help-pay-rules'; }
    public function title(): string { return WidgetI18n::label('帮我付是什么'); }
    public function summary(): string { return WidgetI18n::label('如何请人付款、订单归属与出链方式'); }
    public function sortOrder(): int { return 40; }
    public function isEnabled(): bool { return true; }
    public function template(): string { return 'Weline_HelpPay::templates/frontend/faq/help-pay-rules.phtml'; }
    public function group(): string { return WidgetI18n::label('帮我付'); }
}
