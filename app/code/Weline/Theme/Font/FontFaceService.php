<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Font;

use Weline\Framework\App\State;

/**
 * Build @font-face CSS / link markup for a language-aware subset (or custom chars).
 */
class FontFaceService
{
    private FontSubsetService $subsetService;

    private FontDiscovery $discovery;

    public function __construct(
        ?FontSubsetService $subsetService = null,
        ?FontDiscovery $discovery = null
    ) {
        $this->subsetService = $subsetService ?? new FontSubsetService();
        $this->discovery = $discovery ?? new FontDiscovery();
    }

    /**
     * @param array{
     *   src:string,
     *   family?:string,
     *   lang?:string,
     *   chars?:string,
     *   weight?:string|int,
     *   style?:string,
     *   display?:string,
     *   unicode-range?:string
     * } $options
     */
    public function renderCss(array $options): string
    {
        $src = trim((string)($options['src'] ?? ''));
        if ($src === '') {
            return '';
        }

        $absolute = $this->discovery->resolveSource($src);
        if ($absolute === null) {
            return '/* theme:font source not found: ' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . ' */';
        }

        $chars = (string)($options['chars'] ?? '');
        if ($chars !== '') {
            $subsetPath = $this->subsetService->extractChars($absolute, $chars);
        } else {
            $lang = trim((string)($options['lang'] ?? ''));
            if ($lang === '') {
                $lang = State::getLangLocal();
            }
            $subsetPath = $this->subsetService->getSubsetPath($absolute, $lang);
        }

        $url = $this->subsetService->pathToUrl($subsetPath);
        $family = trim((string)($options['family'] ?? ''));
        if ($family === '') {
            $family = pathinfo($absolute, PATHINFO_FILENAME);
        }
        $weight = trim((string)($options['weight'] ?? '400'));
        $style = trim((string)($options['style'] ?? 'normal'));
        $display = trim((string)($options['display'] ?? 'swap'));
        $unicodeRange = trim((string)($options['unicode-range'] ?? $options['unicode_range'] ?? ''));

        $format = $this->guessFormat($subsetPath);
        $css = "@font-face{\n"
            . '  font-family:' . $this->cssString($family) . ";\n"
            . '  src:url(' . $this->cssString($url) . ') format(' . $this->cssString($format) . ");\n"
            . '  font-weight:' . $this->cssIdent($weight) . ";\n"
            . '  font-style:' . $this->cssIdent($style) . ";\n"
            . '  font-display:' . $this->cssIdent($display) . ";\n";
        if ($unicodeRange !== '') {
            $css .= '  unicode-range:' . $unicodeRange . ";\n";
        }
        $css .= '}';

        return $css;
    }

    /**
     * Wrap CSS in a <style> tag for template output.
     *
     * @param array<string,mixed> $options
     */
    public function renderStyleTag(array $options): string
    {
        try {
            $css = $this->renderCss($options);
        } catch (\Throwable $e) {
            return '<!-- theme:font subset failed: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ' -->';
        }
        if ($css === '' || str_starts_with($css, '/*')) {
            return $css === '' ? '' : '<!-- ' . trim($css, " \t\n\r\0\x0B/*") . ' -->';
        }

        return '<style data-weline-font="1">' . $css . '</style>';
    }

    private function guessFormat(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'woff2' => 'woff2',
            'woff' => 'woff',
            'otf' => 'opentype',
            default => 'truetype',
        };
    }

    private function cssString(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    private function cssIdent(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $value)) {
            return 'normal';
        }

        return $value;
    }
}
