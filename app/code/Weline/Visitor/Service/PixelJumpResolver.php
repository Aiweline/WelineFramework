<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * 前台 / 后台编辑跳转解析。
 */
class PixelJumpResolver
{
    /**
     * @return array{frontend: string, backend_edit: string}
     */
    public function resolve(int $websiteId, int $pageId, string $pageSource, string $frontendUrl): array
    {
        $frontend = \trim($frontendUrl);
        $backend = '';

        if ($pageId > 0 && ($pageSource === 'pagebuilder' || $pageSource === '' || $pageSource === 'page_builder')) {
            if (\class_exists(\GuoLaiRen\PageBuilder\Model\Page::class)) {
                $backend = '/pagebuilder/backend/page/form?id=' . $pageId;
                if ($websiteId > 0) {
                    $backend .= '&website_id=' . $websiteId;
                }
            }
        }

        return [
            'frontend' => $frontend,
            'backend_edit' => $backend,
        ];
    }
}
