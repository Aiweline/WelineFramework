<?php

declare(strict_types=1);

namespace Weline\HelpPay\Extends\Module\Weline_Faq\FaqPageProvider;

use Weline\Faq\Api\FaqPageProviderInterface;
use Weline\Theme\Helper\WidgetI18n;

final class SelectionShareFaqPageProvider implements FaqPageProviderInterface
{
    public function pageCode(): string { return 'selection-share'; }
    public function slug(): string { return 'selection-share'; }
    public function title(): string { return WidgetI18n::label('分享给朋友（帮选规格）'); }
    public function summary(): string { return WidgetI18n::label('与帮我付不同：朋友自己结账下单'); }
    public function sortOrder(): int { return 43; }
    public function isEnabled(): bool { return true; }
    public function template(): string { return 'Weline_HelpPay::templates/frontend/faq/selection-share.phtml'; }
    public function group(): string { return WidgetI18n::label('分享'); }
}
