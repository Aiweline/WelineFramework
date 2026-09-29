<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeResourceCatalog;

/**
 * Bake layout document shell (DOCTYPE/html/head/body) into solidified shell.phtml.
 *
 * Freeze: solidify-document-shell-fix/meetings/architect-freeze.md §3–§5.
 * Does NOT bake Partials header/footer (chrome.phtml owns those).
 */
final class ThemeLayoutEntityDocumentShellBaker
{
    /**
     * v4: document shell keeps non-widget Taglib (w:hook / Partials blocks) as source markup.
     * Only slot↔widget relationships belong to Materializer page body — do not bake hooks to getHook().
     */
    public const ALGO_VERSION = 'docshell.v4';

    /**
     * @return array{
     *     preamble: string,
     *     postamble: string,
     *     fingerprint: string,
     *     source_path: string
     * }
     */
    public function bakeForPageType(
        string $pageType,
        string $area = 'frontend',
        string $layoutOption = 'default',
        ?int $themeId = null,
    ): array {
        $sourcePath = $this->resolveLayoutAbsolutePath($pageType, $area, $layoutOption, $themeId);
        $source = \file_get_contents($sourcePath);
        if (!\is_string($source) || \trim($source) === '') {
            throw new \RuntimeException('document_shell_layout_empty: ' . $sourcePath);
        }

        $segments = $this->bakeFromSource($source, $sourcePath);
        $segments['source_path'] = $sourcePath;

        return $segments;
    }

    /**
     * @return array{
     *     preamble: string,
     *     postamble: string,
     *     fingerprint: string,
     *     source_path: string
     * }
     */
    public function bakeFromSource(string $source, string $sourcePath = ''): array
    {
        $doctypePos = \stripos($source, '<!DOCTYPE');
        if ($doctypePos === false) {
            throw new \RuntimeException('document_shell_missing_doctype: ' . ($sourcePath !== '' ? $sourcePath : '(memory)'));
        }
        $doc = \substr($source, $doctypePos);

        if (!\preg_match('#^(.*?)</head>#is', $doc, $headMatch)) {
            throw new \RuntimeException('document_shell_missing_head: ' . ($sourcePath !== '' ? $sourcePath : '(memory)'));
        }
        $throughHead = $headMatch[0];
        $rest = \substr($doc, \strlen($headMatch[0]));

        [$bodyOpenRaw, $afterBody] = $this->splitBodyOpen($rest);
        $bodyOpen = $this->sanitizeBodyOpenTag($bodyOpenRaw);

        [$leading, $wrapperOpen] = $this->extractLeadingStructure($afterBody);
        $trailing = $this->extractTrailingStructure($afterBody, $wrapperOpen !== '');

        $preamble = $this->rewriteDocumentShellMarkup(
            \rtrim($throughHead) . "\n" . \trim($bodyOpen) . "\n" . $leading . ($wrapperOpen !== '' ? $wrapperOpen . "\n" : '')
        );
        $postamble = $this->rewriteDocumentShellMarkup($trailing);

        $preamble = $this->assertExecutableShellMarkup($preamble, 'preamble');
        $postamble = $this->assertExecutableShellMarkup($postamble, 'postamble');

        if (!\preg_match('#<!DOCTYPE\b#i', $preamble) || !\preg_match('#<html\b#i', $preamble)) {
            throw new \RuntimeException('document_shell_bake_lost_html_root');
        }
        if (!\preg_match('#</body>#i', $postamble) || !\preg_match('#</html>#i', $postamble)) {
            throw new \RuntimeException('document_shell_bake_lost_html_close');
        }

        $mtime = ($sourcePath !== '' && \is_file($sourcePath)) ? (int)(@\filemtime($sourcePath) ?: 0) : 0;
        $fingerprint = \hash('sha256', \implode("\0", [
            self::ALGO_VERSION,
            $sourcePath,
            (string)$mtime,
            \hash('sha256', $source),
        ]));

        return [
            'preamble' => $preamble,
            'postamble' => $postamble,
            'fingerprint' => $fingerprint,
            'source_path' => $sourcePath,
        ];
    }

