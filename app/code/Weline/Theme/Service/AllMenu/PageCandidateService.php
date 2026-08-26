<?php

declare(strict_types=1);

namespace Weline\Theme\Service\AllMenu;

/**
 * Default seed + Theme Router shell page candidates (tag=page).
 */
final class PageCandidateService
{
    /**
     * Curated shell pages for default seed / page picker (subset of Theme Router map).
     *
     * @return array<string, string> path => title
     */
    public static function shellPageMap(): array
    {
        return [
            'about' => '关于我们',
            'contact' => '联系我们',
            'help' => '帮助中心',
            'support' => '支持',
            'faq' => '常见问题',
            'solutions' => '解决方案',
            'docs' => '文档',
            'privacy' => '隐私政策',
            'terms' => '服务条款',
            'orders/track' => '订单跟踪',
        ];
    }

    public function __construct(
        private readonly MenuTreeNormalizer $normalizer = new MenuTreeNormalizer(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shellPages(): array
    {
        $items = [];
        foreach (self::shellPageMap() as $path => $title) {
            // Store Chinese source string as i18n key; storefront translates for current locale.
            $items[] = [
                'id' => 'page_' . str_replace('/', '_', $path),
                'tag' => MenuTreeNormalizer::TAG_PAGE,
                'name' => $title,
                'url' => '/' . ltrim($path, '/'),
                'ref' => 'router:' . $path,
                'children' => [],
            ];
        }

        return $this->normalizer->normalize($items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function defaultSeedTree(): array
    {
        return $this->shellPages();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectPageCandidates(): array
    {
        $items = $this->shellPages();
        try {
            if (function_exists('w_query')) {
                $result = w_query('cms', 'listPages', ['page_size' => 100, 'status' => 'published']);
                $rows = is_array($result) ? ($result['items'] ?? $result['data']['items'] ?? []) : [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $title = trim((string)($row['title'] ?? ''));
                        $url = trim((string)($row['public_url'] ?? ''));
                        if ($url === '' && !empty($row['identifier'])) {
                            $url = '/' . ltrim((string)$row['identifier'], '/');
                        }
                        if ($title === '' || $url === '') {
                            continue;
                        }
                        $pageId = (string)($row['page_id'] ?? $row['identifier'] ?? $url);
                        $items[] = [
                            'id' => 'cms_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $pageId),
                            'tag' => MenuTreeNormalizer::TAG_PAGE,
                            'name' => $title,
                            'url' => $url,
                            'ref' => 'cms:' . $pageId,
                            'children' => [],
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // CMS optional
        }

        return $this->normalizer->normalize($items);
    }
}
