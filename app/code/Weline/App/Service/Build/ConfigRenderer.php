<?php
declare(strict_types=1);

namespace Weline\App\Service\Build;

use Weline\App\Service\SiteProfile;
use Weline\Framework\Manager\ObjectManager;

class ConfigRenderer
{
    public const PLATFORMS = ['android', 'web', 'ios'];

    /**
     * @param string|null $domainOverride 本机构建时显式指定的站点地址（线上域名），优先于库内配置
     */
    public function render(int $websiteId, string $platform, ?string $domainOverride = null): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new \InvalidArgumentException('非法平台：' . $platform . '（允许 android|web|ios）');
        }
        $override = $this->prepareDomain((string)$domainOverride);
        if ($domainOverride !== null && \trim($domainOverride) !== '' && $override === '') {
            throw new \InvalidArgumentException('站点地址必须是 http(s) 绝对地址：' . $domainOverride);
        }
        // iOS：本机出「未签名 IPA」供自签侧载（不经 App Store）；上架后改用 App Store 链接即可
        $config = $this->loadWebsiteConfig($websiteId);

        if ((int)($config['enabled'] ?? 1) !== 1) {
            throw new \RuntimeException('该站点自动打包已关闭');
        }
        $platforms = json_decode((string)($config['platforms'] ?? '[]'), true) ?: [];
        if (!in_array($platform, $platforms, true)) {
            throw new \RuntimeException('平台未启用：' . $platform);
        }

        $versions = $this->readVersions();
        $profile = $this->resolveProfile($websiteId);
        $domain = $override !== '' ? $override : $this->prepareDomain((string)($config['domain'] ?? ''));

        return [
            'website_id' => $websiteId,
            'domain' => $domain,
            // 编译期基址 = 显式 --base-url 或站点绑定域名；都为空时由构建器拒绝出包（禁止打本机测试 Host）
            'base_url' => $domain,
            'universal' => $domain === '',
            'platform' => $platform,
            'app_name' => $profile['name'],
            'locales' => $profile['locales'],
            'default_locale' => $profile['default_locale'],
            'currencies' => $profile['currencies'],
            'default_currency' => $profile['default_currency'],
            'theme' => $profile['theme'],
            'version' => $versions['module'] ?? '1.0.0',
            'flutter_pin' => $versions['template_flutter'] ?? '3.27.4',
            'build_id_prefix' => $websiteId . '-' . $platform . '-' . date('Ymd'),
        ];
    }

    /**
     * CLI/单测环境可能没有完整 DB/站点上下文；画像读取失败时退回空集合，
     * 不阻断打包配置校验。
     *
     * @return array<string, mixed>
     */
    private function resolveProfile(int $websiteId): array
    {
        try {
            return ObjectManager::getInstance(SiteProfile::class)->resolve($websiteId);
        } catch (\Throwable) {
            return [
                'name' => '',
                'locales' => [],
                'default_locale' => '',
                'currencies' => [],
                'default_currency' => '',
                'theme' => ['primary' => '', 'surface' => ''],
            ];
        }
    }

    private function loadWebsiteConfig(int $websiteId): array
    {
        $model = ObjectManager::getInstance(\Weline\App\Model\AppBuild::class);
        $found = $model->clear()
            ->where($model::schema_fields_website_id, $websiteId)
            ->find()
            ->fetch();
        if ($found && $found->getData('id')) {
            return $found->getData() ?? [];
        }
        return [
            'website_id' => $websiteId,
            'domain' => '',
            'platforms' => json_encode(['android', 'web']),
            'enabled' => 1,
        ];
    }

    /**
     * 归一化站点地址：去空白与尾斜杠，非 http(s) 绝对地址一律返回空串（由调用方决定是否报错）。
     */
    private function prepareDomain(string $domain): string
    {
        $domain = \rtrim(\trim($domain), '/');
        if ($domain === '' || \preg_match('#^https?://[^/\s]+#i', $domain) !== 1) {
            return '';
        }

        return $domain;
    }

    private function readVersions(): array
    {
        $file = BP . 'app/code/Weline/App/etc/weline_app_versions.json';
        if (!is_file($file)) {
            return [];
        }
        $json = json_decode((string)file_get_contents($file), true);
        return is_array($json) ? $json : [];
    }
}
