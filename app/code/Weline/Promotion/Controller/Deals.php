<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller;

final class Deals extends Index
{
    public function index(): string
    {
        return $this->renderPromotionPage('deals');
    }
}
