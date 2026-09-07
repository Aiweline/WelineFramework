<?php

declare(strict_types=1);

/**
 * Hanfu content R2 remediation.
 *
 * Usage:
 *   php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --dry-run
 *   php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --apply
 *   php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --verify
 *   php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --cleanup
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 4) . '/bootstrap.php';

const HANFU_R2_WEBSITE_ID = 0;
const HANFU_R2_DISK = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
const HANFU_R2_AUTHOR = 'Amayun Hanfu Editorial';
const HANFU_R2_ZH = 'zh_Hans_CN';
const HANFU_R2_EN = 'en_US';

$mode = '--dry-run';
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--dry-run', '--apply', '--verify', '--cleanup', '--self-test-contextual-assets', '--self-test-contextual-figures'], true)) {
        $mode = $argument;
    }
}

$repoRoot = dirname(__DIR__, 5);
$coreProfiles = require __DIR__ . '/hanfu-r2-core-profiles.php';
$ethnicProfiles = require __DIR__ . '/china-ethnic-groups.php';
$sourceEvidence = require __DIR__ . '/hanfu-r3-source-evidence.php';
require_once __DIR__ . '/hanfu-r2-ethnic-editorial.php';
require_once __DIR__ . '/hanfu-r3-image-manifest.php';
require_once __DIR__ . '/hanfu-r3-contextual-image-manifest.php';
$imageManifest = hanfuR3ImageManifest();
$contextualImageManifest = hanfuR3ContextualImageManifest();
$contextualProvenancePath = $repoRoot . '/var/hanfu-production/final/blog-inline/provenance.json';
$contextualProvenanceJson = file_get_contents($contextualProvenancePath);
if (!is_string($contextualProvenanceJson)) {
    throw new RuntimeException('hanfu_r3_contextual_provenance_unreadable');
}
$contextualProvenance = json_decode($contextualProvenanceJson, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($coreProfiles) || count($coreProfiles) !== 48) {
    throw new RuntimeException('hanfu_r2_core_profile_count_invalid');
}
if (!is_array($ethnicProfiles) || count($ethnicProfiles) !== 56) {
    throw new RuntimeException('hanfu_r2_ethnic_profile_count_invalid');
}
if (count($imageManifest) !== 160) {
    throw new RuntimeException('hanfu_r3_image_manifest_count_invalid');
}
if (!is_array($contextualProvenance) || count($contextualImageManifest) !== 160) {
    throw new RuntimeException('hanfu_r3_contextual_manifest_or_provenance_invalid');
}

$ethnicByCode = [];
foreach ($ethnicProfiles as $profile) {
    if (!is_array($profile)) {
        continue;
    }
    $code = strtolower(trim((string)($profile['code'] ?? '')));
    if ($code !== '') {
        $ethnicByCode[$code] = $profile;
    }
}
if (count($ethnicByCode) !== 56) {
    throw new RuntimeException('hanfu_r2_ethnic_profile_codes_invalid');
}

function hanfuR2Esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hanfuR2P(string $value): string
{
    return '<p>' . hanfuR2Esc(trim($value)) . '</p>';
}

function hanfuR2H2(string $value): string
{
    return '<h2>' . hanfuR2Esc(trim($value)) . '</h2>';
}

/** @param list<string> $items */
function hanfuR2List(array $items): string
{
    $html = '<ul>';
    foreach ($items as $item) {
        $html .= '<li>' . hanfuR2Esc(trim((string)$item)) . '</li>';
    }
    return $html . '</ul>';
}

/** @param list<list<string>> $rows */
function hanfuR2Table(array $headers, array $rows): string
{
    $html = '<table><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . hanfuR2Esc((string)$header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . hanfuR2Esc((string)$cell) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

function hanfuR3NormalizeReusableBlock(string $html): string
{
    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = (string)preg_replace('#https?://\S+|\[[^\]]+\]#u', '', $plain);
    $plain = (string)preg_replace('/[“”‘’—–\p{P}\p{Z}]+/u', ' ', $plain);
    return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $plain)), 'UTF-8');
}

function hanfuR2BaseSlug(string $slug, string $locale): string
{
    if (str_starts_with(strtolower($locale), 'en') && str_ends_with($slug, '-en')) {
        return substr($slug, 0, -3);
    }
    return $slug;
}

function hanfuR2IsEnglish(string $locale): bool
{
    return str_starts_with(strtolower(trim($locale)), 'en');
}

function hanfuR4PromptLanguagePattern(string $locale): string
{
    if (hanfuR2IsEnglish($locale)) {
        return '/plain-language category|evidence brief|evidence card|profile definition|required observation|editor(?:’|\x{2019}|\x{27})s conclusion|before any recommendation is published|Here, that conclusion applies specifically|evidence fields|publishable record|occasion diagnostic|working method into/iu';
    }

    return '/先用普通语言写清|把“?缺少证据”?写进结论|(?:可追溯(?:的)?)?证据卡|唯一可执行的下一步|该主题的定义是|必须核对的观察是|卖家陈述与编辑结论|在发布任何建议前|在本文中[，,]这一结论只针对|围绕“[^”]+”[：:]|证据字段|可发布记录|场合诊断|发布“[^”]+”记录|核查清单包括|应把工作方法整理/u';
}

/** @return array{0:string,1:string}|null */
function hanfuR2EthnicRoute(string $baseSlug): ?array
{
    if (preg_match('/^ethnic-([a-z0-9-]+)-(dress-overview|occasion-craft)$/D', $baseSlug, $matches) !== 1) {
        return null;
    }
    return [$matches[1], $matches[2] === 'dress-overview' ? 'overview' : 'occasion'];
}

/** @param array<string,mixed> $profile @param array<string,mixed> $sourceEvidence @return list<array<string,string>> */
function hanfuR3EvidenceFor(array $profile, array $sourceEvidence, string $baseSlug): array
{
    $keys = $profile['evidence_keys'] ?? [];
    if (!is_array($keys) || count($keys) < 2) {
        throw new RuntimeException('hanfu_r3_evidence_keys_missing:' . $baseSlug);
    }
    $records = [];
    foreach ($keys as $key) {
        $record = $sourceEvidence[(string)$key] ?? null;
        if (!is_array($record) || trim((string)($record['url'] ?? '')) === '') {
            throw new RuntimeException('hanfu_r3_evidence_record_missing:' . $baseSlug . ':' . $key);
        }
        $records[] = $record;
    }
    return $records;
}

function hanfuR3CoreHeading(string $role, int $step, bool $en): string
{
    $outlines = $en ? [
        'platform_due_diligence' => ['Map the seller and destination', 'Read the SKU evidence', 'Calculate delivery exposure', 'Test fit and material disclosures', 'Set a purchase stop rule', 'Keep a dated decision record'],
        'new_chinese_boundary' => ['Name the design boundary', 'Inspect construction before aesthetics', 'Separate reference from reconstruction', 'Check material and movement', 'Style with an honest label', 'Choose the right use context'],
        'garment_form_history' => ['Identify the garment system', 'Read the construction record', 'Compare bounded historical evidence', 'Test material and proportion', 'Mark reconstruction limits', 'Build a transparent conclusion'],
        'fabric_craft_sizing_care' => ['Separate fibre, weave and finish', 'Read construction evidence', 'Measure the finished garment', 'Plan care by material', 'Recognise craft claims', 'Make a low-risk decision'],
        'occasion_styling' => ['Define the occasion and movement', 'Build the garment base', 'Plan proportion and layers', 'Test material, weather and fit', 'Secure accessories safely', 'Rehearse and revise'],
        'purchase_decision' => ['Set the non-negotiables', 'Verify the exact listing', 'Compare total cost and return route', 'Check fit and construction', 'Document seller answers', 'Decide or stop'],
        'brand_factory_claim_audit' => ['Separate story from claim', 'Ask for product-level evidence', 'Trace process and responsibility', 'Check material and measurement records', 'State what remains unverified', 'Publish a dated audit'],
        'global_traditional_clothing_comparison' => ['Set a respectful comparison frame', 'Describe each garment system', 'Compare only like evidence', 'Keep material and use context separate', 'Name the limits of analogy', 'Return to local terminology'],
        'china_56_ethnic_dress_hub' => ['Locate communities before labels', 'Read silhouette and wearing system', 'Separate material from technique', 'Place dress in use context', 'State photographic limits', 'Build an attributable reading list'],
    ] : [
        'platform_due_diligence' => ['先核对卖家与目的地', '读取具体 SKU 证据', '计算交付风险', '核对尺码与材质披露', '设定停止下单条件', '保存带日期的决策记录'],
        'new_chinese_boundary' => ['先说明设计边界', '结构先于审美', '区分借鉴与复原', '核对材质与活动量', '用诚实标签搭配', '匹配真正的使用场景'],
        'garment_form_history' => ['识别服装系统', '读取结构记录', '对照有边界的历史证据', '核对材质与比例', '标明复原限度', '形成透明结论'],
        'fabric_craft_sizing_care' => ['分开纤维、织法与整理', '读取结构证据', '测量成衣', '按材料安排护理', '识别工艺声明', '做低风险决策'],
        'occasion_styling' => ['明确场合与活动量', '建立服装基础', '安排比例与层次', '测试材质、天气与合身', '安全固定配饰', '排练后再调整'],
        'purchase_decision' => ['列出不可妥协条件', '核验具体商品页', '比较总价与退货路径', '检查合身与结构', '保存卖家答复', '决定购买或停止'],
        'brand_factory_claim_audit' => ['把故事与声明分开', '索取商品级证据', '追溯流程与责任', '核对材质和尺寸记录', '说明未核实部分', '发布带日期的审计'],
        'global_traditional_clothing_comparison' => ['建立尊重的比较框架', '描述各自服装系统', '只比较可比证据', '分开材料与使用语境', '说明类比的限度', '回到地方术语'],
        'china_56_ethnic_dress_hub' => ['先定位社区，再谈标签', '读取轮廓与穿着系统', '分开材料与工艺', '放回使用场合', '说明照片判断限度', '建立可署名阅读清单'],
    ];
    $outline = $outlines[$role] ?? $outlines['purchase_decision'];
    return ($step + 1) . '. ' . $outline[$step];
}

