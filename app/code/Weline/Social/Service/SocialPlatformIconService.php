<?php

declare(strict_types=1);

namespace Weline\Social\Service;

class SocialPlatformIconService
{
    /**
     * @var array<string, array{color: string, label: string}>
     */
    private const PLATFORM_META = [
        'facebook' => ['color' => '#1877f2', 'label' => 'f'],
        'youtube' => ['color' => '#ff0000', 'label' => 'YT'],
        'instagram' => ['color' => '#e4405f', 'label' => 'Ig'],
        'tiktok' => ['color' => '#010101', 'label' => 'Tk'],
        'whatsapp' => ['color' => '#25d366', 'label' => 'Wa'],
        'wechat' => ['color' => '#07c160', 'label' => 'Wx'],
        'x' => ['color' => '#000000', 'label' => 'X'],
        'linkedin' => ['color' => '#0a66c2', 'label' => 'in'],
        'pinterest' => ['color' => '#e60023', 'label' => 'P'],
        'snapchat' => ['color' => '#fffc00', 'label' => 'Sc'],
        'mastodon' => ['color' => '#6364ff', 'label' => 'M'],
        'tumblr' => ['color' => '#36465d', 'label' => 'T'],
        'wordpress' => ['color' => '#21759b', 'label' => 'W'],
        'ghost' => ['color' => '#15171a', 'label' => 'G'],
        'misskey' => ['color' => '#86b300', 'label' => 'Mi'],
        'lemmy' => ['color' => '#00a36c', 'label' => 'L'],
        'discourse' => ['color' => '#f2b705', 'label' => 'D'],
        'bluesky' => ['color' => '#1185fe', 'label' => 'B'],
        'telegram' => ['color' => '#229ed9', 'label' => 'Tg'],
        'discord' => ['color' => '#5865f2', 'label' => 'Di'],
        'line' => ['color' => '#06c755', 'label' => 'Li'],
        'viber' => ['color' => '#7360f2', 'label' => 'V'],
        'fake_browser' => ['color' => '#0f766e', 'label' => 'F'],
    ];

    public function enrichDefinition(array $definition): array
    {
        $code = (string)($definition['code'] ?? '');
        $definition['icon'] = (string)($definition['icon'] ?? $code);
        $definition['icon_svg'] = $this->getIconSvg($code, $definition);
        $definition['brand_color'] = $this->getBrandColor($code, $definition);

        return $definition;
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @return array<int, array<string, mixed>>
     */
    public function enrichDefinitions(array $definitions): array
    {
        $enriched = [];
        foreach ($definitions as $definition) {
            $enriched[] = \is_array($definition) ? $this->enrichDefinition($definition) : $definition;
        }

        return $enriched;
    }

    public function getIconSvg(string $platformCode, array $definition = []): string
    {
        $inlineSvg = (string)($definition['icon_svg'] ?? '');
        if ($inlineSvg !== '') {
            $sanitized = $this->sanitizeSvg($inlineSvg);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        $iconKey = $this->normalizeIconKey((string)($definition['icon'] ?? $platformCode));
        $file = BP . '/app/code/Weline/Social/view/statics/icons/social/' . $iconKey . '.svg';
        if ($iconKey !== '' && \is_file($file)) {
            $content = \file_get_contents($file);
            $sanitized = $this->sanitizeSvg(\is_string($content) ? $content : '');
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        return $this->buildGeneratedSvg($platformCode, $definition);
    }

    public function getBrandColor(string $platformCode, array $definition = []): string
    {
        $color = \trim((string)($definition['brand_color'] ?? ''));
        if ($this->isHexColor($color)) {
            return $color;
        }

        $code = \strtolower(\trim($platformCode));
        return self::PLATFORM_META[$code]['color'] ?? '#475569';
    }

    private function buildGeneratedSvg(string $platformCode, array $definition): string
    {
        $code = \strtolower(\trim($platformCode));
        $label = self::PLATFORM_META[$code]['label'] ?? $this->buildInitials($code);
        $color = $this->getBrandColor($code, $definition);
        $title = $this->escape((string)($definition['title'] ?? $platformCode));
        $label = $this->escape($label);

        return '<svg class="weline-social-platform-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" role="img" aria-label="' . $title . '">'
            . '<rect width="48" height="48" rx="10" fill="' . $color . '"/>'
            . '<circle cx="35" cy="13" r="5" fill="rgba(255,255,255,.28)"/>'
            . '<path d="M13 31c4-9 13-14 24-14-7 3-12 8-15 15 5-2 10-2 14 0-7 4-16 4-23-1Z" fill="rgba(255,255,255,.2)"/>'
            . '<text x="24" y="29" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" font-weight="700" fill="#fff">' . $label . '</text>'
            . '</svg>';
    }

    private function sanitizeSvg(string $svg): string
    {
        $svg = \trim($svg);
        if ($svg === '' || \stripos($svg, '<svg') === false) {
            return '';
        }
        if (\preg_match('/<(script|iframe|object|embed|foreignObject)\b/i', $svg) === 1) {
            return '';
        }

        $svg = (string)\preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg);
        $svg = (string)\preg_replace('/\s+(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', '', $svg);
        $svg = (string)\preg_replace('/<\?xml[^>]*>/i', '', $svg);
        if (\stripos($svg, '<svg') !== 0) {
            $start = \stripos($svg, '<svg');
            $svg = $start === false ? '' : \substr($svg, $start);
        }
        if ($svg !== '' && \stripos($svg, 'xmlns=') === false) {
            $svg = (string)\preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $svg, 1);
        }

        return $svg;
    }

    private function normalizeIconKey(string $value): string
    {
        $value = \strtolower(\trim($value));
        return \preg_match('/^[a-z0-9_-]{1,64}$/', $value) === 1 ? $value : '';
    }

    private function buildInitials(string $code): string
    {
        $parts = \array_values(\array_filter(\preg_split('/[^a-z0-9]+/', \strtolower($code)) ?: []));
        if ($parts === []) {
            return 'S';
        }
        $initials = '';
        foreach ($parts as $part) {
            $initials .= \strtoupper(\substr($part, 0, 1));
            if (\strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'S';
    }

    private function isHexColor(string $value): bool
    {
        return \preg_match('/^#[0-9a-f]{6}$/i', $value) === 1;
    }

    private function escape(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
