<?php

declare(strict_types=1);

namespace Weline\Server\Security;

/**
 * Built-in parasitic crawler catalog for WLS crawler_block.
 *
 * These agents scrape content without referral / organic search traffic.
 * Friendly search engines stay allowlisted and are never matched here.
 */
final class CrawlerBlockCatalog
{
    /**
     * @return list<array{id:string,name:string,pattern:string,category:string,reason:string}>
     */
    public static function builtins(): array
    {
        return [
            [
                'id' => 'gptbot',
                'name' => 'GPTBot',
                'pattern' => '/GPTBot/i',
                'category' => 'ai_training',
                'reason' => 'OpenAI training crawler; no referral traffic',
            ],
            [
                'id' => 'chatgpt_user',
                'name' => 'ChatGPT-User',
                'pattern' => '/ChatGPT-User/i',
                'category' => 'ai_training',
                'reason' => 'ChatGPT browsing agent; scrapes without site referrals',
            ],
            [
                'id' => 'oai_searchbot',
                'name' => 'OAI-SearchBot',
                'pattern' => '/OAI-SearchBot/i',
                'category' => 'ai_training',
                'reason' => 'OpenAI search index bot; not a traffic-referring engine',
            ],
            [
                'id' => 'claudebot',
                'name' => 'ClaudeBot',
                'pattern' => '/ClaudeBot|anthropic-ai|Claude-Web/i',
                'category' => 'ai_training',
                'reason' => 'Anthropic training / browsing crawler',
            ],
            [
                'id' => 'google_extended',
                'name' => 'Google-Extended',
                'pattern' => '/Google-Extended/i',
                'category' => 'ai_training',
                'reason' => 'Gemini / Vertex training crawler; separate from Googlebot',
            ],
            [
                'id' => 'bytespider',
                'name' => 'Bytespider',
                'pattern' => '/Bytespider/i',
                'category' => 'ai_training',
                'reason' => 'ByteDance training crawler; heavy scrape, no referrals',
            ],
            [
                'id' => 'ccbot',
                'name' => 'CCBot',
                'pattern' => '/CCBot/i',
                'category' => 'dataset',
                'reason' => 'Common Crawl dataset scraper',
            ],
            [
                'id' => 'amazonbot',
                'name' => 'Amazonbot',
                'pattern' => '/Amazonbot/i',
                'category' => 'ai_training',
                'reason' => 'Amazon training / Alexa crawler',
            ],
            [
                'id' => 'meta_external',
                'name' => 'Meta-ExternalAgent',
                'pattern' => '/meta-externalagent|FacebookBot|meta-externalfetcher/i',
                'category' => 'ai_training',
                'reason' => 'Meta AI training / fetch agents',
            ],
            [
                'id' => 'perplexity',
                'name' => 'PerplexityBot',
                'pattern' => '/PerplexityBot|Perplexity-User/i',
                'category' => 'ai_training',
                'reason' => 'Perplexity answer scrapers',
            ],
            [
                'id' => 'diffbot',
                'name' => 'Diffbot',
                'pattern' => '/Diffbot/i',
                'category' => 'scraper',
                'reason' => 'Commercial page extraction bot',
            ],
            [
                'id' => 'semrush',
                'name' => 'SemrushBot',
                'pattern' => '/SemrushBot|SemrushBot-BA|SiteAuditBot/i',
                'category' => 'seo',
                'reason' => 'SEO audit crawler; no organic referrals',
            ],
            [
                'id' => 'ahrefs',
                'name' => 'AhrefsBot',
                'pattern' => '/AhrefsBot/i',
                'category' => 'seo',
                'reason' => 'SEO link graph crawler',
            ],
            [
                'id' => 'dotbot',
                'name' => 'DotBot',
                'pattern' => '/DotBot/i',
                'category' => 'seo',
                'reason' => 'Moz link explorer crawler',
            ],
            [
                'id' => 'mj12bot',
                'name' => 'MJ12bot',
                'pattern' => '/MJ12bot/i',
                'category' => 'seo',
                'reason' => 'Majestic SEO crawler',
            ],
            [
                'id' => 'petalbot',
                'name' => 'PetalBot',
                'pattern' => '/PetalBot/i',
                'category' => 'seo',
                'reason' => 'Huawei Petal search crawler; rarely refers traffic',
            ],
            [
                'id' => 'dataforseo',
                'name' => 'DataForSeoBot',
                'pattern' => '/DataForSeoBot|DataForSeo/i',
                'category' => 'seo',
                'reason' => 'SEO data marketplace crawler',
            ],
            [
                'id' => 'blexbot',
                'name' => 'BLEXBot',
                'pattern' => '/BLEXBot/i',
                'category' => 'seo',
                'reason' => 'WebMeUp SEO crawler',
            ],
            [
                'id' => 'mauibot',
                'name' => 'MauiBot',
                'pattern' => '/MauiBot/i',
                'category' => 'scraper',
                'reason' => 'Aggressive content scraper',
            ],
            [
                'id' => 'seekport',
                'name' => 'SeekportBot',
                'pattern' => '/Seekport|SeekportBot/i',
                'category' => 'seo',
                'reason' => 'Seekport indexer; negligible referrals',
            ],
            [
                'id' => 'scrapy',
                'name' => 'Scrapy',
                'pattern' => '/Scrapy/i',
                'category' => 'scraper',
                'reason' => 'Generic Scrapy framework UA',
            ],
            [
                'id' => 'python_requests',
                'name' => 'python-requests',
                'pattern' => '/python-requests\\/(?:2\\.|3\\.)/i',
                'category' => 'scraper',
                'reason' => 'Default python-requests library UA used by scrapers',
            ],
            [
                'id' => 'go_http',
                'name' => 'Go-http-client',
                'pattern' => '/Go-http-client\\//i',
                'category' => 'scraper',
                'reason' => 'Default Go net/http client UA used by scrapers',
            ],
            [
                'id' => 'curl',
                'name' => 'curl',
                'pattern' => '/\\bcurl\\/[0-9]/i',
                'category' => 'scraper',
                'reason' => 'Bare curl client scraping pages',
            ],
            [
                'id' => 'wget',
                'name' => 'wget',
                'pattern' => '/\\bWget\\//i',
                'category' => 'scraper',
                'reason' => 'wget mirror / scrape agent',
            ],
            [
                'id' => 'httpclient',
                'name' => 'Apache-HttpClient',
                'pattern' => '/Apache-HttpClient\\//i',
                'category' => 'scraper',
                'reason' => 'Java HttpClient default scrape UA',
            ],
            [
                'id' => 'java',
                'name' => 'Java/',
                'pattern' => '/^Java\\/[0-9]/i',
                'category' => 'scraper',
                'reason' => 'Bare Java runtime UA',
            ],
            [
                'id' => 'libwww',
                'name' => 'libwww-perl',
                'pattern' => '/libwww-perl/i',
                'category' => 'scraper',
                'reason' => 'Perl LWP scraper UA',
            ],
            [
                'id' => 'httrack',
                'name' => 'HTTrack',
                'pattern' => '/HTTrack|WebCopier|WebZIP|Offline Explorer/i',
                'category' => 'scraper',
                'reason' => 'Site mirroring / offline copy tools',
            ],
            [
                'id' => 'empty_ua',
                'name' => 'Empty UA',
                'pattern' => '/^\\s*$/',
                'category' => 'scraper',
                'reason' => 'Explicit empty User-Agent (often scrapers)',
            ],
        ];
    }

