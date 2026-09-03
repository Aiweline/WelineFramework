<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

final class HanfuR2ContentRemediationContractTest extends TestCase
{
    public function testCoreTopicProfilesAreCompleteAndUnique(): void
    {
        $profiles = require dirname(__DIR__, 3) . '/data/hanfu-r2-core-profiles.php';

        self::assertCount(48, $profiles);
        self::assertCount(48, array_unique(array_keys($profiles)));
        foreach ($profiles as $slug => $profile) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string)$slug);
            foreach ([
                'subject_zh',
                'subject_en',
                'definition_zh',
                'definition_en',
                'evidence_zh',
                'evidence_en',
                'risk_zh',
                'risk_en',
                'practice_zh',
                'practice_en',
            ] as $field) {
                self::assertNotSame('', trim((string)($profile[$field] ?? '')), $slug . ':' . $field);
            }
        }
    }

    public function testEthnicProfilesRemainExactFiftySixGroupSource(): void
    {
        $profiles = require dirname(__DIR__, 3) . '/data/china-ethnic-groups.php';

        self::assertCount(56, $profiles);
        $codes = [];
        foreach ($profiles as $profile) {
            self::assertIsArray($profile);
            $code = strtolower(trim((string)($profile['code'] ?? '')));
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $code);
            self::assertArrayNotHasKey($code, $codes, 'duplicate ethnic code: ' . $code);
            $codes[$code] = true;
            foreach (['zh', 'en', 'region', 'region_en', 'silhouette', 'silhouette_en', 'fabric', 'fabric_en', 'occasion', 'occasion_en', 'motif', 'motif_en'] as $field) {
                self::assertNotSame('', trim((string)($profile[$field] ?? '')), $code . ':' . $field);
            }
        }
    }

    public function testRemediationScriptOwnsFullBilingualFileAssetAndQualityContract(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/remediate-hanfu-content-r2.php',
        );
        $editorial = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/hanfu-r2-ethnic-editorial.php',
        );

        self::assertStringContainsString(
            "require_once __DIR__ . '/hanfu-r2-ethnic-editorial.php';",
            $script,
        );
        foreach ([
            'FileAssetLibraryInterface',
            'BlogPostAdminService',
            "'zh_Hans_CN'",
            "'en_US'",
            "'source'",
            "'license'",
            "'purpose'",
            "'relations'",
            "'translation_state'",
            "'translation_origin'",
            "'default_caption'",
            'blog/hanfu/r2/covers/core/',
            'blog/hanfu/r2/covers/ethnic/',
            '--dry-run',
            '--apply',
            '--verify',
            '--cleanup',
            'substr($sourceSha, 0, 12)',
            "['asset_ready']",
            'hanfu_r2_cleanup_allowlist_invalid',
            'hanfu_r2_cleanup_post_reference_mismatch',
            'deleteObject',
            '160',
            '320',
            'cover_url_not_unique',
            'paragraph',
            'hanfuR2IsEnglish($locale) ? 330 : 620',
        ] as $required) {
            self::assertStringContainsString($required, $script);
        }
        foreach ([
            'Museum records, community accounts and dated field photographs are stronger together than an anonymous sales caption.',
            'The illustration for this article is editorial and must not be cited as field evidence.',
            '博物馆藏品、社区口述与有日期的田野照片互相印证',
            '本文图片属于编辑性说明图，不可引用为田野证据。',
        ] as $required) {
            self::assertStringContainsString($required, $editorial);
        }

        $combinedSource = $script . $editorial;
        self::assertStringNotContainsString('不是简单的“看起来像”', $combinedSource);
        self::assertStringNotContainsString('这个问题看似简单', $combinedSource);
        self::assertStringNotContainsString('<img ', $combinedSource);
    }

    public function testImagePolicyRequiresUniqueTopicCoversAndReviewedMetadata(): void
    {
        $policy = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/BLOG_IMAGE_NO_DUPLICATE.md',
        );

        self::assertStringContainsString('160 个主题封面', $policy);
        self::assertStringContainsString('中英文同主题可共用', $policy);
        self::assertStringContainsString('zh_Hans_CN', $policy);
        self::assertStringContainsString('en_US', $policy);
        self::assertStringContainsString('default_caption', $policy);
        self::assertStringContainsString('source / license / purpose / relations', $policy);
        self::assertStringContainsString('零引用证明', $policy);
    }
}
