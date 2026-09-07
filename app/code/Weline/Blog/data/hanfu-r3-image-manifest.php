<?php

declare(strict_types=1);

/**
 * R3 visual-provenance manifest for the 160 active bilingual Hanfu topics.
 *
 * Research references bound editorial interpretation only. They are never the
 * licence or provenance of the generated image itself.
 *
 * @return array<string,array<string,mixed>>
 */
function hanfuR3ImageManifest(): array
{
    static $cachedManifest = null;
    if (is_array($cachedManifest)) {
        return $cachedManifest;
    }

    $coreProfiles = hanfuR3ImageCoreTopics();
    $ethnicProfiles = require __DIR__ . '/china-ethnic-groups.php';
    $evidence = require __DIR__ . '/hanfu-r3-source-evidence.php';
    $replacements = hanfuR3ImageReplacementRecords();
    $manifest = [];

    foreach ($coreProfiles as $slug => $profile) {
        $slug = (string)$slug;
        $zhName = trim((string)($profile['zh'] ?? $slug));
        $enName = trim((string)($profile['en'] ?? $slug));
        $imagePath = 'var/hanfu-production/final/blog-core/' . $slug . '.webp';
        $replacement = $replacements[$slug] ?? null;
        $visibleFeatures = [
            'AI editorial illustration composed specifically for ' . $enName,
            'apparent topic-specific clothing, accessory, or craft cues; these cues are descriptive and not authentication evidence',
        ];
        $requiredVisibleFeatures = [
            'a composition centered on the named topic: ' . $enName,
            'a topic-specific composition that is not reused by another active cover',
        ];
        if (is_array($replacement)) {
            $visibleFeatures = $replacement['visible_features'];
            $requiredVisibleFeatures = $replacement['required_visible_features'];
        }

        $relations = [
            'blog_base_slug' => $slug,
            'topic_type' => 'core',
            'editorial_role' => (string)($profile['editorial_role'] ?? ''),
            'profile_source' => 'app/code/Weline/Blog/data/hanfu-r2-core-profiles.php',
            'source_image_path' => $imagePath,
        ];
        if (is_array($replacement)) {
            $relations['replaces_object_key'] = $replacement['old_object_key'];
        }

        $manifest[$slug] = hanfuR3ImageManifestRow(
            $imagePath,
            is_array($replacement)
                ? (string)$replacement['garment_form']
                : $zhName . '主题服饰编辑构图（不认证具体形制） / topic-related clothing editorial composition for '
                    . $enName . ' (no specific form authenticated)',
            $requiredVisibleFeatures,
            $visibleFeatures,
            is_array($replacement)
                ? (string)$replacement['source']
                : hanfuR3RetainedImageSource($imagePath),
            $relations,
            hanfuR3ResearchUrls((array)($profile['evidence_keys'] ?? []), $evidence),
            $zhName,
            $enName,
            is_array($replacement) ? ($replacement['locale_copy'] ?? []) : [],
        );
    }

    foreach ($ethnicProfiles as $profile) {
        $code = strtolower(trim((string)($profile['code'] ?? '')));
        foreach (['overview', 'occasion'] as $variant) {
            $slug = 'ethnic-' . $code . '-' . ($variant === 'overview' ? 'dress-overview' : 'occasion-craft');
            $zhName = trim((string)($profile['zh'] ?? $code))
                . ($variant === 'overview' ? '服饰概览' : '服饰场合与工艺');
            $enName = trim((string)($profile['en'] ?? $code))
                . ($variant === 'overview' ? ' dress overview' : ' dress, occasion, and craft');
            $imagePath = 'var/hanfu-production/final/blog-ethnic/' . $code . '-' . $variant . '.webp';
            $replacement = $replacements[$slug] ?? null;
            $silhouette = trim((string)($profile['silhouette_en'] ?? 'the reviewed clothing profile'));
            $fabric = trim((string)($profile['fabric_en'] ?? 'surface and craft cues'));
            $requiredVisibleFeatures = $variant === 'overview'
                ? [
                    'an AI editorial composition centered on ' . $enName,
                    'apparent silhouette cues from the reviewed profile: ' . $silhouette,
                ]
                : [
                    'an AI editorial composition distinct from the paired overview cover',
                    'apparent clothing and craft context cues without assigning a ritual rank',
                ];
            $visibleFeatures = [
                'AI editorial illustration created specifically for ' . $enName,
                'apparent silhouette cues associated with ' . $silhouette . '; not a subgroup identification',
                'apparent colour, surface, or craft cues associated with ' . $fabric . '; not fibre identification or handmade proof',
            ];
            if (is_array($replacement)) {
                $visibleFeatures = $replacement['visible_features'];
                $requiredVisibleFeatures = $replacement['required_visible_features'];
            }

            $relations = [
                'blog_base_slug' => $slug,
                'topic_type' => 'ethnic_dress',
                'ethnic_code' => $code,
                'variant' => $variant,
                'profile_source' => 'app/code/Weline/Blog/data/china-ethnic-groups.php',
                'source_image_path' => $imagePath,
            ];
            if (is_array($replacement)) {
                $relations['replaces_object_key'] = $replacement['old_object_key'];
            }

            $research = hanfuR3ResearchUrls((array)($profile['evidence_keys'] ?? []), $evidence);
            if ($slug === 'ethnic-shui-dress-overview') {
                $research[] = 'https://www.ihchina.cn/project_details/13992/';
                $research = array_values(array_unique($research));
            }
            $manifest[$slug] = hanfuR3ImageManifestRow(
                $imagePath,
                is_array($replacement)
                    ? (string)$replacement['garment_form']
                    : $zhName . '主题服饰编辑构图（不认证支系形制） / topic-related clothing editorial composition for '
                        . $enName . ' (no subgroup form authenticated)',
                $requiredVisibleFeatures,
                $visibleFeatures,
                is_array($replacement)
                    ? (string)$replacement['source']
                    : hanfuR3RetainedImageSource($imagePath),
                $relations,
                $research,
                $zhName,
                $enName,
                is_array($replacement) ? ($replacement['locale_copy'] ?? []) : [],
            );
        }
    }

    ksort($manifest, SORT_STRING);
    if (count($manifest) !== 160) {
        throw new RuntimeException('hanfu_r3_image_manifest_count_invalid:' . count($manifest));
    }
    return $cachedManifest = $manifest;
}