    /**
     * Friendly engines that must never be blocked by crawler_block (legacy global floor).
     *
     * @return list<string>
     */
    public static function allowlistPatterns(): array
    {
        $patterns = [];
        foreach (self::allowlistEntries() as $item) {
            $patterns[] = (string)$item['pattern'];
        }

        return $patterns;
    }

    /**
     * @return list<array{id:string,name:string,pattern:string,category:string,reason:string}>
     */
    public static function allowlistEntries(): array
    {
        return [
            ['id' => 'googlebot', 'name' => 'Googlebot', 'pattern' => '/Googlebot/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'googlebot_image', 'name' => 'Googlebot-Image', 'pattern' => '/Googlebot-Image/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'googlebot_news', 'name' => 'Googlebot-News', 'pattern' => '/Googlebot-News/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'googlebot_video', 'name' => 'Googlebot-Video', 'pattern' => '/Googlebot-Video/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'storebot_google', 'name' => 'Storebot-Google', 'pattern' => '/Storebot-Google/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'adsbot_google', 'name' => 'AdsBot-Google', 'pattern' => '/AdsBot-Google/i', 'category' => 'search', 'reason' => 'Ads quality crawler'],
            ['id' => 'mediapartners_google', 'name' => 'Mediapartners-Google', 'pattern' => '/Mediapartners-Google/i', 'category' => 'search', 'reason' => 'AdSense crawler'],
            ['id' => 'bingbot', 'name' => 'bingbot', 'pattern' => '/bingbot/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'bingpreview', 'name' => 'BingPreview', 'pattern' => '/BingPreview/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'duckduckbot', 'name' => 'DuckDuckBot', 'pattern' => '/DuckDuckBot/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'yandex', 'name' => 'Yandex', 'pattern' => '/Yandex(Bot|Images|Video|Favicons)?/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'baiduspider', 'name' => 'Baiduspider', 'pattern' => '/Baiduspider/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'sogou', 'name' => 'Sogou', 'pattern' => '/Sogou/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'spider360', 'name' => '360Spider', 'pattern' => '/360Spider/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'applebot', 'name' => 'Applebot', 'pattern' => '/Applebot(?!-Extended)/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'slurp', 'name' => 'Slurp', 'pattern' => '/Slurp/i', 'category' => 'search', 'reason' => 'Yahoo search traffic'],
            ['id' => 'facebookexternalhit', 'name' => 'facebookexternalhit', 'pattern' => '/facebookexternalhit/i', 'category' => 'social', 'reason' => 'Social preview referrals'],
            ['id' => 'twitterbot', 'name' => 'Twitterbot', 'pattern' => '/Twitterbot/i', 'category' => 'social', 'reason' => 'Social preview referrals'],
            ['id' => 'linkedinbot', 'name' => 'LinkedInBot', 'pattern' => '/LinkedInBot/i', 'category' => 'social', 'reason' => 'Social preview referrals'],
            ['id' => 'pinterestbot', 'name' => 'Pinterestbot', 'pattern' => '/Pinterestbot/i', 'category' => 'social', 'reason' => 'Social preview referrals'],
        ];
    }

    /**
     * Default website-managed entries: friendly allow + parasitic block.
     *
     * @return list<array{id:string,name:string,pattern:string,category:string,reason:string,action:string,enabled:bool,builtin:bool}>
     */
    public static function defaultWebsiteEntries(): array
    {
        $entries = [];
        foreach (self::allowlistEntries() as $item) {
            $entries[] = $item + [
                'action' => 'allow',
                'enabled' => true,
                'builtin' => true,
            ];
        }
        foreach (self::builtins() as $item) {
            $entries[] = $item + [
                'action' => 'block',
                'enabled' => true,
                'builtin' => true,
            ];
        }

        return $entries;
    }

    /**
     * Resolve effective match entries from a crawler_block rule blob.
     *
     * @param array<string, mixed> $rule
     * @return list<array{id:string,name:string,pattern:string,category:string,reason:string,builtin:bool,action:string}>
     */
    public static function resolveEntries(array $rule): array
    {
        if (\is_array($rule['entries'] ?? null) && $rule['entries'] !== []) {
            $normalized = [];
            foreach ($rule['entries'] as $item) {
                if (!\is_array($item) || !($item['enabled'] ?? true)) {
                    continue;
                }
                $pattern = \trim((string)($item['pattern'] ?? ''));
                if ($pattern === '') {
                    continue;
                }
                $id = \trim((string)($item['id'] ?? ''));
                if ($id === '') {
                    $id = 'custom-' . \substr(\hash('sha256', $pattern), 0, 12);
                }
                $action = \strtolower(\trim((string)($item['action'] ?? 'block')));
                if ($action !== 'allow') {
                    $action = 'block';
                }
                $normalized[] = [
                    'id' => $id,
                    'name' => \trim((string)($item['name'] ?? $id)),
                    'pattern' => $pattern,
                    'category' => \trim((string)($item['category'] ?? 'custom')),
                    'reason' => \trim((string)($item['reason'] ?? 'Custom crawler rule')),
                    'builtin' => (bool)($item['builtin'] ?? false),
                    'action' => $action,
                ];
            }

            return $normalized;
        }

        $disabled = [];
        foreach ((array)($rule['disabled_builtin_ids'] ?? []) as $id) {
            $id = \strtolower(\trim((string)$id));
            if ($id !== '') {
                $disabled[$id] = true;
            }
        }

        $entries = [];
        foreach (self::builtins() as $item) {
            $id = (string)$item['id'];
            if (isset($disabled[$id])) {
                continue;
            }
            $entries[] = $item + ['builtin' => true, 'action' => 'block'];
        }

        foreach ((array)($rule['custom_entries'] ?? []) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            if (!($item['enabled'] ?? true)) {
                continue;
            }
            $pattern = \trim((string)($item['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $id = \trim((string)($item['id'] ?? ''));
            if ($id === '') {
                $id = 'custom-' . \substr(\hash('sha256', $pattern), 0, 12);
            }
            $action = \strtolower(\trim((string)($item['action'] ?? 'block')));
            if ($action !== 'allow') {
                $action = 'block';
            }
            $entries[] = [
                'id' => $id,
                'name' => \trim((string)($item['name'] ?? $id)),
                'pattern' => $pattern,
                'category' => \trim((string)($item['category'] ?? 'custom')),
                'reason' => \trim((string)($item['reason'] ?? 'Custom crawler rule')),
                'builtin' => false,
                'action' => $action,
            ];
        }

        return $entries;
    }

    /**
     * Default AttackDetector rule blob (upgrade-safe: builtins live in catalog).
     *
     * @return array<string, mixed>
     */
    public static function defaultRule(): array
    {
        return [
            'enabled' => true,
            'block_duration' => 86400,
            'disabled_builtin_ids' => [],
            'custom_entries' => [],
        ];
    }

    /**
     * Website-owned rule blob with explicit allow/block entries.
     *
     * @return array<string, mixed>
     */
    public static function defaultWebsiteRule(): array
    {
        return [
            'enabled' => true,
            'block_duration' => 0,
            'entries' => self::defaultWebsiteEntries(),
        ];
    }

    /**
     * Match UA: allow entries win first, then block entries.
     * Legacy rules without `entries` keep the hard friendly allowlist floor.
     *
     * @param array<string, mixed> $rule
     * @return array{matched:bool,id:string,name:string,reason:string}|null
     */
    public static function match(string $ua, array $rule): ?array
    {
        if (!($rule['enabled'] ?? true)) {
            return null;
        }

        $hasExplicitEntries = \is_array($rule['entries'] ?? null) && $rule['entries'] !== [];
        if (!$hasExplicitEntries) {
            foreach (self::allowlistPatterns() as $allow) {
                if (@\preg_match($allow, $ua) === 1) {
                    return null;
                }
            }
        }

        $resolved = self::resolveEntries($rule);
        foreach ($resolved as $entry) {
            if (($entry['action'] ?? 'block') !== 'allow') {
                continue;
            }
            if (@\preg_match((string)$entry['pattern'], $ua) === 1) {
                return null;
            }
        }
        foreach ($resolved as $entry) {
            if (($entry['action'] ?? 'block') !== 'block') {
                continue;
            }
            if (@\preg_match((string)$entry['pattern'], $ua) === 1) {
                return [
                    'matched' => true,
                    'id' => (string)$entry['id'],
                    'name' => (string)$entry['name'],
                    'reason' => (string)$entry['reason'],
                ];
            }
        }

        return null;
    }
}
