<?php

declare(strict_types=1);

namespace Weline\Cms\Kind;

use Weline\Cms\Api\Kind\CmsPageKindInterface;
use Weline\Cms\Model\Page;

/**
 * Built-in kind for ordinary CMS pages (layout cms_page).
 */
final class DefaultCmsPageKind implements CmsPageKindInterface
{
    public function getCode(): string
    {
        return 'cms';
    }

    public function getLabel(): string
    {
        return (string)__('CMS 页面');
    }

    public function getPathGroup(): string
    {
        return '';
    }

    public function getPublicNamespace(): string
    {
        return '';
    }

    public function getLayoutTypes(): array
    {
        return [Page::LAYOUT_TYPE];
    }

    public function getDefaultLayoutType(): string
    {
        return Page::LAYOUT_TYPE;
    }

    public function getDefaultLayoutOption(): string
    {
        return 'default';
    }
}