/** @return list<string> */
function hanfuR3ReplacedObjectAllowlist(): array
{
    return array_values(array_map(
        static fn(array $record): string => (string)$record['old_object_key'],
        hanfuR3ImageReplacementRecords(),
    ));
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function hanfuR3ImageAssetMetadata(array $row): array
{
    $fields = [
        'garment_form',
        'required_visible_features',
        'visible_features',
        'forbidden_features',
        'source_type',
        'source',
        'license',
        'purpose',
        'relations',
        'created_for',
        'reviewer',
        'reviewed_at',
        'review_scope',
        'review_limitations',
        'review_method',
        'review_evidence',
        'research_references',
    ];
    $metadata = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $row) || $row[$field] === '' || $row[$field] === []) {
            throw new RuntimeException('hanfu_r3_image_asset_metadata_incomplete:' . $field);
        }
        $metadata[$field] = $row[$field];
    }
    if ($metadata['source_type'] !== 'original_ai_generation') {
        throw new RuntimeException('hanfu_r3_image_source_type_invalid');
    }
    foreach (['zh_Hans_CN', 'en_US'] as $locale) {
        $localeRow = $row['locales'][$locale] ?? null;
        if (!is_array($localeRow)
            || ($localeRow['translation_state'] ?? '') !== 'reviewed'
            || ($localeRow['translation_origin'] ?? '') !== 'manual'
        ) {
            throw new RuntimeException('hanfu_r3_image_locale_metadata_invalid:' . $locale);
        }
        foreach (['display_name', 'default_alt', 'description', 'default_caption'] as $field) {
            if (trim((string)($localeRow[$field] ?? '')) === '') {
                throw new RuntimeException('hanfu_r3_image_locale_metadata_incomplete:' . $locale . ':' . $field);
            }
        }
    }
    $metadata['review'] = [
        'state' => 'contact_sheet_screened',
        'reviewer' => $metadata['reviewer'],
        'reviewed_at' => $metadata['reviewed_at'],
        'scope' => $metadata['review_scope'],
        'limitations' => $metadata['review_limitations'],
        'method' => $metadata['review_method'],
        'evidence' => $metadata['review_evidence'],
        'checks' => [
            'broad_topic_alignment',
            'card_crop_legibility',
            'non_evidentiary_boundaries',
            'no_logo',
            'no_watermark',
            'whole_and_panel_visual_uniqueness',
        ],
    ];
    return $metadata;
}

