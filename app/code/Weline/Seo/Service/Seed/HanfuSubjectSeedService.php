<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Seed;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoKeyword;
use Weline\Seo\Model\SeoSubject;
use Weline\Websites\Service\WebsiteBrandIdentitySeedService;

/**
 * 安装预置：默认站 SEO 主体（幂等）。
 *
 * 绑定真实默认网站 website_id=0。官方模块默认中性关键词；
 * 汉服站专属标题/描述/关键词见 design/Weline/hanfu/data/seo-subject-seed.v1.php
 * （仅 hanfu 主题激活时加载）。URL 从 WebsiteDomain 解析。
 * 仅当 module/scope 仍为种子标记时回写画像字段。
 */
final class HanfuSubjectSeedService
{
    public const SEED_MODULE = 'Weline_Seo';
    public const SEED_SCOPE = 'seed';
    public const SEED_SUBJECT_TYPE = SeoSubject::SUBJECT_TYPE_WEBSITE;
    /** 默认网站 website_id=0 */
    public const SEED_ENTITY_ID = 0;
    /** 历史假种子 entity_id，升级时删除（仅种子所有） */
    public const LEGACY_DEMO_ENTITY_ID = 900001;

    /** @deprecated 使用 {@see self::resolveSeedTitle()}；保留常量供旧测试/调用方兼容读中性默认 */
    public const SEED_TITLE = WebsiteBrandIdentitySeedService::SEED_NAME;
    /** @deprecated 使用 {@see self::resolveSeedDescription()} */
    public const SEED_DESCRIPTION = WebsiteBrandIdentitySeedService::SEED_DESCRIPTION;
    public const SEED_LOCALE = 'zh-CN';

    /**
     * 官方模块中性关键词（非站专属）。
     *
     * @var list<array{keyword:string,priority:int}>
     */
    public const SEED_KEYWORDS = [
        ['keyword' => '在线商店', 'priority' => 100],
        ['keyword' => '新品', 'priority' => 90],
        ['keyword' => '热销', 'priority' => 80],
    ];

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @return array{
     *   subject_id:int,
     *   subject_created:bool,
     *   subject_updated:bool,
     *   keywords_created:int,
     *   keywords_updated:int,
     *   skipped:bool,
     *   legacy_removed:int,
     *   url:string
     * }
     */
    public function seed(): array
    {
        $legacyRemoved = $this->removeLegacyDemoSeed();
        $url = $this->resolvePublicBaseUrl();

        /** @var SeoSubject $subject */
        $subject = $this->objectManager->getInstance(SeoSubject::class);
        $subject->clear()
            ->where(SeoSubject::schema_fields_SUBJECT_TYPE, self::SEED_SUBJECT_TYPE)
            ->where(SeoSubject::schema_fields_SUBJECT_ID, self::SEED_ENTITY_ID)
            ->find()
            ->fetch();

        $created = false;
        $updated = false;
        $skipped = false;

        $seedTitle = self::resolveSeedTitle();
        $seedDescription = self::resolveSeedDescription();

        if (!$subject->getId()) {
            $subject->clearData()
                ->setSubjectType(self::SEED_SUBJECT_TYPE)
                ->setSubjectId(self::SEED_ENTITY_ID)
                ->setData(SeoSubject::schema_fields_MODULE, self::SEED_MODULE)
                ->setData(SeoSubject::schema_fields_SCOPE, self::SEED_SCOPE)
                ->setTitle($seedTitle)
                ->setUrl($url)
                ->setDescription($seedDescription)
                ->setLocale(self::SEED_LOCALE)
                ->setStatus(SeoSubject::STATUS_ENABLED)
                ->save();
            $created = true;
        } elseif ($this->isSeedOwned($subject)) {
            $subject->setTitle($seedTitle)
                ->setUrl($url)
                ->setDescription($seedDescription)
                ->setLocale(self::SEED_LOCALE)
                ->setData(SeoSubject::schema_fields_MODULE, self::SEED_MODULE)
                ->setData(SeoSubject::schema_fields_SCOPE, self::SEED_SCOPE)
                ->setStatus(SeoSubject::STATUS_ENABLED)
                ->save();
            $updated = true;
        } else {
            $skipped = true;
        }

        $subjectId = (int)$subject->getId();
        $keywordStats = $subjectId > 0
            ? $this->ensureKeywords($subjectId)
            : ['keywords_created' => 0, 'keywords_updated' => 0];

        return [
            'subject_id' => $subjectId,
            'subject_created' => $created,
            'subject_updated' => $updated,
            'keywords_created' => $keywordStats['keywords_created'],
            'keywords_updated' => $keywordStats['keywords_updated'],
            'skipped' => $skipped,
            'legacy_removed' => $legacyRemoved,
            'url' => $url,
        ];
    }

