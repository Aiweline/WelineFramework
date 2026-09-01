<?php

declare(strict_types=1);

/**
 * Seed R1 batch-4: styling + buying guides + world ethnic dress (zh + en).
 * Hard rules: product-*.jpg only; within-article unique; unordered sets unique vs existing posts.
 *
 * Usage: php app/code/Weline/Blog/data/seed-blog-r1-articles-batch4.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const PHOTO_BASE = '/media/blog/hanfu/articles/photos';
const CAPTIONS_FILE = __DIR__ . '/hanfu-photo-captions.json';
const AUTHOR = 'Amayun Editorial';
const POOL_SIZE = 29;
const PER_ARTICLE = 4;

/** @return array<string,int> */
function categoryIdMap(): array
{
    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $map = [];
    foreach ($admin->tree(WEBSITE_ID, 'zh_Hans_CN') as $node) {
        $slug = trim((string)($node['code'] ?? ''));
        $id = (int)($node['category_id'] ?? 0);
        if ($slug !== '' && $id > 0) {
            $map[$slug] = $id;
        }
    }

    return $map;
}

function h2(string $t): string
{
    return '<h2>' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
}

function p(string $t): string
{
    return '<p>' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
}

function ul(array $items): string
{
    $lis = '';
    foreach ($items as $item) {
        $lis .= '<li>' . htmlspecialchars((string)$item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
    }

    return '<ul>' . $lis . '</ul>';
}

function tableHtml(array $headers, array $rows): string
{
    $html = '<table><thead><tr>';
    foreach ($headers as $h) {
        $html .= '<th>' . htmlspecialchars((string)$h, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }

    return $html . '</tbody></table>';
}

/** @return array<string, array{zh:string,en:string}> */
function loadCaptions(): array
{
    $raw = file_get_contents(CAPTIONS_FILE);
    if ($raw === false) {
        throw new RuntimeException('Cannot read captions');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid captions JSON');
    }
    $out = [];
    foreach ($data as $file => $row) {
        if (!is_string($file) || !is_array($row) || !preg_match('/^product-\d{2}\.jpg$/', $file)) {
            continue;
        }
        $out[$file] = [
            'zh' => trim((string)($row['zh'] ?? '')),
            'en' => trim((string)($row['en'] ?? '')),
        ];
    }

    return $out;
}

/** @return array<string, true> unordered set keys already used by zh posts */
function reservedPhotoSetKeys(): array
{
    $model = ObjectManager::getInstance(Post::class);
    $rows = $model->clear()->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
        ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
        ->select()->fetchArray();
    $keys = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row) || str_ends_with((string)($row[Post::schema_fields_SLUG] ?? ''), '-en')) {
            continue;
        }
        $blob = (string)($row[Post::schema_fields_COVER_IMAGE] ?? '') . "\n" . (string)($row[Post::schema_fields_CONTENT] ?? '');
        preg_match_all('#product-(\d{2})\.jpg#', $blob, $m);
        $nums = array_values(array_unique(array_map('intval', $m[1] ?? [])));
        sort($nums);
        if (count($nums) >= 2) {
            $keys[implode(',', $nums)] = true;
        }
    }

    return $keys;
}

/**
 * @param list<string> $slugs
 * @param array<string, true> $reserved
 * @return array<string, list<int>>
 */
function allocateAvoidingReserved(array $slugs, array $reserved, int $poolSize = POOL_SIZE, int $per = PER_ARTICLE): array
{
    $usage = array_fill(1, $poolSize, 0);
    $coverUsage = array_fill(1, $poolSize, 0);
    $seen = $reserved;
    $assign = [];
    foreach ($slugs as $slug) {
        $ids = range(1, $poolSize);
        usort($ids, static fn (int $a, int $b): int => ($usage[$a] <=> $usage[$b]) ?: ($a <=> $b));
        $picked = null;
        $n = count($ids);
        for ($i = 0; $i < $n && $picked === null; ++$i) {
            for ($j = $i + 1; $j < $n && $picked === null; ++$j) {
                for ($k = $j + 1; $k < $n && $picked === null; ++$k) {
                    for ($l = $k + 1; $l < $n; ++$l) {
                        $combo = [$ids[$i], $ids[$j], $ids[$k], $ids[$l]];
                        sort($combo);
                        $key = implode(',', $combo);
                        if (!isset($seen[$key])) {
                            $picked = $combo;
                            break;
                        }
                    }
                }
            }
        }
        if ($picked === null) {
            throw new RuntimeException('No free photo set for ' . $slug);
        }
        usort($picked, static function (int $a, int $b) use ($coverUsage, $usage): int {
            return ($coverUsage[$a] <=> $coverUsage[$b])
                ?: (($usage[$a] <=> $usage[$b]) ?: ($a <=> $b));
        });
        $sorted = $picked;
        sort($sorted);
        $seen[implode(',', $sorted)] = true;
        ++$coverUsage[$picked[0]];
        foreach ($picked as $num) {
            ++$usage[$num];
        }
        $assign[$slug] = $picked;
    }

    return $assign;
}

