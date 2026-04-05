<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;

final class AiSiteHtmlBlocksBuildService
{
    public function __construct(
        private readonly ?AiSitePageBlueprintService $pageBlueprintService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return list<array{block_id:string,type:string,html:string}>
     */
    public function buildPlaceholderBlocksForPageType(string $pageType, array $websiteProfile, array $scope = []): array
    {
        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $blueprint = $pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile);
        $blocks = [];

        foreach ($blueprint['sections'] as $section) {
            $blockId = \str_replace(['content/', '/'], ['', '-'], (string)$section['code']);
            $template = (string)($section['template'] ?? 'hero');
            $config = \is_array($section['config'] ?? null) ? $section['config'] : [];

            $blocks[] = [
                'block_id' => $blockId !== '' ? $blockId : ('block-' . \bin2hex(\random_bytes(4))),
                'type' => $template,
                'html' => $this->renderBlockHtml($template, $config),
            ];
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderBlockHtml(string $template, array $config): string
    {
        return match ($template) {
            'cards' => $this->renderCardsBlock($config),
            'checklist' => $this->renderChecklistBlock($config),
            'cta' => $this->renderCtaBlock($config),
            default => $this->renderHeroBlock($config),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderHeroBlock(array $config): string
    {
        $eyebrow = $this->escape($config['eyebrow'] ?? '');
        $headline = $this->escape($config['headline'] ?? '');
        $description = \nl2br($this->escape($config['description'] ?? ''));
        $chips = $this->renderChipList($config['chips'] ?? []);
        $cta = $this->escape($config['primary_cta'] ?? '');

        return '<section class="ai-block ai-block-hero" style="padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;">'
            . ($eyebrow !== '' ? '<span style="font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;">' . $eyebrow . '</span>' : '')
            . '<h1 style="margin:0;font-size:40px;line-height:1.08;color:#0f172a;">' . $headline . '</h1>'
            . ($description !== '' ? '<p style="margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;">' . $description . '</p>' : '')
            . $chips
            . ($cta !== '' ? '<div><span style="display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;">' . $cta . '</span></div>' : '')
            . '</section>';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderCardsBlock(array $config): string
    {
        $title = $this->escape($config['section_title'] ?? '');
        $intro = \nl2br($this->escape($config['section_intro'] ?? ''));
        $items = [];
        foreach (($config['items'] ?? []) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $items[] = '<article style="display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;">'
                . (!empty($item['eyebrow']) ? '<span style="font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;">' . $this->escape($item['eyebrow']) . '</span>' : '')
                . '<h3 style="margin:0;font-size:20px;line-height:1.2;color:#0f172a;">' . $this->escape($item['title'] ?? '') . '</h3>'
                . (!empty($item['description']) ? '<p style="margin:0;font-size:15px;line-height:1.7;color:#475569;">' . \nl2br($this->escape($item['description'])) . '</p>' : '')
                . '</article>';
        }

        return '<section class="ai-block ai-block-cards" style="padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;">'
            . ($title !== '' ? '<h2 style="margin:0;font-size:28px;line-height:1.2;color:#0f172a;">' . $title . '</h2>' : '')
            . ($intro !== '' ? '<p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;">' . $intro . '</p>' : '')
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;">' . \implode('', $items) . '</div>'
            . '</section>';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderChecklistBlock(array $config): string
    {
        $title = $this->escape($config['section_title'] ?? '');
        $intro = \nl2br($this->escape($config['section_intro'] ?? ''));
        $points = [];
        foreach (($config['points'] ?? []) as $index => $point) {
            if (!\is_scalar($point) || \trim((string)$point) === '') {
                continue;
            }
            $points[] = '<div style="display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;">'
                . '<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;">' . ((int)$index + 1) . '</span>'
                . '<p style="margin:0;font-size:15px;line-height:1.7;color:#334155;">' . \nl2br($this->escape((string)$point)) . '</p>'
                . '</div>';
        }

        return '<section class="ai-block ai-block-checklist" style="padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;">'
            . ($title !== '' ? '<h2 style="margin:0;font-size:26px;line-height:1.25;color:#0f172a;">' . $title . '</h2>' : '')
            . ($intro !== '' ? '<p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;">' . $intro . '</p>' : '')
            . '<div style="display:grid;gap:12px;">' . \implode('', $points) . '</div>'
            . '</section>';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderCtaBlock(array $config): string
    {
        $title = $this->escape($config['section_title'] ?? '');
        $text = \nl2br($this->escape($config['section_text'] ?? ''));
        $button = $this->escape($config['button_label'] ?? '');
        $assist = $this->escape($config['assist_text'] ?? '');

        return '<section class="ai-block ai-block-cta" style="padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);">'
            . '<div style="padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;">'
            . ($title !== '' ? '<h2 style="margin:0;font-size:28px;line-height:1.2;color:#fff;">' . $title . '</h2>' : '')
            . ($text !== '' ? '<p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);">' . $text . '</p>' : '')
            . '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">'
            . ($button !== '' ? '<span style="display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;">' . $button . '</span>' : '')
            . ($assist !== '' ? '<span style="font-size:13px;color:rgba(255,255,255,.72);">' . $assist . '</span>' : '')
            . '</div></div></section>';
    }

    /**
     * @param mixed $chips
     */
    private function renderChipList(mixed $chips): string
    {
        if (!\is_array($chips) || $chips === []) {
            return '';
        }

        $items = [];
        foreach ($chips as $chip) {
            if (!\is_scalar($chip) || \trim((string)$chip) === '') {
                continue;
            }
            $items[] = '<span style="display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;">' . $this->escape((string)$chip) . '</span>';
        }

        if ($items === []) {
            return '';
        }

        return '<div style="display:flex;flex-wrap:wrap;gap:10px;">' . \implode('', $items) . '</div>';
    }

    private function escape(mixed $value): string
    {
        return \htmlspecialchars(\trim((string)$value), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
