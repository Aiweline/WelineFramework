<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

final class HanfuR2ContentRemediationContractTest extends TestCase
{
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $coreProfiles = null;

    /** @return array<string,array<string,mixed>> */
    private function coreProfiles(): array
    {
        if (self::$coreProfiles === null) {
            self::$coreProfiles = require dirname(__DIR__, 3) . '/data/hanfu-r2-core-profiles.php';
        }
        return self::$coreProfiles;
    }

    /** @return string */
    private function normalized(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string)preg_replace('#https?://\S+|\[[^\]]+\]|(?:资料|Reference)\s*[:：].*$#u', '', $value);
        $value = (string)preg_replace('/[“”‘’—–\p{P}\p{Z}]+/u', ' ', $value);
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    public function testAllCoreArticlesUseReaderFacingVoice(): void
    {
        $profiles = $this->coreProfiles();
        self::assertTrue(
            function_exists('hanfuR4CoreArticle'),
            'The core renderer must expose reader-facing article output for quality checks.',
        );

        $forbidden = [
            'zh_Hans_CN' => '/先用普通语言写清|把“?缺少证据”?写进结论|(?:可追溯(?:的)?)?证据卡|唯一可执行的下一步|该主题的定义是|必须核对的观察是|卖家陈述与编辑结论|在发布任何建议前|在本文中[，,]这一结论只针对|围绕“[^”]+”[：:]|证据字段|可发布记录|场合诊断|发布“[^”]+”记录|核查清单包括|应把工作方法整理/u',
            'en_US' => '/plain-language category|evidence brief|evidence card|profile definition|required observation|editor(?:’|\x{2019}|\x{27})s conclusion|before any recommendation is published|Here, that conclusion applies specifically|evidence fields|publishable record|occasion diagnostic|working method into/iu',
        ];
        $bodies = [];

        foreach ($profiles as $slug => $profile) {
            foreach (['zh_Hans_CN', 'en_US'] as $locale) {
                $title = $locale === 'en_US'
                    ? (string)$profile['subject_en']
                    : (string)$profile['subject_zh'];
                $article = \hanfuR4CoreArticle($profile, $title, $locale, (string)$slug);
                $key = $slug . '|' . $locale;

                self::assertNotSame('', trim((string)($article['lede'] ?? '')), $key . ':lede');
                self::assertGreaterThanOrEqual(6, count($article['sections'] ?? []), $key . ':sections');
                $plain = (string)$article['lede'];
                foreach ($article['sections'] as $section) {
                    self::assertNotSame('', trim((string)($section['heading'] ?? '')), $key . ':heading');
                    self::assertNotEmpty($section['paragraphs'] ?? [], $key . ':paragraphs');
                    $plain .= ' ' . $section['heading'] . ' ' . implode(' ', $section['paragraphs']);
                    foreach (($section['table']['headers'] ?? []) as $header) {
                        $plain .= ' ' . $header;
                    }
                    foreach (($section['table']['rows'] ?? []) as $row) {
                        $plain .= ' ' . implode(' ', $row);
                    }
                    $plain .= ' ' . implode(' ', $section['bullets'] ?? []);
                }

                self::assertDoesNotMatchRegularExpression($forbidden[$locale], $plain, $key);
                foreach (['definition', 'evidence', 'risk', 'practice'] as $field) {
                    $profileField = $field . ($locale === 'en_US' ? '_en' : '_zh');
                    self::assertStringContainsString((string)$profile[$profileField], $plain, $key . ':' . $profileField);
                }
                $subject = (string)$profile[$locale === 'en_US' ? 'subject_en' : 'subject_zh'];
                self::assertLessThanOrEqual(8, substr_count($plain, $subject), $key . ':subject repetition');
                if ($locale === 'en_US') {
                    self::assertGreaterThanOrEqual(700, count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: []), $key . ':word count');
                } else {
                    self::assertGreaterThanOrEqual(1200, preg_match_all('/\p{Han}/u', $plain), $key . ':Han count');
                }
                $bodies[$key] = $plain;
            }
        }

