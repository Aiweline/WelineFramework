<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Park script/style (and optional widget-wrapper inners) before string slot scans.
 */
final class SlotHtmlOpaqueParker
{
    /** @var array<string, string> */
    private array $tokens = [];

    public function reset(): void
    {
        $this->tokens = [];
    }

    /**
     * @return array<string, string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function park(string $html): string
    {
        $this->reset();
        if ($html === '') {
            return $html;
        }

        $html = $this->parkOpaqueBlocks($html);
        return $this->parkWidgetWrapperInners($html);
    }

    public function restore(string $html): string
    {
        if ($this->tokens === [] || $html === '') {
            return $html;
        }

        for ($i = 0; $i < 8; $i++) {
            $next = strtr($html, $this->tokens);
            if ($next === $html) {
                break;
            }
            $html = $next;
        }

        return $html;
    }

    private function parkOpaqueBlocks(string $html): string
    {
        $scanner = new SlotBoundaryScanner();
        $offset = 0;
        $out = '';
        foreach ($scanner->scanTags($html) as $tag) {
            if ($tag['closing'] || !in_array($tag['name'], ['script', 'style'], true)) {
                continue;
            }
            $bounds = $scanner->findElementBounds($html, $tag['start']);
            if ($bounds === null) {
                continue;
            }
            $token = '<!--WELINE_SLOT_OPAQUE_' . count($this->tokens) . '_' . bin2hex(random_bytes(4)) . '-->';
            $this->tokens[$token] = substr($html, $tag['start'], $bounds['close_end'] - $tag['start']);
            $out .= substr($html, $offset, $tag['start'] - $offset) . $token;
            $offset = $bounds['close_end'];
        }
        return $out . substr($html, $offset);
    }

    private function parkWidgetWrapperInners(string $html): string
    {
        if (!str_contains($html, 'widget-wrapper')) {
            return $html;
        }

        $scanner = new SlotBoundaryScanner();
        $offset = 0;
        $out = '';
        foreach ($scanner->scanTags($html) as $tag) {
            if ($tag['start'] < $offset || $tag['closing'] || $tag['name'] !== 'div'
                || preg_match('/(?:^|\s)widget-wrapper(?:\s|$)/', $scanner->attributeValue($tag['html'], 'class') ?? '') !== 1
            ) {
                continue;
            }
            $bounds = $scanner->findElementBounds($html, $tag['start']);
            if ($bounds === null) {
                continue;
            }
            $token = '<!--WELINE_SLOT_OPAQUE_WIDGET_' . count($this->tokens) . '_' . bin2hex(random_bytes(4)) . '-->';
            $this->tokens[$token] = substr($html, $bounds['open_end'], $bounds['close_start'] - $bounds['open_end']);
            $out .= substr($html, $offset, $bounds['open_end'] - $offset) . $token
                . substr($html, $bounds['close_start'], $bounds['close_end'] - $bounds['close_start']);
            $offset = $bounds['close_end'];
        }
        return $out . substr($html, $offset);
    }
}