    /**
     * Assemble executable shell body.
     *
     * docshell.v3 storefront order (architect-freeze §3.1 interleaved enhancement):
     * preamble → Partials header → page body → Partials footer → postamble.
     * Raw chrome.phtml slot dump before page body put footer above main (empty homepage).
     * chromeIncludePhp is retained as a non-echoing relationship bind (path assign only).
     */
    public function wrap(string $preamble, string $chromeIncludePhp, string $pageBody, string $postamble): string
    {
        $pageBody = \ltrim($pageBody);
        $chromeBindPhp = $this->chromeRelationshipBindPhp($chromeIncludePhp);
        $headerPhp = $this->partialsRenderPhp('header');
        $footerPhp = $this->partialsRenderPhp('footer');

        return \rtrim($preamble) . "\n"
            . ($chromeBindPhp !== '' ? $chromeBindPhp . "\n" : '')
            . $headerPhp
            . $pageBody
            . (\str_ends_with($pageBody, "\n") ? '' : "\n")
            . $footerPhp
            . \ltrim($postamble)
            . (\str_ends_with($postamble, "\n") ? '' : "\n");
    }

    private function partialsRenderPhp(string $type): string
    {
        $type = \strtolower(\trim($type));
        if ($type !== 'header' && $type !== 'footer') {
            return '';
        }

        return "<?= \\Weline\\Framework\\Manager\\ObjectManager::getInstance("
            . "\\Weline\\Theme\\Block\\Partials::class)"
            . "->renderPartials('frontend', '" . $type . "', [], 'default') ?>\n";
    }

    /**
     * Keep same-identity chrome.phtml path in the shell for binding/audit without
     * echoing chrome slots (those dump footer before main).
     */
    private function chromeRelationshipBindPhp(string $chromeIncludePhp): string
    {
        $chromeIncludePhp = \trim($chromeIncludePhp);
        if ($chromeIncludePhp === '') {
            return '';
        }
        if (!\preg_match('/\$__chromePath\s*=\s*(.+?);/s', $chromeIncludePhp, $m)) {
            return "<?php\n"
                . "/* docshell.v3: chrome.phtml relationship bind (no raw slot echo). */\n"
                . "?>";
        }
        $assign = \trim($m[1]);

        return "<?php\n"
            . "/* docshell.v3: same-identity chrome.phtml relationship artifact — "
            . "header/footer via Partials (interleaved); do not include-echo chrome slots. */\n"
            . "\$__chromePath = {$assign};\n"
            . "?>";
    }

    public function fingerprintForPageType(
        string $pageType,
        string $area = 'frontend',
        string $layoutOption = 'default',
        ?int $themeId = null,
    ): string {
        try {
            return $this->bakeForPageType($pageType, $area, $layoutOption, $themeId)['fingerprint'];
        } catch (\Throwable) {
            return \hash('sha256', self::ALGO_VERSION . "\0" . \trim($pageType) . "\0missing");
        }
    }

    public function resolveLayoutAbsolutePath(
        string $pageType,
        string $area = 'frontend',
        string $layoutOption = 'default',
        ?int $themeId = null,
    ): string {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            $pageType = 'default';
        }
        $layoutOption = \trim($layoutOption) !== '' ? \trim($layoutOption) : 'default';
        $area = \strtolower(\trim($area)) !== '' ? \strtolower(\trim($area)) : 'frontend';
        $theme = $this->loadTheme($themeId, $area);

        $candidates = [
            [$pageType, $layoutOption],
        ];
        if ($layoutOption !== 'default') {
            $candidates[] = [$pageType, 'default'];
        }
        if ($pageType !== 'default') {
            $candidates[] = ['default', 'default'];
        }

        foreach ($candidates as [$type, $option]) {
            $path = $this->resolveOneLayoutPath($type, $option, $area, $theme);
            if ($path !== null) {
                return $path;
            }
        }