/**
 * @param list<string> $requiredVisibleFeatures
 * @param list<string> $visibleFeatures
 * @param array<string,string> $relations
 * @param list<string> $researchReferences
 * @param array<string,array<string,string>> $localeCopy
 * @return array<string,mixed>
 */
function hanfuR3ImageManifestRow(
    string $imagePath,
    string $garmentForm,
    array $requiredVisibleFeatures,
    array $visibleFeatures,
    string $source,
    array $relations,
    array $researchReferences,
    string $zhName,
    string $enName,
    array $localeCopy = [],
): array {
    $boundaries = [
        'object_level_evidence',
        'subgroup_identity',
        'ritual_rank',
        'dynasty_attribution',
        'fibre_identification',
        'handmade_proof',
    ];
    $locales = hanfuR3ImageLocaleRows($zhName, $enName);
    foreach ($localeCopy as $locale => $copy) {
        if (isset($locales[$locale]) && is_array($copy)) {
            $locales[$locale] = array_replace($locales[$locale], $copy);
        }
    }

    return [
        'image_path' => $imagePath,
        'garment_form' => $garmentForm,
        'required_visible_features' => array_values($requiredVisibleFeatures),
        'visible_features' => array_values($visibleFeatures),
        'forbidden_features' => $boundaries,
        'source_type' => 'original_ai_generation',
        'source' => $source,
        'license' => 'Project-generated OpenAI ImageGen asset approved for commercial editorial use in Amayun-owned channels; research references are not the image licence.',
        'purpose' => 'bilingual_blog_cover',
        'relations' => $relations,
        'created_for' => 'Amayun Hanfu R3 image audit and selective remediation 2026-09-04',
        'reviewer' => 'Codex R3 contact-sheet screening',
        'reviewed_at' => '2026-09-04',
        'review_scope' => 'Broad topic alignment, card-crop legibility, logo/watermark screening, and cross-topic visual-collision screening on the current 160 source images.',
        'review_limitations' => 'Editorial screening only. It does not authenticate a garment, ethnic or subgroup identity, ritual rank, dynasty, fibre, handmade production, or every named construction feature.',
        'review_method' => 'Codex inspected the three current-source contact sheets for broad topic and crop conflicts, then combined that screening with deterministic SHA-256 and whole/panel dHash checks. This is editorial screening, not historical or cultural authentication.',
        'review_evidence' => hanfuR3ImageReviewEvidence($relations),
        'research_references' => array_values($researchReferences),
        'locales' => $locales,
    ];
}

/** @return array<string,array<string,string>> */
function hanfuR3ImageLocaleRows(string $zhName, string $enName): array
{
    return [
        'zh_Hans_CN' => [
            'display_name' => $zhName . '编辑插图',
            'default_alt' => 'AI 编辑插图：' . $zhName . '主题服饰或工艺线索',
            'description' => '为“' . $zhName . '”主题创作的 AI 编辑插图，已完成当前源图接触表主题筛查与重复度检测；可见线索仅用于主题导航，不构成专业实物鉴定，也不证明支系身份、礼仪等级、朝代、纤维成分或手工制作。',
            'default_caption' => '“' . $zhName . '”主题 AI 编辑配图；实物级与文化身份结论须回到正文列出的独立证据。',
            'translation_state' => 'reviewed',
            'translation_origin' => 'manual',
        ],
        'en_US' => [
            'display_name' => $enName . ' editorial illustration',
            'default_alt' => 'AI editorial illustration with clothing or craft cues for ' . $enName,
            'description' => 'AI editorial illustration for ' . $enName . ', screened on the current-source contact sheet and for visual duplication; visible cues guide the topic and do not authenticate an object, subgroup identity, ritual rank, dynasty, fibre, or handmade production.',
            'default_caption' => 'AI editorial visual for ' . $enName . '; use the article’s independent sources for object-level and cultural-identity claims.',
            'translation_state' => 'reviewed',
            'translation_origin' => 'manual',
        ],
    ];
}

