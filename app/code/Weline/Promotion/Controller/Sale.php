<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller;

final class Sale extends Index
{
    public function index(): string
    {
        return $this->renderPromotionPage('sale');
    }
}