/** @param array<string,mixed> $profile @param array<string,mixed> $sourceEvidence */
function hanfuR3BuildCoreByRole(array $profile, array $sourceEvidence, string $title, string $locale, string $baseSlug): string
{
    $article = hanfuR4CoreArticle($profile, $title, $locale, $baseSlug);
    $evidenceRecords = hanfuR3EvidenceFor($profile, $sourceEvidence, $baseSlug);
    $html = hanfuR2P($article['lede']);
    foreach ($article['sections'] as $section) {
        $html .= hanfuR2H2($section['heading']);
        foreach ($section['paragraphs'] as $paragraph) {
            $html .= hanfuR2P($paragraph);
        }
        if (isset($section['table'])) {
            $html .= hanfuR2Table($section['table']['headers'], $section['table']['rows']);
        }
        if (!empty($section['bullets'])) {
            $html .= hanfuR2List($section['bullets']);
        }
    }
    $html .= '<aside class="editorial-sources"><strong>'
        . (hanfuR2IsEnglish($locale) ? 'References:' : '参考资料：')
        . '</strong> '
        . implode(hanfuR2IsEnglish($locale) ? '; ' : '；', array_map(
        static fn(array $record): string => '<a href="' . hanfuR2Esc($record['url']) . '" rel="noopener noreferrer">' . hanfuR2Esc($record['title']) . '</a>',
        $evidenceRecords,
    )) . (hanfuR2IsEnglish($locale) ? '.</aside>' : '。</aside>');

    return $html;
}

/** @param array<string,mixed> $profile */
function hanfuR2MaterialHandling(array $profile, bool $en): string
{
    $fabric = trim((string)($profile[$en ? 'fabric_en' : 'fabric'] ?? ''));
    $probe = strtolower($fabric);
    if (preg_match('/wool|felt|fur|hide|皮|毛|毡/u', $probe) === 1) {
        return $en
            ? 'Wool, felt, fur or hide elements can react differently to water, heat and pressure. Follow a documented maker or conservator method; do not infer one wash rule from the group name.'
            : '羊毛、毡、毛皮或皮革部件对水、热和压力的反应不同，应遵循制作者或保管方的明确方法，不能按民族名称推断统一洗法。';
    }
    if (preg_match('/silk|brocade|satin|metallic|丝|绸|锦|金银线/u', $probe) === 1) {
        return $en
            ? 'Silk, brocade, satin or metallic-thread areas require attention to abrasion, snagging and color transfer. Use the maker’s care label and test only in a hidden place when appropriate.'
            : '丝绸、织锦、缎或金银线区域需要防摩擦、勾挂与移色；护理以制作者标签为准，确需测试时只在隐蔽处进行。';
    }
    if (preg_match('/indigo|batik|tie-dye|靛|蜡染|扎染/u', $probe) === 1) {
        return $en
            ? 'Indigo, batik and resist-dyed cloth may transfer color or change with repeated washing. Store away from pale textiles and follow maker-specific instructions.'
            : '靛蓝、蜡染与扎染布可能移色或随清洗变化，应与浅色织物分隔保存，并遵循具体制作者的护理说明。';
    }
    return $en
        ? 'Material names are not a universal care label. Separate fiber, weave, applied ornament and metal or bead components, then follow the maker’s documented instructions.'
        : '材料名称不能代替护理标签。应把纤维、织法、附加装饰以及金属或珠饰部件分开，再按制作者的明确说明处理。';
}

/** @param array<string,mixed> $profile @param array<string,mixed> $sourceEvidence */
function hanfuR2BuildEthnic(array $profile, array $sourceEvidence, string $variant, string $title, string $locale): string
{
    $article = hanfuR2EthnicEditorial($profile, $variant, $title, $locale);
    $html = hanfuR2P($article['lede']);
    foreach ($article['sections'] as $section) {
        $html .= hanfuR2H2($section['heading']);
        foreach ($section['paragraphs'] as $paragraph) {
            $html .= hanfuR2P($paragraph);
        }
    }
    $html .= hanfuR2H2(str_starts_with(strtolower($locale), 'en') ? 'At a glance' : '一页读懂');
    $records = hanfuR3EvidenceFor(['evidence_keys' => $article['evidence_keys']], $sourceEvidence, (string)$profile['code']);
    $html .= hanfuR2List($article['reviewed_facts']);
    return $html . '<aside class="editorial-sources"><strong>'
        . (hanfuR2IsEnglish($locale) ? 'References:' : '参考资料：') . '</strong> '
        . implode(hanfuR2IsEnglish($locale) ? '; ' : '；', array_map(
            static fn(array $record): string => '<a href="' . hanfuR2Esc($record['url']) . '" rel="noopener noreferrer">' . hanfuR2Esc($record['title']) . '</a>',
            $records,
        )) . '.</aside>';
}

/**
 * @param array<string,string> $zh
 * @param array<string,string> $en
 * @param array<string,mixed> $assetMetadata
 * @return array<string,mixed>
 */
