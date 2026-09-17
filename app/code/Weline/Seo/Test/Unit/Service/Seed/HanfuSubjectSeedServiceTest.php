<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Seed;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Seo\Model\SeoKeyword;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Service\Seed\HanfuSubjectSeedService;

/**
 * 长安汉服真实主体种子契约。
 *
 * @package Weline_Seo
 */
class HanfuSubjectSeedServiceTest extends TestCore
{
    public function testSeedCatalogMatchesLiveChanganHanfu(): void
    {
        $this->assertSame(SeoSubject::SUBJECT_TYPE_WEBSITE, HanfuSubjectSeedService::SEED_SUBJECT_TYPE);
        $this->assertSame(0, HanfuSubjectSeedService::SEED_ENTITY_ID);
        $this->assertSame(900001, HanfuSubjectSeedService::LEGACY_DEMO_ENTITY_ID);
        $this->assertSame('长安汉服 · Hanfu Atelier', HanfuSubjectSeedService::SEED_TITLE);
        $this->assertStringContainsString('明制、宋制、唐制汉服与马面裙', HanfuSubjectSeedService::SEED_DESCRIPTION);
        $this->assertStringNotContainsString('示例', HanfuSubjectSeedService::SEED_TITLE);
        $this->assertStringNotContainsString('示例', HanfuSubjectSeedService::SEED_DESCRIPTION);
        $this->assertStringNotContainsString('example.com', HanfuSubjectSeedService::SEED_DESCRIPTION);

        $names = array_column(HanfuSubjectSeedService::SEED_KEYWORDS, 'keyword');
        $this->assertContains('长安汉服', $names);
        $this->assertContains('明制汉服', $names);
        $this->assertContains('马面裙', $names);
        $this->assertNotContains('汉服租赁', $names);
    }

    public function testInstallAndUpgradeWireSeedService(): void
    {
        $install = (string)file_get_contents(BP . '/app/code/Weline/Seo/Setup/Install.php');
        $upgrade = (string)file_get_contents(BP . '/app/code/Weline/Seo/Setup/Upgrade.php');
        $this->assertStringContainsString('HanfuSubjectSeedService', $install);
        $this->assertStringContainsString('HanfuSubjectSeedService', $upgrade);
    }

    public function testSeedIdempotentWhenSubjectTableReady(): void
    {
        /** @var SeoSubject $probe */
        $probe = ObjectManager::getInstance(SeoSubject::class);
        try {
            if (!$probe->getConnection()->getConnector()->tableExist($probe->getOriginTableName())) {
                $this->markTestSkipped('weline_seo_subject 表未就绪，跳过落库幂等断言');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('无法探测主体表：' . $e->getMessage());
        }

        /** @var HanfuSubjectSeedService $seeder */
        $seeder = ObjectManager::getInstance(HanfuSubjectSeedService::class);
        $first = $seeder->seed();
        $this->assertGreaterThan(0, $first['subject_id']);
        $this->assertFalse($first['skipped']);
        $this->assertSame(HanfuSubjectSeedService::SEED_TITLE, $this->loadSeedSubject()->getTitle());
        $this->assertStringNotContainsString('example.com', (string)$this->loadSeedSubject()->getUrl());
        $this->assertStringNotContainsString('示例', (string)$this->loadSeedSubject()->getDescription());

        /** @var SeoKeyword $keywordModel */
        $keywordModel = ObjectManager::getInstance(SeoKeyword::class);
        $keywords = $keywordModel->clear()
            ->where(SeoKeyword::schema_fields_SUBJECT_ID, (int)$first['subject_id'])
            ->select()
            ->fetchArray();
        $this->assertIsArray($keywords);
        $this->assertCount(count(HanfuSubjectSeedService::SEED_KEYWORDS), $keywords);
        $names = array_map(static fn(array $row): string => (string)($row[SeoKeyword::schema_fields_KEYWORD] ?? ''), $keywords);
        $this->assertContains('长安汉服', $names);
        $this->assertNotContains('汉服租赁', $names);

        $legacy = ObjectManager::getInstance(SeoSubject::class);
        $legacy->clear()
            ->where(SeoSubject::schema_fields_SUBJECT_TYPE, SeoSubject::SUBJECT_TYPE_WEBSITE)
            ->where(SeoSubject::schema_fields_SUBJECT_ID, HanfuSubjectSeedService::LEGACY_DEMO_ENTITY_ID)
            ->find()
            ->fetch();
        $this->assertFalse((bool)$legacy->getId(), '历史 900001 假种子应已清理');

        $second = $seeder->seed();
        $this->assertSame((int)$first['subject_id'], (int)$second['subject_id']);
        $this->assertFalse($second['subject_created']);
        $this->assertSame(0, $second['keywords_created']);
    }

    public function testSeedSkipsOverwriteWhenNotSeedOwned(): void
    {
        /** @var SeoSubject $probe */
        $probe = ObjectManager::getInstance(SeoSubject::class);
        try {
            if (!$probe->getConnection()->getConnector()->tableExist($probe->getOriginTableName())) {
                $this->markTestSkipped('weline_seo_subject 表未就绪，跳过归属保护断言');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('无法探测主体表：' . $e->getMessage());
        }

        /** @var HanfuSubjectSeedService $seeder */
        $seeder = ObjectManager::getInstance(HanfuSubjectSeedService::class);
        $seeder->seed();
        $subject = $this->loadSeedSubject();
        $this->assertGreaterThan(0, (int)$subject->getId());

        $customTitle = '运营自改长安主体';
        $subject->setTitle($customTitle)
            ->setData(SeoSubject::schema_fields_MODULE, 'Operator_Custom')
            ->setData(SeoSubject::schema_fields_SCOPE, 'live')
            ->save();

        $result = $seeder->seed();
        $this->assertTrue($result['skipped']);

        $subject = $this->loadSeedSubject();
        $this->assertSame($customTitle, $subject->getTitle());

        $subject->setTitle(HanfuSubjectSeedService::SEED_TITLE)
            ->setData(SeoSubject::schema_fields_MODULE, HanfuSubjectSeedService::SEED_MODULE)
            ->setData(SeoSubject::schema_fields_SCOPE, HanfuSubjectSeedService::SEED_SCOPE)
            ->save();
        $seeder->seed();
    }

    private function loadSeedSubject(): SeoSubject
    {
        /** @var SeoSubject $subject */
        $subject = ObjectManager::getInstance(SeoSubject::class);
        $subject->clear()
            ->where(SeoSubject::schema_fields_SUBJECT_TYPE, HanfuSubjectSeedService::SEED_SUBJECT_TYPE)
            ->where(SeoSubject::schema_fields_SUBJECT_ID, HanfuSubjectSeedService::SEED_ENTITY_ID)
            ->find()
            ->fetch();

        return $subject;
    }
}