/** @param array<string,string> $relations @return list<string> */
function hanfuR3ImageReviewEvidence(array $relations): array
{
    $root = '.superpowers/sdd/2026-09-03-hanfu-blog-editorial-remediation/';
    $topicType = (string)($relations['topic_type'] ?? '');
    $variant = (string)($relations['variant'] ?? '');
    $contactSheet = $topicType === 'core'
        ? $root . 'core-contact-sheet.png'
        : $root . ($variant === 'occasion' ? 'ethnic-occasion-contact-sheet.png' : 'ethnic-overview-contact-sheet.png');

    return [
        $root . 'visual-audit.md',
        $contactSheet,
        (string)($relations['source_image_path'] ?? ''),
    ];
}

/** @param list<string> $keys @param array<string,array<string,string>> $evidence @return list<string> */
function hanfuR3ResearchUrls(array $keys, array $evidence): array
{
    $urls = [];
    foreach ($keys as $key) {
        $url = trim((string)($evidence[(string)$key]['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('hanfu_r3_image_research_reference_missing:' . (string)$key);
        }
        $urls[] = $url;
    }
    return array_values(array_unique($urls));
}

function hanfuR3RetainedImageSource(string $imagePath): string
{
    return 'OpenAI ImageGen; retained R2 project source: ' . $imagePath
        . '; legacy generation request identifier was not preserved; source reviewed in the R3 audit on 2026-09-04.';
}

/** @return array<string,array{zh:string,en:string,editorial_role:string,evidence_keys:list<string>}> */
function hanfuR3ImageCoreTopics(): array
{
    $rows = [
        ['tiktok-shop-hanfu-guide', 'TikTok Shop 汉服渠道', 'TikTok Shop Hanfu listings', 'platform_due_diligence'],
        ['aliexpress-hanfu-europe-sea', 'AliExpress 欧洲与东南亚汉服采购', 'AliExpress Hanfu buying for Europe and Southeast Asia', 'platform_due_diligence'],
        ['shein-new-chinese-style-hanfu', 'SHEIN 新中式与汉服商品', 'SHEIN new-Chinese-style and Hanfu products', 'new_chinese_boundary'],
        ['temu-hanfu-accessories-guide', 'Temu 汉服配饰', 'Temu Hanfu accessories', 'platform_due_diligence'],
        ['hanfu-vertical-stores-top10-overview', '汉服垂直店铺渠道评估', 'specialist Hanfu store evaluation', 'purchase_decision'],
        ['newmoondance-hanfu-review', 'NewMoonDance 汉服渠道', 'NewMoonDance Hanfu', 'purchase_decision'],
        ['nuwa-hanfu-review', 'Nuwa Hanfu 渠道', 'Nuwa Hanfu', 'purchase_decision'],
        ['hanfu-story-review', 'Hanfu Story 渠道', 'Hanfu Story', 'brand_factory_claim_audit'],
        ['newhanfu-store-review', 'NewHanfu Store 渠道', 'NewHanfu Store', 'purchase_decision'],
        ['fashion-hanfu-review', 'Fashion Hanfu 渠道', 'Fashion Hanfu', 'purchase_decision'],
        ['intervene-new-chinese-designer', 'Intervene 新中式设计', 'Intervene new-Chinese-style design', 'new_chinese_boundary'],
        ['dawn-x-dare-han-element-buyer', 'Dawn x Dare 汉元素商品', 'Dawn x Dare Han-inspired products', 'purchase_decision'],
        ['doresuwe-costume-hanfu-formal', 'Doresuwe 礼服、表演服与汉服页面', 'Doresuwe formalwear, costume and Hanfu listings', 'china_56_ethnic_dress_hub'],
        ['east-meets-dress-chinese-wedding', 'East Meets Dress 中式婚礼服', 'East Meets Dress Chinese wedding attire', 'occasion_styling'],
        ['soulsfen-oriental-retro-huafu', 'Soulsfen 东方复古华服', 'Soulsfen oriental-retro dress', 'china_56_ethnic_dress_hub'],
        ['what-is-hanfu-complete-guide', '汉服基础定义', 'the definition of Hanfu', 'garment_form_history'],
        ['hanfu-styles-ruqun-mamian-yuanling', '襦裙、马面裙与圆领袍分类', 'ruqun, mamian and yuanling classification', 'garment_form_history'],
        ['hanfu-through-dynasties-tang-song-ming', '唐、宋、明服饰线索', 'Tang, Song and Ming dress cues', 'garment_form_history'],
        ['hanfu-fabrics-embroidery-green-manufacturing', '汉服面料、刺绣与绿色制造', 'Hanfu fabrics, embroidery and greener manufacturing', 'fabric_craft_sizing_care'],
        ['hanfu-occasions-daily-wedding-festival', '汉服日常、婚礼与节令场合', 'daily, wedding and festival Hanfu', 'occasion_styling'],
        ['amayun-technology-company-story', 'Amayun Technology 企业故事', 'the Amayun Technology company story', 'brand_factory_claim_audit'],
        ['amayun-origin-visits-factory-partners', 'Amayun 产地走访与工厂合作', 'Amayun origin visits and factory partnerships', 'brand_factory_claim_audit'],
        ['amayun-handmade-green-mechanical-production', 'Amayun 手工、绿色与机械生产说明', 'Amayun handwork, sustainability and machine production', 'china_56_ethnic_dress_hub'],
        ['hanfu-daily-commute-styling', '汉服日常通勤穿搭', 'daily-commute Hanfu styling', 'occasion_styling'],
        ['hanfu-wedding-festival-styling', '汉服婚礼与节庆穿搭', 'Hanfu wedding and festival styling', 'occasion_styling'],
        ['hanfu-hair-makeup-accessories-guide', '汉服发型、妆容与配饰', 'Hanfu hair, makeup and accessories', 'china_56_ethnic_dress_hub'],
        ['hanfu-size-chart-care-guide', '汉服尺码与护理', 'Hanfu sizing and care', 'fabric_craft_sizing_care'],
        ['hanfu-buying-guides-hub', '汉服购买指南体系', 'the Hanfu buying-guide hub', 'purchase_decision'],
        ['hanfu-beginner-buyer-checklist', '汉服新手购买清单', 'the beginner Hanfu buying checklist', 'purchase_decision'],
        ['choose-first-mamian-or-ruqun', '第一条马面裙或第一套襦裙', 'choosing a first mamian skirt or ruqun', 'garment_form_history'],
        ['amayun-factory-direct-value-explained', 'Amayun 工厂直达价值', 'Amayun factory-direct value', 'brand_factory_claim_audit'],
        ['world-ethnic-dress-and-hanfu', '世界民族服饰与汉服比较', 'world ethnic dress and Hanfu comparison', 'global_traditional_clothing_comparison'],
        ['kimono-hanbok-aodai-vs-hanfu', '和服、韩服、奥黛与汉服比较', 'kimono, hanbok, áo dài and Hanfu comparison', 'global_traditional_clothing_comparison'],
        ['sari-sarong-southeast-south-asia-dress', '纱丽、纱笼与南亚东南亚服饰', 'sari, sarong and South/Southeast Asian dress', 'global_traditional_clothing_comparison'],
        ['mena-africa-traditional-dress-guide', '中东、北非与撒哈拉以南传统服饰', 'traditional dress across MENA and sub-Saharan Africa', 'global_traditional_clothing_comparison'],
        ['european-folk-american-traditional-dress', '欧洲民俗服装与美洲传统服饰', 'European folk and American traditional dress', 'global_traditional_clothing_comparison'],
        ['where-to-buy-hanfu-top-marketplaces', '汉服购买渠道总览', 'Hanfu marketplace selection', 'purchase_decision'],
        ['amazon-hanfu-buying-guide', 'Amazon 汉服购买', 'Amazon Hanfu buying', 'purchase_decision'],
        ['hanfu-styling-complete-guide', '汉服完整穿搭路径', 'a complete Hanfu styling path', 'occasion_styling'],
        ['hanfu-menswear-guide', '汉服男装入门', 'beginner Hanfu menswear', 'china_56_ethnic_dress_hub'],
        ['mens-yuanling-robe-checklist', '男装圆领袍校核', 'men’s yuanling robe verification', 'garment_form_history'],
        ['china-56-ethnic-dress-hub', '中国 56 个民族服饰导览', 'China’s 56 ethnic dress hub', 'china_56_ethnic_dress_hub'],
        ['why-amayun-factory-direct-hanfu', 'Amayun 工厂直达汉服说明', 'Amayun factory-direct Hanfu', 'brand_factory_claim_audit'],
        ['yesstyle-hanfu-asia-fashion-gateway', 'YesStyle 亚洲时尚渠道中的汉服', 'Hanfu in the YesStyle Asian-fashion marketplace', 'china_56_ethnic_dress_hub'],
        ['etsy-hanfu-handmade-custom', 'Etsy 汉服手工与定制', 'Etsy handmade and custom Hanfu', 'china_56_ethnic_dress_hub'],
        ['ebay-hanfu-secondhand-cosplay', 'eBay 二手汉服与 cosplay 区分', 'eBay second-hand Hanfu and cosplay', 'purchase_decision'],
        ['shopee-hanfu-southeast-asia', 'Shopee 东南亚汉服购买', 'Shopee Hanfu buying in Southeast Asia', 'platform_due_diligence'],
        ['lazada-new-chinese-style-sea', 'Lazada 东南亚新中式与汉服', 'Lazada new-Chinese-style and Hanfu listings in Southeast Asia', 'platform_due_diligence'],
    ];
    $evidenceByRole = [
        'garment_form_history' => ['palace-ming-yuanling', 'cns-mamian-skirt', 'met-chinese-textiles'],
        'fabric_craft_sizing_care' => ['unesco-sericulture-silk', 'unesco-nanjing-yunjin', 'met-chinese-textiles'],
        'china_56_ethnic_dress_hub' => ['unesco-li-textile', 'met-chinese-textiles'],
        'platform_due_diligence' => ['consumer-listing-verification', 'retailer-claim-boundary'],
        'purchase_decision' => ['consumer-listing-verification', 'retailer-claim-boundary'],
        'brand_factory_claim_audit' => ['consumer-listing-verification', 'retailer-claim-boundary'],
        'new_chinese_boundary' => ['met-chinese-textiles', 'unesco-sericulture-silk'],
        'occasion_styling' => ['met-chinese-textiles', 'unesco-sericulture-silk'],
        'global_traditional_clothing_comparison' => ['met-chinese-textiles', 'unesco-sericulture-silk'],
    ];
    $topics = [];
    foreach ($rows as [$slug, $zh, $en, $role]) {
        $topics[$slug] = [
            'zh' => $zh,
            'en' => $en,
            'editorial_role' => $role,
            'evidence_keys' => $evidenceByRole[$role],
        ];
    }
    return $topics;
}

/** @return array<string,array<string,mixed>> */
function hanfuR3ImageReplacementRecords(): array
{
    $generatedRoot = '.superpowers/sdd/2026-09-03-hanfu-blog-editorial-remediation/generated/';
    return [
        'hanfu-styles-ruqun-mamian-yuanling' => [
            'source' => 'OpenAI ImageGen; generated source: ' . $generatedRoot . 'forms-guide-cover.webp; source sha256: 3d9726db3770da9ea2fb8929b7a70b5e433f4c988b4e764da1be9eeed21b16ee.',
            'old_object_key' => 'blog/hanfu/r2/covers/core/hanfu-styles-ruqun-mamian-yuanling-2ed07e6cb2d6.webp',
            'garment_form' => '襦裙套装、马面裙与圆领袍 / ruqun ensemble, mamian skirt, and round-collar robe',
            'required_visible_features' => ['separate ruqun, mamian, and yuanling garment forms', 'construction cues visible without a dynasty claim'],
            'visible_features' => [
                'right-closing cross-collar ruqun ensemble',
                'front-facing mamian skirt with a flat central door and side pleats',
                "round-collar robe with closure points on the wearer's right",
            ],
            'locale_copy' => [],
        ],
        'choose-first-mamian-or-ruqun' => [
            'source' => 'OpenAI ImageGen; generated source: ' . $generatedRoot . 'mamian-vs-ruqun-cover.webp; source sha256: f1f70ad4df1f61e114044cb9634227ace866453b31c099b4fbd5ef1d4401695d.',
            'old_object_key' => 'blog/hanfu/r2/covers/core/choose-first-mamian-or-ruqun-01907a1bee4d.webp',
            'garment_form' => '单独马面裙与完整襦裙对比 / standalone mamian skirt versus complete ruqun',
            'required_visible_features' => ['standalone mamian skirt and complete ruqun shown as separate choices', 'traditional blouse described without recasting it as contemporary clothing'],
            'visible_features' => ['standalone mamian skirt', 'complete ruqun', 'separately displayed traditional blouse'],
            'locale_copy' => [],
        ],
        'hanfu-styling-complete-guide' => [
            'source' => 'OpenAI ImageGen; generated source: ' . $generatedRoot . 'styling-guide-cover.webp; source sha256: 24e3f964a8175e3548a0674e6d4c907ae5134e59c42ce52c2d1c01636ff05f96.',
            'old_object_key' => 'blog/hanfu/r2/covers/core/hanfu-styling-complete-guide-6da1159389d7.webp',
            'garment_form' => '交领上衣与下装套装 / cross-collar upper-and-lower ensemble',
            'required_visible_features' => ['cross-collar upper-and-lower ensemble', 'practical styling and fit-check accessories without dynasty identification'],
            'visible_features' => ['cross-collar upper-and-lower ensemble', 'waist ties', 'shoes', 'belt', 'hair ornament', 'measuring tape'],
            'locale_copy' => [],
        ],
        'ethnic-shui-dress-overview' => [
            'source' => 'OpenAI ImageGen; generated source: ' . $generatedRoot . 'shui-overview-cover.webp; source sha256: b391920c44a99920b57c0606f0293606578ccd2772f908d61b47d6f85e1e3ce2.',
            'old_object_key' => 'blog/hanfu/r2/covers/ethnic/shui-overview-1802aa2cee55.webp',
            'garment_form' => '水族马尾绣技法细节（非服装实物） / horsehair-embroidery technique detail (not a garment object)',
            'required_visible_features' => ['horsehair-embroidery technique shown as an editorial process illustration', 'composition distinct from the Shui occasion cover'],
            'visible_features' => [
                'AI-created editorial illustration of horsehair-embroidery technique',
                'indigo ground',
                'raised silk-wrapped horsehair cord outlines',
                'coloured braided fill',
                'hand stitching',
                'sequins',
            ],
            'locale_copy' => [
                'zh_Hans_CN' => [
                    'description' => '水族马尾绣技法的 AI 编辑插图，可见靛蓝底、丝线包缠马尾芯形成的凸起轮廓、彩色扁线填绣、手针与亮片；它不是博物馆实物照片，也不是田野证据。',
                    'default_caption' => 'AI 创作的水族马尾绣技法编辑示意；不指认制作者、村寨、年代、礼仪角色或收藏编号。',
                ],
                'en_US' => [
                    'description' => 'AI-created editorial illustration of horsehair-embroidery technique, with an indigo ground, raised silk-wrapped horsehair cord, coloured braided fill, hand stitching, and sequins; it is not a photographed museum object and not field evidence.',
                    'default_caption' => 'AI-created editorial illustration, not a photographed museum object and not field evidence; it does not identify a maker, village, date, ritual role, or collection number.',
                ],
            ],
        ],
    ];
}
