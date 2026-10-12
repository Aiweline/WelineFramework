<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller;

/**
 * @Extra type=fpc ttl=3600 namespaces=website/default/marketing public_path_patterns=/promotion/sale,/promotion/sale/**
 */
final class Sale extends Index
{
    public function index(): string
    {
        return $this->renderPromotionPage('sale');
    }
}
