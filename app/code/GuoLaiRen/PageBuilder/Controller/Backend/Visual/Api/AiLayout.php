<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend\Visual\Api;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiHtmlSanitizerService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * ai_html 轨：读写页面级 blocks[]（弱 schema，不经 ThemeComponent）
 */
class AiLayout extends BackendController
{
    /**
     * POST page_id + blocks JSON 数组 或 ai_layout JSON 对象
     */
    public function save(): string
    {
        while (\ob_get_level() > 0) {
            \ob_end_clean();
        }
        \ob_start();
        try {
            $pageId = (int)$this->request->getPost('page_id', 0);
            if ($pageId <= 0) {
                return $this->fetchJson(['success' => false, 'message' => (string)__('缺少 page_id')]);
            }
            /** @var Page $pageModel */
            $pageModel = ObjectManager::getInstance(Page::class);
            $page = clone $pageModel;
            $page->clearData()->clearQuery()->load($pageId);
            if (!$page->getId()) {
                return $this->fetchJson(['success' => false, 'message' => (string)__('页面不存在')]);
            }
            if (!$page->isAiHtmlRenderMode()) {
                return $this->fetchJson(['success' => false, 'message' => (string)__('当前页面不是 ai_html 渲染模式')]);
            }

            $rawLayout = $this->request->getPost('ai_layout', '');
            $rawBlocks = $this->request->getPost('blocks', '');
            if (\is_string($rawLayout) && \trim($rawLayout) !== '') {
                $decoded = \json_decode($rawLayout, true);
                $layout = \is_array($decoded) ? $decoded : ['blocks' => []];
            } elseif (\is_string($rawBlocks) && \trim($rawBlocks) !== '') {
                $decoded = \json_decode($rawBlocks, true);
                $layout = ['blocks' => \is_array($decoded) ? $decoded : []];
            } else {
                return $this->fetchJson(['success' => false, 'message' => (string)__('缺少 ai_layout 或 blocks')]);
            }

            /** @var AiHtmlSanitizerService $san */
            $san = ObjectManager::getInstance(AiHtmlSanitizerService::class);
            $strict = $this->request->getPost('strict_sanitize', '0') === '1';
            $toSave = $strict ? $san->sanitizeAiLayout($layout) : $this->weakNormalizeAiLayout($layout);

            $page->setAiLayoutArray(\is_array($toSave) ? $toSave : ['blocks' => []]);
            $page->save(true);

            return $this->fetchJson(['success' => true, 'data' => ['page_id' => $pageId, 'ai_layout' => $page->getAiLayoutArray()]]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function weakNormalizeAiLayout(array $layout): array
    {
        $blocks = $layout['blocks'] ?? [];
        if (!\is_array($blocks)) {
            return ['blocks' => []];
        }
        $out = [];
        foreach ($blocks as $b) {
            if (!\is_array($b)) {
                continue;
            }
            $bid = \trim((string)($b['block_id'] ?? ''));
            if ($bid === '') {
                $bid = 'blk_' . \bin2hex(\random_bytes(4));
            }
            $out[] = [
                'block_id' => $bid,
                'type' => \trim((string)($b['type'] ?? 'section')),
                'html' => (string)($b['html'] ?? ''),
            ];
        }

        return ['blocks' => $out];
    }
}
