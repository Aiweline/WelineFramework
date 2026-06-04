<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * Contract style patch for font/CTA quality gates.
 */
final class AiSiteContractStylePatchService
{
    /**
     * @param array<string, mixed> $designTokens
     */
    public function patchHardcodedFonts(string $css, array $designTokens): string
    {
        if ($css === '') {
            return $css;
        }

        $display = 'var(--pb-font-display)';
        $body = 'var(--pb-font-body)';

        $css = (string)\preg_replace(
            '/font-family\s*:\s*[^;}]*\b(?:Inter|Roboto|Arial|Helvetica|system-ui|-apple-system|Segoe UI)\b[^;}]*/iu',
            'font-family:' . $display,
            $css
        );

        if (\preg_match('/font-family\s*:/iu', $css) !== 1) {
            $css .= '#componentId .pb-c-root{font-family:' . $body . ';}';
            $css .= '#componentId .pb-c-title{font-family:' . $display . ';}';
        }

        return $css;
    }

    /**
     * @param list<string> $ctaLexicon
     */
    public function patchCtaLexicon(string $html, array $ctaLexicon): string
    {
        if ($html === '' || $ctaLexicon === []) {
            return $html;
        }

        $target = (string)$ctaLexicon[0];

        return (string)\preg_replace_callback(
            '/(<(?:a|button)[^>]*>)(.*?)(<\/(?:a|button)>)/is',
            static function (array $matches) use ($target): string {
                $inner = \trim(\strip_tags((string)$matches[2]));
                if ($inner === '' || \mb_strlen($inner) > 32) {
                    return $matches[0];
                }

                return $matches[1] . \htmlspecialchars($target, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . $matches[3];
            },
            $html,
            3
        );
    }
}