        throw new \RuntimeException(
            'document_shell_layout_not_found: type=' . $pageType . ' option=' . $layoutOption . ' area=' . $area
        );
    }

    private function resolveOneLayoutPath(string $layoutType, string $option, string $area, WelineTheme $theme): ?string
    {
        try {
            /** @var ThemeResourceCatalog $catalog */
            $catalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
            $resource = $catalog->getLayoutResource($area, $theme, $layoutType, $option);
            if (\is_array($resource)) {
                $filePath = (string)($resource['file_path'] ?? '');
                if ($filePath !== '' && \is_file($filePath)) {
                    return $filePath;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        $layoutPath = 'theme' . \DIRECTORY_SEPARATOR . $area . \DIRECTORY_SEPARATOR . 'layouts'
            . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $layoutType)
            . \DIRECTORY_SEPARATOR . $option . '.phtml';

        try {
            $modulePath = LayoutPathResolver::resolveLayoutTemplate($layoutPath, $theme, $area);
            if (\is_string($modulePath) && $modulePath !== '') {
                $abs = LayoutPathResolver::getLayoutFilePath($modulePath, $theme, $area);
                if (\is_string($abs) && $abs !== '' && \is_file($abs)) {
                    return $abs;
                }
            }
        } catch (\Throwable) {
            // continue
        }

        $defaultPath = LayoutPathResolver::getDefaultLayoutPath($layoutPath, $area);
        if (\is_string($defaultPath) && $defaultPath !== '' && \is_file($defaultPath)) {
            return $defaultPath;
        }

        return null;
    }

    private function loadTheme(?int $themeId, string $area): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        if ($themeId !== null && $themeId > 0) {
            try {
                $theme->clearData()->clearQuery()->load($themeId);
                if ((int)$theme->getId() === $themeId) {
                    return $theme;
                }
            } catch (\Throwable) {
                // fall through to active
            }
        }
        try {
            return $theme->clearData()->clearQuery()->getActiveTheme($area);
        } catch (\Throwable) {
            return $theme;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function extractLeadingStructure(string $afterBody): array
    {
        $leading = '';
        $wrapperOpen = '';
        $cursor = 0;
        $len = \strlen($afterBody);

        while ($cursor < $len) {
            if (\preg_match('/\G\s+/', $afterBody, $ws, 0, $cursor) === 1) {
                $leading .= $ws[0];
                $cursor += \strlen($ws[0]);
                continue;
            }
            if (\preg_match('/\G<!--.*?-->/s', $afterBody, $comment, 0, $cursor) === 1) {
                $cursor += \strlen($comment[0]);
                continue;
            }
            if (\preg_match('#\G<w:hook>([^<]*)</w:hook>#i', $afterBody, $hook, 0, $cursor) === 1) {
                $leading .= $hook[0];
                $cursor += \strlen($hook[0]);
                continue;
            }
            if ($wrapperOpen === ''
                && \preg_match(
                    '#\G<div\b[^>]*class=["\'][^"\']*(?:weline-page-wrapper|w-frontend-shell)[^"\']*["\'][^>]*>#i',
                    $afterBody,
                    $div,
                    0,
                    $cursor,
                ) === 1
            ) {
                $wrapperOpen = $div[0];
                $cursor += \strlen($div[0]);
                break;
            }
            break;
        }

        return [\rtrim($leading), $wrapperOpen];
    }

    private function extractTrailingStructure(string $afterBody, bool $closeWrapper): string
    {
        if (!\preg_match('#^(.*)</body>\s*</html>\s*\z#is', $afterBody, $m)) {
            if (!\preg_match('#^(.*)</body>#is', $afterBody, $m2)) {
                throw new \RuntimeException('document_shell_missing_body_close');
            }
            $beforeBodyClose = $m2[1];
            $htmlClose = "</html>\n";
        } else {
            $beforeBodyClose = $m[1];
            $htmlClose = "</html>\n";
        }

        $endHooks = '';
        $scan = $beforeBodyClose;
        $cutMarkers = [
            'type="footer"',
            "type='footer'",
            '</main>',
            '</Main>',
        ];
        $cutAt = -1;
        foreach ($cutMarkers as $marker) {
            $pos = \strripos($scan, $marker);
            if ($pos !== false && $pos > $cutAt) {
                $cutAt = $pos;
            }
        }
        $tailRegion = $cutAt >= 0 ? \substr($scan, $cutAt) : $scan;
        if (\preg_match_all('#<w:hook>([^<]*)</w:hook>#i', $tailRegion, $hooks) > 0) {
            foreach ($hooks[0] as $i => $full) {
                $name = \trim((string)($hooks[1][$i] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (\preg_match('#body-end$|^seo::footer$#i', $name) === 1) {
                    $endHooks .= $full . "\n";
                }
            }
        }
        if ($endHooks === '' && \preg_match_all('#<w:hook>([^<]*)</w:hook>#i', $beforeBodyClose, $allHooks) > 0) {
            foreach ($allHooks[0] as $i => $full) {
                $name = \trim((string)($allHooks[1][$i] ?? ''));
                if (\preg_match('#body-end$|^seo::footer$#i', $name) === 1) {
                    $endHooks .= $full . "\n";
                }
            }
        }

        // Keep layout page CSS (e.g. products-layout 2-col grid) that sits after
        // chrome and before </body>. Without this, solidified products shells lose
        // sidebar/grid rules and flatten to a full-width filter strip.
        $layoutStyles = '';
        if (\preg_match_all('#<style\b[^>]*>.*?</style>#is', $beforeBodyClose, $styleMatches) > 0) {
            foreach ($styleMatches[0] as $styleBlock) {
                if (!\is_string($styleBlock) || \trim($styleBlock) === '') {
                    continue;
                }
                // Prefer catalog layout tokens; skip empty shells.
                if (\preg_match('/products-layout|category-layout|weline-page-wrapper/i', $styleBlock) !== 1) {
                    continue;
                }
                $layoutStyles .= \trim($styleBlock) . "\n";
            }
        }

        $parts = [];
        if ($closeWrapper) {
            $parts[] = '</div>';
        }
        if ($layoutStyles !== '') {
            $parts[] = \rtrim($layoutStyles);
        }
        if ($endHooks !== '') {
            $parts[] = \rtrim($endHooks);
        }
        $parts[] = '</body>';
        $parts[] = \rtrim($htmlClose);

        return \implode("\n", $parts) . "\n";
    }

    /**
     * Split "<body ...>" even when attributes contain "<?= ... ?>" (first ">" must not win).
     *
     * @return array{0: string, 1: string} [bodyOpenIncludingTag, afterBody]
     */
    private function splitBodyOpen(string $rest): array
    {
        if (!\preg_match('#^(\s*)<body\b#i', $rest, $m)) {
            throw new \RuntimeException('document_shell_missing_body');
        }
        $start = 0;
        $i = \strlen($m[0]);
        $len = \strlen($rest);
        $inPhp = false;
        while ($i < $len) {
            if (!$inPhp && \substr($rest, $i, 2) === '<?') {
                $inPhp = true;
                $i += 2;
                continue;
            }
            if ($inPhp && \substr($rest, $i, 2) === '?>') {
                $inPhp = false;
                $i += 2;
                continue;
            }
            if (!$inPhp && $rest[$i] === '>') {
                $i++;
                break;
            }
            $i++;
        }
        if ($i >= $len && ($len === 0 || $rest[$len - 1] !== '>')) {
            throw new \RuntimeException('document_shell_unclosed_body_tag');
        }

        return [\substr($rest, $start, $i - $start), \substr($rest, $i)];
    }

    private function sanitizeBodyOpenTag(string $bodyOpen): string
    {
        $bodyOpen = \preg_replace('#<\?=?.*?\?>#s', '', $bodyOpen) ?? $bodyOpen;
        $bodyOpen = \preg_replace('#\s{2,}#', ' ', $bodyOpen) ?? $bodyOpen;
        $bodyOpen = \preg_replace('#\s+>#', '>', $bodyOpen) ?? $bodyOpen;
        // Repair odd quotes left after stripping PHP in attributes.
        if (\substr_count($bodyOpen, '"') % 2 === 1) {
            $bodyOpen = \preg_replace('#>$#', '">', $bodyOpen) ?? ($bodyOpen . '"');
        }

        return \trim($bodyOpen);
    }

    /**
     * Shell fringe only strips page slot/widget trees (those belong in the body wrap).
     * Leave w:hook / Partials / other Taglib 原模原样 — solidify owns layout↔widget relations only.
     */
    private function rewriteDocumentShellMarkup(string $html): string
    {
        $html = \preg_replace('#<w:slot\b[^>]*>.*?</w:slot>#is', '', $html) ?? $html;
        $html = \preg_replace('#<w:widget\b[^>]*/?>#i', '', $html) ?? $html;

        return $html;
    }

    /** @internal */
    public static function phpRenderPartialsHead(string $tag): string
    {
        $area = 'frontend';
        if (\preg_match('#\barea=(["\'])([^"\']+)\1#i', $tag, $am) === 1) {
            $area = \trim($am[2]) !== '' ? \trim($am[2]) : 'frontend';
        }
        $option = 'default';
        if (\preg_match('#\bdefault-option=(["\'])([^"\']+)\1#i', $tag, $om) === 1) {
            $option = \trim($om[2]) !== '' ? \trim($om[2]) : 'default';
        }

        $open = '<' . '?= ';
        $close = ' ?' . '>';

        return $open
            . '\\Weline\\Framework\\Manager\\ObjectManager::getInstance('
            . '\\Weline\\Theme\\Block\\Partials::class)->renderPartials('
            . \var_export($area, true) . ', \'head\', [], '
            . \var_export($option, true) . ')'
            . $close;
    }

    /**
     * @deprecated Relation-only solidify keeps &lt;w:hook&gt; as source markup.
     *             Do not call from bake paths — retained only for accidental call-site detection in UT.
     */
    public static function phpGetHook(string $name): string
    {
        throw new \RuntimeException(
            'document_shell_forbid_hook_bake: keep <w:hook> source markup (relation-only solidify)'
        );
    }

    private function assertExecutableShellMarkup(string $html, string $label): string
    {
        // Hooks / Partials / other Taglib may remain as source markup (relation-only solidify).
        // Page body slots/widgets must not leak into shell fringe.
        if (\preg_match('#<w:(?:slot|widget)\b#i', $html) === 1) {
            throw new \RuntimeException('document_shell_taglib_residue:' . $label);
        }

        return $html;
    }
}
