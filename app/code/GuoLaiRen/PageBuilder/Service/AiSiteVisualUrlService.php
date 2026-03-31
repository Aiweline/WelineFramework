<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Http\Url;

class AiSiteVisualUrlService
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    /**
     * @return array{preview_full_url:string,visual_preview_url:string,visual_edit_url:string}
     */
    public function resolveUrls(int $pageId, int $virtualThemeId = 0): array
    {
        if ($pageId <= 0) {
            return [
                'preview_full_url' => '',
                'visual_preview_url' => '',
                'visual_edit_url' => '',
            ];
        }

        $previewParams = ['page_id' => $pageId];
        $visualPreviewParams = ['page_id' => $pageId, 'visual_editor' => '1'];
        $visualEditParams = ['id' => $pageId];

        if ($virtualThemeId > 0) {
            $previewParams['virtual_theme_id'] = $virtualThemeId;
            $visualPreviewParams['virtual_theme_id'] = $virtualThemeId;
            $visualEditParams['virtual_theme_id'] = $virtualThemeId;
        }

        return [
            'preview_full_url' => $this->url->getBackendUrl('pagebuilder/backend/preview/full', $previewParams),
            'visual_preview_url' => $this->url->getBackendUrl('pagebuilder/backend/preview/full', $visualPreviewParams),
            'visual_edit_url' => $this->url->getBackendUrl('pagebuilder/backend/page/edit', $visualEditParams),
        ];
    }

    /**
     * @return array{preview_full_url:string,visual_preview_url:string,visual_edit_url:string}
     */
    public function resolveVirtualUrls(string $publicId, string $pageType, int $virtualThemeId = 0): array
    {
        $publicId = \trim($publicId);
        $pageType = \trim($pageType);
        if ($publicId === '' || $pageType === '') {
            return [
                'preview_full_url' => '',
                'visual_preview_url' => '',
                'visual_edit_url' => '',
            ];
        }

        $previewParams = [
            'public_id' => $publicId,
            'page_type' => $pageType,
        ];
        $visualPreviewParams = $previewParams + ['visual_editor' => '1'];
        $visualEditParams = $previewParams;

        if ($virtualThemeId > 0) {
            $previewParams['virtual_theme_id'] = $virtualThemeId;
            $visualPreviewParams['virtual_theme_id'] = $virtualThemeId;
            $visualEditParams['virtual_theme_id'] = $virtualThemeId;
        }

        return [
            'preview_full_url' => $this->url->getBackendUrl('pagebuilder/backend/preview/full', $previewParams),
            'visual_preview_url' => $this->url->getBackendUrl('pagebuilder/backend/preview/full', $visualPreviewParams),
            'visual_edit_url' => $this->url->getBackendUrl('pagebuilder/backend/page/virtual-edit', $visualEditParams),
        ];
    }
}
