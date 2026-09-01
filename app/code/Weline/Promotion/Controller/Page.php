<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller;

final class Page extends Index
{
    public function index(): string
    {
        $pageSlug = strtolower(trim((string)$this->request->getGet('page_slug', '')));
        if ($pageSlug === '') {
            return $this->renderPromotionPage('index');
        }

        return $this->renderPromotionPage($pageSlug);
    }
}