function hanfuR2EnsureAsset(
    FileAssetLibraryInterface $library,
    string $sourceFile,
    string $objectKey,
    array $zh,
    array $en,
    array $assetMetadata,
): array {
    if (!is_file($sourceFile) || filesize($sourceFile) < 1024) {
        throw new RuntimeException('hanfu_r2_asset_source_missing:' . $sourceFile);
    }
    $sha = strtolower((string)hash_file('sha256', $sourceFile));
    $size = getimagesize($sourceFile);
    if (!is_array($size) || (int)$size[0] < 1 || (int)$size[1] < 1) {
        throw new RuntimeException('hanfu_r2_asset_dimensions_invalid:' . $sourceFile);
    }
    $accessZh = new FileAccessContext(ScopeIdentity::global(), HANFU_R2_ZH, null, [], 'metadata_edit');
    $accessEn = new FileAccessContext(ScopeIdentity::global(), HANFU_R2_EN, null, [], 'metadata_edit');

    try {
        $descriptor = $library->describe(HANFU_R2_DISK, $objectKey, HANFU_R2_ZH, $accessZh);
        if (($descriptor['asset_ready'] ?? false) !== true
            || trim((string)($descriptor['asset_id'] ?? '')) === ''
        ) {
            throw new RuntimeException('hanfu_r2_asset_missing:' . $objectKey);
        }
        if (!hash_equals($sha, strtolower(trim((string)($descriptor['sha256'] ?? ''))))) {
            throw new RuntimeException('hanfu_r2_asset_identity_collision:' . $objectKey);
        }
    } catch (RuntimeException $exception) {
        if (str_starts_with($exception->getMessage(), 'hanfu_r2_asset_identity_collision:')) {
            throw $exception;
        }
        $stream = fopen($sourceFile, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('hanfu_r2_asset_open_failed:' . $sourceFile);
        }
        try {
            $descriptor = $library->upload(
                HANFU_R2_DISK,
                $objectKey,
                $stream,
                basename($sourceFile),
                'image/webp',
                HANFU_R2_ZH,
                $accessZh,
                $zh,
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                $assetMetadata,
                (int)$size[0],
                (int)$size[1],
            );
        } finally {
            fclose($stream);
        }
    }

    $encodedAssetMetadata = json_encode(
        $assetMetadata,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    $descriptor = $library->saveAssetMetadata(
        (string)$descriptor['asset_id'],
        HANFU_R2_DISK,
        $objectKey,
        HANFU_R2_ZH,
        $accessZh,
        (int)$descriptor['asset_revision'],
        $assetMetadata,
    );
    if (!hash_equals(
        hash('sha256', $encodedAssetMetadata),
        (string)($descriptor['asset_metadata_sha256'] ?? ''),
    )) {
        throw new RuntimeException('hanfu_r3_asset_metadata_readback_mismatch:' . $objectKey);
    }
    $descriptor = $library->saveMetadata(
        (string)$descriptor['asset_id'],
        HANFU_R2_DISK,
        $objectKey,
        HANFU_R2_ZH,
        $accessZh,
        (int)$descriptor['asset_revision'],
        $zh,
    );
    $descriptorEn = $library->saveMetadata(
        (string)$descriptor['asset_id'],
        HANFU_R2_DISK,
        $objectKey,
        HANFU_R2_EN,
        $accessEn,
        (int)$descriptor['asset_revision'],
        $en,
    );
    foreach ([$descriptor, $descriptorEn] as $localeDescriptor) {
        if (($localeDescriptor['translation_state'] ?? '') !== FileAssetLibraryInterface::TRANSLATION_REVIEWED
            || ($localeDescriptor['translation_origin'] ?? '') !== FileAssetLibraryInterface::TRANSLATION_MANUAL
            || trim((string)($localeDescriptor['display_name'] ?? '')) === ''
            || trim((string)($localeDescriptor['default_alt'] ?? '')) === ''
            || trim((string)($localeDescriptor['description'] ?? '')) === ''
            || trim((string)($localeDescriptor['default_caption'] ?? '')) === ''
        ) {
            throw new RuntimeException('hanfu_r2_asset_locale_incomplete:' . $objectKey);
        }
    }
    return $library->describe(HANFU_R2_DISK, $objectKey, HANFU_R2_ZH, $accessZh);
}

/** @param array<string,mixed> $locale */
function hanfuR3ContextualLocale(array $locale, string $localeCode, string $objectKey): array
{
    foreach (['display_name', 'default_alt', 'description', 'default_caption'] as $field) {
        if (trim((string)($locale[$field] ?? '')) === '') {
            throw new RuntimeException('hanfu_r3_contextual_locale_incomplete:' . $objectKey . ':' . $localeCode);
        }
    }
    if (($locale['translation_state'] ?? '') !== 'reviewed'
        || ($locale['translation_origin'] ?? '') !== 'manual'
    ) {
        throw new RuntimeException('hanfu_r3_contextual_locale_unreviewed:' . $objectKey . ':' . $localeCode);
    }
    $metadata = [
        'display_name' => trim((string)$locale['display_name']),
        'default_alt' => trim((string)$locale['default_alt']),
        'description' => trim((string)$locale['description']),
        'default_caption' => trim((string)$locale['default_caption']),
        'translation_state' => 'reviewed',
        'translation_origin' => 'manual',
    ];
    return $metadata;
}

/** @param array<string,mixed> $record @param array<string,mixed> $slot */
function hanfuR3ContextualAssetMetadata(
    array $record,
    array $slot,
    string $baseSlug,
    string $slotId,
    string $sourceSha,
    string $provenanceIdentity,
): array {
    $sourceType = trim((string)($record['source_type'] ?? ''));
    $sourcePath = trim((string)($record['source_path'] ?? ''));
    $sourcePage = trim((string)($record['source_page'] ?? ''));
    $sourceImageUrl = trim((string)($record['source_image_url'] ?? ''));
    if ($sourceType === '' || ($sourcePath === '' && $sourcePage === '' && $sourceImageUrl === '')) {
        throw new RuntimeException('hanfu_r3_contextual_source_incomplete:' . $baseSlug . ':' . $slotId);
    }
    $visualRole = trim((string)($slot['visual_role'] ?? ''));
    $metadata = [
        'source_type' => $sourceType,
        'source_path' => $sourcePath,
        'source_page' => $sourcePage,
        'source_image_url' => $sourceImageUrl,
        'source_url' => $sourcePage !== '' ? $sourcePage : ($sourceImageUrl !== '' ? $sourceImageUrl : $sourcePath),
        'source_object_id' => trim((string)($record['source_object_id'] ?? '')),
        'source_title' => trim((string)($record['source_title'] ?? '')),
        'source_date' => trim((string)($record['source_date'] ?? '')),
        'source_medium' => trim((string)($record['source_medium'] ?? '')),
        'license' => trim((string)($record['license'] ?? '')),
        'license_url' => trim((string)($record['license_url'] ?? '')),
        'ai_editorial' => (bool)($record['ai_editorial'] ?? false),
        'transformation' => trim((string)($record['transformation'] ?? '')),
        'must_not_claim' => trim((string)($record['must_not_claim'] ?? '')),
        'output_sha256' => $sourceSha,
        'output_width' => (int)($record['width'] ?? 0),
        'output_height' => (int)($record['height'] ?? 0),
        'relations' => [
            'blog_base_slug' => $baseSlug,
            'slot_id' => $slotId,
            'visual_role' => $visualRole,
            'anchor_h2' => (int)($slot['anchor_h2'] ?? 0),
            'source_sha256' => $sourceSha,
            'source_type' => $sourceType,
            'accepted_provenance_identity' => $provenanceIdentity,
        ],
    ];
    foreach (['is_public_domain', 'met_fallback_reason', 'source_sha256', 'source_generation_id', 'generator', 'reviewed_at', 'review_method'] as $field) {
        if (array_key_exists($field, $record)) {
            $metadata[$field] = $record[$field];
        }
    }
    return $metadata;
}

/**
 * @param array<string,array{slots:list<array<string,mixed>>}> $manifest
 * @param array<string,mixed> $provenance
 * @return array<string,list<array<string,mixed>>>
 */
function hanfuR3EnsureContextualAssets(
    array $manifest,
    array $provenance,
    string $repoRoot,
    ?FileAssetLibraryInterface $library,
    bool $apply,
): array {
    if (count($manifest) !== 160) {
        throw new RuntimeException('hanfu_r3_contextual_manifest_or_provenance_count_invalid');
    }
    $assetsByBase = [];
    $usedProvenance = [];
    $usedSourceShas = [];
    foreach ($manifest as $baseSlug => $topic) {
        if (preg_match('/^[a-z0-9-]+$/D', (string)$baseSlug) !== 1 || !is_array($topic)) {
            throw new RuntimeException('hanfu_r3_contextual_topic_invalid:' . $baseSlug);
        }
        $slots = $topic['slots'] ?? null;
        if (!is_array($slots) || $slots === []) {
            throw new RuntimeException('hanfu_r3_contextual_slots_missing:' . $baseSlug);
        }
        $descriptors = [];
        $seenSlots = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                throw new RuntimeException('hanfu_r3_contextual_slot_invalid:' . $baseSlug);
            }
            $visualRole = trim((string)($slot['visual_role'] ?? ''));
            $slotId = trim((string)($slot['slot_id'] ?? $visualRole));
            $anchorH2 = (int)($slot['anchor_h2'] ?? 0);
            if (!in_array($visualRole, ['context', 'form', 'craft', 'care', 'evidence'], true)
                || $slotId !== $visualRole
                || $anchorH2 < 1
                || isset($seenSlots[$slotId])
            ) {
                throw new RuntimeException('hanfu_r3_contextual_slot_identity_invalid:' . $baseSlug . ':' . $slotId);
            }
            $seenSlots[$slotId] = true;
            $sourceFile = $repoRoot . '/var/hanfu-production/final/blog-inline/' . $baseSlug . '/' . $visualRole . '.webp';
            if (!is_file($sourceFile) || filesize($sourceFile) < 1024) {
                throw new RuntimeException('hanfu_r3_contextual_source_missing:' . $baseSlug . ':' . $visualRole);
            }
            $size = getimagesize($sourceFile);
            if (!is_array($size) || (int)$size[0] !== 1200 || (int)$size[1] !== 800) {
                throw new RuntimeException('hanfu_r3_contextual_dimensions_invalid:' . $baseSlug . ':' . $visualRole);
            }
            $sourceSha = strtolower((string)hash_file('sha256', $sourceFile));
            if (preg_match('/^[0-9a-f]{64}$/D', $sourceSha) !== 1 || isset($usedSourceShas[$sourceSha])) {
                throw new RuntimeException('hanfu_r3_contextual_source_sha_invalid_or_duplicate:' . $baseSlug . ':' . $visualRole);
            }
            $record = $provenance[$sourceSha] ?? null;
            if (!is_array($record) || isset($usedProvenance[$sourceSha])) {
                throw new RuntimeException('hanfu_r3_contextual_provenance_missing_or_duplicate:' . $baseSlug . ':' . $visualRole);
            }
            $expectedOutputPath = $repoRoot . '/var/hanfu-production/final/blog-inline/' . $baseSlug . '/' . $visualRole . '.webp';
            if (!hash_equals($sourceSha, strtolower(trim((string)($record['sha256'] ?? ''))))
                || !hash_equals($baseSlug, (string)($record['base_slug'] ?? ''))
                || !hash_equals($slotId, (string)($record['slot_id'] ?? ''))
                || !hash_equals($visualRole, (string)($record['visual_role'] ?? ''))
                || !hash_equals($expectedOutputPath, (string)($record['output_path'] ?? ''))
                || (int)($record['width'] ?? 0) !== 1200
                || (int)($record['height'] ?? 0) !== 800
            ) {
                throw new RuntimeException('hanfu_r3_contextual_provenance_identity_mismatch:' . $baseSlug . ':' . $visualRole);
            }
            if (trim((string)($record['license'] ?? '')) === ''
                || trim((string)($record['license_url'] ?? '')) === ''
                || trim((string)($record['transformation'] ?? '')) === ''
                || trim((string)($record['must_not_claim'] ?? '')) === ''
            ) {
                throw new RuntimeException('hanfu_r3_contextual_provenance_incomplete:' . $baseSlug . ':' . $visualRole);
            }
            $zh = hanfuR3ContextualLocale((array)($record['locales'][HANFU_R2_ZH] ?? []), HANFU_R2_ZH, $baseSlug . ':' . $slotId);
            $en = hanfuR3ContextualLocale((array)($record['locales'][HANFU_R2_EN] ?? []), HANFU_R2_EN, $baseSlug . ':' . $slotId);
            if (($record['ai_editorial'] ?? null) === true) {
                foreach (['display_name', 'default_alt', 'description', 'default_caption'] as $field) {
                    if (!str_contains($zh[$field], 'AI 编辑插图')
                        || !str_contains($en[$field], 'AI editorial illustration')
                    ) {
                        throw new RuntimeException('hanfu_r3_contextual_ai_label_missing:' . $baseSlug . ':' . $visualRole . ':' . $field);
                    }
                }
            } elseif (($record['source_type'] ?? '') !== 'met_open_access_public_domain'
                || ($record['is_public_domain'] ?? false) !== true
                || trim((string)($record['source_object_id'] ?? '')) === ''
                || trim((string)($record['source_title'] ?? '')) === ''
                || trim((string)($record['source_date'] ?? '')) === ''
                || trim((string)($record['source_medium'] ?? '')) === ''
                || !str_contains($zh['default_caption'], '大都会艺术博物馆')
                || !str_contains($en['default_caption'], 'The Met object')
            ) {
                throw new RuntimeException('hanfu_r3_contextual_met_provenance_incomplete:' . $baseSlug . ':' . $visualRole);
            }
            $objectKey = 'blog/hanfu/r3/inline/' . $baseSlug . '/' . $visualRole . '-' . substr($sourceSha, 0, 12) . '.webp';
            $expectedUrl = '/pub/media/' . $objectKey;
            $assetMetadata = hanfuR3ContextualAssetMetadata($record, $slot, $baseSlug, $slotId, $sourceSha, $sourceSha);
            if ($apply) {
                if (!$library instanceof FileAssetLibraryInterface) {
                    throw new LogicException('hanfu_r3_contextual_file_library_unavailable');
                }
                hanfuR2EnsureAsset($library, $sourceFile, $objectKey, $zh, $en, $assetMetadata);
                $accessZh = new FileAccessContext(ScopeIdentity::global(), HANFU_R2_ZH, null, [], 'metadata_edit');
                $accessEn = new FileAccessContext(ScopeIdentity::global(), HANFU_R2_EN, null, [], 'metadata_edit');
                $verifiedZh = $library->describe(HANFU_R2_DISK, $objectKey, HANFU_R2_ZH, $accessZh);
                $verifiedEn = $library->describe(HANFU_R2_DISK, $objectKey, HANFU_R2_EN, $accessEn);
                foreach ([HANFU_R2_ZH => $verifiedZh, HANFU_R2_EN => $verifiedEn] as $locale => $verified) {
                    if (($verified['asset_ready'] ?? false) !== true
                        || ($verified['visibility'] ?? '') !== FileAssetLibraryInterface::VISIBILITY_PUBLIC
                        || !hash_equals($objectKey, (string)($verified['object_key'] ?? ''))
                        || !hash_equals($sourceSha, strtolower((string)($verified['sha256'] ?? '')))
                        || !hash_equals(hash('sha256', json_encode($assetMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), (string)($verified['asset_metadata_sha256'] ?? ''))
                    ) {
                        throw new RuntimeException('hanfu_r3_contextual_asset_readback_mismatch:' . $objectKey . ':' . $locale);
                    }
                    $expectedLocale = $locale === HANFU_R2_ZH ? $zh : $en;
                    foreach ($expectedLocale as $field => $value) {
                        if (!hash_equals((string)$value, (string)($verified[$field] ?? ''))) {
                            throw new RuntimeException('hanfu_r3_contextual_locale_readback_mismatch:' . $objectKey . ':' . $locale . ':' . $field);
                        }
                    }
                }
                $expectedUrl = trim((string)($verifiedZh['preview_url'] ?? ''));
                if (!str_starts_with($expectedUrl, '/pub/media/blog/hanfu/r3/inline/')) {
                    throw new RuntimeException('hanfu_r3_contextual_public_url_invalid:' . $objectKey);
                }
                $zh = array_intersect_key($verifiedZh, $zh);
                $en = array_intersect_key($verifiedEn, $en);
            }
            if (!str_starts_with($expectedUrl, '/pub/media/blog/hanfu/r3/inline/')) {
                throw new RuntimeException('hanfu_r3_contextual_public_url_invalid:' . $objectKey);
            }
            $descriptors[] = [
                'object_key' => $objectKey,
                'public_url' => $expectedUrl,
                'source_sha256' => $sourceSha,
                'slot_id' => $slotId,
                'visual_role' => $visualRole,
                'anchor_h2' => $anchorH2,
                'locales' => [
                    HANFU_R2_ZH => ['locale' => HANFU_R2_ZH, 'default_alt' => $zh['default_alt'], 'default_caption' => $zh['default_caption']],
                    HANFU_R2_EN => ['locale' => HANFU_R2_EN, 'default_alt' => $en['default_alt'], 'default_caption' => $en['default_caption']],
                ],
                'provenance' => [
                    'source_type' => (string)$record['source_type'],
                    'source_page' => (string)($record['source_page'] ?? ''),
                    'source_object_id' => (string)($record['source_object_id'] ?? ''),
                    'license' => (string)$record['license'],
                    'license_url' => (string)$record['license_url'],
                    'ai_editorial' => (bool)($record['ai_editorial'] ?? false),
                ],
            ];
            $usedProvenance[$sourceSha] = true;
            $usedSourceShas[$sourceSha] = true;
        }
        $assetsByBase[$baseSlug] = $descriptors;
    }
    if (count($assetsByBase) !== 160 || count($usedProvenance) !== 444 || count($usedSourceShas) !== 444) {
        throw new RuntimeException('hanfu_r3_contextual_descriptor_count_invalid');
    }
    foreach ($provenance as $identity => $record) {
        if (!is_string($identity) || !isset($usedProvenance[$identity]) || !is_array($record)) {
            throw new RuntimeException('hanfu_r3_contextual_provenance_orphaned:' . $identity);
        }
    }
    return $assetsByBase;
}

