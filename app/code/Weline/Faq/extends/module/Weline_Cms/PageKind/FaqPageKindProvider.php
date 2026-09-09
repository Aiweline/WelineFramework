<?php

declare(strict_types=1);

namespace Weline\Faq\Extends\Module\Weline_Cms\PageKind;

use Weline\Cms\Api\Kind\CmsPageKindInterface;
use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Theme\Model\ThemeLayout;

final class FaqPageKindProvider implements CmsPageKindInterface
{
    public function getCode(): string
    {
        return 'faq';
    }

    public function getLabel(): string
    {
        return (string)__('FAQ');
    }

    public function getPathGroup(): string
    {
        return FaqNamespace::PREFIX;
    }

    public function getPublicNamespace(): string
    {
        return '/' . FaqNamespace::PREFIX;
    }

    public function getLayoutTypes(): array
    {
        return [ThemeLayout::PAGE_TYPE_FAQ];
    }

    public function getDefaultLayoutType(): string
    {
        return ThemeLayout::PAGE_TYPE_FAQ;
    }

    public function getDefaultLayoutOption(): string
    {
        return 'default';
    }
}
