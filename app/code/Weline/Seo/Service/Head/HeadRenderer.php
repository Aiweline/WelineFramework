<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

class HeadRenderer
{
    public function __construct(
        private readonly PageSeoContextResolver $resolver,
        private readonly ?HeadProviderRegistry $providerRegistry = null
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $options
     */
    public function render($template, array $options = []): string
    {
        $slot = (string) ($options['slot'] ?? 'head');
        if ($slot === 'head' && $this->claimTemplateRender($template, '__weline_seo_head_rendered')) {
            return '';
        }

        $context = $this->resolver->resolve($template, $options);
        $context['_template'] = $template;
        return match ($slot) {
            'meta' => $this->renderMeta($context),
            'canonical' => $this->renderCanonical($context),
            'social' => $this->renderSocial($context),
            'schema', 'structured-data' => $this->renderStructuredData($context),
            default => implode("\n", array_filter([
                $this->renderMeta($context),
                $this->renderCanonical($context),
                $this->renderSocial($context),
                $this->renderStructuredData($context),
            ])),
        };
    }

    private function claimTemplateRender($template, string $key): bool
    {
        if (!is_object($template) || !method_exists($template, 'getData') || !method_exists($template, 'setData')) {
            return false;
        }

        if (!empty($template->getData($key))) {
            return true;
        }

        $template->setData($key, true);
        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderMeta(array $context): string
    {
        $html = [];
        $title = trim((string) ($context['title'] ?? ''));
        $description = trim((string) ($context['description'] ?? ''));
        $keywords = $context['keywords'] ?? '';
        if (is_array($keywords)) {
            $keywords = implode(', ', array_filter(array_map('strval', $keywords)));
        }

        if ($title !== '') {
            $html[] = '<title>' . $this->escape($title) . '</title>';
        }
        if ($description !== '') {
            $html[] = '<meta name="description" content="' . $this->escape($description) . '">';
        }
        if (trim((string) $keywords) !== '') {
            $html[] = '<meta name="keywords" content="' . $this->escape((string) $keywords) . '">';
        }
        if (!empty($context['robots'])) {
            $html[] = '<meta name="robots" content="' . $this->escape((string) $context['robots']) . '">';
        }
        return implode("\n", $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderCanonical(array $context): string
    {
        $html = [];
        if (!empty($context['canonical_url'])) {
            $html[] = '<link rel="canonical" href="' . $this->escape((string) $context['canonical_url']) . '">';
        }
        foreach ((array) ($context['alternates'] ?? []) as $locale => $url) {
            if (!is_string($locale) || !is_string($url) || trim($url) === '') {
                continue;
            }
            $html[] = '<link rel="alternate" hreflang="' . $this->escape($locale) . '" href="' . $this->escape($url) . '">';
        }
        return implode("\n", $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderSocial(array $context): string
    {
        $title = (string) ($context['title'] ?? '');
        $description = (string) ($context['description'] ?? '');
        $url = (string) ($context['canonical_url'] ?? $context['url'] ?? '');
        $type = ($context['page_type'] ?? '') === 'product' ? 'product' : 'website';
        $html = [
            '<meta property="og:type" content="' . $this->escape($type) . '">',
        ];
        if ($title !== '') {
            $html[] = '<meta property="og:title" content="' . $this->escape($title) . '">';
            $html[] = '<meta name="twitter:title" content="' . $this->escape($title) . '">';
        }
        if ($description !== '') {
            $html[] = '<meta property="og:description" content="' . $this->escape($description) . '">';
            $html[] = '<meta name="twitter:description" content="' . $this->escape($description) . '">';
        }
        if ($url !== '') {
            $html[] = '<meta property="og:url" content="' . $this->escape($url) . '">';
        }
        if (!empty($context['image'])) {
            $html[] = '<meta property="og:image" content="' . $this->escape((string) $context['image']) . '">';
            $html[] = '<meta name="twitter:image" content="' . $this->escape((string) $context['image']) . '">';
            $html[] = '<meta name="twitter:card" content="summary_large_image">';
        } else {
            $html[] = '<meta name="twitter:card" content="summary">';
        }
        return implode("\n", $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderStructuredData(array $context): string
    {
        $graph = $this->buildGraph($context);
        if ($graph === []) {
            return '';
        }
        return '<script type="application/ld+json">' . "\n"
            . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n" . '</script>';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function buildGraph(array $context): array
    {
        $url = (string) ($context['canonical_url'] ?? $context['url'] ?? '');
        $siteUrl = $this->siteRoot($url);
        $organization = (array) ($context['organization'] ?? []);
        $orgId = rtrim($siteUrl, '/') . '/#organization';
        $graph = [
            [
                '@type' => !empty($organization['address']) || !empty($organization['telephone']) ? 'LocalBusiness' : 'Organization',
                '@id' => $orgId,
                'name' => (string) ($organization['name'] ?? $context['site_name'] ?? ''),
                'url' => (string) ($organization['url'] ?? $siteUrl),
            ],
            [
                '@type' => 'WebSite',
                '@id' => rtrim($siteUrl, '/') . '/#website',
                'url' => $siteUrl,
                'name' => (string) ($context['site_name'] ?? ''),
                'publisher' => ['@id' => $orgId],
            ],
        ];

        if (!empty($organization['logo'])) {
            $graph[0]['logo'] = (string) $organization['logo'];
        }
        if (!empty($organization['sameAs']) && is_array($organization['sameAs'])) {
            $graph[0]['sameAs'] = array_values(array_filter($organization['sameAs']));
        }
        if (!empty($organization['telephone'])) {
            $graph[0]['telephone'] = (string) $organization['telephone'];
        }
        if (!empty($organization['address'])) {
            $graph[0]['address'] = $organization['address'];
        }

        $graph[] = $this->webPageNode($context, $orgId);

        if (!empty($context['breadcrumbs'])) {
            $graph[] = $this->breadcrumbNode((array) $context['breadcrumbs'], $url);
        }
        if (($context['page_type'] ?? '') === 'product') {
            $product = $this->productNode($context, $url, $orgId);
            if ($product !== []) {
                $graph[] = $product;
            }
        }
        if (in_array((string) ($context['page_type'] ?? ''), ['article', 'blog_post', 'post'], true)) {
            $graph[] = $this->articleNode($context, $url, $orgId);
        }
        if (!empty($context['faqs'])) {
            $graph[] = $this->faqNode((array) $context['faqs'], $url);
        }

        foreach ($this->providerRegistry?->getStructuredDataProviders() ?? [] as $provider) {
            try {
                foreach ($provider->provideStructuredData($context['_template'] ?? null, $context) as $node) {
                    if (is_array($node) && $node !== []) {
                        $graph[] = $node;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return array_values(array_filter($graph));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function webPageNode(array $context, string $orgId): array
    {
        return [
            '@type' => 'WebPage',
            '@id' => (string) ($context['canonical_url'] ?? $context['url'] ?? '') . '#webpage',
            'url' => (string) ($context['canonical_url'] ?? $context['url'] ?? ''),
            'name' => (string) ($context['title'] ?? ''),
            'description' => (string) ($context['description'] ?? ''),
            'isPartOf' => ['@id' => $this->siteRoot((string) ($context['canonical_url'] ?? '')) . '#website'],
            'publisher' => ['@id' => $orgId],
        ];
    }

    /**
     * @param array<int, array{name:string,url:string}> $breadcrumbs
     * @return array<string, mixed>
     */
    private function breadcrumbNode(array $breadcrumbs, string $url): array
    {
        $items = [];
        foreach ($breadcrumbs as $index => $breadcrumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => (string) ($breadcrumb['name'] ?? ''),
                'item' => (string) ($breadcrumb['url'] ?? $url),
            ];
        }
        return [
            '@type' => 'BreadcrumbList',
            '@id' => $url . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function productNode(array $context, string $url, string $orgId): array
    {
        $product = $context['product'] ?? null;
        $name = $this->read($product, ['name', 'title']);
        if (!$name) {
            return [];
        }
        $price = $this->read($product, ['price', 'final_price']);
        $stock = $this->read($product, ['stock', 'qty', 'stock_status']);
        $node = [
            '@type' => 'Product',
            '@id' => $url . '#product',
            'name' => (string) $name,
            'description' => (string) ($context['description'] ?? ''),
            'url' => $url,
        ];
        $brand = $this->read($product, ['brand']);
        if ($brand) {
            $node['brand'] = ['@type' => 'Brand', 'name' => (string) $brand];
        }
        if ($price !== null && trim((string) $price) !== '') {
            $offer = [
                '@type' => 'Offer',
                'url' => $url,
                'price' => (string) $price,
                'priceCurrency' => (string) w_env('user.currency', 'USD'),
                'seller' => ['@id' => $orgId],
            ];
            if ($stock !== null && trim((string) $stock) !== '') {
                $offer['availability'] = $this->isInStock($stock) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
            }
            $node['offers'] = $offer;
        }
        if (!empty($context['image'])) {
            $node['image'] = [(string) $context['image']];
        }
        $sku = $this->read($product, ['sku']);
        if ($sku) {
            $node['sku'] = (string) $sku;
        }
        $rating = $this->read($product, ['rating']);
        $reviewCount = (int) ($this->read($product, ['review_count']) ?: 0);
        if ($rating && $reviewCount > 0) {
            $node['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $rating,
                'reviewCount' => $reviewCount,
            ];
        }
        return $node;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function articleNode(array $context, string $url, string $orgId): array
    {
        $page = $context['page'] ?? null;
        $node = [
            '@type' => 'BlogPosting',
            '@id' => $url . '#article',
            'headline' => (string) ($context['title'] ?? ''),
            'description' => (string) ($context['description'] ?? ''),
            'url' => $url,
            'publisher' => ['@id' => $orgId],
            'author' => ['@id' => $orgId],
        ];
        if (!empty($context['image'])) {
            $node['image'] = [(string) $context['image']];
        }
        $published = $this->read($page, ['published_at', 'created_at']);
        $modified = $this->read($page, ['updated_at', 'modified_at']);
        if ($published) {
            $node['datePublished'] = $this->formatDate($published);
        }
        if ($modified) {
            $node['dateModified'] = $this->formatDate($modified);
        }
        return $node;
    }

    /**
     * @param array<int, array{question:string,answer:string}> $faqs
     * @return array<string, mixed>
     */
    private function faqNode(array $faqs, string $url): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $url . '#faq',
            'mainEntity' => array_map(static fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], $faqs),
        ];
    }

    private function read(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }
            if (is_object($source) && method_exists($source, 'getData')) {
                $value = $source->getData($key);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    private function isInStock(mixed $stock): bool
    {
        if (is_numeric($stock)) {
            return (float) $stock > 0;
        }
        $stock = strtolower((string) $stock);
        return $stock === '' || str_contains($stock, 'in');
    }

    private function siteRoot(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim((string) w_env('website.url', ''), '/');
        }
        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
    }

    private function formatDate(mixed $value): string
    {
        if (is_numeric($value)) {
            return date('c', (int) $value);
        }
        $time = strtotime((string) $value);
        return date('c', $time ?: time());
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
