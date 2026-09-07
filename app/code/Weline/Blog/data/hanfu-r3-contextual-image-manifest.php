<?php

declare(strict_types=1);

/**
 * Contextual inline-image directions for the 160 active R3 bilingual topics.
 *
 * These records direct article illustrations. They are deliberately not image
 * identification, historical authentication, or cultural-identity evidence.
 *
 * @return array<string,array{slots:list<array<string,mixed>>}>
 */
function hanfuR3ContextualImageManifest(): array
{
    static $cachedManifest = null;
    if (is_array($cachedManifest)) {
        return $cachedManifest;
    }

    require_once __DIR__ . '/hanfu-r3-image-manifest.php';

    $topics = [];
    foreach (hanfuR3ImageCoreTopics() as $slug => $profile) {
        $topics[$slug] = hanfuR3ContextualTopicRow(
            (string)$slug,
            (string)$profile['zh'],
            (string)$profile['en'],
            'core',
            (string)$profile['editorial_role'],
        );
    }
    foreach (require __DIR__ . '/china-ethnic-groups.php' as $profile) {
        foreach (['dress-overview', 'occasion-craft'] as $variant) {
            $slug = 'ethnic-' . $profile['code'] . '-' . $variant;
            $topics[$slug] = hanfuR3ContextualTopicRow(
                $slug,
                (string)$profile['zh'],
                (string)$profile['en'],
                'ethnic_dress',
                (string)$profile['editorial_family'],
                $variant,
            );
        }
    }

    ksort($topics, SORT_STRING);
    if (count($topics) !== 160) {
        throw new RuntimeException('hanfu_r3_contextual_image_manifest_count_invalid:' . count($topics));
    }

    return $cachedManifest = $topics;
}

/**
 * @return array{slots:list<array<string,mixed>>}
 */
function hanfuR3ContextualTopicRow(
    string $slug,
    string $zhName,
    string $enName,
    string $topicType,
    string $profileTheme,
    string $variant = '',
): array {
    $slots = [];
    $roles = hanfuR3ContextualExplicitRoles(
        $slug,
        hanfuR3ContextualSlotRoles($topicType, $profileTheme),
    );
    foreach ($roles as $index => $role) {
        $slots[] = hanfuR3ContextualSlot(
            $role,
            $index + 2,
            $zhName,
            $enName,
            $topicType,
            $variant,
            hanfuR3ContextualExplicitCopy($slug, $role),
        );
    }

    return ['slots' => $slots];
}

/** @param list<string> $derivedRoles @return list<string> */
function hanfuR3ContextualExplicitRoles(string $slug, array $derivedRoles): array
{
    return match ($slug) {
        'hanfu-occasions-daily-wedding-festival' => ['context', 'form', 'craft'],
        'hanfu-fabrics-embroidery-green-manufacturing' => ['context', 'craft', 'evidence'],
        'hanfu-size-chart-care-guide' => ['context', 'care', 'evidence'],
        default => $derivedRoles,
    };
}

/** @return list<string> */
function hanfuR3ContextualSlotRoles(string $topicType, string $profileTheme): array
{
    if ($topicType === 'ethnic_dress') {
        return ['context', 'form', 'evidence'];
    }

    return match ($profileTheme) {
        'garment_form_history' => ['context', 'form', 'evidence'],
        'fabric_craft_sizing_care' => ['context', 'craft', 'care'],
        'occasion_styling' => ['context', 'form', 'care'],
        default => ['context', 'evidence'],
    };
}

/**
 * @param array{zh_Hans_CN:string,en_US:string} $explicitCopy
 * @return array<string,mixed>
 */
function hanfuR3ContextualSlot(
    string $visualRole,
    int $anchorH2,
    string $zhName,
    string $enName,
    string $topicType,
    string $variant,
    array $explicitCopy,
): array {
    $copy = $explicitCopy === []
        ? hanfuR3ContextualDefaultCopy($visualRole, $zhName, $enName, $topicType, $variant)
        : $explicitCopy;

    return [
        'visual_role' => $visualRole,
        'anchor_h2' => $anchorH2,
        'locale_copy' => $copy,
        'provenance' => hanfuR3ContextualProvenance($visualRole, $topicType, $variant),
    ];
}

