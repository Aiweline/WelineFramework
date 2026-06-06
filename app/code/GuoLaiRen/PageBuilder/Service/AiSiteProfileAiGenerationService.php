<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

class AiSiteProfileAiGenerationService
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public function generateProfile(array $context): array
    {
        $brief = \trim((string)($context['brief_description'] ?? ''));
        if ($brief === '') {
            return [];
        }

        $systemPrompt = $this->getSkillRegistry()->prependPromptGuide($this->buildSystemPrompt($context), 'profile');
        $userPrompt = $this->buildUserPrompt($context);

        $response = AiService::generateText(
            $userPrompt,
            null,
            null,
            null,
            [
                'system_message' => $systemPrompt,
                'temperature' => 0.35,
                'max_tokens' => 1800,
                'timeout' => 60,
            ]
        );

        $parser = ObjectManager::getInstance(AiResponseJsonParser::class);
        $decoded = $parser->extractAndDecode($response) ?? [];
        if ($decoded === []) {
            return [];
        }

        return $this->normalizeGeneratedPayload($decoded);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildSystemPrompt(array $context = []): string
    {
        $locale = \trim((string)($context['default_locale'] ?? ''));
        $localeRule = '';
        if ($locale !== '') {
            $localeRule = "\n- Primary locale is \"{$locale}\". All customer-facing strings (titles, taglines, descriptions, and meta fields) MUST be written in that language: zh_Hans_CN → Simplified Chinese; zh_Hant_TW → Traditional Chinese; en_US → English; ja_JP → Japanese; ko_KR → Korean; otherwise use the closest natural match for the locale code. Logo/icon SVG must not contain visible text in any language.";
        }

        return <<<PROMPT
You are a brand strategist, copywriter, and SVG logo designer for website creation.
Generate polished, customer-ready website profile data from the customer brief.

Return JSON only. Do not use markdown. Do not wrap in code fences.

Required JSON shape:
{
  "site_title": "string",
  "site_tagline": "string",
  "brief_description": "string",
  "meta_title": "string",
  "meta_description": "string",
  "meta_keywords": "comma separated keywords",
  "logo_svg": "<svg ...></svg>",
  "icon_svg": "<svg ...></svg>"
}

Example return shape (copy structure, not content; locked fields still win):
{
  "site_title": "Neon Table Club",
  "site_tagline": "Play clear neon card rooms",
  "brief_description": "Neon Table Club helps players choose card rooms, understand rules, view rewards, and reach support from one premium neon gaming site.",
  "meta_title": "Neon Table Club - Neon Card Rooms and Player Support",
  "meta_description": "Explore neon card rooms with clear rules, rewards, player proof, strategy guides, and fast support.",
  "meta_keywords": "neon card games, poker rooms, mahjong tables, player support",
  "logo_svg": "<svg width=\"160\" height=\"48\" viewBox=\"0 0 160 48\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M80 10 L102 24 L80 38 L58 24 Z\" fill=\"#f59e0b\"/><circle cx=\"80\" cy=\"24\" r=\"8\" fill=\"#0f172a\"/></svg>",
  "icon_svg": "<svg width=\"64\" height=\"64\" viewBox=\"0 0 64 64\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M32 8 L54 32 L32 56 L10 32 Z\" fill=\"#f59e0b\"/><circle cx=\"32\" cy=\"32\" r=\"9\" fill=\"#0f172a\"/></svg>"
}

Rules:
- Keep locked fields exactly if they are provided.
- If the customer brief clearly names a brand/site title, site_title MUST be exactly that compact brand name. Do not append a sentence, product description, locale rule, or SEO phrase to site_title.
- Create customer-facing content, not internal placeholders.
- Skills, design-direction names/codes, style-template names, adapter names, and tool labels are internal generation guidance only. Never use them as site_title, meta_title, tagline, description, keywords, or SVG text.
- If forbidden_visible_terms are provided in the input, those exact terms are banned from every customer-facing string. Rewrite them into visitor-facing business language instead of copying them.
- Site title should feel like a real brand or website name.
- Site tagline should be concise and marketable.
- Brief description should be 1-2 polished sentences suitable for preview/meta/brand usage.
- meta_title should be concise, meta_description should stay within roughly 160 characters.
- logo_svg must be a clean inline SVG with width 160 height 48 and viewBox "0 0 160 48". The SVG canvas must stay transparent and symbol-only: do not draw a full-width/full-height white, solid, gradient, card, tile, or rounded-rectangle background.
- icon_svg must be a clean inline SVG with width 64 height 64 and viewBox "0 0 64 64". The icon must be an isolated symbol on transparent canvas: do not draw a white box, colored square, rounded tile, gradient backdrop, badge card, screenshot frame, or background plate.
- SVG text ban (HARD): logo_svg and icon_svg must not contain <text>, <tspan>, readable letters, initials, monograms, site_title, brand names, slogans, prompt/contract words, JSON field names, or truncated requirement sentences.
- SVG must not contain script, foreignObject, animation, external URLs, or embedded raster images.
- Prefer simple geometric shapes, gradients, paths, circles, rectangles, and subject-derived pictorial symbols.{$localeRule}
PROMPT;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildUserPrompt(array $context): string
    {
        $forbiddenTerms = $this->normalizeForbiddenVisibleTerms($context['forbidden_visible_terms'] ?? []);
        $payload = [
            'customer_brief' => (string)($context['brief_description'] ?? ''),
            'target_domain' => (string)($context['target_domain'] ?? ''),
            'default_locale' => (string)($context['default_locale'] ?? ''),
            'locked_fields' => [
                'site_title' => (string)($context['locked_site_title'] ?? ''),
                'site_tagline' => (string)($context['locked_site_tagline'] ?? ''),
                'brief_description' => (string)($context['locked_brief_description'] ?? ''),
            ],
            'brand_direction' => [
                'generate_real_brand_copy' => true,
                'generate_logo_svg' => empty($context['locked_logo']),
                'generate_icon_svg' => empty($context['locked_icon']),
            ],
        ];
        if ($forbiddenTerms !== []) {
            $payload['forbidden_visible_terms'] = $forbiddenTerms;
        }

        return "Generate the website profile JSON from this input:\n"
            . \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    /**
     * @return list<string>
     */
    private function normalizeForbiddenVisibleTerms(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $terms = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $term = \trim((string)$item);
            $term = (string)\preg_replace('/\s+/u', ' ', $term);
            if ($term === '') {
                continue;
            }
            $length = \function_exists('mb_strlen') ? \mb_strlen($term) : \strlen($term);
            if ($length < 3 || $length > 80 || \in_array($term, $terms, true)) {
                continue;
            }
            $terms[] = $term;
            if (\count($terms) >= 20) {
                break;
            }
        }

        return $terms;
    }

    private function getSkillRegistry(): AiSiteSkillRegistry
    {
        return ObjectManager::getInstance(AiSiteSkillRegistry::class);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, string>
     */
    private function normalizeGeneratedPayload(array $decoded): array
    {
        $metaKeywords = $decoded['meta_keywords'] ?? '';
        if (\is_array($metaKeywords)) {
            $metaKeywords = \implode(', ', \array_values(\array_filter(\array_map(
                static fn(mixed $value): string => \is_scalar($value) ? \trim((string)$value) : '',
                $metaKeywords
            ))));
        }

        return [
            'site_title' => $this->sanitizeText($decoded['site_title'] ?? '', 42),
            'site_tagline' => $this->sanitizeText($decoded['site_tagline'] ?? '', 72),
            'brief_description' => $this->sanitizeText($decoded['brief_description'] ?? '', 220),
            'meta_title' => $this->sanitizeText($decoded['meta_title'] ?? '', 80),
            'meta_description' => $this->sanitizeText($decoded['meta_description'] ?? '', 180),
            'meta_keywords' => $this->sanitizeText($metaKeywords, 180),
            'logo_svg' => $this->sanitizeSvg((string)($decoded['logo_svg'] ?? ''), 160, 48),
            'icon_svg' => $this->sanitizeSvg((string)($decoded['icon_svg'] ?? ''), 64, 64),
        ];
    }

    private function sanitizeText(mixed $value, int $limit): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $text = \trim((string)$value);
        $text = (string)\preg_replace('/\s+/u', ' ', $text);
        $text = \trim($text, "\"' \t\n\r\0\x0B");
        if ($text === '') {
            return '';
        }

        if (\function_exists('mb_strlen') && \function_exists('mb_substr') && \mb_strlen($text) > $limit) {
            return \rtrim(\mb_substr($text, 0, $limit - 3)) . '...';
        }
        if (\strlen($text) > $limit) {
            return \rtrim(\substr($text, 0, $limit - 3)) . '...';
        }

        return $text;
    }

    private function sanitizeSvg(string $value, int $expectedWidth, int $expectedHeight): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\str_starts_with($value, 'data:image/svg+xml;base64,')) {
            $decoded = \base64_decode(\substr($value, 26), true);
            $value = \is_string($decoded) ? $decoded : '';
        }

        if ($value === '' || !\str_contains($value, '<svg') || !\str_contains($value, '</svg>')) {
            return '';
        }

        $normalized = \strtolower($value);
        $blockedPatterns = [
            '<script',
            '<foreignobject',
            '<iframe',
            '<object',
            '<embed',
            '<!doctype',
            '<!entity',
            'javascript:',
            'onload=',
            'onclick=',
            '<image',
            '<text',
            '<tspan',
            'xlink:href=',
            'href="http',
            'href=\'http',
            '<animate',
            '<set ',
        ];
        foreach ($blockedPatterns as $pattern) {
            if (\str_contains($normalized, $pattern)) {
                return '';
            }
        }

        if (!\preg_match('/<svg\b[^>]*viewBox="0 0 ' . $expectedWidth . ' ' . $expectedHeight . '"/i', $value)) {
            if (!\preg_match('/<svg\b[^>]*viewbox="0 0 ' . $expectedWidth . ' ' . $expectedHeight . '"/i', $value)) {
                return '';
            }
        }

        if (\strlen($value) > 12000) {
            return '';
        }

        if (\class_exists(\DOMDocument::class)) {
            $previousUseInternalErrors = \libxml_use_internal_errors(true);
            $document = new \DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadXML($value, \LIBXML_NONET | \LIBXML_NOWARNING | \LIBXML_NOERROR);
            $errors = \libxml_get_errors();
            \libxml_clear_errors();
            \libxml_use_internal_errors($previousUseInternalErrors);

            if (!$loaded || $errors !== []) {
                return '';
            }

            $root = $document->documentElement;
            if (!$root instanceof \DOMElement) {
                return '';
            }

            if (\strtolower($root->localName) !== 'svg') {
                return '';
            }

            $namespace = \trim((string)$root->namespaceURI);
            if ($namespace !== '' && $namespace !== 'http://www.w3.org/2000/svg') {
                return '';
            }
        }

        return $value;
    }
}
