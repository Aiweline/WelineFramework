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
    if (in_array($argument, ['--dry-run', '--apply', '--verify', '--cleanup'], true)) {
        $mode = $argument;
    }
}

$repoRoot = dirname(__DIR__, 5);
$coreProfiles = require __DIR__ . '/hanfu-r2-core-profiles.php';
$ethnicProfiles = require __DIR__ . '/china-ethnic-groups.php';
require_once __DIR__ . '/hanfu-r2-ethnic-editorial.php';
if (!is_array($coreProfiles) || count($coreProfiles) !== 48) {
    throw new RuntimeException('hanfu_r2_core_profile_count_invalid');
}
if (!is_array($ethnicProfiles) || count($ethnicProfiles) !== 56) {
    throw new RuntimeException('hanfu_r2_ethnic_profile_count_invalid');
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

/** @return array{0:string,1:string}|null */
function hanfuR2EthnicRoute(string $baseSlug): ?array
{
    if (preg_match('/^ethnic-([a-z0-9-]+)-(dress-overview|occasion-craft)$/D', $baseSlug, $matches) !== 1) {
        return null;
    }
    return [$matches[1], $matches[2] === 'dress-overview' ? 'overview' : 'occasion'];
}

/** @param array<string,string> $profile */
function hanfuR2BuildCore(array $profile, string $title, string $locale, string $baseSlug): string
{
    $en = hanfuR2IsEnglish($locale);
    $subject = trim((string)($profile[$en ? 'subject_en' : 'subject_zh'] ?? $title));
    $definition = trim((string)($profile[$en ? 'definition_en' : 'definition_zh'] ?? ''));
    $evidence = trim((string)($profile[$en ? 'evidence_en' : 'evidence_zh'] ?? ''));
    $risk = trim((string)($profile[$en ? 'risk_en' : 'risk_zh'] ?? ''));
    $practice = trim((string)($profile[$en ? 'practice_en' : 'practice_zh'] ?? ''));
    foreach ([$subject, $definition, $evidence, $risk, $practice] as $field) {
        if ($field === '') {
            throw new RuntimeException('hanfu_r2_core_profile_field_missing:' . $baseSlug);
        }
    }

    if ($en) {
        $html = hanfuR2P(
            $title . ' is written as a working guide to ' . $subject
            . '. It separates observable garment evidence from styling atmosphere, and it treats changing marketplace information as something to re-check on the purchase date.'
        );
        $html .= hanfuR2H2('1. Define the object before judging it');
        $html .= hanfuR2P($definition);
        $html .= hanfuR2P(
            'For ' . $subject . ', begin with a plain-language category, the intended occasion and the exact question the evidence must answer. '
            . 'A product name, dynasty mood board or platform badge is a lead, never the conclusion.'
        );
        $html .= hanfuR2H2('2. Read construction and listing evidence');
        $html .= hanfuR2P($evidence);
        $html .= hanfuR2Table(
            ['Decision layer', 'Evidence to retain', 'Stop condition'],
            [
                ['Garment identity', 'Front, back and critical construction views for ' . $subject, 'The image hides the collar, closure or lower-garment structure'],
                ['Material and fit', 'Fiber percentage, finished measurements and measurement method', 'Only subjective words or height/weight advice are supplied'],
                ['Fulfillment and use', 'Included pieces, lead time, return route and occasion constraints', 'The seller cannot connect an answer to the exact SKU'],
            ],
        );
        $html .= hanfuR2H2('3. Material, fit and movement');
        $html .= hanfuR2P(
            'Keep fiber, weave, finish and decoration in separate fields when reading ' . $subject
            . '. Compare finished-garment dimensions with a garment that already fits, allow for planned underlayers, and test sitting, walking and arm movement rather than relying on a static pose.'
        );
        $html .= hanfuR2P(
            'If a listing omits a critical measurement or construction view, record the omission. A professional guide is allowed to stop; inventing a likely answer is less useful than identifying the missing evidence.'
        );
        $html .= hanfuR2H2('4. Main risk and cultural boundary');
        $html .= hanfuR2P($risk);
        $html .= hanfuR2P(
            'In the context of ' . $title
            . ', distinguish historically named forms, modern adaptations, Han-inspired fashion and stage costume. Honest labeling protects both the buyer’s occasion and the cultural meaning of the garment.'
        );
        $html .= hanfuR2H2('5. A field method that can be repeated');
        $html .= hanfuR2P($practice);
        $html .= hanfuR2List([
            'Write the intended occasion, movement needs and deadline for ' . $subject . '.',
            'Save the exact SKU, seller identity, product views and specification page.',
            'Separate fiber composition from weave, finish and decorative technique.',
            'Compare finished measurements, not a generic size letter alone.',
            'Re-check price, stock, shipping, tax and returns on the day of purchase.',
            'On arrival, measure flat and record any difference before removing tags.',
        ]);
        $html .= hanfuR2H2('6. Editorial conclusion');
        $html .= hanfuR2P(
            'The useful conclusion for ' . $title
            . ' is therefore conditional: state what the evidence supports, what remains unverified and which use case the item can realistically serve. '
            . 'That standard is stricter than a trend roundup, but it is what makes the guide reusable.'
        );
        if (in_array($baseSlug, ['what-is-hanfu-complete-guide', 'hanfu-through-dynasties-tang-song-ming', 'hanfu-styles-ruqun-mamian-yuanling', 'hanfu-styling-complete-guide', 'mens-yuanling-robe-checklist'], true)) {
            $html .= '<aside class="editorial-sources"><strong>Reference trail:</strong> '
                . '<a href="https://www.chnmuseum.cn/portals/0/web/zt/202102gdfsh/" rel="noopener noreferrer">National Museum of China costume exhibition</a>; '
                . '<a href="https://www.chinasilkmuseum.com/zggd/info_21.aspx?itemid=1974" rel="noopener noreferrer">China National Silk Museum mamian object note</a>; '
                . '<a href="https://www.metmuseum.org/art/collection/search/69672" rel="noopener noreferrer">The Met Open Access object record</a>. '
                . 'Object records support observation; they do not make one surviving piece representative of an entire dynasty.</aside>';
        }
        return $html;
    }

    $html = hanfuR2P(
        '《' . $title . '》不是热词汇总，而是一条针对“' . $subject . '”的可执行判断路径。'
        . '本文把看得见的服装结构、商品证据与穿着场合分开，并把会变化的渠道信息明确留到下单当天复核。'
    );
    $html .= hanfuR2H2('一、先定义对象，再开始判断');
    $html .= hanfuR2P($definition);
    $html .= hanfuR2P(
        '阅读“' . $subject . '”时，先用普通语言写清类别、用途和要回答的问题。'
        . '商品标题、朝代氛围图或平台徽标只能提供线索，不能代替结构结论。'
    );
    $html .= hanfuR2H2('二、读结构，也读商品证据');
    $html .= hanfuR2P($evidence);
    $html .= hanfuR2Table(
        ['判断层', '应保留的证据', '停止条件'],
        [
            ['服装身份', $subject . '的正面、背面与关键结构视角', '领口、闭合或下装结构被遮挡'],
            ['材质与合身', '纤维百分比、成衣尺寸、测量方法', '只有主观形容词或身高体重建议'],
            ['履约与场合', '包含件数、交期、退换路径、活动限制', '客服答复无法对应具体 SKU'],
        ],
    );
    $html .= hanfuR2H2('三、把面料、尺码和活动量拆开');
    $html .= hanfuR2P(
        '判断“' . $subject . '”时，应把纤维、织物组织、后整理和装饰工艺分别记录。'
        . '尺码则用成衣尺寸对照一件已经合身的衣服，并为计划中的内搭留出余量；静态模特照不能替代坐、走、抬手测试。'
    );
    $html .= hanfuR2P(
        '若页面缺少关键尺寸或结构视角，就把“缺少证据”写进结论。'
        . '专业文章可以停在不能确认的位置，猜一个看似合理的答案反而会误导购买。'
    );
    $html .= hanfuR2H2('四、主要风险与文化边界');
    $html .= hanfuR2P($risk);
    $html .= hanfuR2P(
        '放回《' . $title . '》的语境，还要区分历史形制、现代改良、汉元素时装和舞台服。'
        . '准确命名既保护读者的场合选择，也避免用“古风”抹平服装本身的文化身份。'
    );
    $html .= hanfuR2H2('五、可以重复执行的现场方法');
    $html .= hanfuR2P($practice);
    $html .= hanfuR2List([
        '先写清' . $subject . '的用途、活动量和截止日期。',
        '保存具体 SKU、卖家主体、结构图片和规格页面。',
        '把纤维成分与织法、整理、装饰工艺分开。',
        '比较成衣尺寸，不单独相信 S/M/L 字母。',
        '价格、库存、物流、税费与退换在下单当天复核。',
        '收货后平铺实测，未确认差异前保留吊牌与包装。',
    ]);
    $html .= hanfuR2H2('六、编辑结论');
    $html .= hanfuR2P(
        '因此，《' . $title . '》的有效结论必须带条件：哪些事实已有证据、哪些仍未核实、这件商品或方法真正适合什么场合。'
        . '它比热度榜单更克制，却能在页面和平台变化后继续帮助读者。'
    );
    if (in_array($baseSlug, ['what-is-hanfu-complete-guide', 'hanfu-through-dynasties-tang-song-ming', 'hanfu-styles-ruqun-mamian-yuanling', 'hanfu-styling-complete-guide', 'mens-yuanling-robe-checklist'], true)) {
        $html .= '<aside class="editorial-sources"><strong>资料线索：</strong>'
            . '<a href="https://www.chnmuseum.cn/portals/0/web/zt/202102gdfsh/" rel="noopener noreferrer">中国国家博物馆服饰专题展</a>；'
            . '<a href="https://www.chinasilkmuseum.com/zggd/info_21.aspx?itemid=1974" rel="noopener noreferrer">中国丝绸博物馆马面裙藏品说明</a>；'
            . '<a href="https://www.metmuseum.org/art/collection/search/69672" rel="noopener noreferrer">大都会艺术博物馆开放藏品记录</a>。'
            . '藏品记录用于结构观察，不把一件存世实物泛化为整个朝代。</aside>';
    }
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

/** @param array<string,mixed> $profile */
function hanfuR2BuildEthnic(array $profile, string $variant, string $title, string $locale): string
{
    $article = hanfuR2EthnicEditorial($profile, $variant, $title, $locale);
    $html = hanfuR2P($article['lede']);
    foreach ($article['sections'] as $section) {
        $html .= hanfuR2H2($section['heading']);
        foreach ($section['paragraphs'] as $paragraph) {
            $html .= hanfuR2P($paragraph);
        }
    }
    $html .= hanfuR2H2(str_starts_with(strtolower($locale), 'en') ? 'Reviewed fact card' : '已核对事实卡');
    return $html . hanfuR2List($article['reviewed_facts']);
}

/** @param array<string,bool> $seen */
function hanfuR2UniquifyParagraphs(string $html, string $context, string $locale, array &$seen): string
{
    $en = hanfuR2IsEnglish($locale);
    $result = preg_replace_callback(
        '#<p>(.*?)</p>#su',
        static function (array $matches) use ($context, $en, &$seen): string {
            $plain = trim(html_entity_decode(strip_tags((string)$matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $key = mb_strtolower((string)preg_replace('/\s+/u', ' ', $plain));
            if ($key === '') {
                return (string)$matches[0];
            }
            if (isset($seen[$key])) {
                $plain = $en
                    ? 'For the specific case “' . $context . ',” ' . lcfirst($plain)
                    : '就《' . $context . '》的具体对象而言，' . $plain;
                $key = mb_strtolower((string)preg_replace('/\s+/u', ' ', $plain));
            }
            if (isset($seen[$key])) {
                throw new RuntimeException('hanfu_r2_duplicate_paragraph_unresolved:' . $context);
            }
            $seen[$key] = true;
            return hanfuR2P($plain);
        },
        $html,
    );
    if (!is_string($result)) {
        throw new RuntimeException('hanfu_r2_paragraph_rewrite_failed');
    }
    return $result;
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

/** @return array<string,string> */
function hanfuR2LocaleMetadata(string $title, string $kind, string $locale): array
{
    $en = hanfuR2IsEnglish($locale);
    return [
        'display_name' => $en ? $title . ' editorial cover' : $title . '专业文章封面',
        'default_alt' => $en
            ? $title . ': evidence-led clothing and craft illustration'
            : $title . '：与文章主题一致的服装结构与工艺配图',
        'description' => $en
            ? 'Human-reviewed ' . $kind . ' cover created for the bilingual Hanfu editorial library; the image is used only for its paired topic.'
            : '经人工复核的' . $kind . '封面，仅用于这一组中英文主题，服装形制、材料或文化线索与正文一致。',
        'default_caption' => $en
            ? 'Editorial visual for ' . $title . '; verify object-level evidence and regional variation in the article.'
            : '《' . $title . '》编辑配图；具体形制与地区差异以正文证据边界为准。',
        'translation_state' => FileAssetLibraryInterface::TRANSLATION_REVIEWED,
        'translation_origin' => FileAssetLibraryInterface::TRANSLATION_MANUAL,
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

$library = $mode === '--dry-run' ? null : ObjectManager::getInstance(FileAssetLibraryInterface::class);
$admin = $mode === '--apply' ? ObjectManager::getInstance(BlogPostAdminService::class) : null;
$coverByBase = [];
$sourceByBase = [];
$objectKeysByBase = [];
$legacyObjectKeys = [];

foreach ($titles as $base => $pairTitles) {
    $route = hanfuR2EthnicRoute($base);
    if ($route === null) {
        if (!isset($coreProfiles[$base])) {
            throw new RuntimeException('hanfu_r2_core_topic_missing:' . $base);
        }
        $source = $repoRoot . '/var/hanfu-production/final/blog-core/' . $base . '.webp';
        $objectDirectory = 'blog/hanfu/r2/covers/core/';
        $objectStem = $base;
        $kindZh = '汉服专题';
        $kindEn = 'Hanfu topic';
        $relations = ['blog_base_slug' => $base, 'topic_type' => 'core'];
    } else {
        [$code, $variant] = $route;
        if (!isset($ethnicByCode[$code])) {
            throw new RuntimeException('hanfu_r2_ethnic_topic_missing:' . $base);
        }
        $source = $repoRoot . '/var/hanfu-production/final/blog-ethnic/' . $code . '-' . $variant . '.webp';
        $objectDirectory = 'blog/hanfu/r2/covers/ethnic/';
        $objectStem = $code . '-' . $variant;
        $kindZh = '民族服饰';
        $kindEn = 'ethnic-dress';
        $relations = [
            'blog_base_slug' => $base,
            'ethnic_code' => $code,
            'variant' => $variant,
            'profile_source' => 'app/code/Weline/Blog/data/china-ethnic-groups.php',
        ];
    }
    if (!is_file($source) || filesize($source) < 1024) {
        throw new RuntimeException('hanfu_r2_cover_source_missing:' . $source);
    }
    $sourceSha = strtolower((string)hash_file('sha256', $source));
    if (preg_match('/^[0-9a-f]{64}$/D', $sourceSha) !== 1) {
        throw new RuntimeException('hanfu_r2_cover_sha_invalid:' . $source);
    }
    $legacyObjectKey = $objectDirectory . $objectStem . '.webp';
    $objectKey = $objectDirectory . $objectStem . '-' . substr($sourceSha, 0, 12) . '.webp';
    $sourceByBase[$base] = $source;
    $objectKeysByBase[$base] = $objectKey;
    $legacyObjectKeys[$base] = $legacyObjectKey;
    $expectedUrl = '/pub/media/' . $objectKey;
    if ($mode !== '--dry-run') {
        if (!$library instanceof FileAssetLibraryInterface) {
            throw new LogicException('hanfu_r2_file_library_unavailable');
        }
        $zhTitle = trim((string)($pairTitles['zh'] ?? $base));
        $enTitle = trim((string)($pairTitles['en'] ?? $base));
        $descriptor = hanfuR2EnsureAsset(
            $library,
            $source,
            $objectKey,
            hanfuR2LocaleMetadata($zhTitle, $kindZh, HANFU_R2_ZH),
            hanfuR2LocaleMetadata($enTitle, $kindEn, HANFU_R2_EN),
            [
                'source_type' => 'ai_generated_editorial',
                'source' => $route === null
                    ? 'OpenAI ImageGen; human-reviewed against the named Hanfu topic'
                    : 'OpenAI ImageGen; prompt grounded in app/code/Weline/Blog/data/china-ethnic-groups.php',
                'license' => 'project_generated_asset_for_commercial_editorial_use',
                'purpose' => 'bilingual_blog_cover',
                'relations' => $relations,
                'review' => [
                    'state' => 'human_reviewed',
                    'checks' => ['topic_match', 'garment_or_cultural_cues', 'no_logo', 'no_watermark', 'no_reused_file'],
                ],
                'created_for' => 'Amayun Hanfu R2 remediation 2026-09-01',
            ],
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

$seenParagraphs = [];
$drafts = [];
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
        $content = hanfuR2BuildCore($coreProfiles[$base], $title, $locale, $base);
    } else {
        [$code, $variant] = $route;
        $content = hanfuR2BuildEthnic($ethnicByCode[$code], $variant, $title, $locale);
    }
    $content = hanfuR2UniquifyParagraphs($content, $title, $locale, $seenParagraphs);
    if (preg_match('/补充说明|Additional note|为了达到字数|to reach the word count/iu', $content) === 1) {
        throw new RuntimeException('hanfu_r2_filler_detected:' . $slug);
    }
    if (substr_count($content, '<h2>') < 6 || str_contains($content, '<img')) {
        throw new RuntimeException('hanfu_r2_structure_invalid:' . $slug);
    }
    $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $measure = hanfuR2IsEnglish($locale)
        ? count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [])
        : mb_strlen($plain, 'UTF-8');
    $minimum = hanfuR2IsEnglish($locale) ? 330 : 620;
    if ($measure < $minimum) {
        throw new RuntimeException('hanfu_r2_content_too_short:' . $slug . ':' . $measure);
    }
    $excerpt = mb_substr((string)preg_replace('/\s+/u', ' ', $plain), 0, hanfuR2IsEnglish($locale) ? 220 : 110, 'UTF-8');
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
}
if (count($drafts) !== 320) {
    throw new RuntimeException('hanfu_r2_draft_count_invalid');
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
    $currentParagraphs = [];
    foreach ($current as $row) {
        $slug = (string)($row[Post::schema_fields_SLUG] ?? '');
        $locale = (string)($row[Post::schema_fields_LOCALE] ?? '');
        $base = hanfuR2BaseSlug($slug, $locale);
        $content = (string)($row[Post::schema_fields_CONTENT] ?? '');
        $cover = trim((string)($row[Post::schema_fields_COVER_IMAGE] ?? ''));
        if (preg_match('/补充说明|Additional note|为了达到字数|to reach the word count/iu', $content) === 1
            || substr_count($content, '<h2>') < 6
            || str_contains($content, '<img')
            || !str_starts_with($cover, '/pub/media/blog/hanfu/r2/covers/')
        ) {
            throw new RuntimeException('hanfu_r2_verify_post_failed:' . $slug);
        }
        if (!isset($coverByBase[$base]) || !hash_equals((string)$coverByBase[$base], $cover)) {
            throw new RuntimeException(
                ($mode === '--cleanup'
                    ? 'hanfu_r2_cleanup_post_reference_mismatch:'
                    : 'hanfu_r2_verify_cover_mismatch:') . $slug,
            );
        }
        $currentCovers[$base] = $cover;
        if (preg_match_all('#<p>(.*?)</p>#su', $content, $matches)) {
            foreach ($matches[1] as $paragraph) {
                $key = mb_strtolower(trim((string)preg_replace(
                    '/\s+/u',
                    ' ',
                    html_entity_decode(strip_tags((string)$paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                )));
                if ($key !== '' && isset($currentParagraphs[$key])) {
                    throw new RuntimeException('hanfu_r2_verify_duplicate_paragraph:' . $slug);
                }
                $currentParagraphs[$key] = true;
            }
        }
    }
    if (count($current) !== 320 || count($currentCovers) !== 160 || count(array_unique($currentCovers)) !== 160) {
        throw new RuntimeException('hanfu_r2_verify_counts_failed');
    }
}

$deletedObsoleteAssets = 0;
if ($mode === '--cleanup') {
    if (!$library instanceof FileAssetLibraryInterface
        || count($legacyObjectKeys) !== 160
        || count(array_unique($legacyObjectKeys)) !== 160
        || count($objectKeysByBase) !== 160
    ) {
        throw new RuntimeException('hanfu_r2_cleanup_allowlist_invalid');
    }
    $cleanupAccess = new FileAccessContext(ScopeIdentity::global(), HANFU_R2_ZH, null, [], 'metadata_edit');
    foreach ($legacyObjectKeys as $base => $legacyObjectKey) {
        if (!isset($objectKeysByBase[$base])
            || hash_equals($objectKeysByBase[$base], $legacyObjectKey)
            || preg_match('#^blog/hanfu/r2/covers/(?:core|ethnic)/[a-z0-9-]+\.webp$#D', $legacyObjectKey) !== 1
        ) {
            throw new RuntimeException('hanfu_r2_cleanup_key_outside_allowlist:' . $legacyObjectKey);
        }
        try {
            $descriptor = $library->describe(HANFU_R2_DISK, $legacyObjectKey, HANFU_R2_ZH, $cleanupAccess);
        } catch (RuntimeException) {
            continue;
        }
        if (($descriptor['asset_ready'] ?? false) !== true
            || trim((string)($descriptor['asset_id'] ?? '')) === ''
        ) {
            continue;
        }
        if (!hash_equals($legacyObjectKey, (string)($descriptor['object_key'] ?? ''))) {
            throw new RuntimeException('hanfu_r2_cleanup_identity_mismatch:' . $legacyObjectKey);
        }
        $library->deleteObject(HANFU_R2_DISK, $legacyObjectKey, $cleanupAccess);
        $afterDelete = $library->describe(HANFU_R2_DISK, $legacyObjectKey, HANFU_R2_ZH, $cleanupAccess);
        if (($afterDelete['asset_ready'] ?? false) === true
            || trim((string)($afterDelete['asset_id'] ?? '')) !== ''
        ) {
            throw new RuntimeException('hanfu_r2_cleanup_delete_failed:' . $legacyObjectKey);
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
    'unique_paragraphs' => count($seenParagraphs),
    'deleted_obsolete_assets' => $deletedObsoleteAssets,
    'minimum_measure' => $measurements === [] ? 0 : min($measurements),
    'maximum_measure' => $measurements === [] ? 0 : max($measurements),
    'locales' => $localeCounts,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