/** @return list<array{start:int,end:int}> */
function hanfuR3ContextualH2Sections(string $content): array
{
    if (preg_match_all('#<h2\\b[^>]*>.*?</h2>#su', $content, $matches, PREG_OFFSET_CAPTURE) !== 1
        && ($matches[0] ?? []) === []
    ) {
        return [];
    }
    $sections = [];
    foreach ($matches[0] as $index => $match) {
        $start = (int)$match[1];
        $end = isset($matches[0][$index + 1]) ? (int)$matches[0][$index + 1][1] : strlen($content);
        $sections[] = ['start' => $start, 'end' => $end];
    }
    return $sections;
}

function hanfuR3ContextualFigureUrlIsApproved(string $publicUrl, string $objectKey): bool
{
    return preg_match('#^/pub/media/blog/hanfu/r3/inline/[a-z0-9-]+/[a-z]+-[0-9a-f]{12}\\.webp$#D', $publicUrl) === 1
        && preg_match('#^blog/hanfu/r3/inline/[a-z0-9-]+/[a-z]+-[0-9a-f]{12}\\.webp$#D', $objectKey) === 1
        && hash_equals('/pub/media/' . $objectKey, $publicUrl);
}

function hanfuR3ContextualFigureHttpsUrlIsSafe(string $url): bool
{
    $parts = parse_url($url);
    return is_array($parts)
        && ($parts['scheme'] ?? null) === 'https'
        && is_string($parts['host'] ?? null)
        && trim((string)$parts['host']) !== ''
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function hanfuR3ContextualFigureLocaleCopyIsSafe(string $value): bool
{
    return preg_match(
        '#(?:\\b[a-z][a-z0-9+.-]*:(?://|[^[:space:]]+))|(?:^|(?<=[^\\p{L}\\p{N}_]))/(?:[^[:space:]<>"\']+)|(?:^|[^[:alnum:]_])var/hanfu-production(?:/|$)|(?:^|[^[:alnum:]_])/pub/media(?:/|$)#iu',
        $value,
    ) !== 1;
}

/**
 * @param list<array<string,mixed>> $assets
 * @return list<array<string,mixed>>
 */
function hanfuR3ValidatedContextualFigureAssets(array $assets, string $locale, int $h2Count): array
{
    if (!in_array($locale, [HANFU_R2_ZH, HANFU_R2_EN], true)) {
        throw new RuntimeException('hanfu_r3_contextual_figure_locale_invalid');
    }
    if (count($assets) < 2 || count($assets) > 3) {
        throw new RuntimeException('hanfu_r3_contextual_figure_count_invalid');
    }
    $validated = [];
    $slots = [];
    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            throw new RuntimeException('hanfu_r3_contextual_figure_asset_invalid');
        }
        $slotId = trim((string)($asset['slot_id'] ?? ''));
        $role = trim((string)($asset['visual_role'] ?? ''));
        $objectKey = trim((string)($asset['object_key'] ?? ''));
        $publicUrl = trim((string)($asset['public_url'] ?? ''));
        $anchor = (int)($asset['anchor_h2'] ?? 0);
        if (!in_array($role, ['context', 'form', 'craft', 'care', 'evidence'], true) || $slotId !== $role) {
            throw new RuntimeException('hanfu_r3_contextual_figure_role_invalid');
        }
        if (isset($slots[$slotId])) {
            throw new RuntimeException('hanfu_r3_contextual_figure_slot_duplicate');
        }
        $slots[$slotId] = true;
        if (!hanfuR3ContextualFigureUrlIsApproved($publicUrl, $objectKey)) {
            throw new RuntimeException('hanfu_r3_contextual_figure_url_invalid');
        }
        if ($anchor < 1 || $anchor > $h2Count) {
            throw new RuntimeException('hanfu_r3_contextual_figure_anchor_invalid');
        }
        $localePayload = $asset['locales'][$locale] ?? null;
        if (!is_array($localePayload)
            || !hash_equals($locale, (string)($localePayload['locale'] ?? ''))
            || trim((string)($localePayload['default_alt'] ?? '')) === ''
            || trim((string)($localePayload['default_caption'] ?? '')) === ''
        ) {
            throw new RuntimeException('hanfu_r3_contextual_figure_locale_invalid');
        }
        if (!hanfuR3ContextualFigureLocaleCopyIsSafe((string)$localePayload['default_alt'])
            || !hanfuR3ContextualFigureLocaleCopyIsSafe((string)$localePayload['default_caption'])
        ) {
            throw new RuntimeException('hanfu_r3_contextual_figure_locale_copy_invalid');
        }
        $provenance = $asset['provenance'] ?? null;
        if (!is_array($provenance)
            || trim((string)($provenance['license'] ?? '')) === ''
            || !hanfuR3ContextualFigureHttpsUrlIsSafe(trim((string)($provenance['license_url'] ?? '')))
        ) {
            throw new RuntimeException('hanfu_r3_contextual_figure_provenance_invalid');
        }
        if (($provenance['ai_editorial'] ?? null) === true) {
            if (!in_array(
                    ($provenance['source_type'] ?? ''),
                    ['approved_r3_ai_editorial_derivative', 'openai_imagegen_editorial_generation'],
                    true,
                )
                || !hash_equals('https://openai.com/policies/terms-of-use/', (string)$provenance['license_url'])
            ) {
                throw new RuntimeException('hanfu_r3_contextual_figure_provenance_invalid');
            }
        } elseif (($provenance['source_type'] ?? '') !== 'met_open_access_public_domain'
            || preg_match('/^[1-9][0-9]*$/D', (string)($provenance['source_object_id'] ?? '')) !== 1
            || !hash_equals(
                'https://www.metmuseum.org/art/collection/search/' . (string)$provenance['source_object_id'],
                (string)($provenance['source_page'] ?? ''),
            )
            || !hash_equals(
                'https://www.metmuseum.org/about-the-met/policies-and-documents/open-access',
                (string)$provenance['license_url'],
            )
        ) {
            throw new RuntimeException('hanfu_r3_contextual_figure_provenance_invalid');
        }
        $validated[] = $asset;
    }
    usort($validated, static fn(array $left, array $right): int => [(int)$left['anchor_h2'], (string)$left['slot_id']] <=> [(int)$right['anchor_h2'], (string)$right['slot_id']]);
    return $validated;
}

/** @param array<string,mixed> $asset */
function hanfuR3ContextualFigureMarkup(array $asset, string $locale): string
{
    $localePayload = (array)$asset['locales'][$locale];
    $provenance = (array)$asset['provenance'];
    $source = ($provenance['ai_editorial'] ?? false) === true
        ? '<span class="hanfu-article-figure__source">' . (hanfuR2IsEnglish($locale) ? 'AI editorial illustration' : 'AI 编辑插图') . '</span>'
        : '<a class="hanfu-article-figure__source" href="' . hanfuR2Esc((string)$provenance['source_page']) . '" rel="noopener noreferrer">'
            . (hanfuR2IsEnglish($locale) ? 'The Met object' : '大都会艺术博物馆藏品') . '</a>';
    $license = '<a class="hanfu-article-figure__license" href="' . hanfuR2Esc((string)$provenance['license_url']) . '" rel="noopener noreferrer">'
        . hanfuR2Esc((string)$provenance['license']) . '</a>';
    return '<figure class="hanfu-article-figure" data-slot-id="' . hanfuR2Esc((string)$asset['slot_id'])
        . '" data-visual-role="' . hanfuR2Esc((string)$asset['visual_role'])
        . '" data-anchor-h2="' . (int)$asset['anchor_h2'] . '"><img src="' . hanfuR2Esc((string)$asset['public_url'])
        . '" alt="' . hanfuR2Esc((string)$localePayload['default_alt'])
        . '" width="1200" height="800" loading="lazy" decoding="async"><figcaption>'
        . hanfuR2Esc((string)$localePayload['default_caption'])
        . ' <span class="hanfu-article-figure__provenance">' . $source . ' · ' . $license
        . '</span></figcaption></figure>';
}

/** @param list<array<string,mixed>> $assets */
function hanfuR3InjectContextualFigures(string $content, array $assets, string $locale): string
{
    $sections = hanfuR3ContextualH2Sections($content);
    $validated = hanfuR3ValidatedContextualFigureAssets($assets, $locale, count($sections));
    $byAnchor = [];
    foreach ($validated as $asset) {
        $byAnchor[(int)$asset['anchor_h2']][] = hanfuR3ContextualFigureMarkup($asset, $locale);
    }
    krsort($byAnchor, SORT_NUMERIC);
    foreach ($byAnchor as $anchor => $figures) {
        $section = $sections[$anchor - 1];
        $insertion = $section['end'];
        if ($anchor === count($sections)) {
            $sourceMaterial = strpos($content, '<aside class="editorial-sources"', $section['start']);
            if ($sourceMaterial !== false && $sourceMaterial < $section['end']) {
                $insertion = $sourceMaterial;
            }
        }
        $content = substr_replace($content, implode('', $figures), $insertion, 0);
    }
    hanfuR3ValidateContextualFigures($content, $validated, $locale);
    return $content;
}

