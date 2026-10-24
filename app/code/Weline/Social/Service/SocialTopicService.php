<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;

class SocialTopicService
{
    public const DEFAULT_RATIO = ['site' => 3, 'web' => 1];
    public const MAX_COUNT = 100;

    private ?\Closure $siteProvider;
    private ?\Closure $webProvider;

    public function __construct(?callable $siteProvider = null, ?callable $webProvider = null)
    {
        $this->siteProvider = $siteProvider !== null ? \Closure::fromCallable($siteProvider) : null;
        $this->webProvider = $webProvider !== null ? \Closure::fromCallable($webProvider) : null;
    }

    /**
     * @param array<string, mixed> $ratio
     * @return array<int, array<string, mixed>>
     */
    public function pick(int $websiteId, array $ratio, int $count): array
    {
        $count = \max(0, \min(self::MAX_COUNT, $count));
        if ($count === 0) {
            return [];
        }

        $ratio = $this->normalizeRatio($ratio);
        [$siteQuota, $webQuota] = $this->splitQuota($count, $ratio);
        $siteItems = $this->siteProvider !== null
            ? $this->fetch($this->siteProvider, $websiteId, $count)
            : $this->defaultSiteTopics($websiteId, $count);
        $webItems = $this->webProvider !== null
            ? $this->fetch($this->webProvider, $websiteId, $count)
            : [];
        $sitePool = $this->normalizeSiteItems($siteItems);
        $webPool = $this->normalizeWebItems($webItems);

        $picked = [];
        $siteUsed = [];
        for ($i = 0; $i < $siteQuota && isset($sitePool[$i]); $i++) {
            $picked[] = $sitePool[$i];
            $siteUsed[$i] = true;
        }

        $webUsed = [];
        $webPicked = 0;
        for ($i = 0; $i < \count($webPool) && $webPicked < $webQuota; $i++) {
            $picked[] = $webPool[$i];
            $webUsed[$i] = true;
            $webPicked++;
        }

        for ($i = 0; $i < \count($sitePool) && $webPicked < $webQuota; $i++) {
            if (isset($siteUsed[$i])) {
                continue;
            }
            $entry = $sitePool[$i];
            $entry['fallback'] = true;
            $picked[] = $entry;
            $siteUsed[$i] = true;
            $webPicked++;
        }

        foreach ($webPool as $i => $entry) {
            if (\count($picked) >= $count) {
                break;
            }
            if (isset($webUsed[$i])) {
                continue;
            }
            $picked[] = $entry;
            $webUsed[$i] = true;
        }

        foreach ($sitePool as $i => $entry) {
            if (\count($picked) >= $count) {
                break;
            }
            if (isset($siteUsed[$i])) {
                continue;
            }
            $picked[] = $entry;
            $siteUsed[$i] = true;
        }

        return \array_slice($picked, 0, $count);
    }

    /**
     * 默认站内取材：优先最近创意草稿，草稿不可用时回落站内内容位。
     *
     * @return array<int, array<string, mixed>>
     */
    private function defaultSiteTopics(int $websiteId, int $count): array
    {
        $topics = [];
        $seen = [];
        // 框架未引导（纯函数单测/脚本）时不触库，直接用站内内容位兜底。
        if (\defined('BP') && \class_exists(SocialCreativeService::class)) {
            try {
                $drafts = ObjectManager::getInstance(SocialCreativeService::class)
                    ->listRecentDrafts(\max(5, \min(50, $count)));
                foreach ($drafts as $draft) {
                    if (!\is_array($draft)) {
                        continue;
                    }
                    $content = \trim((string)($draft['content'] ?? ''));
                    $title = \trim((string)($draft['title'] ?? ''));
                    if ($title === '') {
                        $title = $this->clip($content, 40);
                    }
                    if ($title === '' || isset($seen[$title])) {
                        continue;
                    }
                    $seen[$title] = true;
                    $topics[] = [
                        'title' => $title,
                        'url' => '',
                        'excerpt' => $this->clip($content, 120),
                    ];
                    if (\count($topics) >= $count) {
                        break;
                    }
                }
            } catch (\Throwable) {
                $topics = [];
            }
        }

        foreach ($this->siteFallbackTopics() as $topic) {
            if (\count($topics) >= $count) {
                break;
            }
            if (isset($seen[$topic['title']])) {
                continue;
            }
            $seen[$topic['title']] = true;
            $topics[] = $topic;
        }

        return $topics;
    }

