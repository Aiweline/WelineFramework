<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller;

/**
 * @Extra type=fpc ttl=3600 namespaces=website/default/marketing public_path_patterns=/promotion/**
 */
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