/** @param list<array<string,mixed>> $assets */
function hanfuR3ValidateContextualFigures(string $content, array $assets, string $locale): void
{
    $sections = hanfuR3ContextualH2Sections($content);
    $validated = hanfuR3ValidatedContextualFigureAssets($assets, $locale, count($sections));
    if (substr_count($content, 'class="hanfu-article-figure"') !== count($validated)) {
        throw new RuntimeException('hanfu_r3_contextual_figure_count_invalid');
    }
    if (preg_match_all('#<img\\b[^>]*>#su', $content) !== count($validated)) {
        throw new RuntimeException('hanfu_r3_contextual_figure_img_count_invalid');
    }
    $byAnchor = [];
    foreach ($validated as $asset) {
        $markup = hanfuR3ContextualFigureMarkup($asset, $locale);
        if (substr_count($content, $markup) !== 1) {
            throw new RuntimeException('hanfu_r3_contextual_figure_markup_invalid');
        }
        $byAnchor[(int)$asset['anchor_h2']][] = $markup;
    }
    foreach ($byAnchor as $anchor => $figures) {
        $group = implode('', $figures);
        $position = strpos($content, $group);
        $section = $sections[$anchor - 1];
        $boundary = $section['end'];
        if ($anchor === count($sections)) {
            $sourceMaterial = strpos($content, '<aside class="editorial-sources"', $section['start']);
            if ($sourceMaterial !== false && $sourceMaterial < $section['end']) {
                $boundary = $sourceMaterial;
            }
        }
        if ($position === false || $position < $section['start'] || $position + strlen($group) !== $boundary) {
            throw new RuntimeException('hanfu_r3_contextual_figure_placement_invalid');
        }
    }
}

/**
 * @param array<string,array{slots:list<array<string,mixed>>}> $manifest
 * @param array<string,mixed> $provenance
 * @return array<string,mixed>
 */
function hanfuR3ContextualSelfTest(array $manifest, array $provenance, string $repoRoot): array
{
    $descriptors = hanfuR3EnsureContextualAssets($manifest, $provenance, $repoRoot, null, false);
    $objectKeys = [];
    $publicUrls = [];
    $roleAnchorDescriptors = 0;
    $localeAltCaptionPayloads = 0;
    $exactProvenanceMatches = 0;
    foreach ($descriptors as $topicDescriptors) {
        foreach ($topicDescriptors as $descriptor) {
            $objectKey = (string)($descriptor['object_key'] ?? '');
            $publicUrl = (string)($descriptor['public_url'] ?? '');
            if (preg_match('#^blog/hanfu/r3/inline/[a-z0-9-]+/[a-z]+-[0-9a-f]{12}\.webp$#D', $objectKey) !== 1
                || !hash_equals('/pub/media/' . $objectKey, $publicUrl)
                || !in_array($descriptor['visual_role'] ?? null, ['context', 'form', 'craft', 'care', 'evidence'], true)
                || (int)($descriptor['anchor_h2'] ?? 0) < 1
            ) {
                throw new RuntimeException('hanfu_r3_contextual_self_test_descriptor_invalid');
            }
            $objectKeys[$objectKey] = true;
            $publicUrls[$publicUrl] = true;
            ++$roleAnchorDescriptors;
            $sourceSha = (string)($descriptor['source_sha256'] ?? '');
            $record = $provenance[$sourceSha] ?? null;
            if (!is_array($record)) {
                throw new RuntimeException('hanfu_r3_contextual_self_test_provenance_missing:' . $sourceSha);
            }
            foreach ([HANFU_R2_ZH, HANFU_R2_EN] as $locale) {
                $alt = (string)($descriptor['locales'][$locale]['default_alt'] ?? '');
                $caption = (string)($descriptor['locales'][$locale]['default_caption'] ?? '');
                if ($alt === '' || $caption === ''
                    || !hash_equals((string)($record['locales'][$locale]['default_alt'] ?? ''), $alt)
                    || !hash_equals((string)($record['locales'][$locale]['default_caption'] ?? ''), $caption)
                ) {
                    throw new RuntimeException('hanfu_r3_contextual_self_test_provenance_locale_mismatch:' . $sourceSha . ':' . $locale);
                }
                ++$localeAltCaptionPayloads;
                ++$exactProvenanceMatches;
            }
        }
    }
    if (count($descriptors) !== 160 || $roleAnchorDescriptors !== 444
        || count($objectKeys) !== 444 || count($publicUrls) !== 444 || $localeAltCaptionPayloads !== 888
        || $exactProvenanceMatches !== 888
    ) {
        throw new RuntimeException('hanfu_r3_contextual_self_test_count_invalid');
    }

    $manifestCopySentinel = '__untrusted_manifest_locale_copy__';
    $manifestCopyMutation = $manifest;
    foreach ($manifestCopyMutation as &$topic) {
        foreach ($topic['slots'] as &$slot) {
            $slot['locale_copy'] = [
                HANFU_R2_ZH => $manifestCopySentinel,
                HANFU_R2_EN => $manifestCopySentinel,
            ];
        }
        unset($slot);
    }
    unset($topic);
    $copyIsolatedDescriptors = hanfuR3EnsureContextualAssets($manifestCopyMutation, $provenance, $repoRoot, null, false);
    $manifestCopyIsolationMatches = 0;
    foreach ($descriptors as $baseSlug => $topicDescriptors) {
        foreach ($topicDescriptors as $index => $descriptor) {
            $copyIsolated = $copyIsolatedDescriptors[$baseSlug][$index] ?? null;
            if (!is_array($copyIsolated)) {
                throw new RuntimeException('hanfu_r3_contextual_self_test_manifest_copy_descriptor_missing');
            }
            foreach ([HANFU_R2_ZH, HANFU_R2_EN] as $locale) {
                foreach (['default_alt', 'default_caption'] as $field) {
                    $expected = (string)($descriptor['locales'][$locale][$field] ?? '');
                    $actual = (string)($copyIsolated['locales'][$locale][$field] ?? '');
                    if (!hash_equals($expected, $actual) || str_contains($actual, $manifestCopySentinel)) {
                        throw new RuntimeException('hanfu_r3_contextual_self_test_manifest_copy_leaked:' . $baseSlug . ':' . $locale . ':' . $field);
                    }
                }
                ++$manifestCopyIsolationMatches;
            }
        }
    }
    if ($manifestCopyIsolationMatches !== 888) {
        throw new RuntimeException('hanfu_r3_contextual_self_test_manifest_copy_count_invalid');
    }

    $identity = array_key_first($provenance);
    if (!is_string($identity) || !is_array($provenance[$identity] ?? null)) {
        throw new RuntimeException('hanfu_r3_contextual_self_test_provenance_fixture_invalid');
    }
    $assertFailure = static function (array $candidate, string $expected) use ($manifest, $repoRoot): string {
        try {
            hanfuR3EnsureContextualAssets($manifest, $candidate, $repoRoot, null, false);
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), $expected)) {
                return $expected;
            }
            throw new RuntimeException('hanfu_r3_contextual_self_test_unexpected_failure:' . $exception->getMessage());
        }
        throw new RuntimeException('hanfu_r3_contextual_self_test_expected_failure_missing:' . $expected);
    };

    $identityMismatch = $provenance;
    $identityMismatch[$identity]['base_slug'] = 'invalid-base-slug';
    $incompleteLocale = $provenance;
    $incompleteLocale[$identity]['locales'][HANFU_R2_ZH]['default_alt'] = '';
    $missingProvenance = $provenance;
    unset($missingProvenance[$identity]);
    $orphanedProvenance = $provenance;
    $orphanedProvenance[str_repeat('f', 64)] = $provenance[$identity];

    return [
        'ok' => true,
        'mode' => 'self-test-contextual-assets',
        'contextual_topic_sets' => count($descriptors),
        'contextual_descriptors' => $roleAnchorDescriptors,
        'unique_object_keys' => count($objectKeys),
        'unique_public_urls' => count($publicUrls),
        'role_anchor_descriptors' => $roleAnchorDescriptors,
        'locale_alt_caption_payloads' => $localeAltCaptionPayloads,
        'exact_provenance_matches' => $exactProvenanceMatches,
        'manifest_copy_isolation_matches' => $manifestCopyIsolationMatches,
        'fail_closed' => [
            'identity_mismatch' => $assertFailure($identityMismatch, 'hanfu_r3_contextual_provenance_identity_mismatch'),
            'incomplete_locale' => $assertFailure($incompleteLocale, 'hanfu_r3_contextual_locale_incomplete'),
            'missing_provenance' => $assertFailure($missingProvenance, 'hanfu_r3_contextual_provenance_missing_or_duplicate'),
            'orphaned_provenance' => $assertFailure($orphanedProvenance, 'hanfu_r3_contextual_provenance_orphaned'),
        ],
        'descriptors' => $descriptors,
    ];
}