    private function isSeedOwned(SeoSubject $subject): bool
    {
        return (string)$subject->getData(SeoSubject::schema_fields_MODULE) === self::SEED_MODULE
            && (string)$subject->getData(SeoSubject::schema_fields_SCOPE) === self::SEED_SCOPE;
    }

    /**
     * 删除历史 example.com / 900001 假种子（仅种子所有）。
     */
    private function removeLegacyDemoSeed(): int
    {
        /** @var SeoSubject $subject */
        $subject = $this->objectManager->getInstance(SeoSubject::class);
        $subject->clear()
            ->where(SeoSubject::schema_fields_SUBJECT_TYPE, self::SEED_SUBJECT_TYPE)
            ->where(SeoSubject::schema_fields_SUBJECT_ID, self::LEGACY_DEMO_ENTITY_ID)
            ->find()
            ->fetch();

        if (!$subject->getId() || !$this->isSeedOwned($subject)) {
            return 0;
        }

        $subjectId = (int)$subject->getId();
        /** @var SeoKeyword $keyword */
        $keyword = $this->objectManager->getInstance(SeoKeyword::class);
        $rows = $keyword->clear()
            ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subjectId)
            ->select()
            ->fetchArray();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $kid = (int)($row[SeoKeyword::schema_fields_ID] ?? 0);
                if ($kid <= 0) {
                    continue;
                }
                /** @var SeoKeyword $one */
                $one = $this->objectManager->getInstance(SeoKeyword::class);
                $one->load($kid);
                if ($one->getId()) {
                    $one->delete();
                }
            }
        }
        $subject->delete();

        return 1;
    }

    /**
     * 从默认站 WebsiteDomain 取公网基址；优先交付 Host *.test.weline.com。
     */
    private function resolvePublicBaseUrl(): string
    {
        $candidates = [];
        try {
            $rows = w_query('websites', 'getWebsiteDomains', ['website_id' => self::SEED_ENTITY_ID]);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if (strtolower((string)($row['status'] ?? 'active')) === 'disabled') {
                        continue;
                    }
                    $base = rtrim(trim((string)($row['base_url'] ?? '')), '/');
                    if ($base === '' || !preg_match('#^https?://#i', $base)) {
                        $domain = strtolower(trim((string)($row['domain'] ?? '')));
                        if ($domain === '') {
                            continue;
                        }
                        $scheme = !empty($row['https_enabled']) ? 'https' : 'http';
                        $base = $scheme . '://' . $domain;
                    }
                    $host = strtolower((string)(parse_url($base, PHP_URL_HOST) ?: ''));
                    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
                        continue;
                    }
                    $candidates[] = [
                        'url' => $base,
                        'host' => $host,
                        'primary' => !empty($row['is_primary']),
                        'prefer' => str_ends_with($host, '.test.weline.com') ? 0
                            : (str_ends_with($host, '.weline.test') ? 2 : 1),
                    ];
                }
            }
        } catch (\Throwable) {
            // Websites 未装或查询失败时回退。
        }

        if ($candidates !== []) {
            usort($candidates, static function (array $a, array $b): int {
                if ($a['prefer'] !== $b['prefer']) {
                    return $a['prefer'] <=> $b['prefer'];
                }
                if ($a['primary'] !== $b['primary']) {
                    return $a['primary'] ? -1 : 1;
                }
                return strcmp($a['host'], $b['host']);
            });

            return (string)$candidates[0]['url'];
        }

        try {
            if (class_exists(\Weline\Websites\Model\Website::class)) {
                /** @var \Weline\Websites\Model\Website $website */
                $website = $this->objectManager->getInstance(\Weline\Websites\Model\Website::class);
                $website->load(self::SEED_ENTITY_ID);
                $url = rtrim(trim((string)$website->getData('url')), '/');
                $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
                if ($url !== '' && preg_match('#^https?://#i', $url)
                    && $host !== '' && $host !== 'localhost' && $host !== '127.0.0.1'
                ) {
                    return $url;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @return array{keywords_created:int,keywords_updated:int}
     */
    private function ensureKeywords(int $subjectId): array
    {
        $created = 0;
        $updated = 0;
        $allowed = [];

        foreach (self::resolveSeedKeywords() as $row) {
            $keyword = trim((string)$row['keyword']);
            if ($keyword === '') {
                continue;
            }
            $allowed[$keyword] = true;
            $priority = (int)$row['priority'];

            /** @var SeoKeyword $model */
            $model = $this->objectManager->getInstance(SeoKeyword::class);
            $model->clear()
                ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subjectId)
                ->where(SeoKeyword::schema_fields_KEYWORD, $keyword)
                ->find()
                ->fetch();

            if (!$model->getId()) {
                $model->clearData()
                    ->setSubjectId($subjectId)
                    ->setKeyword($keyword)
                    ->setPriority($priority)
                    ->setSource(SeoKeyword::SOURCE_MANUAL)
                    ->setStatus(SeoKeyword::STATUS_ENABLED)
                    ->save();
                ++$created;
                continue;
            }

            $dirty = false;
            if ((int)$model->getPriority() !== $priority) {
                $model->setPriority($priority);
                $dirty = true;
            }
            if ((string)$model->getSource() !== SeoKeyword::SOURCE_MANUAL) {
                $model->setSource(SeoKeyword::SOURCE_MANUAL);
                $dirty = true;
            }
            if ((int)$model->getStatus() !== SeoKeyword::STATUS_ENABLED) {
                $model->setStatus(SeoKeyword::STATUS_ENABLED);
                $dirty = true;
            }
            if ($dirty) {
                $model->save();
                ++$updated;
            }
        }

        // 清理历史假关键词（如「汉服租赁」「example」遗留），仅当主体仍为种子所有。
        /** @var SeoSubject $owner */
        $owner = $this->objectManager->getInstance(SeoSubject::class);
        $owner->load($subjectId);
        if ($owner->getId() && $this->isSeedOwned($owner)) {
            /** @var SeoKeyword $keywordModel */
            $keywordModel = $this->objectManager->getInstance(SeoKeyword::class);
            $existing = $keywordModel->clear()
                ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subjectId)
                ->select()
                ->fetchArray();
            if (is_array($existing)) {
                foreach ($existing as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $name = (string)($row[SeoKeyword::schema_fields_KEYWORD] ?? '');
                    if ($name === '' || isset($allowed[$name])) {
                        continue;
                    }
                    $kid = (int)($row[SeoKeyword::schema_fields_ID] ?? 0);
                    if ($kid <= 0) {
                        continue;
                    }
                    /** @var SeoKeyword $one */
                    $one = $this->objectManager->getInstance(SeoKeyword::class);
                    $one->load($kid);
                    if ($one->getId()) {
                        $one->delete();
                    }
                }
            }
        }

        return [
            'keywords_created' => $created,
            'keywords_updated' => $updated,
        ];
    }

    public static function resolveSeedTitle(): string
    {
        $pack = self::loadDesignSeoSubjectSeed();
        $title = trim((string)($pack['title'] ?? ''));
        if ($title !== '' && WebsiteBrandIdentitySeedService::isHanfuThemeActive()) {
            return $title;
        }

        return WebsiteBrandIdentitySeedService::resolveSeedName();
    }

    public static function resolveSeedDescription(): string
    {
        $pack = self::loadDesignSeoSubjectSeed();
        $description = trim((string)($pack['description'] ?? ''));
        if ($description !== '' && WebsiteBrandIdentitySeedService::isHanfuThemeActive()) {
            return $description;
        }

        return WebsiteBrandIdentitySeedService::resolveSeedDescription();
    }

    /**
     * @return list<array{keyword:string,priority:int}>
     */
    public static function resolveSeedKeywords(): array
    {
        $pack = self::loadDesignSeoSubjectSeed();
        $keywords = $pack['keywords'] ?? null;
        if (is_array($keywords) && $keywords !== [] && WebsiteBrandIdentitySeedService::isHanfuThemeActive()) {
            $out = [];
            foreach ($keywords as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $keyword = trim((string)($row['keyword'] ?? ''));
                if ($keyword === '') {
                    continue;
                }
                $out[] = [
                    'keyword' => $keyword,
                    'priority' => (int)($row['priority'] ?? 0),
                ];
            }
            if ($out !== []) {
                return $out;
            }
        }

        return self::SEED_KEYWORDS;
    }

    /**
     * @return array{title?:string, description?:string, keywords?:list<array{keyword:string,priority:int}>}
     */
    public static function loadDesignSeoSubjectSeed(): array
    {
        $path = (defined('BP') ? BP : '') . 'app/design/Weline/hanfu/data/seo-subject-seed.v1.php';
        if (!is_file($path)) {
            return [];
        }
        /** @var mixed $pack */
        $pack = require $path;

        return is_array($pack) ? $pack : [];
    }
}
