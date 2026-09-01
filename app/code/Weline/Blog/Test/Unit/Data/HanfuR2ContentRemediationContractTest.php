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
            '160',
            '320',
            'cover_url_not_unique',
            'paragraph',
        ] as $required) {
            self::assertStringContainsString($required, $script);
        }

        self::assertStringNotContainsString('不是简单的“看起来像”', $script);
        self::assertStringNotContainsString('这个问题看似简单', $script);
        self::assertStringNotContainsString('<img ', $script);
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
