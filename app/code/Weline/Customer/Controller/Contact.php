<?php

declare(strict_types=1);

namespace Weline\Customer\Controller;

class Contact extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = null;

    public function getIndex(): string
    {
        return $this->renderLayout(
            'Weline_Customer::theme/frontend/layouts/contact/default.phtml',
            'Weline_Customer::templates/frontend/contact.phtml',
            (string) __('联系我们'),
            [
                'meta' => [
                    'showHeader' => true,
                    'showFooter' => true,
                    'class' => 'customer-contact-layout__main',
                ],
            ]
        );
    }
}