/** @param list<int> $nums @return list<string> */
function photoFiles(array $nums): array
{
    $out = [];
    $seen = [];
    foreach ($nums as $n) {
        $n = (int)$n;
        if ($n <= 0 || isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $out[] = sprintf('product-%02d.jpg', $n);
    }

    return $out;
}

/**
 * @param array<string, array{zh:string,en:string}> $captions
 * @param list<string> $files cover + body
 * @return list<string> body figure HTML only
 */
function bodyFigs(array $files, string $locale, array $captions, string $altBase): array
{
    $blocks = [];
    $body = array_slice($files, 1);
    $seen = [$files[0] => true];
    foreach ($body as $i => $file) {
        if (isset($seen[$file])) {
            throw new InvalidArgumentException('Duplicate figure: ' . $file);
        }
        $seen[$file] = true;
        $src = PHOTO_BASE . '/' . $file;
        $cap = $captions[$file][str_starts_with($locale, 'en') ? 'en' : 'zh'] ?? $file;
        $blocks[] = '<figure class="blog-illust">'
            . '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="'
            . htmlspecialchars($altBase . ' #' . ($i + 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" loading="lazy" />'
            . '<figcaption>' . htmlspecialchars($cap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</figcaption>'
            . '</figure>';
    }

    return $blocks;
}

function cta(string $locale): string
{
    if (str_starts_with($locale, 'en')) {
        return h2('Factory-direct next step')
            . p('Amayun Technology Co., Ltd. (registered 2024) offers factory-direct Hanfu: verified origin partners, inspected construction, and handmade finishing with green mechanical production. When a listing cannot show flat-lay structure or honest fiber data, compare the same silhouette against Amayun catalog photos and QC notes before you pay.')
            . p('Product photos here are correct-form references for learning structure—not claims that every channel sells these exact SKUs.');
    }

    return h2('源头工厂直销：下一步怎么选')
        . p('阿玛云科技有限公司（注册于 2024）提供源头工厂直销：原产地伙伴可追溯、结构与面料按可穿标准质检，手工结合绿色机械制造。当详情页缺少平铺结构或纤维说明时，请先用成衣实拍对照形制，再决定是否转向工厂直销。')
        . p('本文配图为形制/面料参考实拍，用于学习正确结构，并不等同于宣称某渠道正在售卖同一 SKU。');
}

function ensureLength(string $html, string $locale): string
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (str_starts_with($locale, 'en')) {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $i = 0;
        while (count($words) < 720 && $i < 12) {
            $html .= p('Additional note ' . ($i + 1) . ': translate marketing adjectives into checkable structure—collar direction, mamian panel width and pleat spacing, ruqun waistband join, yuanling collar and sleeve span, fiber percentages and lining, centimeter size charts, and return windows that cover international shipping time. Once the silhouette name is fixed, compare the same cues against factory-direct catalog photos.');
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            ++$i;
        }

        return $html;
    }
    $i = 0;
    while (mb_strlen($text) < 1200 && $i < 8) {
        $html .= p('补充说明' . ($i + 1) . '：下单前把营销形容词翻译成可检查的结构点——交领方向、马面光面与褶距、襦裙腰头连接、圆领袍领圈与通袖、纤维百分比与里布、厘米尺码表，以及退货窗口是否覆盖国际物流耗时。形制名称固定后，再用同一结构对照工厂直销目录与质检说明。');
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        ++$i;
    }

    return $html;
}

function assertNoDupImages(string $cover, string $content, string $slug): void
{
    preg_match_all('#product-\d{2}\.jpg#', $cover . "\n" . $content, $m);
    $counts = array_count_values($m[0] ?? []);
    $dups = [];
    foreach ($counts as $file => $n) {
        if ($n > 1) {
            $dups[] = $file . 'x' . $n;
        }
    }
    if ($dups !== []) {
        throw new RuntimeException('Duplicate images in ' . $slug . ': ' . implode(',', $dups));
    }
}

function slugExists(string $slug): bool
{
    $model = ObjectManager::getInstance(Post::class);
    $row = $model->clearData()->reset()
        ->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
        ->where(Post::schema_fields_SLUG, $slug)
        ->find()
        ->fetchArray();

    return is_array($row) && (int)($row[Post::schema_fields_ID] ?? 0) > 0;
}

/**
 * @param list<string> $F body figures
 */
function buildContent(string $type, string $locale, string $title, array $F): string
{
    $en = str_starts_with($locale, 'en');
    $html = match ($type) {
        'styling_hub' => $en
            ? h2('Styling is structure first')
            . p('Hanfu and New Chinese looks fail when accessories arrive before silhouette literacy. This hub connects daily commute, wedding/festival dressing, beauty & accessories, and size/care—so outfits stay wearable rather than costume-only.')
            . $F[0]
            . h2('Four styling tracks on Amayun')
            . ul([
                'Daily & commute: lightweight layering, hem clearance, subway-friendly sleeves.',
                'Wedding & festival: ceremony read vs photoshoot weight; honest naming.',
                'Beauty & accessories: hair, waist ornaments, shoes that respect collar and waistline.',
                'Size & care: flat cm charts, lining notes, wash/storage that protect weave and embroidery.',
            ])
            . $F[1]
            . h2('Outfit decision order')
            . tableHtml(
                ['Step', 'Question', 'Fail signal'],
                [
                    ['1 Silhouette', 'Mamian / ruqun / yuanling / New Chinese?', 'Title says Hanfu but zipper sheath only'],
                    ['2 Occasion', 'Commute, ceremony, travel photos?', 'Festival weight for office stairs'],
                    ['3 Proportion', 'Waist height vs height/hem?', 'Hands always hide the waistband'],
                    ['4 Finish', 'Hair/shoes/jewelry restrained?', 'Ornaments louder than garment structure'],
                ]
            )
            . $F[2]
            . h2('How photos teach styling')
            . p('Use product photography as geometry class: panel width, collar direction, waist join. Mood lighting is optional; flat construction is not.')
            : h2('穿搭先过形制关')
            . p('汉服与新中式穿搭最常见的失败，是配饰先于形制认知。本专栏把日常通勤、婚礼节令、妆发配饰、尺码保养串成一条可执行路径，让造型可穿、可重复，而不是一次性影楼效果。')
            . $F[0]
            . h2('阿玛云站内四条穿搭线')
            . ul([
                '日常与通勤：轻量叠穿、裙长净空、袖型适合公共交通。',
                '婚礼与节令：仪式感与拍摄负重分开谈，命名要诚实。',
                '妆发与配饰：发饰、腰饰、鞋履服从领型与腰线，而不是反过来。',
                '尺码与保养：厘米平铺、里布说明、洗涤收纳保护织纹与绣面。',
            ])
            . $F[1]
            . h2('穿搭决策顺序')
            . tableHtml(
                ['步骤', '先问什么', '失败信号'],
                [
                    ['1 形制', '马面 / 襦裙 / 圆领 / 新中式？', '标题写汉服却只有拉链连衣裙'],
                    ['2 场合', '通勤、仪式、旅行出片？', '把节令重工搬进办公室楼梯'],
                    ['3 比例', '腰线高度与身高裙长？', '所有图都用手挡住腰头'],
                    ['4 收尾', '发饰鞋履是否克制？', '配饰声量压过衣身结构'],
                ]
            )
            . $F[2]
            . h2('用成衣图学穿搭')
            . p('把产品摄影当几何课：光面宽度、交领方向、腰头连接。氛围光可有可无，平铺结构不可缺。'),

        'styling_daily' => $en
            ? h2('Daily Hanfu that survives real cities')
            . p('Commute styling prioritizes hem clearance, sleeve swing in crowded spaces, breathable mid-weight fabrics, and silhouettes you can sit in for hours. Classical form still matters—just choose lighter constructions.')
            . $F[0]
            . h2('Commute checklist')
            . ul([
                'Hem: avoid dragging; rough rule height−60cm still loses to flat measurements.',
                'Sleeves: wide ceremonial sleeves fight subway doors—prefer daily sleeve cuts.',
                'Layers: jacket/outer that removes indoors without destroying the collar story.',
                'Shoes: closed toe for rain cities; keep ornamentation low for office norms.',
            ])
            . $F[1]
            . h2('Silhouette picks for weekdays')
            . tableHtml(
                ['Goal', 'Prefer', 'Caution'],
                [
                    ['Office-adjacent', 'Clean ruqun / restrained New Chinese', 'Heavy embroidery that snags'],
                    ['Weekend city walk', 'Mamian with stable panels', 'Costume zipper dresses labeled Hanfu'],
                    ['Travel photos', 'Coordinated color stories', 'Unstable metallic yarns that crack after wash'],
                ]
            )
            . $F[2]
            . h2('Styling without costume theater')
            . p('One hair piece and one waist accent beat five loud props. If coworkers cannot see structure, they only see “costume day.”')
            : h2('能进真实城市的日常汉服')
            . p('通勤穿搭优先考虑裙长净空、拥挤空间里的袖摆、中等透气面料，以及能久坐的结构。正统形制仍然重要——只是选更轻的做工与版型。')
            . $F[0]
            . h2('通勤检查清单')
            . ul([
                '裙长：避免拖地；「身高-60cm」只是粗算，以平铺厘米为准。',
                '袖型：礼仪大袖与地铁门冲突，日常款更友好。',
                '叠穿：室内可脱外套且不破坏领型叙事。',
                '鞋履：多雨城市优先包头；办公室场景配饰克制。',
            ])
            . $F[1]
            . h2('工作日形制怎么选')
            . tableHtml(
                ['目标', '更推荐', '小心'],
                [
                    ['偏办公', '清爽襦裙 / 克制新中式', '易勾丝的重绣'],
                    ['周末城市步行', '光面稳定的马面', '标题汉服实则拉链裙'],
                    ['旅行出片', '色系统一的成套', '洗后易裂的劣质金属纱'],
                ]
            )
            . $F[2]
            . h2('日常造型不要演古装剧')
            . p('一件发饰 + 一条腰饰，通常胜过五件大声道具。同事若看不见结构，只会觉得你在「穿戏服上班」。'),

        'styling_wedding' => $en
            ? h2('Wedding and festival: two different loads')
            . p('Ceremony needs readable structure under harsh light and long standing; festival photos tolerate more color and accessories but still fail if the waistband and collar are wrong. Separate “looks good on camera” from “survives the day.”')
            . $F[0]
            . h2('Occasion matrix')
            . tableHtml(
                ['Occasion', 'Priority', 'Common mistake'],
                [
                    ['Chinese wedding rites', 'Formal mamian / structured sets', 'Unwearable weight sold as “daily”'],
                    ['Tea / banquet photos', 'Color + group harmony', 'Every guest louder than the couple'],
                    ['Lantern / Mid-Autumn outings', 'Seasonal layers', 'Costume shortcuts named as classical Hanfu'],
                ]
            )
            . $F[1]
            . h2('Etiquette notes without gatekeeping')
            . p('Respect hosts and venues: some temples and halls prefer modest coverage. New Chinese bridal fashion is valid when naming stays honest—do not sell zipper dresses as Ming reconstructions.')
            . $F[2]
            . h2('Packing for the day')
            . ul([
                'Spare ties/waistbands and blotting papers for makeup.',
                'Confirm bathroom logistics with wide skirts.',
                'Photograph flat construction before steam and accessories hide seams.',
            ])
            : h2('婚礼与节令：两种负重')
            . p('仪式需要在强光与久站下仍可读的结构；节令出片允许更多色彩与配饰，但腰头与领型错了依旧失败。把「镜头好看」和「撑完一整天」分开评估。')
            . $F[0]
            . h2('场合矩阵')
            . tableHtml(
                ['场合', '优先', '常见错误'],
                [
                    ['中式婚礼仪式', '正式马面 / 结构成套', '把不可久穿的重工当日常卖'],
                    ['敬茶 / 宴席出片', '色彩与群体和谐', '宾客比新人更喧哗'],
                    ['元宵 / 中秋出行', '季节叠穿', '影楼捷径却叫正统汉服'],
                ]
            )
            . $F[1]
            . h2('礼仪提示，不做羞辱式说教')
            . p('尊重场地：部分祠堂/礼堂偏好更端庄的覆盖度。新中式婚纱时装可以成立——只要命名诚实，别把拉链裙当明制复原。')
            . $F[2]
            . h2('当天携带清单')
            . ul([
                '备用系带/腰头与吸油纸。',
                '确认宽摆裙的卫生间动线。',
                '蒸汽与配饰上身前先拍平铺结构，避免缝份被遮住。',
            ]),

        'styling_beauty' => $en
            ? h2('Beauty serves the collar and waistline')
            . p('Hairpieces, makeup, waist ornaments and shoes should reinforce silhouette geometry—not compete with it. Start with a clean base face and one primary hair accent.')
            . $F[0]
            . h2('Accessory map')
            . tableHtml(
                ['Zone', 'Role', 'Overdo signal'],
                [
                    ['Hair', 'Frame face + collar', 'Crown blocks collar photos'],
                    ['Waist', 'Mark waistband height', 'Belts hide the join you need to verify'],
                    ['Shoes', 'Lengthen line / weather', 'Platforms that fight hem math'],
                    ['Hands', 'Optional fan/bracelet', 'Props in every product shot'],
                ]
            )
            . $F[1]
            . h2('Makeup that survives structure photos')
            . p('Matte or soft satin finishes photograph closer to fabric truth than heavy glitter that fights embroidery sheen. Keep brows and lips readable under outdoor light.')
            . $F[2]
            . h2('Shop accessories after the garment')
            . p('Buy the silhouette first. Accessories are easy to swap; a wrong mamian panel width is not.')
            : h2('妆发配饰服从领型与腰线')
            . p('发饰、妆造、腰饰与鞋履应强化形制几何，而不是抢戏。先底妆干净，再放一个主发饰即可。')
            . $F[0]
            . h2('配饰地图')
            . tableHtml(
                ['区域', '作用', '过度信号'],
                [
                    ['头发', '框住脸与领', '冠太大挡住领型照片'],
                    ['腰部', '标明腰头高度', '腰带把该核验的连接处全挡住'],
                    ['鞋履', '拉长线条 / 应对天气', '厚底打乱裙长计算'],
                    ['手部', '可选扇/镯', '每张产品图都在挥道具'],
                ]
            )
            . $F[1]
            . h2('经得起结构拍照的妆')
            . p('哑光或柔缎光比满脸亮片更接近面料真实；眉与唇在户外光下仍要可读。')
            . $F[2]
            . h2('先买衣身，再买配饰')
            . p('形制买对，配饰可换；马面光面宽度买错，配饰救不回来。'),

        'styling_size' => $en
            ? h2('Size from flat centimeters, not vanity labels')
            . p('Hanfu shopping collapses when buyers trust S/M/L across sellers. Demand bust/waist/hip, garment length, sleeve span, and waistband height in centimeters—then compare to a garment you already own.')
            . $F[0]
            . h2('Measurement ritual')
            . ul([
                'Measure a well-fitting top and skirt flat; photograph the tape.',
                'Ask for the same points on the listing size chart.',
                'Add ease for breathability; do not chase “skin-tight ancient” myths.',
                'Confirm lining and recommended underskirts for light colors.',
            ])
            . $F[1]
            . h2('Care that protects craft')
            . tableHtml(
                ['Issue', 'Do', 'Avoid'],
                [
                    ['Embroidery / metallic yarn', 'Gentle wash or spot clean', 'Harsh spin that cracks yarns'],
                    ['Pleated mamian', 'Hang to settle pleats', 'Ironing flat faces into oblivion'],
                    ['Storage', 'Breathable cover, dry space', 'Compressed vacuum bags forever'],
                ]
            )
            . $F[2]
            . h2('Returns start at size honesty')
            . p('International returns punish vague charts. Factory-direct catalogs that publish flat specs reduce the gamble.')
            : h2('尺码看平铺厘米，不看虚荣码')
            . p('汉服购物最容易翻车的，是相信不同卖家的 S/M/L。必须要胸围/腰围/臀围、衣长、通袖长、腰头高度的厘米数据，再对照一件已合身的成衣。')
            . $F[0]
            . h2('量体仪式')
            . ul([
                '平铺量一件合身的上衣与裙子，并给软尺拍照。',
                '要求详情页尺码表提供同一测点。',
                '预留活动松量；别追「紧身才像古人」的神话。',
                '浅色确认里布与推荐内搭。',
            ])
            . $F[1]
            . h2('保养保护工艺')
            . tableHtml(
                ['问题', '建议', '避免'],
                [
                    ['刺绣 / 金属纱', '轻柔洗或局部清洁', '强力脱水扯裂纱线'],
                    ['有褶马面', '悬挂让褶复位', '把光面熨成「没了」'],
                    ['收纳', '透气罩、干燥空间', '永久真空压缩'],
                ]
            )
            . $F[2]
            . h2('退货成本从尺码诚实开始')
            . p('跨境退货惩罚含糊尺码表。能公布平铺数据的工厂直销目录，能显著降低赌博成分。'),

        'buying_hub' => $en
            ? h2('Buying guides exist to reduce expensive mistakes')
            . p('This hub links three conversion pages: beginner anti-pitfall checklist, choose-by-silhouette, and factory-direct value. Read in that order if you are new; skip ahead if you already know mamian vs ruqun.')
            . $F[0]
            . h2('What “buying literacy” means')
            . ul([
                'Name the silhouette before naming the brand.',
                'Demand flat-lay structure and fiber percentages.',
                'Price the channel: discovery convenience vs construction repeatability.',
            ])
            . $F[1]
            . h2('Internal path')
            . tableHtml(
                ['Page', 'Job', 'Outcome'],
                [
                    ['Beginner checklist', 'Stop costume traps', 'Safer first cart'],
                    ['Choose by style', 'First mamian/ruqun', 'Correct category click'],
                    ['Factory-direct value', 'Why Amayun pricing', 'Informed checkout'],
                ]
            )
            . $F[2]
            . h2('Marketplaces still useful')
            . p('Platforms remain discovery engines. Guides here teach you when to leave the platform for factory-direct once the form is fixed.')
            : h2('购买指南的目标：少交学费')
            . p('本专栏串联三篇转化文：新手避雷清单、按形制选购、工厂直销价值。新手按顺序读；已分清马面/襦裙可直接跳转。')
            . $F[0]
            . h2('什么叫「会买」')
            . ul([
                '先定形制名，再定品牌名。',
                '要平铺结构与纤维百分比。',
                '给渠道定价：发现便利 vs 结构可重复。',
            ])
            . $F[1]
            . h2('站内路径')
            . tableHtml(
                ['页面', '任务', '结果'],
                [
                    ['新手避雷', '挡住影楼陷阱', '更安全的首单'],
                    ['按形制选购', '首套马面/襦裙', '点到正确类目'],
                    ['工厂直销价值', '理解阿玛云定价', '知情下单'],
                ]
            )
            . $F[2]
            . h2('平台仍然有用')
            . p('综合平台仍是发现引擎。指南教你：形制固定后，何时离开平台转向工厂直销。'),

        'buying_beginner' => $en
            ? h2('Beginner anti-pitfall checklist')
            . p('First-time overseas buyers often pay for atmosphere: soft filters, vague “ancient style,” and no flat construction. Use this checklist before any cart—marketplace or DTC.')
            . $F[0]
            . h2('Hard checks')
            . ul([
                'Silhouette named specifically (mamian / ruqun / yuanling / New Chinese).',
                'Flat-lay or exploded structure photos exist.',
                'Fiber content and lining notes are numeric, not poetic.',
                'Size chart in centimeters with the same points you measured.',
                'Return window covers shipping time to your country.',
            ])
            . $F[1]
            . h2('Red flags')
            . tableHtml(
                ['Signal', 'Likely meaning', 'Action'],
                [
                    ['Only model poses', 'Hiding joins/seams', 'Ask for flat-lays or leave'],
                    ['“Hanfu” + zipper sheath', 'Costume / fashion hybrid', 'Buy only if you want fashion'],
                    ['No fiber %', 'Unknown hand-feel/pilling', 'Do not pay premium'],
                    ['One photo reused everywhere', 'Weak QC storytelling', 'Demand more angles'],
                ]
            )
            . $F[2]
            . h2('First purchase strategy')
            . p('Start with one wearable silhouette and restrained color. Learn structure on a garment you will actually put on twice—then expand.')
            : h2('新手避雷检查清单')
            . p('海外首购常为氛围买单：柔光滤镜、含糊「古风」、没有平铺结构。无论平台还是独立站，进购物车前先过本清单。')
            . $F[0]
            . h2('硬检查')
            . ul([
                '形制名具体（马面 / 襦裙 / 圆领 / 新中式）。',
                '存在平铺或拆解结构图。',
                '纤维与里布是数字，不是形容词。',
                '厘米尺码表测点与你自测一致。',
                '退货窗口覆盖到你所在国的物流耗时。',
            ])
            . $F[1]
            . h2('红灯信号')
            . tableHtml(
                ['信号', '可能含义', '动作'],
                [
                    ['只有摆拍', '遮挡连接/缝份', '要平铺，否则离开'],
                    ['「汉服」+ 拉链裙', '影楼/时装混血', '只在你要时装时买'],
                    ['无纤维百分比', '手感与起球未知', '别付溢价'],
                    ['全网同一张图', '质检叙事弱', '要求更多角度'],
                ]
            )
            . $F[2]
            . h2('首购策略')
            . p('先买一套真会穿两次的形制与克制配色，在真衣上学会结构，再扩展衣橱。'),

        'buying_style' => $en
            ? h2('Choose your first silhouette on purpose')
            . p('“Which Hanfu should I buy first?” is really “which geometry matches my occasion and body comfort?” Mamian, ruqun, and yuanling answer different weeks of your life.')
            . $F[0]
            . h2('Decision table')
            . tableHtml(
                ['If you want…', 'Start with', 'Verify'],
                [
                    ['Formal photos / Ming-leaning look', 'Mamian set', 'Flat panels + side pleats'],
                    ['Soft daily / travel', 'Ruqun (chest- or waist-high)', 'Waistband join consistency'],
                    ['Robes / menswear-leaning', 'Yuanlingpao cues', 'Round collar + sleeve span'],
                    ['Modern office-friendly', 'Honest New Chinese', 'Do not mislabel as classical'],
                ]
            )
            . $F[1]
            . h2('Body and mobility notes')
            . p('High chest-high lines change balance; wide sleeves change door clearance; heavy embroidery changes shoulder fatigue. Try sitting, stairs, and bag straps in your mind before checkout.')
            . $F[2]
            . h2('Map to catalog categories')
            . p('Once named, open the matching Amayun category rather than scrolling random “ancient dress” carousels. Structure first, SKU second.')
            : h2('首套形制要有意识地选')
            . p('「第一套汉服买什么」真正问的是：哪种几何匹配你的场合与身体舒适度？马面、襦裙、圆领袍回答的是不同生活周。')
            . $F[0]
            . h2('决策表')
            . tableHtml(
                ['如果你想…', '从这开始', '核验点'],
                [
                    ['正式出片 / 偏明制观感', '马面套装', '前后光面 + 侧褶'],
                    ['柔软日常 / 旅行', '襦裙（齐胸或齐腰）', '腰头连接是否稳定'],
                    ['袍服 / 偏男装线索', '圆领袍线索', '圆领 + 通袖'],
                    ['现代办公友好', '诚实的新中式', '不要冒充正统汉服'],
                ]
            )
            . $F[1]
            . h2('身体与行动笔记')
            . p('齐胸线改变重心；大袖改变过门净空；重绣改变肩部疲劳。下单前在脑内预演久坐、楼梯与背包带。')
            . $F[2]
            . h2('映射到商品分类')
            . p('名称定下后，打开阿玛云对应类目，而不是在随机「古装」轮播里滑。先结构，后 SKU。'),

        'buying_factory' => $en
            ? h2('What factory-direct value actually is')
            . p('Factory-direct is not a slogan for “cheap.” It is a cost structure: fewer retail markups, documented origin partners, and QC aimed at repeatable construction. You trade some boutique storytelling for measurable specs.')
            . $F[0]
            . h2('Compare three price stories')
            . tableHtml(
                ['Channel', 'You often pay for', 'Risk'],
                [
                    ['Marketplaces', 'Discovery + dispute tools', 'Thin structure photos'],
                    ['DTC boutiques', 'Education + brand experience', 'Markup beyond needed service'],
                    ['Amayun factory-direct', 'Origin supply + QC notes', 'You must know the silhouette name'],
                ]
            )
            . $F[1]
            . h2('Amayun’s 2024 promise')
            . p('Amayun Technology Co., Ltd. (registered 2024) visits origin suppliers, partners with reliable workshops, and combines handmade finishing with green mechanical production so fair prices do not mean disposable seams.')
            . $F[2]
            . h2('When factory-direct wins')
            . ul([
                'You already know mamian vs ruqun.',
                'You can read flat-lays and fiber lines.',
                'You want repurchase consistency more than lifestyle branding.',
            ])
            : h2('工厂直销价值到底是什么')
            . p('工厂直销不是「便宜」口号，而是成本结构：更少零售加价、可记录的原产地伙伴、面向可重复结构的质检。你用一部分精品站叙事，换可测量的规格。')
            . $F[0]
            . h2('三种价格故事对照')
            . tableHtml(
                ['渠道', '你常在为什么付钱', '风险'],
                [
                    ['综合平台', '发现 + 纠纷工具', '结构图稀薄'],
                    ['垂直独立站', '教育 + 品牌体验', '溢价超过所需服务'],
                    ['阿玛云工厂直销', '源头供给 + 质检说明', '你需要先懂形制名'],
                ]
            )
            . $F[1]
            . h2('阿玛云 2024 的承诺')
            . p('阿玛云科技有限公司（注册于 2024）走访原产地、与可靠车间合作，手工结合绿色机械制造，让公道价格不等于一次性缝份。')
            . $F[2]
            . h2('何时工厂直销更胜')
            . ul([
                '你已分清马面与襦裙。',
                '你会读平铺图与纤维行。',
                '你要复购稳定性，多于生活方式品牌包装。',
            ]),

        'ethnic_hub' => $en
            ? h2('World dress literacy with honest boundaries')
            . p('This series introduces East Asian neighbors, South & Southeast Asia, MENA & Africa, and Euro-American folk threads—always with clear boundaries versus Hanfu. Photos in these articles are Amayun Hanfu form references for comparison, not claims that they depict kimono, hanbok, sari, or other systems.')
            . $F[0]
            . h2('Why compare at all')
            . p('Global readers mix “Asian traditional dress” into one shopping bin. Comparison pages reduce cultural flattening and improve search clarity for people who want Hanfu specifically—or who want neighboring traditions named correctly.')
            . $F[1]
            . h2('Reading order')
            . ul([
                'East Asia: kimono, hanbok, áo dài boundaries.',
                'SE & South Asia: sari, sarong and regional occasions.',
                'MENA & Africa: robes and wax-print cultures (high-level).',
                'Euro-American folk: festival costume vs everyday heritage wear.',
            ])
            . $F[2]
            . h2('Shopping ethics')
            . p('Buy each tradition from makers who name it honestly. Do not relabel neighboring dress as Hanfu to ride Chinese search traffic—or the reverse.')
            : h2('全球服饰素养，边界要诚实')
            . p('本系列介绍东亚邻邦、东南亚与南亚、中东与非洲、欧美民俗线索——始终与汉服保持清晰边界。文中配图为阿玛云汉服形制对照参考，并不宣称它们是和服、韩服、纱丽或其他体系的实物图。')
            . $F[0]
            . h2('为什么要对照')
            . p('全球读者常把「亚洲传统服饰」塞进同一个购物篮。对照页减少文化扁平化，也让真正要买汉服——或要正确命名邻邦传统——的人更容易检索。')
            . $F[1]
            . h2('阅读顺序')
            . ul([
                '东亚：和服、韩服、越服的边界。',
                '东南亚与南亚：纱丽、纱笼与场合。',
                '中东与非洲：长袍与蜡染等文化（导览级）。',
                '欧美民俗：节庆服装 vs 日常遗产穿着。',
            ])
            . $F[2]
            . h2('购买伦理')
            . p('每种传统向诚实命名的制作者购买。不要为了蹭中文流量把邻邦服饰改叫汉服——反之亦然。'),

        'ethnic_east' => $en
            ? h2('East Asian neighbors are not “types of Hanfu”')
            . p('Kimono, hanbok, and áo dài are distinct dress systems with their own histories, closures, and etiquette. Hanfu comparisons help shoppers who land on mixed “Asian costume” search results—not to rank cultures.')
            . $F[0]
            . h2('Boundary table')
            . tableHtml(
                ['Tradition', 'Quick tell', 'Vs Hanfu shopping'],
                [
                    ['Kimono', 'Straight collar wrap, obi logic', 'Different collar/closure grammar'],
                    ['Hanbok', 'Jeogori + chima proportions', 'Different waist and volume story'],
                    ['Áo dài', 'Long tunic + pants modernity', 'Modern national dress path ≠ Hanfu'],
                    ['Hanfu', 'Cross-collar / mamian / ruqun cues', 'Use Chinese form references'],
                ]
            )
            . $F[1]
            . h2('How to talk about influence without theft')
            . p('Historical exchange existed across East Asia. That does not license modern sellers to mislabel inventory for SEO. Name the system you are selling.')
            . $F[2]
            . h2('If you came for Hanfu')
            . p('Return to silhouette literacy: mamian panels, ruqun joins, yuanling collars—then shop Amayun categories. Neighbor pages stay educational.')
            : h2('东亚邻邦不是「汉服的分支款」')
            . p('和服、韩服、越服是各自独立的服饰体系，有自己的历史、闭合方式与礼仪。与汉服对照，是为了帮助落在「亚洲古装」混合搜索结果里的买家——不是给文化排名。')
            . $F[0]
            . h2('边界对照表')
            . tableHtml(
                ['传统', '快速辨识', '相对汉服购物'],
                [
                    ['和服', '直领交叠与带结逻辑', '领与闭合语法不同'],
                    ['韩服', '短袄 + 裙的比例', '腰线与体积叙事不同'],
                    ['越服', '长袄 + 裤的现代路径', '民族现代礼服 ≠ 汉服'],
                    ['汉服', '交领 / 马面 / 襦裙线索', '使用中式形制参考'],
                ]
            )
            . $F[1]
            . h2('谈影响，不谈占有')
            . p('东亚历史上确有交流。这不授权现代卖家为了 SEO 错标库存。卖什么，就叫什么。')
            . $F[2]
            . h2('若你本为汉服而来')
            . p('回到形制素养：马面光面、襦裙连接、圆领袍——再进入阿玛云类目。邻邦页保持教育属性。'),

        'ethnic_south' => $en
            ? h2('Sari, sarong, and regional dress grammars')
            . p('South and Southeast Asia contain many dress systems. Sari draping, sarong wraps, kebaya pairings, and ceremonial textiles follow local occasion rules. Hanfu photos here are only comparison anchors for readers studying Chinese forms.')
            . $F[0]
            . h2('Learner cues')
            . ul([
                'Ask which region and community a garment belongs to.',
                'Separate everyday wrap practices from bridal/festival peak craft.',
                'Do not flatten everything into “oriental dress” marketplace tags.',
            ])
            . $F[1]
            . h2('Occasion thinking shared with Hanfu')
            . p('All of these traditions punish buying the loudest photo without asking about mobility, climate, and ceremony length—the same literacy Hanfu buyers need.')
            . $F[2]
            . h2('Respectful shopping')
            . p('Support makers who credit region and technique. If you ultimately need Hanfu, switch to Chinese silhouette categories rather than forcing a cross-label.')
            : h2('纱丽、纱笼与区域服饰语法')
            . p('南亚与东南亚包含多种服饰体系。纱丽披覆、纱笼缠绕、kebaya 搭配与礼仪织品，都遵循本地场合规则。本文汉服配图只作中式形制对照锚点。')
            . $F[0]
            . h2('学习者线索')
            . ul([
                '问清属于哪个地区与社群。',
                '区分日常缠绕与婚礼/节庆高峰工艺。',
                '不要把一切压成「东方服饰」商品标签。',
            ])
            . $F[1]
            . h2('与汉服共通的场合思维')
            . p('这些传统同样惩罚「只看最吵的照片」：行动、气候、仪式时长都要问——汉服买家需要的是同一套素养。')
            . $F[2]
            . h2('尊重式购买')
            . p('支持标注地区与工艺的制作者。若你最终需要汉服，请切换到中式形制类目，而不是硬贴跨标签。'),

        'ethnic_mena' => $en
            ? h2('MENA & Africa: robes, print cultures, and caution')
            . p('From flowing robes to wax-print ensembles, dress cultures across MENA and Africa are diverse. This page is a respectful high-level map—not a substitute for community-authored history. Hanfu images remain Chinese form references only.')
            . $F[0]
            . h2('What global shoppers mix up')
            . ul([
                'Calling any long robe “biblical costume” or “generic ethnic.”',
                'Buying print fashion without knowing regional design ownership debates.',
                'Using African or MENA aesthetics as props for unrelated brand stories.',
            ])
            . $F[1]
            . h2('Shared buying discipline')
            . p('Fiber honesty, occasion fit, and maker credit matter everywhere. If your cart is actually Hanfu, leave this page and open silhouette guides.')
            . $F[2]
            . h2('Continue learning')
            . p('Read region-specific authors and museums for depth. Amayun’s role here is boundary clarity beside Hanfu commerce—not claiming expertise over every continent.')
            : h2('中东与非洲：长袍、印花文化与谨慎')
            . p('从长袍到蜡染套装，中东北非与非洲服饰文化高度多样。本页是尊重式导览，不能替代社群书写的历史。汉服图仅为中式形制参考。')
            . $F[0]
            . h2('全球买家常混的点')
            . ul([
                '把任何长袍叫成「圣经装」或「泛民族装」。',
                '买印花时装却不了解设计归属讨论。',
                '把非洲或中东美学当无关品牌故事的道具。',
            ])
            . $F[1]
            . h2('共通的购买纪律')
            . p('纤维诚实、场合匹配、制作者署名，在哪里都重要。若购物车其实是汉服，请离开本页打开形制指南。')
            . $F[2]
            . h2('继续学习')
            . p('深度请读地区作者与博物馆叙述。阿玛云在此的角色是与汉服商业并列的边界澄清——而非宣称精通每一块大陆。'),

        'ethnic_euro' => $en
            ? h2('European folk and American traditional threads')
            . p('Folk costume, dirndl/tracht lineages, festival dress, and Indigenous / regional American traditions are often flattened into “vintage cosplay” online. Treat them as living or historically grounded systems with local rules—parallel to how Hanfu demands silhouette honesty.')
            . $F[0]
            . h2('Useful parallels for Hanfu shoppers')
            . tableHtml(
                ['Parallel', 'Folk/Euro-Am lesson', 'Hanfu lesson'],
                [
                    ['Occasion dress', 'Festival vs daily wear', 'Ceremony vs commute'],
                    ['Naming', 'Region-specific names', 'Mamian/ruqun not “ancient skirt”'],
                    ['Craft', 'Embroidery as identity labor', 'Embroidery ≠ filter'],
                ]
            )
            . $F[1]
            . h2('Avoid costume extractivism')
            . p('Do not strip folk motifs into fashion drops without credit. The same ethic applies when New Chinese fashion borrows classical cues—name the borrow.')
            . $F[2]
            . h2('Back to Chinese forms')
            . p('If your goal is Hanfu, use these comparisons only as literacy, then return to Amayun silhouette and buying guides with product-structure photography.')
            : h2('欧美民俗与美洲传统线索')
            . p('民俗服装、dirndl/tracht 谱系、节庆装，以及美洲原住民/地区传统，常在网上被压成「复古 cos」。请把它们当作有本地规则的活态或历史体系——正如汉服要求形制诚实。')
            . $F[0]
            . h2('给汉服买家的有用平行')
            . tableHtml(
                ['平行', '民俗/欧美启示', '汉服启示'],
                [
                    ['场合装', '节庆 vs 日常', '仪式 vs 通勤'],
                    ['命名', '地区专名', '马面/襦裙不是「古装裙」'],
                    ['工艺', '刺绣是身份劳动', '刺绣 ≠ 滤镜'],
                ]
            )
            . $F[1]
            . h2('拒绝抽取式戏服化')
            . p('不要把民俗纹样无署名抽进时装快闪。新中式借用古典线索时也一样——借用要命名。')
            . $F[2]
            . h2('回到中式形制')
            . p('若目标是汉服，对照只作素养，然后回到阿玛云形制与购买指南，并用成衣结构摄影做核验。'),

        default => throw new InvalidArgumentException('Unknown type ' . $type),
    };

    return ensureLength($html . cta($locale), $locale);
}

/** @return list<array<string,mixed>> */
function articleDefs(): array
{
    return [
        [
            'category' => 'styling',
            'slug' => 'hanfu-styling-complete-guide',
            'type' => 'styling_hub',
            'keywords' => 'hanfu styling,Chinese outfit guide,汉服穿搭',
            'zh_title' => '汉服穿搭总指南：从形制到场合的完整路径',
            'zh_excerpt' => '把日常通勤、婚礼节令、妆发配饰与尺码保养串成一条可执行穿搭路径，先结构后氛围。',
            'en_title' => 'Hanfu Styling Hub: From Silhouette to Occasion',
            'en_excerpt' => 'Connect daily commute, wedding/festival, beauty & accessories, and size/care into one actionable styling path.',
        ],
        [
            'category' => 'styling-daily',
            'slug' => 'hanfu-daily-commute-styling',
            'type' => 'styling_daily',
            'keywords' => 'daily hanfu,commute outfit,日常汉服穿搭',
            'zh_title' => '日常与通勤汉服穿搭：能进真实城市的轻量方案',
            'zh_excerpt' => '裙长净空、袖型、叠穿与鞋履——让国风造型适应地铁、办公与周末步行。',
            'en_title' => 'Daily & Commute Hanfu Styling for Real Cities',
            'en_excerpt' => 'Hem clearance, sleeves, layers and shoes—Chinese-style looks that survive subways and offices.',
        ],
        [
            'category' => 'styling-wedding-festival',
            'slug' => 'hanfu-wedding-festival-styling',
            'type' => 'styling_wedding',
            'keywords' => 'hanfu wedding,festival outfit,汉服婚礼节令',
            'zh_title' => '婚礼与节令汉服穿搭：仪式感与久站负重分开谈',
            'zh_excerpt' => '中式婚礼、宴席出片与传统节令：命名诚实、结构正确、当天可完成。',
            'en_title' => 'Hanfu Wedding & Festival Styling Without Costume Traps',
            'en_excerpt' => 'Ceremony, banquet photos and seasonal festivals—honest naming, correct structure, wearable duration.',
        ],
        [
            'category' => 'styling-beauty-accessories',
            'slug' => 'hanfu-hair-makeup-accessories-guide',
            'type' => 'styling_beauty',
            'keywords' => 'hanfu accessories,hair makeup,汉服妆发配饰',
            'zh_title' => '汉服妆发与配饰指南：服从领型与腰线',
            'zh_excerpt' => '发饰、妆造、腰饰与鞋履如何强化形制几何，而不是抢戏。',
            'en_title' => 'Hanfu Hair, Makeup & Accessories That Serve Structure',
            'en_excerpt' => 'How hairpieces, makeup, waist ornaments and shoes reinforce silhouette geometry.',
        ],
        [
            'category' => 'styling-size-care',
            'slug' => 'hanfu-size-chart-care-guide',
            'type' => 'styling_size',
            'keywords' => 'hanfu size chart,garment care,汉服尺码保养',
            'zh_title' => '汉服尺码与保养：平铺厘米、洗涤与收纳',
            'zh_excerpt' => '用厘米平铺选码，保护刺绣与褶裥，降低跨境退货风险。',
            'en_title' => 'Hanfu Size Charts & Care: Flat Specs That Cut Returns',
            'en_excerpt' => 'Choose size from centimeters, protect embroidery and pleats, reduce international return pain.',
        ],
        [
            'category' => 'buying-guides',
            'slug' => 'hanfu-buying-guides-hub',
            'type' => 'buying_hub',
            'keywords' => 'buy hanfu,buying guide,汉服购买指南',
            'zh_title' => '汉服购买指南总览：避雷、选形制、工厂直销',
            'zh_excerpt' => '新手避雷、按形制选购与工厂直销价值三条转化路径一次说清。',
            'en_title' => 'Hanfu Buying Guides Hub: Checklist, Style Pick, Factory-Direct',
            'en_excerpt' => 'Beginner checklist, choose-by-silhouette, and factory-direct value—linked for conversion.',
        ],
        [
            'category' => 'buying-beginner',
            'slug' => 'hanfu-beginner-buyer-checklist',
            'type' => 'buying_beginner',
            'keywords' => 'hanfu checklist,first hanfu,汉服新手避雷',
            'zh_title' => '汉服新手避雷清单：平铺、形制、面料与售后',
            'zh_excerpt' => '海外首购硬检查与红灯信号，挡住氛围营销与影楼陷阱。',
            'en_title' => 'Hanfu Beginner Buyer Checklist: Flat-Lay, Fiber, Returns',
            'en_excerpt' => 'Hard checks and red flags for first-time overseas buyers before any cart.',
        ],
        [
            'category' => 'buying-choose-by-style',
            'slug' => 'choose-first-mamian-or-ruqun',
            'type' => 'buying_style',
            'keywords' => 'first mamian,first ruqun,按形制选购',
            'zh_title' => '按形制选购：首套马面或襦裙怎么选',
            'zh_excerpt' => '用场合与行动舒适度决定第一套形制，并映射到正确商品分类。',
            'en_title' => 'Choose by Style: Your First Mamian or Ruqun',
            'en_excerpt' => 'Match occasion and mobility to silhouette, then open the right catalog category.',
        ],
        [
            'category' => 'buying-factory-direct',
            'slug' => 'amayun-factory-direct-value-explained',
            'type' => 'buying_factory',
            'keywords' => 'factory direct hanfu,Amayun value,工厂直销',
            'zh_title' => '工厂直销价值解析：源头供给 vs 平台与独立站',
            'zh_excerpt' => '说清阿玛云 2024 源头工厂、质检与定价结构，帮你知情下单。',
            'en_title' => 'Amayun Factory-Direct Value Explained',
            'en_excerpt' => 'Cost structure vs marketplaces and DTC boutiques—when factory-direct wins.',
        ],
        [
            'category' => 'world-ethnic',
            'slug' => 'world-ethnic-dress-and-hanfu',
            'type' => 'ethnic_hub',
            'keywords' => 'world ethnic dress,traditional clothing,全球民族服饰',
            'zh_title' => '全球民族服饰与汉服：对照阅读总指南',
            'zh_excerpt' => '东亚、南亚东南亚、中东非洲与欧美民俗对照入口，边界诚实、配图标明汉服参考。',
            'en_title' => 'World Ethnic Dress & Hanfu: A Comparison Hub',
            'en_excerpt' => 'Entry to East Asia, South/SE Asia, MENA & Africa, Euro-American folk—with honest boundaries.',
        ],
        [
            'category' => 'ethnic-east-asia',
            'slug' => 'kimono-hanbok-aodai-vs-hanfu',
            'type' => 'ethnic_east',
            'keywords' => 'kimono vs hanfu,hanbok,áo dài,东亚服饰',
            'zh_title' => '和服·韩服·越服 vs 汉服：东亚邻邦边界说明',
            'zh_excerpt' => '厘清邻邦传统服饰与汉服的结构差异，拒绝 SEO 错标。',
            'en_title' => 'Kimono, Hanbok, Áo Dài vs Hanfu: Clear Boundaries',
            'en_excerpt' => 'Neighbor dress systems are not Hanfu subtypes—name what you sell.',
        ],
        [
            'category' => 'ethnic-south-asia',
            'slug' => 'sari-sarong-southeast-south-asia-dress',
            'type' => 'ethnic_south',
            'keywords' => 'sari,sarong,Southeast Asia dress,南亚服饰',
            'zh_title' => '纱丽与纱笼：东南亚与南亚传统服饰导览',
            'zh_excerpt' => '区域场合与命名素养；汉服图仅作对照，不冒充当地服饰实拍。',
            'en_title' => 'Sari & Sarong: SE & South Asia Dress Literacy',
            'en_excerpt' => 'Regional occasion literacy; Hanfu photos are comparison anchors only.',
        ],
        [
            'category' => 'ethnic-mena-africa',
            'slug' => 'mena-africa-traditional-dress-guide',
            'type' => 'ethnic_mena',
            'keywords' => 'MENA dress,African wax print,中东非洲服饰',
            'zh_title' => '中东与非洲传统服饰导览：长袍、印花与谨慎',
            'zh_excerpt' => '高层次对照与购买伦理；深度请读地区作者，汉服图仅供中式参考。',
            'en_title' => 'MENA & Africa Traditional Dress: A Careful Map',
            'en_excerpt' => 'High-level map and shopping ethics—not a substitute for community histories.',
        ],
        [
            'category' => 'ethnic-euro-folk',
            'slug' => 'european-folk-american-traditional-dress',
            'type' => 'ethnic_euro',
            'keywords' => 'European folk costume,traditional dress,欧美民俗',
            'zh_title' => '欧美民俗服饰线索：节庆装与遗产穿着',
            'zh_excerpt' => '用民俗命名与场合思维对照汉服素养，拒绝抽取式戏服化。',
            'en_title' => 'European Folk & American Traditional Dress Threads',
            'en_excerpt' => 'Parallels for occasion naming and craft respect—then return to Hanfu forms.',
        ],
    ];
}

$captions = loadCaptions();
$categories = categoryIdMap();
$defs = articleDefs();
foreach ($defs as $def) {
    if (!isset($categories[$def['category']])) {
        fwrite(STDERR, 'Missing category: ' . $def['category'] . PHP_EOL);
        exit(1);
    }
}

$slugs = array_column($defs, 'slug');
$photoPlan = allocateAvoidingReserved($slugs, reservedPhotoSetKeys());
$admin = ObjectManager::getInstance(BlogPostAdminService::class);
$created = 0;
$skipped = 0;

foreach ($defs as $def) {
    $nums = $photoPlan[$def['slug']];
    $files = photoFiles($nums);
    if (count($files) < PER_ARTICLE) {
        throw new RuntimeException('Need ' . PER_ARTICLE . ' photos for ' . $def['slug']);
    }
    $cover = PHOTO_BASE . '/' . $files[0];
    $categoryId = $categories[$def['category']];

    foreach ([
        'zh_Hans_CN' => [
            'slug' => $def['slug'],
            'title' => $def['zh_title'],
            'excerpt' => $def['zh_excerpt'],
        ],
        'en_US' => [
            'slug' => $def['slug'] . '-en',
            'title' => $def['en_title'],
            'excerpt' => $def['en_excerpt'],
        ],
    ] as $locale => $pack) {
        if (slugExists($pack['slug'])) {
            echo "= skip {$pack['slug']}\n";
            ++$skipped;
            continue;
        }
        $F = bodyFigs($files, $locale, $captions, $pack['title']);
        $content = buildContent($def['type'], $locale, $pack['title'], $F);
        assertNoDupImages($cover, $content, $pack['slug']);
        $row = $admin->save([
            'website_id' => WEBSITE_ID,
            'locale' => $locale,
            'slug' => $pack['slug'],
            'title' => $pack['title'],
            'excerpt' => $pack['excerpt'],
            'content' => $content,
            'cover_image' => $cover,
            'author' => AUTHOR,
            'keywords' => $def['keywords'],
            'category_id' => $categoryId,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int)($row[Post::schema_fields_ID] ?? 0);
        $bodyList = implode(',', array_slice($files, 1));
        echo "+ #{$id} [{$locale}] {$pack['slug']} cover={$files[0]} body={$bodyList} → {$def['category']}\n";
        ++$created;
    }
}

echo "created={$created} skipped={$skipped}\n";