        self::assertCount(96, $bodies);
        self::assertCount(96, array_unique($bodies));
    }

    public function testCoreTopicProfilesAreCompleteAndUnique(): void
    {
        $profiles = $this->coreProfiles();

        self::assertCount(48, $profiles);
        self::assertCount(48, array_unique(array_keys($profiles)));
        $roles = [];
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
                'editorial_role',
            ] as $field) {
                self::assertNotSame('', trim((string)($profile[$field] ?? '')), $slug . ':' . $field);
            }
            self::assertIsArray($profile['evidence_keys'] ?? null, $slug . ':evidence_keys');
            self::assertGreaterThanOrEqual(2, count($profile['evidence_keys']), $slug . ':evidence_keys');
            $roles[$profile['editorial_role']] = true;
        }
        self::assertGreaterThanOrEqual(9, count($roles));
        foreach (['platform_due_diligence', 'new_chinese_boundary', 'garment_form_history', 'fabric_craft_sizing_care', 'occasion_styling', 'purchase_decision', 'brand_factory_claim_audit', 'global_traditional_clothing_comparison', 'china_56_ethnic_dress_hub'] as $role) {
            self::assertArrayHasKey($role, $roles);
        }
    }

    public function testEveryCoreEditorialRoleHasDistinctBilingualWorkingMethod(): void
    {
        if (!function_exists('hanfuR3CoreRoleBody')) {
            require dirname(__DIR__, 3) . '/data/hanfu-r2-core-profiles.php';
        }

        $roles = [
            'platform_due_diligence' => ['SKU', 'SKU'],
            'new_chinese_boundary' => ['复原', 'reconstruction'],
            'garment_form_history' => ['领', 'collar'],
            'fabric_craft_sizing_care' => ['纤维', 'fibre'],
            'occasion_styling' => ['动作', 'movement'],
            'purchase_decision' => ['退货', 'return'],
            'brand_factory_claim_audit' => ['批次', 'batch'],
            'global_traditional_clothing_comparison' => ['地方术语', 'Local term'],
            'china_56_ethnic_dress_hub' => ['社区', 'community'],
        ];
        $context = [
            'subject' => '测试主题',
            'definition' => '测试定义',
            'evidence' => '测试证据',
            'risk' => '测试风险',
            'practice' => '测试实践',
        ];

        foreach ([false, true] as $english) {
            $signatures = [];
            foreach ($roles as $role => $tokens) {
                $body = \hanfuR3CoreRoleBody($role, $english, $context);
                foreach (['framing', 'headers', 'rows', 'diagnostic', 'checklist', 'evidence_boundary', 'conclusion'] as $field) {
                    self::assertArrayHasKey($field, $body, $role . ':' . $field);
                    self::assertNotEmpty($body[$field], $role . ':' . $field);
                }
                self::assertCount(3, $body['headers'], $role . ':headers');
                self::assertGreaterThanOrEqual(3, count($body['rows']), $role . ':rows');
                self::assertGreaterThanOrEqual(2, count($body['diagnostic']), $role . ':diagnostic');
                self::assertGreaterThanOrEqual(5, count($body['checklist']), $role . ':checklist');

                $encoded = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                self::assertStringContainsString($tokens[$english ? 1 : 0], $encoded, $role . ':anchor');
                $signatures[] = hash('sha256', $encoded);
            }
            self::assertCount(count($roles), array_unique($signatures), $english ? 'english roles' : 'chinese roles');
        }
    }

    public function testEthnicProfilesRemainExactFiftySixGroupSource(): void
    {
        $profiles = require dirname(__DIR__, 3) . '/data/china-ethnic-groups.php';

        self::assertCount(56, $profiles);
        $codes = [];
        $families = [];
        foreach ($profiles as $profile) {
            self::assertIsArray($profile);
            $code = strtolower(trim((string)($profile['code'] ?? '')));
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $code);
            self::assertArrayNotHasKey($code, $codes, 'duplicate ethnic code: ' . $code);
            $codes[$code] = true;
            foreach (['zh', 'en', 'region', 'region_en', 'silhouette', 'silhouette_en', 'fabric', 'fabric_en', 'occasion', 'occasion_en', 'motif', 'motif_en', 'community', 'community_en', 'inference_limit', 'inference_limit_en'] as $field) {
                self::assertNotSame('', trim((string)($profile[$field] ?? '')), $code . ':' . $field);
            }
            self::assertIsArray($profile['evidence_keys'] ?? null, $code . ':evidence_keys');
            self::assertGreaterThanOrEqual(2, count($profile['evidence_keys']), $code . ':evidence_keys');
            self::assertMatchesRegularExpression('/^[a-z_]+$/', (string)($profile['editorial_family'] ?? ''), $code . ':editorial_family');
            self::assertSame('neac-' . $code, $profile['evidence_keys'][0], $code . ':direct_neac_evidence');
            self::assertSame('neac-' . $code . '-overview', $profile['evidence_keys'][1], $code . ':overview_neac_evidence');
            self::assertNotSame('unesco-sericulture-silk', $profile['evidence_keys'][1], $code . ':unrelated_blanket_silk_evidence');
            $families[$profile['editorial_family']] = true;
        }
        self::assertCount(7, $families);

        $byCode = [];
        foreach ($profiles as $profile) {
            $byCode[$profile['code']] = $profile;
        }
        foreach (['hui', 'dongxiang', 'salar', 'bonan'] as $code) {
            self::assertSame('religious_modesty_dress', $byCode[$code]['editorial_family'], $code);
        }
        foreach (['shui', 'li', 'tujia'] as $code) {
            self::assertSame('craft_object_led', $byCode[$code]['editorial_family'], $code);
        }
        self::assertContains('unesco-li-textile', $byCode['li']['evidence_keys']);
    }

    public function testStructuredPrimaryEvidenceHasClaimScopesAndStableKeys(): void
    {
        $evidence = require dirname(__DIR__, 3) . '/data/hanfu-r3-source-evidence.php';
        self::assertIsArray($evidence);
        foreach (['palace-ming-yuanling', 'cns-mamian-skirt', 'unesco-nanjing-yunjin', 'unesco-sericulture-silk', 'unesco-li-textile', 'met-chinese-textiles'] as $key) {
            self::assertArrayHasKey($key, $evidence);
        }
        foreach ($evidence as $key => $record) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string)$key);
            foreach (['title', 'institution', 'url', 'claim_scope', 'language', 'accessed_at'] as $field) {
                self::assertNotSame('', trim((string)($record[$field] ?? '')), $key . ':' . $field);
            }
            self::assertMatchesRegularExpression('#^https://#', (string)$record['url']);
        }
        $neac = array_filter(array_keys($evidence), static fn(string $key): bool => str_starts_with($key, 'neac-') && !str_ends_with($key, '-overview'));
        self::assertCount(56, $neac);
        foreach ($neac as $key) {
            self::assertStringStartsWith('https://www.neac.gov.cn/seac/ztzl/', (string)$evidence[$key]['url']);
            self::assertStringContainsString('photograph', (string)$evidence[$key]['claim_scope']);
        }
        $neacOverview = array_filter(array_keys($evidence), static fn(string $key): bool => str_starts_with($key, 'neac-') && str_ends_with($key, '-overview'));
        self::assertCount(56, $neacOverview);
    }

    public function testRemediationScriptOwnsFullBilingualFileAssetAndQualityContract(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/remediate-hanfu-content-r2.php',
        );
        $editorial = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/hanfu-r2-ethnic-editorial.php',
        );
        $coreProfiles = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/hanfu-r2-core-profiles.php',
        );
        $imageManifest = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/hanfu-r3-image-manifest.php',
        );
        $scriptContract = $script . $imageManifest;

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
            'hanfuR3BuildCoreByRole',
            'hanfuR3EvidenceFor',
            'hanfuR3NormalizeReusableBlock',
            'hanfuR2IsEnglish($locale) ? 700 : 1200',
        ] as $required) {
            self::assertStringContainsString($required, $scriptContract);
        }
        foreach ([
            'hanfuR3EthnicFamilyOutline',
            'hanfuR3EthnicFamilyParagraphs',
            'hanfuR3EthnicFamilyDetail',
            "'robe_system'",
            "'craft_object_led'",
            "'evidence_keys'",
        ] as $required) {
            self::assertStringContainsString($required, $editorial);
        }
        self::assertStringContainsString('hanfuR4CoreArticle', $script);
        self::assertStringContainsString("\$article['lede']", $script);
        self::assertStringContainsString("\$article['sections']", $script);
        self::assertStringContainsString('hanfuR4PromptLanguagePattern', $script);
        self::assertStringContainsString('hanfu_r4_prompt_language_detected', $script);
        self::assertStringContainsString('function hanfuR3CoreRoleBody', $coreProfiles);

        $combinedSource = $script . $editorial;
        self::assertStringNotContainsString('不是简单的“看起来像”', $combinedSource);
        self::assertStringNotContainsString('这个问题看似简单', $combinedSource);
        self::assertStringContainsString('hanfuR3InjectContextualFigures($content, $expectedContextualAssets, $locale)', $script);
        self::assertStringContainsString('hanfuR3ValidateContextualFigures($content, $expectedContextualAssets, $locale)', $script);
        self::assertStringNotContainsString('hanfuR2UniquifyParagraphs', $script);
        self::assertStringContainsString("['--verify', '--cleanup']", $script);
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

    public function testRemediationUsesTheR3ManifestForAssetAndLocaleMetadata(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/remediate-hanfu-content-r2.php',
        );

        self::assertStringContainsString("require_once __DIR__ . '/hanfu-r3-image-manifest.php';", $script);
        self::assertStringContainsString('$imageManifest = hanfuR3ImageManifest();', $script);
        self::assertStringContainsString("\$manifestEntry['locales']['zh_Hans_CN']", $script);
        self::assertStringContainsString("\$manifestEntry['locales']['en_US']", $script);
        self::assertStringContainsString('hanfuR3ImageAssetMetadata($manifestEntry)', $script);
        self::assertStringContainsString('$library->saveAssetMetadata(', $script);
        self::assertStringContainsString("['asset_metadata_sha256']", $script);
        self::assertStringNotContainsString('hanfuR2LocaleMetadata', $script);
    }

    public function testContextualAssetsUseAcceptedProvenanceAndTheFileLibraryBoundary(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/remediate-hanfu-content-r2.php',
        );

        foreach ([
            "require_once __DIR__ . '/hanfu-r3-contextual-image-manifest.php';",
            'hanfuR3ContextualImageManifest()',
            'blog-inline/provenance.json',
            'function hanfuR3EnsureContextualAssets(',
            'blog/hanfu/r3/inline/',
            'hanfu_r3_contextual_descriptor_count_invalid',
            'hanfu_r3_contextual_provenance_identity_mismatch',
            'hanfu_r3_contextual_locale_incomplete',
            'AI 编辑插图',
            'AI editorial illustration',
            'met_open_access_public_domain',
            'is_public_domain',
            'met_fallback_reason',
            'hanfuR2EnsureAsset(',
            "'visual_role'",
            "'anchor_h2'",
            "'translation_state' => 'reviewed'",
            "'translation_origin' => 'manual'",
            "'/pub/media/blog/hanfu/r3/inline/'",
            "\$mode === '--apply' ? \$library : null",
            "\$mode === '--apply'",
        ] as $required) {
            self::assertStringContainsString($required, $script);
        }

        $contextualBoundary = substr($script, (int)strpos($script, 'function hanfuR3EnsureContextualAssets('));
        $applyGuard = strpos($contextualBoundary, 'if ($apply) {');
        self::assertIsInt($applyGuard);
        foreach (['hanfuR2EnsureAsset(', '$library->describe('] as $fileManagerOperation) {
            $operation = strpos($contextualBoundary, $fileManagerOperation);
            self::assertIsInt($operation);
            self::assertLessThan(
                $operation,
                $applyGuard,
                'Contextual FileManager reads and writes must remain behind the --apply guard.',
            );
        }
    }

    public function testContextualAssetSelfTestExercisesDescriptorsAndFailClosedProvenanceCases(): void
    {
        $script = BP . '/app/code/Weline/Blog/data/remediate-hanfu-content-r2.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --self-test-contextual-assets 2>&1';
        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $lines));
        $result = json_decode(implode(PHP_EOL, $lines), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('self-test-contextual-assets', $result['mode'] ?? null);
        self::assertSame(160, $result['contextual_topic_sets'] ?? null);
        self::assertSame(444, $result['contextual_descriptors'] ?? null);
        self::assertSame(444, $result['unique_object_keys'] ?? null);
        self::assertSame(444, $result['unique_public_urls'] ?? null);
        self::assertSame(444, $result['role_anchor_descriptors'] ?? null);
        self::assertSame(888, $result['locale_alt_caption_payloads'] ?? null);
        self::assertSame(888, $result['exact_provenance_matches'] ?? null);
        self::assertSame(888, $result['manifest_copy_isolation_matches'] ?? null);
        self::assertSame([
            'identity_mismatch' => 'hanfu_r3_contextual_provenance_identity_mismatch',
            'incomplete_locale' => 'hanfu_r3_contextual_locale_incomplete',
            'missing_provenance' => 'hanfu_r3_contextual_provenance_missing_or_duplicate',
            'orphaned_provenance' => 'hanfu_r3_contextual_provenance_orphaned',
        ], $result['fail_closed'] ?? null);

        $descriptors = $result['descriptors'] ?? null;
        $provenance = json_decode(
            (string)file_get_contents(BP . '/var/hanfu-production/final/blog-inline/provenance.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($descriptors);
        self::assertIsArray($provenance);
        self::assertCount(160, $descriptors);
        foreach ($descriptors as $baseSlug => $topicDescriptors) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string)$baseSlug);
            self::assertIsArray($topicDescriptors);
            foreach ($topicDescriptors as $descriptor) {
                self::assertIsArray($descriptor);
                self::assertMatchesRegularExpression(
                    '#^blog/hanfu/r3/inline/[a-z0-9-]+/[a-z]+-[0-9a-f]{12}\\.webp$#',
                    (string)($descriptor['object_key'] ?? ''),
                );
                self::assertSame('/pub/media/' . ($descriptor['object_key'] ?? ''), $descriptor['public_url'] ?? null);
                self::assertContains($descriptor['visual_role'] ?? null, ['context', 'form', 'craft', 'care', 'evidence']);
                self::assertGreaterThanOrEqual(1, $descriptor['anchor_h2'] ?? 0);
                $record = $provenance[$descriptor['source_sha256'] ?? ''] ?? null;
                self::assertIsArray($record);
                foreach (['zh_Hans_CN', 'en_US'] as $locale) {
                    self::assertSame(
                        $record['locales'][$locale]['default_alt'] ?? null,
                        $descriptor['locales'][$locale]['default_alt'] ?? null,
                    );
                    self::assertSame(
                        $record['locales'][$locale]['default_caption'] ?? null,
                        $descriptor['locales'][$locale]['default_caption'] ?? null,
                    );
                }
            }
        }
    }

    public function testContextualFigureSelfTestInjectsAndRejectsMalformedFigureContracts(): void
    {
        $script = BP . '/app/code/Weline/Blog/data/remediate-hanfu-content-r2.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --self-test-contextual-figures 2>&1';
        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $lines));
        $result = json_decode(implode(PHP_EOL, $lines), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('self-test-contextual-figures', $result['mode'] ?? null);
        self::assertSame(2, $result['figure_count'] ?? null);
        self::assertSame(2, $result['image_count'] ?? null);
        self::assertSame(4, $result['h2_count'] ?? null);
        self::assertSame(true, $result['prose_preserved'] ?? null);
        self::assertSame(true, $result['escaped_markup'] ?? null);
        self::assertSame(true, $result['semantic_markup'] ?? null);
        self::assertSame(true, $result['lazy_dimensions'] ?? null);
        self::assertSame(true, $result['placement_exact'] ?? null);
        self::assertSame(true, $result['mid_word_slashes_accepted'] ?? null);
        self::assertSame([
            'duplicate_slot' => 'hanfu_r3_contextual_figure_slot_duplicate',
            'unapproved_url' => 'hanfu_r3_contextual_figure_url_invalid',
            'missing_caption' => 'hanfu_r3_contextual_figure_locale_invalid',
            'wrong_locale_payload' => 'hanfu_r3_contextual_figure_locale_invalid',
            'anchor_outside_range' => 'hanfu_r3_contextual_figure_anchor_invalid',
            'extra_unapproved_img' => 'hanfu_r3_contextual_figure_img_count_invalid',
            'wrong_met_route' => 'hanfu_r3_contextual_figure_provenance_invalid',
            'met_object_id_mismatch' => 'hanfu_r3_contextual_figure_provenance_invalid',
            'wrong_met_policy' => 'hanfu_r3_contextual_figure_provenance_invalid',
            'wrong_ai_license_url' => 'hanfu_r3_contextual_figure_provenance_invalid',
            'local_path_in_alt' => 'hanfu_r3_contextual_figure_locale_copy_invalid',
            'other_absolute_local_path_in_caption' => 'hanfu_r3_contextual_figure_locale_copy_invalid',
            'localized_bracket_absolute_path' => 'hanfu_r3_contextual_figure_locale_copy_invalid',
            'localized_quote_absolute_path' => 'hanfu_r3_contextual_figure_locale_copy_invalid',
            'direct_image_url_in_caption' => 'hanfu_r3_contextual_figure_locale_copy_invalid',
        ], $result['fail_closed'] ?? null);
    }

    public function testCleanupIsRestrictedToFourFrozenHashesBehindThreeReferenceGates(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/remediate-hanfu-content-r2.php',
        );

        self::assertStringContainsString('hanfuR3ReplacedObjectAllowlist()', $script);
        self::assertStringContainsString('count($cleanupObjectKeys) !== 4', $script);
        self::assertStringContainsString('hanfuR3AllBlogReferenceHits(', $script);
        self::assertStringContainsString('hanfuR3ProjectReferenceHits(', $script);
        self::assertStringContainsString('$library->referenceCount(', $script);
        self::assertStringContainsString('hanfu_r3_cleanup_blog_reference_found', $script);
        self::assertStringContainsString('hanfu_r3_cleanup_project_reference_found', $script);
        self::assertStringContainsString('hanfu_r3_cleanup_filemanager_reference_found', $script);
        self::assertStringContainsString('hanfu_r3_cleanup_physical_delete_failed', $script);
        self::assertStringContainsString('$cleanupDescriptors[$cleanupObjectKey] = $descriptor;', $script);
        self::assertLessThan(
            strpos($script, 'foreach ($cleanupDescriptors as $cleanupObjectKey => $descriptor)'),
            strpos($script, '$cleanupFileManagerReferenceHits !== 0'),
            'Every frozen asset reference count must pass before the first deletion loop starts.',
        );
        self::assertStringNotContainsString('count($legacyObjectKeys) !== 160', $script);
        self::assertStringNotContainsString('foreach ($legacyObjectKeys as $base => $legacyObjectKey)', $script);
    }
}
