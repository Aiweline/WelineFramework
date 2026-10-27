<?php

declare(strict_types=1);

namespace Amayum\Website\Service;

use Weline\SystemConfig\Api\ConfigReader;
use Weline\Websites\Data\WebsiteData;

/**
 * Amayum 站点资料唯一读取入口。
 *
 * 运行时优先读取 Amayum_Website/frontend/{website}.default.default 的 JSON 配置；
 * 配置缺失时回退 etc/site.php 默认值（seed 会把同一份值写入 SystemConfig）。
 */
final class SiteProfileService
{
    public const MODULE = 'Amayum_Website';
    public const AREA = 'frontend';

    public const KEY_PROFILE = 'amayum/site/profile';
    public const KEY_NAV = 'amayum/site/nav';
    public const KEY_FOOTER = 'amayum/site/footer';
    public const KEY_PROOF_FACTS = 'amayum/site/proof_facts';
    public const KEY_FLAGS = 'amayum/site/flags';

    /** @var array<string, mixed>|null */
    private static ?array $defaults = null;

    public function __construct(
        private readonly ?ConfigReader $configReader = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        if (self::$defaults === null) {
            $file = \dirname(__DIR__) . '/etc/site.php';
            $data = \is_file($file) ? require $file : [];
            self::$defaults = \is_array($data) ? $data : [];
        }

        return self::$defaults;
    }

    public function currentWebsiteCode(): string
    {
        $code = \trim((string)(WebsiteData::getCode() ?? ''));

        return $code !== '' ? \strtolower($code) : 'amayum';
    }

    public function scope(string $websiteCode = ''): string
    {
        $code = \strtolower(\trim($websiteCode !== '' ? $websiteCode : $this->currentWebsiteCode()));

        return $code . '.default.default';
    }

    /** @return array<string, mixed> */
    public function profile(string $websiteCode = ''): array
    {
        $defaults = (array)($this->defaults()['profile'] ?? []);
        $stored = $this->readJson(self::KEY_PROFILE, $websiteCode);
        $profile = $stored !== [] ? \array_replace_recursive($defaults, $stored) : $defaults;
        $links = (array)($profile['links'] ?? []);
        $profile['github'] = (string)($links['github'] ?? '');
        $profile['gitee'] = (string)($links['gitee'] ?? '');
        $profile['docs'] = (string)($links['docs'] ?? '');

        return $profile;
    }

    /** @return array{items: list<array<string, mixed>>, cta: array<string, string>} */
    public function nav(string $websiteCode = ''): array
    {
        $defaults = (array)($this->defaults()['nav'] ?? []);
        $stored = $this->readJson(self::KEY_NAV, $websiteCode);
        $items = \is_array($stored['items'] ?? null) && $stored['items'] !== []
            ? \array_values($stored['items'])
            : (array)($defaults['items'] ?? []);
        $items = \array_values(\array_filter($items, static function ($item): bool {
            return \is_array($item) && ($item['visible'] ?? true) !== false;
        }));
        \usort($items, static fn(array $a, array $b): int => (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0));

        $cta = \is_array($stored['cta'] ?? null) && $stored['cta'] !== []
            ? $stored['cta']
            : (array)($defaults['cta'] ?? []);

        return [
            'items' => $items,
            'cta' => [
                'label' => (string)($cta['label'] ?? '预约架构诊断'),
                'href' => (string)($cta['href'] ?? '/diagnosis#diagnosis'),
            ],
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, bottom: list<array<string, mixed>>} */
    public function footer(string $websiteCode = ''): array
    {
        $defaults = (array)($this->defaults()['footer'] ?? []);
        $stored = $this->readJson(self::KEY_FOOTER, $websiteCode);
        $columns = \is_array($stored['columns'] ?? null) && $stored['columns'] !== []
            ? \array_values($stored['columns'])
            : (array)($defaults['columns'] ?? []);
        $bottom = \is_array($stored['bottom'] ?? null) && $stored['bottom'] !== []
            ? \array_values($stored['bottom'])
            : (array)($defaults['bottom'] ?? []);

        return [
            'columns' => $columns,
            'bottom' => $bottom,
        ];
    }

    /** @return list<array{label: string, detail: string}> */
    public function proofFacts(string $websiteCode = ''): array
    {
        $stored = $this->readJson(self::KEY_PROOF_FACTS, $websiteCode);
        if ($stored !== [] && \array_is_list($stored)) {
            return \array_values(\array_filter($stored, 'is_array'));
        }

        return (array)($this->defaults()['proof_facts'] ?? []);
    }

    /** @return array<string, bool> */
    public function flags(string $websiteCode = ''): array
    {
        $defaults = (array)($this->defaults()['flags'] ?? []);
        $stored = $this->readJson(self::KEY_FLAGS, $websiteCode);

        return \array_replace($defaults, $stored);
    }

    /** @return array<string, mixed> */
    public function organization(string $websiteCode = ''): array
    {
        $profile = $this->profile($websiteCode);
        $flags = $this->flags($websiteCode);
        $links = (array)($profile['links'] ?? []);
        $sameAs = [];
        if (($flags['show_repo_links'] ?? false) === true) {
            foreach (['github', 'gitee'] as $key) {
                $url = \trim((string)($links[$key] ?? ''));
                if ($url !== '') {
                    $sameAs[] = $url;
                }
            }
        }

        return [
            'name' => (string)($profile['brand_name'] ?? 'Amayum'),
            'url' => (string)($profile['site_url'] ?? 'https://amayum.com'),
            'logo' => $this->absoluteAsset((string)($profile['og_image'] ?? ''), (string)($profile['site_url'] ?? '')),
            'email' => (string)($profile['contact_email'] ?? 'hello@amayum.com'),
            'sameAs' => $sameAs,
        ];
    }

    public function absoluteAsset(string $path, string $siteUrl): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if (\preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return \rtrim($siteUrl, '/') . '/' . \ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function readJson(string $key, string $websiteCode): array
    {
        try {
            $reader = $this->configReader ?? new ConfigReader();
            $value = $reader->get($key, self::MODULE, self::AREA, null, $this->scope($websiteCode));
        } catch (\Throwable) {
            return [];
        }

        if (\is_array($value)) {
            return $value;
        }
        if (\is_string($value) && \trim($value) !== '') {
            $decoded = \json_decode($value, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
