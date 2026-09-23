<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 把演示店品牌身份写入 Website 范围配置（幂等）。
 *
 * 运行时展示只读 Website.name / Website.description（及 LocalDescription），
 * 禁止在模板/SEO 运行时硬编码品牌字面量。
 * 本服务仅在站名为系统占位或已知演示遗留站名时回填配置表，已有商户自定义站名不覆盖。
 */
final class WebsiteBrandIdentitySeedService
{
    /** 演示默认站中文源站名（只写入 Website 配置，不作运行时回退字面量） */
    public const SEED_NAME = '长安汉服';

    public const SEED_DESCRIPTION = '长安汉服是面向全球的大型汉服售卖平台，汇聚明制、宋制、唐制汉服与马面裙及传统配饰，覆盖日常出行、节日庆典与礼仪场合。平台同时支持单件零售与批量批发，服务个人买家与全球经销商；并提供形制说明、尺码参考、面料要点与搭配灵感，助你更快选到合身又得体的汉服款式。';

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

    /**
     * 演示站历史简介（可幂等改回现行 SEED_DESCRIPTION）。
     *
     * @var list<string>
     */
    private const LEGACY_DESCRIPTIONS = [
        '长安汉服水墨中国风独立站，精选明制、宋制、唐制汉服与马面裙及传统配饰，覆盖日常出行、节日庆典与礼仪场合；提供形制说明、尺码参考、面料要点与搭配灵感，助你更快选到合身又得体的汉服款式。',
    ];