/** @return array<string,mixed> */
function hanfuR3ContextualFigureSelfTest(): array
{
    $content = '<h2>第一节</h2><p>第一段。</p><h2>第二节</h2><p>第二段。</p><ul><li>第二节列表。</li></ul><h2>第三节</h2><p>第三段。</p><h2>第四节</h2><p>第四段。</p><aside class="editorial-sources">来源。</aside>';
    $assets = [
        [
            'object_key' => 'blog/hanfu/r3/inline/fixture/context-aaaaaaaaaaaa.webp',
            'public_url' => '/pub/media/blog/hanfu/r3/inline/fixture/context-aaaaaaaaaaaa.webp',
            'slot_id' => 'context',
            'visual_role' => 'context',
            'anchor_h2' => 2,
            'locales' => [
                HANFU_R2_ZH => ['locale' => HANFU_R2_ZH, 'default_alt' => '甲<&"', 'default_caption' => '馆藏说明<&"'],
                HANFU_R2_EN => ['locale' => HANFU_R2_EN, 'default_alt' => 'Met fixture', 'default_caption' => 'Met fixture caption'],
            ],
            'provenance' => [
                'source_type' => 'met_open_access_public_domain',
                'source_page' => 'https://www.metmuseum.org/art/collection/search/1',
                'source_object_id' => '1',
                'license' => 'Public & reusable <license>',
                'license_url' => 'https://www.metmuseum.org/about-the-met/policies-and-documents/open-access',
                'ai_editorial' => false,
            ],
        ],
        [
            'object_key' => 'blog/hanfu/r3/inline/fixture/form-bbbbbbbbbbbb.webp',
            'public_url' => '/pub/media/blog/hanfu/r3/inline/fixture/form-bbbbbbbbbbbb.webp',
            'slot_id' => 'form',
            'visual_role' => 'form',
            'anchor_h2' => 4,
            'locales' => [
                HANFU_R2_ZH => ['locale' => HANFU_R2_ZH, 'default_alt' => '编辑图示', 'default_caption' => '编辑图示说明'],
                HANFU_R2_EN => ['locale' => HANFU_R2_EN, 'default_alt' => 'Editorial fixture', 'default_caption' => 'Editorial fixture caption'],
            ],
            'provenance' => [
                'source_type' => 'approved_r3_ai_editorial_derivative',
                'source_page' => '',
                'license' => 'AI license',
                'license_url' => 'https://openai.com/policies/terms-of-use/',
                'ai_editorial' => true,
            ],
        ],
    ];
    $rendered = hanfuR3InjectContextualFigures($content, $assets, HANFU_R2_ZH);
    hanfuR3ValidateContextualFigures($rendered, $assets, HANFU_R2_ZH);
    $expectedContext = hanfuR3ContextualFigureMarkup($assets[0], HANFU_R2_ZH);
    $expectedForm = hanfuR3ContextualFigureMarkup($assets[1], HANFU_R2_ZH);
    $withoutFigures = str_replace([$expectedContext, $expectedForm], '', $rendered);
    $assertFailure = static function (string $candidateContent, array $candidateAssets, string $expected): string {
        try {
            hanfuR3ValidateContextualFigures($candidateContent, $candidateAssets, HANFU_R2_ZH);
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), $expected)) {
                return $expected;
            }
            throw new RuntimeException('hanfu_r3_contextual_figure_self_test_unexpected_failure:' . $exception->getMessage());
        }
        throw new RuntimeException('hanfu_r3_contextual_figure_self_test_expected_failure_missing:' . $expected);
    };
    $duplicateSlot = [$assets[0], $assets[0]];
    $unapprovedUrl = $assets;
    $unapprovedUrl[0]['public_url'] = 'https://example.test/fixture.webp';
    $missingCaption = $assets;
    $missingCaption[0]['locales'][HANFU_R2_ZH]['default_caption'] = '';
    $wrongLocalePayload = $assets;
    $wrongLocalePayload[0]['locales'][HANFU_R2_ZH]['locale'] = HANFU_R2_EN;
    $anchorOutsideRange = $assets;
    $anchorOutsideRange[0]['anchor_h2'] = 5;
    $wrongMetRoute = $assets;
    $wrongMetRoute[0]['provenance']['source_page'] = 'https://www.metmuseum.org/art/collection/search/2';
    $metObjectIdMismatch = $assets;
    $metObjectIdMismatch[0]['provenance']['source_object_id'] = '2';
    $wrongMetPolicy = $assets;
    $wrongMetPolicy[0]['provenance']['license_url'] = 'https://www.metmuseum.org/about-the-met/';
    $wrongAiLicenseUrl = $assets;
    $wrongAiLicenseUrl[1]['provenance']['license_url'] = 'https://openai.com/';
    $localPathInAlt = $assets;
    $localPathInAlt[0]['locales'][HANFU_R2_ZH]['default_alt'] = '/Users/weline/private-object.webp';
    $otherAbsoluteLocalPathInCaption = $assets;
    $otherAbsoluteLocalPathInCaption[0]['locales'][HANFU_R2_ZH]['default_caption'] = '文件位于 /usr/local/source.webp';
    $localizedBracketAbsolutePath = $assets;
    $localizedBracketAbsolutePath[0]['locales'][HANFU_R2_ZH]['default_caption'] = '（/usr/local/source.webp';
    $localizedQuoteAbsolutePath = $assets;
    $localizedQuoteAbsolutePath[0]['locales'][HANFU_R2_ZH]['default_caption'] = '“/usr/local/source.webp';
    $directImageUrlInCaption = $assets;
    $directImageUrlInCaption[0]['locales'][HANFU_R2_ZH]['default_caption'] = 'https://images.metmuseum.org/CRDImages/as/original/173668.jpg';
    $midWordSlashes = $assets;
    $midWordSlashes[0]['locales'][HANFU_R2_ZH]['default_caption'] = '搭配可写作 and/or，比例为 3/4。';
    try {
        hanfuR3InjectContextualFigures($content, $midWordSlashes, HANFU_R2_ZH);
        $midWordSlashesAccepted = true;
    } catch (RuntimeException) {
        $midWordSlashesAccepted = false;
    }

    return [
        'ok' => true,
        'mode' => 'self-test-contextual-figures',
        'figure_count' => substr_count($rendered, 'class="hanfu-article-figure"'),
        'image_count' => preg_match_all('#<img\\b[^>]*>#su', $rendered),
        'h2_count' => count(hanfuR3ContextualH2Sections($rendered)),
        'prose_preserved' => hash_equals($content, $withoutFigures),
        'escaped_markup' => str_contains($rendered, 'alt="甲&lt;&amp;&quot;"')
            && str_contains($rendered, '馆藏说明&lt;&amp;&quot;')
            && str_contains($rendered, 'Public &amp; reusable &lt;license&gt;'),
        'semantic_markup' => str_contains($rendered, 'data-visual-role="context"')
            && str_contains($rendered, '<figcaption>')
            && str_contains($rendered, 'https://www.metmuseum.org/art/collection/search/1')
            && str_contains($rendered, 'AI 编辑插图'),
        'lazy_dimensions' => substr_count($rendered, 'width="1200" height="800" loading="lazy" decoding="async"') === 2,
        'placement_exact' => str_contains($rendered, '<ul><li>第二节列表。</li></ul>' . $expectedContext . '<h2>第三节</h2>')
            && str_contains($rendered, '<p>第四段。</p>' . $expectedForm . '<aside class="editorial-sources">'),
        'mid_word_slashes_accepted' => $midWordSlashesAccepted,
        'fail_closed' => [
            'duplicate_slot' => $assertFailure($content, $duplicateSlot, 'hanfu_r3_contextual_figure_slot_duplicate'),
            'unapproved_url' => $assertFailure($content, $unapprovedUrl, 'hanfu_r3_contextual_figure_url_invalid'),
            'missing_caption' => $assertFailure($content, $missingCaption, 'hanfu_r3_contextual_figure_locale_invalid'),
            'wrong_locale_payload' => $assertFailure($content, $wrongLocalePayload, 'hanfu_r3_contextual_figure_locale_invalid'),
            'anchor_outside_range' => $assertFailure($content, $anchorOutsideRange, 'hanfu_r3_contextual_figure_anchor_invalid'),
            'extra_unapproved_img' => $assertFailure($rendered . '<img src="/unapproved.webp" alt="bad">', $assets, 'hanfu_r3_contextual_figure_img_count_invalid'),
            'wrong_met_route' => $assertFailure($content, $wrongMetRoute, 'hanfu_r3_contextual_figure_provenance_invalid'),
            'met_object_id_mismatch' => $assertFailure($content, $metObjectIdMismatch, 'hanfu_r3_contextual_figure_provenance_invalid'),
            'wrong_met_policy' => $assertFailure($content, $wrongMetPolicy, 'hanfu_r3_contextual_figure_provenance_invalid'),
            'wrong_ai_license_url' => $assertFailure($content, $wrongAiLicenseUrl, 'hanfu_r3_contextual_figure_provenance_invalid'),
            'local_path_in_alt' => $assertFailure($content, $localPathInAlt, 'hanfu_r3_contextual_figure_locale_copy_invalid'),
            'other_absolute_local_path_in_caption' => $assertFailure($content, $otherAbsoluteLocalPathInCaption, 'hanfu_r3_contextual_figure_locale_copy_invalid'),
            'localized_bracket_absolute_path' => $assertFailure($content, $localizedBracketAbsolutePath, 'hanfu_r3_contextual_figure_locale_copy_invalid'),
            'localized_quote_absolute_path' => $assertFailure($content, $localizedQuoteAbsolutePath, 'hanfu_r3_contextual_figure_locale_copy_invalid'),
            'direct_image_url_in_caption' => $assertFailure($content, $directImageUrlInCaption, 'hanfu_r3_contextual_figure_locale_copy_invalid'),
        ],
    ];
}

/** @return list<array<string,mixed>> */
function hanfuR2PublishedRows(): array
{
    /** @var Post $model */
    $model = ObjectManager::getInstance(Post::class);
    $rows = $model->clear()
        ->where(Post::schema_fields_WEBSITE_ID, HANFU_R2_WEBSITE_ID)
        ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
        ->order(Post::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/** @param list<string> $objectKeys @return list<array<string,string|int>> */
function hanfuR3AllBlogReferenceHits(array $objectKeys): array
{
    /** @var Post $model */
    $model = ObjectManager::getInstance(Post::class);
    $rows = $model->clear()->order(Post::schema_fields_ID, 'ASC')->select()->fetchArray();
    $hits = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $field => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $text = (string)$value;
            foreach ($objectKeys as $objectKey) {
                if ($objectKey !== '' && str_contains($text, $objectKey)) {
                    $hits[] = [
                        'post_id' => (int)($row[Post::schema_fields_ID] ?? 0),
                        'slug' => (string)($row[Post::schema_fields_SLUG] ?? ''),
                        'locale' => (string)($row[Post::schema_fields_LOCALE] ?? ''),
                        'status' => (string)($row[Post::schema_fields_STATUS] ?? ''),
                        'field' => (string)$field,
                        'object_key' => $objectKey,
                    ];
                }
            }
        }
    }
    return $hits;
}

/** @param list<string> $objectKeys @return list<array{path:string,object_key:string}> */
function hanfuR3ProjectReferenceHits(string $repoRoot, array $objectKeys): array
{
    $roots = [$repoRoot . '/app/code', $repoRoot . '/app/etc', $repoRoot . '/etc', $repoRoot . '/config'];
    $extensions = ['php'=>true, 'phtml'=>true, 'xml'=>true, 'json'=>true, 'yaml'=>true, 'yml'=>true, 'csv'=>true, 'js'=>true, 'ts'=>true, 'vue'=>true];
    $controlPlane = 'app/code/Weline/Blog/data/hanfu-r3-image-manifest.php';
    $seen = [];
    $hits = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $path = $file->getPathname();
            $real = realpath($path);
            if (!is_string($real) || isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($repoRoot))), '/');
            if ($relative === $controlPlane
                || str_contains('/' . $relative, '/Test/')
                || str_contains('/' . $relative, '/test/')
                || str_contains('/' . $relative, '/doc/')
                || !isset($extensions[strtolower($file->getExtension())])
                || $file->getSize() > 5 * 1024 * 1024
            ) {
                continue;
            }
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                throw new RuntimeException('hanfu_r3_cleanup_project_scan_failed:' . $relative);
            }
            foreach ($objectKeys as $objectKey) {
                if ($objectKey !== '' && str_contains($contents, $objectKey)) {
                    $hits[] = ['path' => $relative, 'object_key' => $objectKey];
                }
            }
        }
    }
    return $hits;
}

