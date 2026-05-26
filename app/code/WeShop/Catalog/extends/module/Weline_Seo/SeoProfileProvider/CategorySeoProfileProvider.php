<?php

declare(strict_types=1);

namespace WeShop\Catalog\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Seo\Interface\SeoProfileProviderInterface;

class CategorySeoProfileProvider implements SeoProfileProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        if (($context['page_type'] ?? '') !== 'category' && empty($context['category'])) {
            return [];
        }

        $profile = ['page_type' => 'category'];
        if (!empty($context['item_list'])) {
            return $profile;
        }

        $items = [];
        $category = $context['category'] ?? [];
        foreach ((array)($category['children'] ?? []) as $child) {
            $this->appendItem($items, $child);
        }
        foreach ((array)($this->readTemplate($template, 'products') ?? []) as $product) {
            $this->appendItem($items, $product);
        }

        if ($items !== []) {
            $profile['item_list'] = array_values($items);
        }
        return $profile;
    }

    /**
     * @param array<string, array<string, string>> $items
     */
    private function appendItem(array &$items, mixed $source): void
    {
        if (!is_array($source) && !is_object($source)) {
            return;
        }
        $name = trim((string)$this->read($source, ['name', 'title']));
        $url = trim((string)$this->read($source, ['url', 'href', 'canonical']));
        $handle = trim((string)$this->read($source, ['handle']));
        if ($url === '' && $handle !== '') {
            $url = '/' . ltrim($handle, '/');
        }
        if ($name === '' || $url === '') {
            return;
        }
        $items[$url] = [
            'name' => $name,
            'url' => $url,
            'description' => (string)($this->read($source, ['short_description', 'description', 'summary']) ?: ''),
            'image' => (string)($this->read($source, ['image', 'main_image', 'thumbnail']) ?: ''),
        ];
    }

    private function readTemplate($template, string $key): mixed
    {
        return is_object($template) && method_exists($template, 'getData') ? $template->getData($key) : null;
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
}
