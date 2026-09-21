<?php

declare(strict_types=1);

namespace Weline\Customer\Controller;

use Weline\Seo\Service\Head\SeoPageProfileBag;
use Weline\Theme\Helper\WidgetI18n;

class Contact extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'contact';

    public function getIndex(): string
    {
        $title = WidgetI18n::label('联系我们');
        $this->assign('title', $title);
        if (class_exists(SeoPageProfileBag::class)) {
            SeoPageProfileBag::publish(['title' => $title]);
        }
        $this->assign('meta', [
            'showHeader' => true,
            'showFooter' => true,
            'class' => 'customer-contact-layout__main',
        ]);

        return $this->fetch('Weline_Customer::templates/frontend/contact.phtml');
    }
}
