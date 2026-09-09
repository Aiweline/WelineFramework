<?php

declare(strict_types=1);

namespace Weline\Cms\Api\Kind;

/**
 * Extension point: modules inject CMS page kinds (path_group + Theme layout binding).
 */
interface CmsPageKindInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    /**
     * Stable path_group stored on CMS pages. Empty string = default/catch-all kind.
     */
    public function getPathGroup(): string;

    /**
     * Public URI namespace prefix (e.g. "/help"). Empty for generic CMS pages.
     */
    public function getPublicNamespace(): string;

    /**
     * @return list<string>
     */
    public function getLayoutTypes(): array;

    public function getDefaultLayoutType(): string;

    public function getDefaultLayoutOption(): string;
}