    /**
     * 演示站历史站名（可幂等改回短品牌写进 Website 配置）。
     *
     * @var list<string>
     */
    private const LEGACY_DEMO_NAMES = [
        '长安汉服 · Hanfu Atelier',
        "Chang'an Hanfu · Hanfu Atelier",
        "Chang’an Hanfu · Hanfu Atelier",
        "长安汉服 · Chang'an Hanfu",
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
        $nameIsLegacyDemo = $this->isLegacyDemoName($currentName);
        $needsName = ($nameIsPlaceholder || $nameIsLegacyDemo) && $currentName !== self::SEED_NAME;
        $needsDescription = self::SEED_DESCRIPTION !== ''
            && $currentDescription !== self::SEED_DESCRIPTION
            && ($currentDescription === '' || $this->isLegacyDescription($currentDescription));

        if (!$needsName && !$needsDescription) {
            return $this->ensureDefaultWebsiteLocalBrandCopy()['upserted'] > 0;
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
        $this->ensureDefaultWebsiteLocalBrandCopy();

        return true;
    }

    /**
     * 为默认站已开通语种补齐 LocalDescription（站名/简介），供邮件壳与店面多语读取。
     * service_hours / topics_label 存在同包 PHP 中，由 {@see self::serviceHoursForLocale()} /
     * {@see self::topicsLabelForLocale()} 供 MailBrandContext 按 locale 直读（Local 表无该列）。
     *
     * @return array{upserted:int,skipped:int}
     */
    public function ensureDefaultWebsiteLocalBrandCopy(): array
    {
        $pack = self::loadLocalBrandCopyPack();
        $names = $pack['name'];
        $descriptions = $pack['description'];
        /** @var WebsiteLanguage $wl */
        $wl = ObjectManager::getInstance(WebsiteLanguage::class);
        $locales = $wl->getWebsiteLanguageCodes(Website::ID_DEFAULT);
        $upserted = 0;
        $skipped = 0;
        foreach ($locales as $locale) {
            $locale = trim((string)$locale);
            if ($locale === '' || $locale === 'zh_Hans_CN' || str_starts_with(strtolower(str_replace('_', '-', $locale)), 'zh')) {
                ++$skipped;
                continue;
            }
            $name = trim((string)($names[$locale] ?? $names['en_US'] ?? ''));
            $description = trim((string)($descriptions[$locale] ?? $descriptions['en_US'] ?? ''));
            if ($name === '' && $description === '') {
                ++$skipped;
                continue;
            }
            if ($this->upsertWebsiteLocal(Website::ID_DEFAULT, $locale, $name, $description)) {
                ++$upserted;
            } else {
                ++$skipped;
            }
        }

        return compact('upserted', 'skipped');
    }

    /**
     * 邮件品牌 service_hours：按 locale 读种子包真译；中文 locale 返回简中源串。
     */
    public static function serviceHoursForLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '' || self::isChineseLocaleCode($locale)) {
            return '周一至周五 9:00 - 18:00（法定节假日除外）';
        }
        $pack = self::loadLocalBrandCopyPack();
        $hours = $pack['service_hours'];
        $hit = trim((string)($hours[$locale] ?? ''));
        if ($hit !== '') {
            return $hit;
        }
        $lang = strtolower(explode('_', str_replace('-', '_', $locale))[0] ?? '');
        foreach ($hours as $code => $value) {
            if (strtolower(explode('_', str_replace('-', '_', (string)$code))[0] ?? '') === $lang) {
                $value = trim((string)$value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return trim((string)($hours['en_US'] ?? ''));
    }

    /**
     * 订阅欢迎信 topics_label 预览样例：非 en 禁止 Offers / New arrivals。
     */
    public static function topicsLabelForLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '' || self::isChineseLocaleCode($locale)) {
            return '优惠 / 新品';
        }
        $pack = self::loadLocalBrandCopyPack();
        $topics = $pack['topics_label'];
        $hit = trim((string)($topics[$locale] ?? ''));
        if ($hit !== '') {
            return $hit;
        }
        $lang = strtolower(explode('_', str_replace('-', '_', $locale))[0] ?? '');
        foreach ($topics as $code => $value) {
            if (strtolower(explode('_', str_replace('-', '_', (string)$code))[0] ?? '') === $lang) {
                $value = trim((string)$value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return trim((string)($topics['en_US'] ?? 'Offers / New arrivals'));
    }

    private static function isChineseLocaleCode(string $locale): bool
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        return $normalized === '' || str_starts_with($normalized, 'zh');
    }

    /**
     * @return array{
     *   name: array<string, string>,
     *   description: array<string, string>,
     *   service_hours: array<string, string>,
     *   topics_label: array<string, string>
     * }
     */
    public static function loadLocalBrandCopyPack(): array
    {
        $empty = ['name' => [], 'description' => [], 'service_hours' => [], 'topics_label' => []];
        $path = __DIR__ . '/data/website-brand-local-copy.v1.php';
        if (!is_file($path)) {
            return $empty;
        }
        /** @var mixed $pack */
        $pack = require $path;
        if (!is_array($pack)) {
            return $empty;
        }

        return [
            'name' => is_array($pack['name'] ?? null) ? $pack['name'] : [],
            'description' => is_array($pack['description'] ?? null) ? $pack['description'] : [],
            'service_hours' => is_array($pack['service_hours'] ?? null) ? $pack['service_hours'] : [],
            'topics_label' => is_array($pack['topics_label'] ?? null) ? $pack['topics_label'] : [],
        ];
    }

    private function upsertWebsiteLocal(int $websiteId, string $locale, string $name, string $description): bool
    {
        // LocalDescription 复合身份是 (website_id, local_code)；默认站 website_id=0 时
        // Model::save 会把「有 ID」误判成更新同一行，导致多语互相覆盖。走 ON CONFLICT。
        $description = mb_substr($description, 0, 500);
        try {
            $env = \Weline\Framework\App\Env::getInstance()->getConfig('db')['master'] ?? [];
            if (!is_array($env) || trim((string)($env['database'] ?? '')) === '') {
                return false;
            }
            $pdo = new \PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    (string)($env['hostname'] ?? '127.0.0.1'),
                    (string)($env['hostport'] ?? '5432'),
                    (string)$env['database']
                ),
                (string)($env['username'] ?? ''),
                (string)($env['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $cur = $pdo->prepare(
                'SELECT name, description FROM w_weline_websites_website_local
                 WHERE website_id = :id AND local_code = :locale LIMIT 1'
            );
            $cur->execute([':id' => $websiteId, ':locale' => $locale]);
            $row = $cur->fetch(\PDO::FETCH_ASSOC) ?: null;
            $curName = trim((string)($row['name'] ?? ''));
            $curDesc = trim((string)($row['description'] ?? ''));
            $nextName = $name !== '' ? $name : $curName;
            $nextDesc = $description !== '' ? $description : $curDesc;
            if ($row !== null && $nextName === $curName && $nextDesc === $curDesc) {
                return false;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO w_weline_websites_website_local (website_id, local_code, name, description)
                 VALUES (:id, :locale, :name, :description)
                 ON CONFLICT (website_id, local_code) DO UPDATE SET
                   name = EXCLUDED.name,
                   description = EXCLUDED.description'
            );
            $stmt->execute([
                ':id' => $websiteId,
                ':locale' => $locale,
                ':name' => $nextName,
                ':description' => $nextDesc,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isPlaceholderName(string $name): bool
    {
        return in_array(trim($name), self::PLACEHOLDER_NAMES, true);
    }

    public function isLegacyDemoName(string $name): bool
    {
        return in_array(trim($name), self::LEGACY_DEMO_NAMES, true);
    }

    public function isLegacyDescription(string $description): bool
    {
        return in_array(trim($description), self::LEGACY_DESCRIPTIONS, true);
    }
}
