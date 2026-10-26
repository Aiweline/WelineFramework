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
                'id' => 'diffbot',
                'name' => 'Diffbot',
                'pattern' => '/Diffbot/i',
                'category' => 'scraper',
                'reason' => 'Commercial page extraction bot',
            ],
            [
                'id' => 'serpstatbot',
                'name' => 'SerpstatBot',
                'pattern' => '/SerpstatBot/i',
                'category' => 'seo',
                'reason' => 'SEO backlink crawler; no organic referrals',
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
            ['id' => 'weline_warmup', 'name' => 'WelineStorefrontWarmup', 'pattern' => '/WelineStorefrontWarmup/i', 'category' => 'internal', 'reason' => 'Framework storefront cache warmup (internal)'],
            ['id' => 'google_inspection', 'name' => 'Google-InspectionTool', 'pattern' => '/Google-InspectionTool/i', 'category' => 'search', 'reason' => 'GSC URL inspection / fetch verification'],
            ['id' => 'googleother', 'name' => 'GoogleOther', 'pattern' => '/GoogleOther/i', 'category' => 'search', 'reason' => 'Google production crawler (GSC crawl stats)'],
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
            ['id' => 'baiduspider', 'name' => 'Baiduspider', 'pattern' => '/Baiduspider|Baidu[\\w\\-]*spider|\\+http:\\/\\/www\\.baidu\\.com\\/search\\/spider\\.htm/i', 'category' => 'search', 'reason' => 'Baidu organic search (all variants incl. image/ads/mobile)'],
            ['id' => 'sogou', 'name' => 'Sogou', 'pattern' => '/Sogou|Sogou [A-Za-z]+ spider|\\+http:\\/\\/www\\.sogou\\.com\\/docs\\/webmasters\\.htm/i', 'category' => 'search', 'reason' => 'Sogou organic search traffic'],
            ['id' => 'spider360', 'name' => '360Spider', 'pattern' => '/360Spider|HaoSouSpider|\\+http:\\/\\/www\\.so\\.com/i', 'category' => 'search', 'reason' => '360 / Haosou organic search traffic'],
            ['id' => 'yisou', 'name' => 'YisouSpider (Shenma)', 'pattern' => '/YisouSpider|Shenma|\\+http:\\/\\/www\\.yisou\\.com/i', 'category' => 'search', 'reason' => 'Shenma (Yisou) mobile search traffic'],
            ['id' => 'toutiao_byte', 'name' => 'ByteDance / Toutiao', 'pattern' => '/bytedance|Toutiao(?:Spider|Bot)|zhanzhang\\.toutiao\\.com|Douyin(?:Bot|Spider)/i', 'category' => 'search', 'reason' => 'ByteDance Toutiao / Douyin crawl and search traffic'],
            ['id' => 'applebot', 'name' => 'Applebot', 'pattern' => '/Applebot(?!-Extended)/i', 'category' => 'search', 'reason' => 'Organic search traffic'],
            ['id' => 'msn_media', 'name' => 'MSN / msnbot-media', 'pattern' => '/msnbot|MSNBot|BingPreview/i', 'category' => 'search', 'reason' => 'MSN/Bing media and preview crawlers'],
            ['id' => 'slurp', 'name' => 'Slurp', 'pattern' => '/Slurp/i', 'category' => 'search', 'reason' => 'Yahoo search traffic'],
            ['id' => 'facebookexternalhit', 'name' => 'facebookexternalhit', 'pattern' => '/facebookexternalhit/i', 'category' => 'social', 'reason' => 'Social preview referrals'],
            ['id' => 'twitterbot', 'name' => 'Twitterbot', 'pattern' => '/Twitterbot/i', 'category' => 'social', 'reason' => 'Social preview referrals'],
            ['id' => 'linkedinbot', 'name' => 'LinkedInBot', 'pattern' => '/LinkedInBot/i', 'category' => 'social', 'reason' => 'Social preview referrals'],
            ['id' => 'pinterestbot', 'name' => 'Pinterestbot', 'pattern' => '/Pinterestbot/i', 'category' => 'social', 'reason' => 'Social preview referrals'],
            ['id' => 'seznambot', 'name' => 'SeznamBot', 'pattern' => '/SeznamBot/i', 'category' => 'search', 'reason' => 'Seznam.cz organic search traffic'],
            ['id' => 'mojeekbot', 'name' => 'MojeekBot', 'pattern' => '/MojeekBot/i', 'category' => 'search', 'reason' => 'Mojeek organic search traffic'],
            ['id' => 'bravebot', 'name' => 'Bravebot', 'pattern' => '/Bravebot/i', 'category' => 'search', 'reason' => 'Brave Search organic traffic'],
            ['id' => 'naverbot', 'name' => 'NaverBot/Yeti', 'pattern' => '/NaverBot|Yeti\//i', 'category' => 'search', 'reason' => 'Naver organic search traffic'],
            ['id' => 'petalbot', 'name' => 'PetalBot', 'pattern' => '/PetalBot/i', 'category' => 'search', 'reason' => 'Huawei Petal Search organic traffic'],
            ['id' => 'openai_crawlers', 'name' => 'OpenAI (GPTBot / OAI-SearchBot / ChatGPT-User)', 'pattern' => '/GPTBot|OAI-SearchBot|ChatGPT-User/i', 'category' => 'ai', 'reason' => 'OpenAI crawl, search and browsing agents'],
            ['id' => 'anthropic_crawlers', 'name' => 'Anthropic (ClaudeBot / Claude-User / Claude-Web)', 'pattern' => '/ClaudeBot|Claude-User|Claude-Web|anthropic-ai/i', 'category' => 'ai', 'reason' => 'Anthropic crawl, search and browsing agents'],
            ['id' => 'google_ai', 'name' => 'Google AI (Google-Extended / Vertex)', 'pattern' => '/Google-Extended|Google-CloudVertexBot/i', 'category' => 'ai', 'reason' => 'Google AI training and agent crawlers'],
            ['id' => 'applebot_extended', 'name' => 'Applebot-Extended', 'pattern' => '/Applebot-Extended/i', 'category' => 'ai', 'reason' => 'Apple AI training crawler'],
            ['id' => 'bytespider', 'name' => 'Bytespider', 'pattern' => '/Bytespider/i', 'category' => 'ai', 'reason' => 'ByteDance AI crawler'],
            ['id' => 'ccbot', 'name' => 'CCBot', 'pattern' => '/CCBot/i', 'category' => 'ai', 'reason' => 'Common Crawl open dataset crawler'],
            ['id' => 'amazonbot', 'name' => 'Amazonbot', 'pattern' => '/Amazonbot/i', 'category' => 'ai', 'reason' => 'Amazon AI / Alexa crawler'],
            ['id' => 'meta_ai', 'name' => 'Meta AI agents', 'pattern' => '/Meta-ExternalAgent|Meta-ExternalFetcher|Meta-WebIndexer|FacebookBot/i', 'category' => 'ai', 'reason' => 'Meta AI crawl and index agents'],
            ['id' => 'perplexity', 'name' => 'PerplexityBot / Perplexity-User', 'pattern' => '/PerplexityBot|Perplexity-User/i', 'category' => 'ai', 'reason' => 'Perplexity answer engine crawlers'],
            ['id' => 'youbot', 'name' => 'YouBot', 'pattern' => '/YouBot/i', 'category' => 'ai', 'reason' => 'you.com AI crawler'],
            ['id' => 'imagesiftbot', 'name' => 'ImagesiftBot', 'pattern' => '/ImagesiftBot/i', 'category' => 'ai', 'reason' => 'Hive AI image search crawler'],
            ['id' => 'cohere', 'name' => 'cohere-ai', 'pattern' => '/cohere-ai/i', 'category' => 'ai', 'reason' => 'Cohere AI crawler'],
            ['id' => 'mistral', 'name' => 'MistralAI', 'pattern' => '/MistralAI/i', 'category' => 'ai', 'reason' => 'Mistral AI crawler'],
            ['id' => 'duckassistbot', 'name' => 'DuckAssistBot', 'pattern' => '/DuckAssistBot/i', 'category' => 'ai', 'reason' => 'DuckDuckGo AI assist crawler'],
            ['id' => 'kimi_moonshot', 'name' => 'Kimi / MoonshotAI', 'pattern' => '/KimiBot|MoonshotAI|Moonshot/i', 'category' => 'ai', 'reason' => 'Kimi (Moonshot AI) crawl agents'],
            ['id' => 'deepseek', 'name' => 'DeepSeek', 'pattern' => '/DeepSeek/i', 'category' => 'ai', 'reason' => 'DeepSeek AI crawler'],
            ['id' => 'zhipu', 'name' => 'Zhipu / ChatGLM', 'pattern' => '/Zhipu|ChatGLM/i', 'category' => 'ai', 'reason' => 'Zhipu AI (ChatGLM) crawler'],
            ['id' => 'minimax', 'name' => 'MiniMax', 'pattern' => '/MiniMax/i', 'category' => 'ai', 'reason' => 'MiniMax AI crawler'],
            ['id' => 'tencent_yuanbao', 'name' => 'Tencent Yuanbao / Hunyuan', 'pattern' => '/YuanbaoBot|Hunyuan/i', 'category' => 'ai', 'reason' => 'Tencent Yuanbao / Hunyuan AI crawler'],
            ['id' => 'iflytek_spark', 'name' => 'iFlytek Spark', 'pattern' => '/iFlytek|SparkBot/i', 'category' => 'ai', 'reason' => 'iFlytek Spark AI crawler'],
            ['id' => 'quark', 'name' => 'QuarkBot', 'pattern' => '/QuarkBot|Quark-Scan/i', 'category' => 'ai', 'reason' => 'Alibaba Quark AI search crawler'],
            ['id' => 'yi_01ai', 'name' => 'Yi (01.AI)', 'pattern' => '/Yi\\-AI|01-ai/i', 'category' => 'ai', 'reason' => '01.AI Yi crawler'],
            ['id' => 'ai2_bot', 'name' => 'AI2Bot (AllenAI)', 'pattern' => '/AI2Bot|AllenAI/i', 'category' => 'ai', 'reason' => 'Allen Institute for AI crawler'],
            ['id' => 'tencent_us', 'name' => 'Tencent / TensparTS', 'pattern' => '/TensparTS|Tencent.*[Cc]rawler/i', 'category' => 'ai', 'reason' => 'Tencent AI research crawlers'],
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
     * True when UA is a crawler-policy allow pass (SEO / site allow entries).
     *
     * Hard floor: built-in friendly search/social allowlist always passes, even when
     * a website publishes explicit `entries` (prevents accidental SEO shared_ban).
     * Site `action=allow` entries also pass. Used by WorkerPolicyKernel to skip
     * shared_ban / path_scan IP bans — not an Origin-Token or attack-deny bypass.
     *
     * @param array<string, mixed> $rule
     */
    public static function isAllowPass(string $ua, array $rule = []): bool
    {
        $ua = \trim($ua);
        if ($ua === '') {
            return false;
        }

        foreach (self::allowlistPatterns() as $allow) {
            if (@\preg_match($allow, $ua) === 1) {
                return true;
            }
        }

        if ($rule === []) {
            return false;
        }

        $resolved = self::resolveEntries($rule);
        foreach ($resolved as $entry) {
            if (($entry['action'] ?? 'block') !== 'allow') {
                continue;
            }
            if (@\preg_match((string)$entry['pattern'], $ua) === 1) {
                return true;
            }
        }

        return false;
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

        // Allow pass ⇒ not a crawler_block hit (same semantics as legacy floor).
        if (self::isAllowPass($ua, $rule)) {
            return null;
        }

        $resolved = self::resolveEntries($rule);
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
