<?php

declare(strict_types=1);

namespace Weline\Help\Extends\Module\Weline_Cms\PageKind;

use Weline\Cms\Api\Kind\CmsPageKindInterface;
use Weline\Help\Api\Uri\HelpNamespace;
use Weline\Theme\Model\ThemeLayout;

final class HelpPageKindProvider implements CmsPageKindInterface
{
    public function getCode(): string
    {
        return 'help';
    }

    public function getLabel(): string
    {
        return (string)__('帮助中心');
    }

    public function getPathGroup(): string
    {
        return HelpNamespace::PREFIX;
    }

    public function getPublicNamespace(): string
    {
        return '/' . HelpNamespace::PREFIX;
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
