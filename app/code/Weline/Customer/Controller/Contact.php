<?php

declare(strict_types=1);

namespace Weline\Customer\Controller;

class Contact extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'contact';

    public function getIndex(): string
    {
        $this->assign('title', __('联系我们'));
        $this->assign('meta', [
            'showHeader' => true,
            'showFooter' => true,
            'class' => 'customer-contact-layout__main',
        ]);

        return $this->fetch('Weline_Customer::templates/frontend/contact.phtml');
    }
}
