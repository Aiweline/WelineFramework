<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Backend\Api\Menu\PageHeaderResolver;
use Weline\Framework\Http\Request;

/**
 * Theme-facing compatibility wrapper for the Backend-owned page header API.
 */
class BackendPageHeaderResolver
{
    public function __construct(
        private readonly Request $request,
        private readonly PageHeaderResolver $pageHeaderResolver,
    ) {
    }

    /**
     * @return array{title:string,breadcrumbs:list<array{title:string,url:string,active:bool}>,has_menu:bool}
     */
    public function resolve(string $fallbackTitle = ''): array
    {
        return $this->pageHeaderResolver->resolve($this->request, $fallbackTitle);
    }
}