/** @return array{zh_Hans_CN:string,en_US:string} */
function hanfuR3ContextualDefaultCopy(
    string $visualRole,
    string $zhName,
    string $enName,
    string $topicType,
    string $variant,
): array {
    $isEthnic = $topicType === 'ethnic_dress';
    $zhBoundary = $isEthnic ? '，不据图确认具体族属支系、地区、身份、礼仪角色或年代' : '，不据图确认具体形制、朝代、材料或历史事实';
    $enBoundary = $isEthnic
        ? '; it does not establish a subgroup, locality, identity, ritual role, or date'
        : '; it does not establish a specific form, dynasty, material, or historical fact';

    return match ($visualRole) {
        'context' => [
            'zh_Hans_CN' => '为“' . $zhName . '”正文提供场景与阅读节奏的编辑插图' . $zhBoundary . '。',
            'en_US' => 'An editorial context visual for “' . $enName . '” that supports reading flow' . $enBoundary . '.',
        ],
        'form' => [
            'zh_Hans_CN' => '呈现“' . $zhName . '”相关的可见层次、轮廓或搭配关系的编辑示意' . $zhBoundary . '。',
            'en_US' => 'An editorial view of visible layers, silhouette, or styling relationships for “' . $enName . '”' . $enBoundary . '.',
        ],
        'craft' => [
            'zh_Hans_CN' => '呈现“' . $zhName . '”正文所讨论材料或工艺的过程、工具或细节线索；不据图确认纤维成分、技法或手工制作。',
            'en_US' => 'An editorial process, tool, or detail cue for materials or craft discussed in “' . $enName . '”; it does not establish fibre, technique, or handmade production.',
        ],
        'care' => [
            'zh_Hans_CN' => '为“' . $zhName . '”的尺码、穿着或护理建议提供操作性编辑示意；不替代具体商品说明或专业建议。',
            'en_US' => 'A practical editorial visual for sizing, wear, or care guidance in “' . $enName . '”; it does not replace product instructions or professional advice.',
        ],
        default => [
            'zh_Hans_CN' => '为“' . $zhName . '”提示应回到正文出处核对的证据路径；插图本身不构成认证证据。',
            'en_US' => 'An editorial prompt to return to the cited evidence in “' . $enName . '”; the visual itself is not authentication evidence.',
        ],
    };
}

/** @return array{zh_Hans_CN:string,en_US:string} */
function hanfuR3ContextualExplicitCopy(string $slug, string $visualRole): array
{
    $copy = [
        'hanfu-occasions-daily-wedding-festival' => [
            'context' => ['婚礼、节令与日常场合的阅读节奏插图；不据图确认礼仪等级、婚礼角色或历史场景。', 'A reading-flow visual for wedding, festival, and daily occasions; it does not establish ritual rank, wedding role, or a historical scene.'],
        ],
        'hanfu-styles-ruqun-mamian-yuanling' => [
            'form' => ['襦裙、马面裙与圆领袍的分类阅读示意；不以插图替代结构、年代或实物判断。', 'A classification-reading visual for ruqun, mamian skirts, and round-collar robes; it does not replace construction, dating, or object assessment.'],
        ],
        'hanfu-through-dynasties-tang-song-ming' => [
            'evidence' => ['提示读者把唐、宋、明线索回到正文证据核对；插图不作朝代归属认证。', 'A prompt to verify Tang, Song, and Ming cues against the article’s evidence; the visual is not dynasty attribution.'],
        ],
        'hanfu-fabrics-embroidery-green-manufacturing' => [
            'craft' => ['面料、刺绣与制造议题的工艺细节插图；不据图确认纤维、针法、环保表现或手工制作。', 'A craft-detail visual for fabric, embroidery, and manufacturing; it does not establish fibre, stitch technique, sustainability performance, or handmade production.'],
        ],
        'hanfu-size-chart-care-guide' => [
            'care' => ['尺码表与护理步骤的操作性插图；不替代具体商品尺码、洗护标签或专业建议。', 'A practical visual for size-chart and care steps; it does not replace a product’s measurements, care label, or professional advice.'],
        ],
        'hanfu-styling-complete-guide' => [
            'form' => ['以大都会艺术博物馆藏裙的完整展开图观察平整裙片与褶裥的关系；藏品信息以原馆目录为准，不作为现代商品图。', 'The complete open view of a skirt in The Met collection shows flat panels and grouped pleats; the museum catalog identifies the object, not a modern retail product.'],
        ],
        'ethnic-blang-dress-overview' => [
            'form' => ['布朗族服饰概览的轮廓与层次阅读示意；不据图确认村寨、支系、身份、年代或节庆角色。', 'A silhouette-and-layer reading visual for the Blang dress overview; it does not establish village, branch, identity, date, or festival role.'],
        ],
        'ethnic-mongol-dress-overview' => [
            'form' => ['蒙古族服饰概览的层次与穿着关系示意；不据图确认地区、群体、季节、身份或仪式角色。', 'A layering-and-wear-relationship visual for the Mongol dress overview; it does not establish locality, group, season, identity, or ritual role.'],
        ],
    ];

    $row = $copy[$slug][$visualRole] ?? null;
    if (!is_array($row)) {
        return [];
    }

    return ['zh_Hans_CN' => $row[0], 'en_US' => $row[1]];
}

function hanfuR3ContextualProvenance(string $visualRole, string $topicType, string $variant): string
{
    $scope = $topicType === 'ethnic_dress'
        ? ($variant === 'occasion-craft' ? 'ethnic occasion and craft context' : 'ethnic dress overview context')
        : 'approved core-topic profile';

    return 'Derived from the ' . $scope . ' for the article; not image provenance, not object authentication, and not cultural-identity evidence.';
}
