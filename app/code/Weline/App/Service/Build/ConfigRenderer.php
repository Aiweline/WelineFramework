<?php
declare(strict_types=1);

namespace Weline\App\Service\Build;

class ConfigRenderer
{
    public const PLATFORMS = ['android', 'web', 'ios'];

    public function render(int $websiteId, string $platform): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new \InvalidArgumentException('非法平台：' . $platform . '（允许 android|web|ios）');
        }
        if ($platform === 'ios') {
            throw new \RuntimeException('iOS 走平台云编译，本机不编译（V1 契约）');
        }

        $config = $this->loadWebsiteConfig($websiteId);

        if ((int)($config['enabled'] ?? 1) !== 1) {
            throw new \RuntimeException('该站点自动打包已关闭');
        }
        $platforms = json_decode((string)($config['platforms'] ?? '[]'), true) ?: [];
        if (!in_array($platform, $platforms, true)) {
            throw new \RuntimeException('平台未启用：' . $platform);
        }

        $versions = $this->readVersions();
        $domain = (string)($config['domain'] ?? '');

        return [
            'website_id' => $websiteId,
            'domain' => $domain,
            'universal' => $domain === '',
            'platform' => $platform,
            'app_name' => 'Weline App',
            'locales' => ['en_US', 'zh_Hans_CN'],
            'currencies' => [
                ['code' => 'USD', 'symbol' => '$'],
                ['code' => 'CNY', 'symbol' => '¥'],
            ],
            'theme' => ['primary' => 'rgb(177,63,60)', 'surface' => 'rgb(247,242,233)'],
            'version' => $versions['module'] ?? '1.0.0',
            'flutter_pin' => $versions['template_flutter'] ?? '3.27.4',
            'build_id_prefix' => $websiteId . '-' . $platform . '-' . date('Ymd'),
        ];
    }

    private function loadWebsiteConfig(int $websiteId): array
    {
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\App\Model\AppBuild::class);
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