if ($mode === '--self-test-contextual-assets') {
    echo json_encode(
        hanfuR3ContextualSelfTest($contextualImageManifest, $contextualProvenance, $repoRoot),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ), PHP_EOL;
    exit(0);
}
if ($mode === '--self-test-contextual-figures') {
    echo json_encode(
        hanfuR3ContextualFigureSelfTest(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ), PHP_EOL;
    exit(0);
}

$rows = hanfuR2PublishedRows();
$localeCounts = [HANFU_R2_ZH => 0, HANFU_R2_EN => 0];
$titles = [];
foreach ($rows as $row) {
    $locale = (string)($row[Post::schema_fields_LOCALE] ?? '');
    if (isset($localeCounts[$locale])) {
        ++$localeCounts[$locale];
    }
    $slug = strtolower(trim((string)($row[Post::schema_fields_SLUG] ?? '')));
    $base = hanfuR2BaseSlug($slug, $locale);
    $titles[$base][hanfuR2IsEnglish($locale) ? 'en' : 'zh'] = trim((string)($row[Post::schema_fields_TITLE] ?? $base));
}
if (count($rows) !== 320 || $localeCounts[HANFU_R2_ZH] !== 160 || $localeCounts[HANFU_R2_EN] !== 160) {
    throw new RuntimeException('hanfu_r2_published_locale_count_invalid:' . json_encode($localeCounts));
}
if (count($titles) !== 160) {
    throw new RuntimeException('hanfu_r2_topic_pair_count_invalid:' . count($titles));
}
$titleBases = array_keys($titles);
$manifestBases = array_keys($imageManifest);
sort($titleBases, SORT_STRING);
sort($manifestBases, SORT_STRING);
if ($titleBases !== $manifestBases) {
    throw new RuntimeException('hanfu_r3_image_manifest_topic_mismatch');
}
$contextualBases = array_keys($contextualImageManifest);
sort($contextualBases, SORT_STRING);
if ($titleBases !== $contextualBases) {
    throw new RuntimeException('hanfu_r3_contextual_manifest_topic_mismatch');
}

$library = in_array($mode, ['--apply', '--cleanup'], true) ? ObjectManager::getInstance(FileAssetLibraryInterface::class) : null;
$admin = $mode === '--apply' ? ObjectManager::getInstance(BlogPostAdminService::class) : null;
$coverByBase = [];
$sourceByBase = [];
$objectKeysByBase = [];

foreach ($titles as $base => $pairTitles) {
    $manifestEntry = $imageManifest[$base] ?? null;
    if (!is_array($manifestEntry)) {
        throw new RuntimeException('hanfu_r3_image_manifest_topic_missing:' . $base);
    }
    $manifestImagePath = ltrim(trim((string)($manifestEntry['image_path'] ?? '')), '/');
    $assetMetadata = hanfuR3ImageAssetMetadata($manifestEntry);
    $zhMetadata = $manifestEntry['locales']['zh_Hans_CN'] ?? null;
    $enMetadata = $manifestEntry['locales']['en_US'] ?? null;
    if (!is_array($zhMetadata) || !is_array($enMetadata)) {
        throw new RuntimeException('hanfu_r3_image_manifest_locale_missing:' . $base);
    }
    $route = hanfuR2EthnicRoute($base);
    if ($route === null) {
        if (!isset($coreProfiles[$base])) {
            throw new RuntimeException('hanfu_r2_core_topic_missing:' . $base);
        }
        $expectedSourcePath = 'var/hanfu-production/final/blog-core/' . $base . '.webp';
        $objectDirectory = 'blog/hanfu/r2/covers/core/';
        $objectStem = $base;
    } else {
        [$code, $variant] = $route;
        if (!isset($ethnicByCode[$code])) {
            throw new RuntimeException('hanfu_r2_ethnic_topic_missing:' . $base);
        }
        $expectedSourcePath = 'var/hanfu-production/final/blog-ethnic/' . $code . '-' . $variant . '.webp';
        $objectDirectory = 'blog/hanfu/r2/covers/ethnic/';
        $objectStem = $code . '-' . $variant;
    }
    if (!hash_equals($expectedSourcePath, $manifestImagePath)) {
        throw new RuntimeException('hanfu_r3_image_manifest_source_mismatch:' . $base);
    }
    $source = $repoRoot . '/' . $manifestImagePath;
    if (!is_file($source) || filesize($source) < 1024) {
        throw new RuntimeException('hanfu_r2_cover_source_missing:' . $source);
    }
    $sourceSha = strtolower((string)hash_file('sha256', $source));
    if (preg_match('/^[0-9a-f]{64}$/D', $sourceSha) !== 1) {
        throw new RuntimeException('hanfu_r2_cover_sha_invalid:' . $source);
    }
    $objectKey = $objectDirectory . $objectStem . '-' . substr($sourceSha, 0, 12) . '.webp';
    $sourceByBase[$base] = $source;
    $objectKeysByBase[$base] = $objectKey;
    $expectedUrl = '/pub/media/' . $objectKey;
    if ($mode === '--apply') {
        if (!$library instanceof FileAssetLibraryInterface) {
            throw new LogicException('hanfu_r2_file_library_unavailable');
        }
        $descriptor = hanfuR2EnsureAsset(
            $library,
            $source,
            $objectKey,
            $zhMetadata,
            $enMetadata,
            $assetMetadata,
        );
        $expectedUrl = trim((string)($descriptor['preview_url'] ?? $expectedUrl));
    }
    $coverByBase[$base] = $expectedUrl;
}

if (count(array_unique($coverByBase)) !== 160) {
    throw new RuntimeException('hanfu_r2_cover_url_not_unique');
}
if (count(array_unique(array_map(static fn(string $file): string => (string)hash_file('sha256', $file), $sourceByBase))) !== 160) {
    throw new RuntimeException('hanfu_r2_cover_binary_not_unique');
}

$contextualAssetsByBase = hanfuR3EnsureContextualAssets(
    $contextualImageManifest,
    $contextualProvenance,
    $repoRoot,
    $mode === '--apply' ? $library : null,
    $mode === '--apply',
);
$contextualDescriptorCount = array_sum(array_map('count', $contextualAssetsByBase));
if (count($contextualAssetsByBase) !== 160 || $contextualDescriptorCount !== 444) {
    throw new RuntimeException('hanfu_r3_contextual_descriptor_count_invalid');
}

$paragraphCount = 0;
$normalizedReuse = [HANFU_R2_ZH => [], HANFU_R2_EN => []];
$drafts = [];
$renderedFigureCount = 0;
$minimumFiguresPerPost = PHP_INT_MAX;
$maximumFiguresPerPost = 0;
foreach ($rows as $row) {
    $postId = (int)($row[Post::schema_fields_ID] ?? 0);
    $slug = strtolower(trim((string)($row[Post::schema_fields_SLUG] ?? '')));
    $locale = (string)($row[Post::schema_fields_LOCALE] ?? HANFU_R2_ZH);
    $base = hanfuR2BaseSlug($slug, $locale);
    $title = trim((string)($row[Post::schema_fields_TITLE] ?? $base));
    if ($base === 'hanfu-vertical-stores-top10-overview') {
        $title = hanfuR2IsEnglish($locale)
            ? 'Specialist Hanfu Stores: A Dated Due-Diligence Framework'
            : '汉服垂直店铺：下单前实时核验的渠道框架';
    }
    $route = hanfuR2EthnicRoute($base);
    if ($route === null) {
        $content = hanfuR3BuildCoreByRole($coreProfiles[$base], $sourceEvidence, $title, $locale, $base);
    } else {
        [$code, $variant] = $route;
        $content = hanfuR2BuildEthnic($ethnicByCode[$code], $sourceEvidence, $variant, $title, $locale);
    }
    if (preg_match('/补充说明|Additional note|为了达到字数|to reach the word count/iu', $content) === 1) {
        throw new RuntimeException('hanfu_r2_filler_detected:' . $slug);
    }
    if (preg_match(hanfuR4PromptLanguagePattern($locale), html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1) {
        throw new RuntimeException('hanfu_r4_prompt_language_detected:' . $slug);
    }
    $expectedContextualAssets = $contextualAssetsByBase[$base] ?? null;
    if (!is_array($expectedContextualAssets)) {
        throw new RuntimeException('hanfu_r3_contextual_figure_assets_missing:' . $slug);
    }
    $content = hanfuR3InjectContextualFigures($content, $expectedContextualAssets, $locale);
    if (substr_count($content, '<h2>') < 6) {
        throw new RuntimeException('hanfu_r2_structure_invalid:' . $slug);
    }
    hanfuR3ValidateContextualFigures($content, $expectedContextualAssets, $locale);
    $figuresForPost = count($expectedContextualAssets);
    $renderedFigureCount += $figuresForPost;
    $minimumFiguresPerPost = min($minimumFiguresPerPost, $figuresForPost);
    $maximumFiguresPerPost = max($maximumFiguresPerPost, $figuresForPost);
    $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $measure = hanfuR2IsEnglish($locale)
        ? count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [])
        : preg_match_all('/\p{Han}/u', $plain);
    $minimum = hanfuR2IsEnglish($locale) ? 700 : 1200;
    if ($measure < $minimum) {
        throw new RuntimeException('hanfu_r2_content_too_short:' . $slug . ':' . $measure);
    }
    preg_match('#<p>(.*?)</p>#su', $content, $firstParagraph);
    $excerptText = trim(html_entity_decode(strip_tags($firstParagraph[1] ?? $plain), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $excerpt = mb_substr((string)preg_replace('/\s+/u', ' ', $excerptText), 0, hanfuR2IsEnglish($locale) ? 220 : 110, 'UTF-8');
    $drafts[] = [
        'post_id' => $postId,
        'website_id' => HANFU_R2_WEBSITE_ID,
        'locale' => $locale,
        'slug' => $slug,
        'title' => $title,
        'excerpt' => $excerpt,
        'content' => $content,
        'cover_image' => $coverByBase[$base],
        'author' => HANFU_R2_AUTHOR,
        'keywords' => trim((string)($row[Post::schema_fields_KEYWORDS] ?? '')),
        'category_id' => (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0),
        'status' => Post::STATUS_PUBLISHED,
        'published_at' => (string)($row[Post::schema_fields_PUBLISHED_AT] ?? ''),
        '_measure' => $measure,
    ];
    $paragraphCount += preg_match_all('#<p>.*?</p>#su', $content);
    if (preg_match_all('#<(?:p|li)>(.*?)</(?:p|li)>#su', $content, $blocks)) {
        foreach ($blocks[1] as $block) {
            $normalized = hanfuR3NormalizeReusableBlock((string)$block);
            if (mb_strlen($normalized, 'UTF-8') >= 36) {
                $normalizedReuse[$locale][$normalized] = ($normalizedReuse[$locale][$normalized] ?? 0) + 1;
            }
        }
    }
}
if (count($drafts) !== 320) {
    throw new RuntimeException('hanfu_r2_draft_count_invalid');
}
if ($renderedFigureCount !== 888 || $minimumFiguresPerPost !== 2 || $maximumFiguresPerPost !== 3) {
    throw new RuntimeException('hanfu_r3_contextual_figure_render_count_invalid');
}
$maximumNormalizedReuse = 0;
foreach ($normalizedReuse as $locale => $counts) {
    $maximum = $counts === [] ? 0 : max($counts);
    $maximumNormalizedReuse = max($maximumNormalizedReuse, $maximum);
    if ($maximum > 4) {
        $mostRepeated = (string)array_search($maximum, $counts, true);
        throw new RuntimeException(
            'hanfu_r3_normalized_reuse_exceeded:' . $locale . ':' . $maximum . ':'
            . mb_substr($mostRepeated, 0, 220, 'UTF-8'),
        );
    }
}

if ($mode === '--apply') {
    if (!$admin instanceof BlogPostAdminService) {
        throw new LogicException('hanfu_r2_blog_admin_unavailable');
    }
    foreach ($drafts as $draft) {
        unset($draft['_measure']);
        $admin->save($draft);
    }
}

$current = [];
if (in_array($mode, ['--verify', '--cleanup'], true)) {
    $current = hanfuR2PublishedRows();
    $currentCovers = [];
    $currentReusableBlocks = [HANFU_R2_ZH => [], HANFU_R2_EN => []];
    foreach ($current as $row) {
        $slug = (string)($row[Post::schema_fields_SLUG] ?? '');
        $locale = (string)($row[Post::schema_fields_LOCALE] ?? '');
        $base = hanfuR2BaseSlug($slug, $locale);
        $content = (string)($row[Post::schema_fields_CONTENT] ?? '');
        $cover = trim((string)($row[Post::schema_fields_COVER_IMAGE] ?? ''));
        if (preg_match('/补充说明|Additional note|为了达到字数|to reach the word count/iu', $content) === 1
            || preg_match(hanfuR4PromptLanguagePattern($locale), html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1
            || substr_count($content, '<h2>') < 6
            || !str_starts_with($cover, '/pub/media/blog/hanfu/r2/covers/')
        ) {
            throw new RuntimeException('hanfu_r2_verify_post_failed:' . $slug);
        }
        $expectedContextualAssets = $contextualAssetsByBase[$base] ?? null;
        if (!is_array($expectedContextualAssets)) {
            throw new RuntimeException('hanfu_r3_verify_contextual_assets_missing:' . $slug);
        }
        try {
            hanfuR3ValidateContextualFigures($content, $expectedContextualAssets, $locale);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('hanfu_r3_verify_contextual_figures_failed:' . $slug . ':' . $exception->getMessage());
        }
        if (!isset($coverByBase[$base]) || !hash_equals((string)$coverByBase[$base], $cover)) {
            throw new RuntimeException(
                ($mode === '--cleanup'
                    ? 'hanfu_r2_cleanup_post_reference_mismatch:'
                    : 'hanfu_r2_verify_cover_mismatch:') . $slug,
            );
        }
        $currentCovers[$base] = $cover;
        if (preg_match_all('#<(?:p|li)>(.*?)</(?:p|li)>#su', $content, $matches)) {
            foreach ($matches[1] as $block) {
                $key = hanfuR3NormalizeReusableBlock((string)$block);
                if (mb_strlen($key, 'UTF-8') >= 36) {
                    $currentReusableBlocks[$locale][$key] = ($currentReusableBlocks[$locale][$key] ?? 0) + 1;
                    if ($currentReusableBlocks[$locale][$key] > 4) {
                        throw new RuntimeException('hanfu_r3_verify_normalized_reuse_exceeded:' . $locale . ':' . $slug);
                    }
                }
            }
        }
    }
    if (count($current) !== 320 || count($currentCovers) !== 160 || count(array_unique($currentCovers)) !== 160) {
        throw new RuntimeException('hanfu_r2_verify_counts_failed');
    }
}

$deletedObsoleteAssets = 0;
$cleanupObjectKeys = [];
$cleanupBlogReferenceHits = 0;
$cleanupProjectReferenceHits = 0;
$cleanupFileManagerReferenceHits = 0;
if ($mode === '--cleanup') {
    $cleanupObjectKeys = hanfuR3ReplacedObjectAllowlist();
    if (!$library instanceof FileAssetLibraryInterface
        || count($cleanupObjectKeys) !== 4
        || count(array_unique($cleanupObjectKeys)) !== 4
        || count($objectKeysByBase) !== 160
    ) {
        throw new RuntimeException('hanfu_r2_cleanup_allowlist_invalid');
    }
    foreach ($cleanupObjectKeys as $cleanupObjectKey) {
        if (preg_match('#^blog/hanfu/r2/covers/(?:core|ethnic)/[a-z0-9-]+-[0-9a-f]{12}\.webp$#D', $cleanupObjectKey) !== 1
            || in_array($cleanupObjectKey, $objectKeysByBase, true)
        ) {
            throw new RuntimeException('hanfu_r2_cleanup_key_outside_allowlist:' . $cleanupObjectKey);
        }
    }
    $blogReferenceHits = hanfuR3AllBlogReferenceHits($cleanupObjectKeys);
    $cleanupBlogReferenceHits = count($blogReferenceHits);
    if ($blogReferenceHits !== []) {
        throw new RuntimeException('hanfu_r3_cleanup_blog_reference_found:' . json_encode($blogReferenceHits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $projectReferenceHits = hanfuR3ProjectReferenceHits($repoRoot, $cleanupObjectKeys);
    $cleanupProjectReferenceHits = count($projectReferenceHits);
    if ($projectReferenceHits !== []) {
        throw new RuntimeException('hanfu_r3_cleanup_project_reference_found:' . json_encode($projectReferenceHits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $cleanupAccess = new FileAccessContext(ScopeIdentity::global(), HANFU_R2_ZH, null, [], 'metadata_edit');
    $cleanupDescriptors = [];
    foreach ($cleanupObjectKeys as $cleanupObjectKey) {
        $descriptor = $library->describe(HANFU_R2_DISK, $cleanupObjectKey, HANFU_R2_ZH, $cleanupAccess);
        $physicalPath = $repoRoot . '/pub/media/' . $cleanupObjectKey;
        if (($descriptor['asset_ready'] ?? false) !== true
            || trim((string)($descriptor['asset_id'] ?? '')) === ''
        ) {
            if (is_file($physicalPath)) {
                throw new RuntimeException('hanfu_r3_cleanup_unregistered_physical_file:' . $cleanupObjectKey);
            }
            continue;
        }
        if (!hash_equals($cleanupObjectKey, (string)($descriptor['object_key'] ?? ''))) {
            throw new RuntimeException('hanfu_r2_cleanup_identity_mismatch:' . $cleanupObjectKey);
        }
        $referenceCount = $library->referenceCount((string)$descriptor['asset_id'], $cleanupAccess);
        $cleanupFileManagerReferenceHits += $referenceCount;
        $cleanupDescriptors[$cleanupObjectKey] = $descriptor;
    }
    if ($cleanupFileManagerReferenceHits !== 0) {
        throw new RuntimeException('hanfu_r3_cleanup_filemanager_reference_found:' . $cleanupFileManagerReferenceHits);
    }
    foreach ($cleanupDescriptors as $cleanupObjectKey => $descriptor) {
        $physicalPath = $repoRoot . '/pub/media/' . $cleanupObjectKey;
        $library->deleteObject(HANFU_R2_DISK, $cleanupObjectKey, $cleanupAccess);
        $afterDelete = $library->describe(HANFU_R2_DISK, $cleanupObjectKey, HANFU_R2_ZH, $cleanupAccess);
        if (($afterDelete['asset_ready'] ?? false) === true
            || trim((string)($afterDelete['asset_id'] ?? '')) !== ''
        ) {
            throw new RuntimeException('hanfu_r2_cleanup_delete_failed:' . $cleanupObjectKey);
        }
        if (is_file($physicalPath)) {
            throw new RuntimeException('hanfu_r3_cleanup_physical_delete_failed:' . $cleanupObjectKey);
        }
        ++$deletedObsoleteAssets;
    }
}

$measurements = array_column($drafts, '_measure');
echo json_encode([
    'ok' => true,
    'mode' => ltrim($mode, '-'),
    'posts' => count($drafts),
    'topic_pairs' => count($titles),
    'unique_covers' => count(array_unique($coverByBase)),
    'contextual_topic_sets' => count($contextualAssetsByBase),
    'contextual_descriptors' => $contextualDescriptorCount,
    'rendered_figures' => $renderedFigureCount,
    'minimum_figures_per_post' => $minimumFiguresPerPost === PHP_INT_MAX ? 0 : $minimumFiguresPerPost,
    'maximum_figures_per_post' => $maximumFiguresPerPost,
    'paragraphs' => $paragraphCount,
    'maximum_normalized_reuse' => $maximumNormalizedReuse,
    'deleted_obsolete_assets' => $deletedObsoleteAssets,
    'cleanup_allowlist_size' => count($cleanupObjectKeys),
    'cleanup_blog_reference_hits' => $cleanupBlogReferenceHits,
    'cleanup_project_reference_hits' => $cleanupProjectReferenceHits,
    'cleanup_filemanager_reference_hits' => $cleanupFileManagerReferenceHits,
    'minimum_measure' => $measurements === [] ? 0 : min($measurements),
    'maximum_measure' => $measurements === [] ? 0 : max($measurements),
    'locales' => $localeCounts,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
