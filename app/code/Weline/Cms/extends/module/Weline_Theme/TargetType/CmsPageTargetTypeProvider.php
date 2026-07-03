<?php
declare(strict_types=1);

namespace Weline\Cms\Extends\Module\Weline_Theme\TargetType;

use Weline\Cms\Model\Page;
use Weline\Cms\Service\PageService;
use Weline\Theme\Api\TargetPreviewPayloadProviderInterface;
use Weline\Theme\Api\TargetTypeProviderInterface;

class CmsPageTargetTypeProvider implements TargetTypeProviderInterface, TargetPreviewPayloadProviderInterface
{
    public function __construct(
        private readonly PageService $pageService
    ) {
    }

    public function getCode(): string
    {
        return Page::TARGET_TYPE;
    }

    public function getLabel(): string
    {
        return (string)__('CMS 页面');
    }

    public function getModule(): string
    {
        return 'Weline_Cms';
    }

    public function getLayoutTypes(): array
    {
        return [Page::LAYOUT_TYPE];
    }

    public function getCapabilities(): array
    {
        return ['layout_selection', 'visual_editor_lock', 'virtual_layout', 'meta', 'preview', 'render'];
    }

    public function validate(int $targetId, array $context = []): bool
    {
        return $this->pageService->getPageModel($targetId, true) !== null;
    }

    public function resolve(int $targetId, array $context = []): ?array
    {
        if (!$this->validate($targetId, $context)) {
            return null;
        }

        return $this->pageService->resolveThemeTarget($targetId);
    }

    public function canUseLayoutType(string $layoutType): bool
    {
        return strtolower(trim($layoutType)) === Page::LAYOUT_TYPE;
    }

    public function resolvePreviewPayload(int $targetId, array $context = []): ?array
    {
        $payload = $this->pageService->renderPagePayload([
            'page_id' => $targetId,
            'scope' => (string)($context['scope'] ?? ''),
            'preview' => true,
        ]);

        if (!is_array($payload)) {
            return null;
        }

        $page = is_array($payload['page'] ?? null) ? $payload['page'] : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $payload['meta'] = array_merge(
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            [
                'title' => (string)($content['title'] ?? ($page['title'] ?? '')),
                'cms_page_id' => (int)($page['page_id'] ?? $targetId),
                'cms_identifier' => (string)($page['identifier'] ?? ''),
            ]
        );

        return $payload;
    }
}