    /**
     * @return array<int, array{title:string,url:string,excerpt:string}>
     */
    private function siteFallbackTopics(): array
    {
        $topics = [
            [
                'title' => '站内商品与分类导购',
                'url' => '',
                'excerpt' => '从本站商品与分类整理推荐理由、选购要点与常见搭配。',
            ],
            [
                'title' => '站内博客精选回顾',
                'url' => '',
                'excerpt' => '从本站博客挑选近期高价值文章做要点回顾与延伸阅读。',
            ],
            [
                'title' => '站内常见问题答疑',
                'url' => '',
                'excerpt' => '汇总本站售前、售后与使用流程中的高频问题做答疑内容。',
            ],
            [
                'title' => '站内服务与品牌介绍',
                'url' => '',
                'excerpt' => '介绍本站服务范围、交付流程、保障与联系方式。',
            ],
            [
                'title' => '站内新品与活动预告',
                'url' => '',
                'excerpt' => '整理本站新品、活动与上新节奏，形成可发布的预告内容。',
            ],
            [
                'title' => '站内案例与用户反馈',
                'url' => '',
                'excerpt' => '整理本站真实案例与服务反馈，沉淀可信的口碑内容。',
            ],
        ];

        foreach ($topics as $index => $topic) {
            $topics[$index]['title'] = $this->phrase($topic['title']);
            $topics[$index]['excerpt'] = $this->phrase($topic['excerpt']);
        }

        return $topics;
    }

    private function phrase(string $text): string
    {
        return \function_exists('__') ? (string)__($text) : $text;
    }

    private function clip(string $text, int $length): string
    {
        $text = \trim($text);
        if ($text === '') {
            return '';
        }

        return \function_exists('mb_substr')
            ? \mb_substr($text, 0, $length)
            : \substr($text, 0, $length);
    }

    /**
     * @param array<string, mixed> $ratio
     * @return array{site:int,web:int}
     */
    private function normalizeRatio(array $ratio): array
    {
        $site = isset($ratio['site']) ? \max(0, (int)$ratio['site']) : self::DEFAULT_RATIO['site'];
        $web = isset($ratio['web']) ? \max(0, (int)$ratio['web']) : self::DEFAULT_RATIO['web'];
        if ($site + $web <= 0) {
            return self::DEFAULT_RATIO;
        }

        return ['site' => $site, 'web' => $web];
    }

    /**
     * @param array{site:int,web:int} $ratio
     * @return array{0:int,1:int}
     */
    private function splitQuota(int $count, array $ratio): array
    {
        $total = $ratio['site'] + $ratio['web'];
        $siteQuota = (int)\round($count * $ratio['site'] / $total);
        $siteQuota = \max(0, \min($count, $siteQuota));

        return [$siteQuota, $count - $siteQuota];
    }

    /**
     * @return array<int, mixed>
     */
    private function fetch(\Closure $provider, int $websiteId, int $count): array
    {
        try {
            $items = $provider($websiteId, $count);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($items) ? $items : [];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSiteItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $title = $this->stringValue($item['title'] ?? ($item['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $normalized[] = [
                'source' => 'site',
                'title' => $title,
                'url' => $this->stringValue($item['url'] ?? ''),
                'excerpt' => $this->stringValue($item['excerpt'] ?? ($item['summary'] ?? ($item['description'] ?? ''))),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeWebItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $title = $this->stringValue($item['title'] ?? ($item['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $url = $this->stringValue($item['url'] ?? ($item['source_url'] ?? ''));
            $excerpt = $this->stringValue($item['excerpt'] ?? ($item['summary'] ?? ($item['description'] ?? '')));
            if ($url !== '' && $this->isHttpUrl($url)) {
                $normalized[] = [
                    'source' => 'web',
                    'title' => $title,
                    'url' => $url,
                    'excerpt' => $excerpt,
                ];
                continue;
            }
            $normalized[] = [
                'source' => 'site',
                'title' => $title,
                'url' => '',
                'excerpt' => $excerpt,
                'fallback' => true,
            ];
        }

        return $normalized;
    }

    private function stringValue(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value)
            ? \trim((string)$value)
            : '';
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = \strtolower((string)\parse_url($url, PHP_URL_SCHEME));
        $host = (string)\parse_url($url, PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && $host !== '';
    }
}
