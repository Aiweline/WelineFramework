<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Font;


use Weline\Framework\Env\WelineEnv;
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
        $resolved = $this->resolveSubset($options);
        if ($resolved === null) {
            $src = trim((string)($options['src'] ?? ''));
            if ($src === '') {
                return '';
            }

            return '/* theme:font source not found: ' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . ' */';
        }

        $url = $resolved['url'];
        $format = $resolved['format'];
        $family = trim((string)($options['family'] ?? ''));
        if ($family === '') {
            $family = $resolved['family'];
        }
        $weight = trim((string)($options['weight'] ?? '400'));
        $style = trim((string)($options['style'] ?? 'normal'));
        $display = trim((string)($options['display'] ?? 'swap'));
        $unicodeRange = trim((string)($options['unicode-range'] ?? $options['unicode_range'] ?? ''));

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
     * Emit a first-viewport font preload for the exact same subset URL renderCss() uses.
     *
     * Fonts are discovered late by the browser (only after the @font-face rule parses),
     * which shifts text on arrival. Preloading the one family/weight that paints the
     * first screen removes that round trip. Kept here (not in templates) so every theme
     * gets identical behaviour and the URL can never drift from the @font-face rule.
     *
     * @param array<string,mixed> $options same shape as renderCss()
     */
    public function renderPreloadLink(array $options): string
    {
        try {
            $resolved = $this->resolveSubset($options);
        } catch (\Throwable $e) {
            return '<!-- theme:font preload failed: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ' -->';
        }
        if ($resolved === null) {
            return '';
        }

        return '<link rel="preload" as="font" type="'
            . htmlspecialchars($this->mimeType($resolved['format']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" href="' . htmlspecialchars($resolved['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" crossorigin="anonymous" data-weline-font-preload="1">';
    }

    /**
     * @param array<string,mixed> $options
     * @return array{url:string,format:string,family:string}|null
     */
    private function resolveSubset(array $options): ?array
    {
        $src = trim((string)($options['src'] ?? ''));
        if ($src === '') {
            return null;
        }

        $absolute = $this->discovery->resolveSource($src);
        if ($absolute === null) {
            return null;
        }

        $chars = (string)($options['chars'] ?? '');
        if ($chars !== '') {
            $subsetPath = $this->subsetService->extractChars($absolute, $chars);
        } else {
            $lang = trim((string)($options['lang'] ?? ''));
            if ($lang === '') {
                $lang = WelineEnv::getLangLocal();
            }
            $subsetPath = $this->subsetService->getSubsetPath($absolute, $lang);
        }

        return [
            'url' => $this->subsetService->pathToUrl($subsetPath),
            'format' => $this->guessFormat($subsetPath),
            'family' => trim((string)($options['family'] ?? '')) !== ''
                ? trim((string)$options['family'])
                : pathinfo($absolute, PATHINFO_FILENAME),
        ];
    }

    private function mimeType(string $cssFormat): string
    {
        return match ($cssFormat) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'opentype' => 'font/otf',
            default => 'font/ttf',
        };
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
