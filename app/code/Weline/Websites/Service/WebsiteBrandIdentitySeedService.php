<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Websites\Model\Website;

/**
 * 把演示店品牌身份写入 Website 范围配置（幂等）。
 *
 * 运行时展示只读 Website.name / Website.description，禁止在模板/SEO 里硬编码品牌字面量。
 * 本服务仅在站名为系统占位（默认网站等）时回填，已有商户自定义站名不覆盖。
 */
final class WebsiteBrandIdentitySeedService
{
    public const SEED_NAME = '长安汉服 · Hanfu Atelier';

    public const SEED_DESCRIPTION = '长安汉服水墨中国风独立站，精选明制、宋制、唐制汉服与马面裙及传统配饰，覆盖日常出行、节日庆典与礼仪场合；提供形制说明、尺码参考、面料要点与搭配灵感，助你更快选到合身又得体的汉服款式。';

    /** @var list<string> */
    private const PLACEHOLDER_NAMES = [
        '',
        '默认网站',
        'Default Website',
        'Weline',
        'weline',
        'Weline Framework',
        '韦林',
        '系统默认站点',
    ];

    public function __construct(
        private readonly Website $website,
        private readonly BackendConfigStore $backendConfig,
    ) {
    }

    public function ensureDefaultWebsiteBrandIdentity(): bool
    {
        $website = clone $this->website;
        $website->clear()->clearQuery()
            ->where(Website::schema_fields_ID, Website::ID_DEFAULT)
            ->find()
            ->fetch();
        if (!$website->getId() && !$website->hasData(Website::schema_fields_ID)) {
            return false;
        }

        $currentName = trim((string)$website->getName());
        $currentDescription = trim((string)$website->getDescription());
        $nameIsPlaceholder = $this->isPlaceholderName($currentName);
        $needsName = $nameIsPlaceholder && $currentName !== self::SEED_NAME;
        $needsDescription = $currentDescription === '' && self::SEED_DESCRIPTION !== '';

        if (!$needsName && !$needsDescription) {
            return false;
        }

        if ($needsName) {
            $website->setName(self::SEED_NAME);
        }
        if ($needsDescription) {
            $website->setDescription(self::SEED_DESCRIPTION);
        }
        if (!$website->save()) {
            throw new \RuntimeException('website_brand_identity_seed_save_failed');
        }

        $name = trim((string)$website->getName());
        $description = trim((string)$website->getDescription());
        $this->backendConfig->setConfig('site_name', $name, 'Weline_Backend');
        $this->backendConfig->setConfig('site_description', $description, 'Weline_Backend');

        return true;
    }

    private function isPlaceholderName(string $name): bool
    {
        return in_array($name, self::PLACEHOLDER_NAMES, true);
    }
}
