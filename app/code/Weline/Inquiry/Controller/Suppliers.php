<?php

declare(strict_types=1);

namespace Weline\Inquiry\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Suppliers extends FrontendController
{
    protected ?string $layoutType = 'default';

    public function getIndex(): string
    {
        $this->assign('title', __('供应商申请'));
        $this->assign('meta', [
            'title' => __('供应商申请'),
            'showHeader' => true,
            'showFooter' => true,
            'class' => 'w-inquiry-suppliers-page',
        ]);

        return $this->fetch('Weline_Inquiry::templates/frontend/suppliers.phtml');
    }
}
