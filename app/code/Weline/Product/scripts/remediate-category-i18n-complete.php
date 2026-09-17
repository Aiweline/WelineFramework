<?php

declare(strict_types=1);

/**
 * Complete category + PLP UI i18n for default-website enabled locales.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-category-i18n-complete.php --dry-run
 *   php app/code/Weline/Product/scripts/remediate-category-i18n-complete.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\RuntimeCacheBroadcaster;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));

/** @var array<string, array<string, mixed>> $pack */
$pack = require __DIR__ . '/data/category-i18n-pack.v1.php';
$hanfuPack = require __DIR__ . '/data/category-i18n-pack-hanfu.v1.php';
foreach ($hanfuPack as $path => $byLocale) {
    $pack[$path] = $byLocale;
}

$uiWords = [
    '“%{1}”' => [
        'ar_SA' => '«%{1}»',
        'bn_BD' => '“%{1}”',
        'es_ES' => '«%{1}»',
        'fr_FR' => '«%{1}»',
        'hi_IN' => '“%{1}”',
        'id_ID' => '“%{1}”',
        'pt_BR' => '“%{1}”',
        'ur_PK' => '«%{1}»',
        'en_US' => '“%{1}”',
        'zh_Hans_CN' => '“%{1}”',
        'de_DE' => '„%{1}“',
        'ja_JP' => '「%{1}」',
        'ko_KR' => '“%{1}”',
        'ru_RU' => '«%{1}»',
        'th_TH' => '“%{1}”',
        'vi_VN' => '“%{1}”',
        'zh_Hant_TW' => '「%{1}」',
    ],
    '该分类下暂无已发布商品。' => [
        'ar_SA' => 'لا توجد منتجات منشورة في هذه الفئة.',
        'bn_BD' => 'এই ক্যাটাগরিতে এখনও কোনো পণ্য প্রকাশিত হয়নি।',
        'es_ES' => 'Aún no hay productos publicados en esta categoría.',
        'fr_FR' => 'Aucun produit n’a encore été publié dans cette catégorie.',
        'hi_IN' => 'इस श्रेणी में अभी कोई उत्पाद प्रकाशित नहीं है।',
        'id_ID' => 'Belum ada produk yang dipublikasikan dalam kategori ini.',
        'pt_BR' => 'Ainda não há produtos publicados nesta categoria.',
        'ur_PK' => 'اس زمرے میں ابھی کوئی شائع شدہ مصنوعات نہیں۔',
        'en_US' => 'No products have been published in this category yet.',
        'zh_Hans_CN' => '该分类下暂无已发布商品。',
        'de_DE' => 'In dieser Kategorie wurden noch keine Produkte veröffentlicht.',
        'ja_JP' => 'このカテゴリにはまだ公開済みの商品がありません。',
        'ko_KR' => '이 카테고리에는 아직 게시된 상품이 없습니다.',
        'ru_RU' => 'В этой категории пока нет опубликованных товаров.',
        'th_TH' => 'ยังไม่มีสินค้าที่เผยแพร่ในหมวดหมู่นี้',
        'vi_VN' => 'Chưa có sản phẩm nào được đăng trong danh mục này.',
        'zh_Hant_TW' => '此分類尚尚無已發佈商品。',
    ],
    '当前筛选条件下暂无商品，请调整筛选。' => [
        'ar_SA' => 'لا توجد منتجات تطابق عوامل التصفية الحالية. جرّب اختيارًا آخر.',
        'bn_BD' => 'বর্তমান ফিল্টারে কোনো পণ্য নেই। ফিল্টার বদলান।',
        'es_ES' => 'No hay productos con los filtros actuales. Prueba otra selección.',
        'fr_FR' => 'Aucun produit ne correspond aux filtres actuels. Essayez un autre choix.',
        'hi_IN' => 'वर्तमान फ़िल्टर से कोई उत्पाद नहीं मिला। फ़िल्टर बदलें।',
        'id_ID' => 'Tidak ada produk yang cocok dengan filter saat ini. Coba pilihan lain.',
        'pt_BR' => 'Nenhum produto corresponde aos filtros atuais. Tente outra seleção.',
        'ur_PK' => 'موجودہ فلٹرز سے کوئی مصنوعات نہیں ملی۔ فلٹر بدلیں۔',
        'en_US' => 'No products match the current filters.',
        'zh_Hans_CN' => '当前筛选条件下暂无商品，请调整筛选。',
        'de_DE' => 'Keine Produkte entsprechen den aktuellen Filtern.',
        'ja_JP' => '現在の絞り込み条件に一致する商品がありません。',
        'ko_KR' => '현재 필터 조건에 맞는 상품이 없습니다.',
        'ru_RU' => 'Нет товаров по текущим фильтрам. Измените выбор.',
        'th_TH' => 'ไม่มีสินค้าที่ตรงกับตัวกรองปัจจุบัน',
        'vi_VN' => 'Không có sản phẩm khớp bộ lọc hiện tại.',
        'zh_Hant_TW' => '目前篩選條件下暫無商品，請調整篩選。',
    ],
    '0 件结果' => [
        'ar_SA' => 'لا توجد نتائج',
        'bn_BD' => '০ ফলাফল',
        'es_ES' => '0 resultados',
        'fr_FR' => '0 résultats',
        'hi_IN' => '0 परिणाम',
        'id_ID' => '0 hasil',
        'pt_BR' => '0 resultados',
        'ur_PK' => '۰ نتائج',
        'en_US' => '0 results',
        'zh_Hans_CN' => '0 件结果',
        'de_DE' => '0 Ergebnisse',
        'ja_JP' => '0件の結果',
        'ko_KR' => '0개 결과',
        'ru_RU' => '0 результатов',
        'th_TH' => '0 ผลลัพธ์',
        'vi_VN' => '0 kết quả',
        'zh_Hant_TW' => '0 件結果',
    ],
];

/** @var ProductCategoryAttributeService $categoryAttributes */
$categoryAttributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);
/** @var LocaleDictionary $dictionary */
$dictionary = ObjectManager::getInstance(LocaleDictionary::class);

$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=mig_clone_productcurrent20260810_20260810022347_e07e',
    'weline',
    'weline',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pathStmt = $pdo->query('SELECT category_id, path FROM w_product_ws_0_category WHERE status = \'active\'');
$categories = $pathStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryWrites = 0;
$descWrites = 0;
$nameWrites = 0;
$uiWrites = 0;
$skipped = 0;

foreach ($categories as $row) {
    $categoryId = (int)$row['category_id'];
    $path = (string)$row['path'];
    $localePack = $pack[$path] ?? null;
    if (!is_array($localePack)) {
        ++$skipped;
        continue;
    }
    foreach ($localePack as $locale => $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $name = '';
        $summary = '';
        $description = '';
        if (isset($pair['summary']) || isset($pair['name'])) {
            $name = trim((string)($pair['name'] ?? ''));
            $summary = trim((string)($pair['summary'] ?? ''));
            $description = trim((string)($pair['description'] ?? ''));
        } else {
            $summary = trim((string)($pair[0] ?? ''));
            $description = isset($pair[1]) ? trim((string)$pair[1]) : '';
        }
        if ($summary === '' && $name === '' && $description === '') {
            continue;
        }
        if ($apply) {
            if ($name !== '') {
                $categoryAttributes->writeName($websiteId, $categoryId, $name, $locale);
                ++$nameWrites;
            }
            if ($summary !== '') {
                $categoryAttributes->writeSummary($websiteId, $categoryId, $summary, $locale);
                ++$summaryWrites;
            }
            if ($description !== '') {
                $categoryAttributes->writeDescription($websiteId, $categoryId, $description, $locale);
                ++$descWrites;
            }
        } else {
            if ($name !== '') {
                ++$nameWrites;
            }
            if ($summary !== '') {
                ++$summaryWrites;
            }
            if ($description !== '') {
                ++$descWrites;
            }
        }
    }
}

foreach ($uiWords as $word => $byLocale) {
    foreach ($byLocale as $locale => $translate) {
        if ($apply) {
            $dictionary->upsert($word, $locale, $translate);
            // Keep is_ai=0 for curated human/model pack (avoid AI re-polluting).
            $md5 = LocaleDictionary::generateMd5($word, $locale);
            $pdo->prepare('UPDATE w_i18n_locale_dictionary SET is_ai = 0, source_module = :m, update_time = CURRENT_TIMESTAMP WHERE md5 = :md5')
                ->execute(['m' => 'Weline_Product', 'md5' => $md5]);
        }
        ++$uiWrites;
    }
}

if ($apply) {
    try {
        ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)
            ->notifyCatalogChanged($websiteId, 'category_i18n_complete', ['pack' => 'category-i18n-pack.v1']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'warn: catalog cache: ' . $e->getMessage() . PHP_EOL);
    }
    try {
        ObjectManager::getInstance(RuntimeCacheBroadcaster::class)->broadcast();
    } catch (Throwable $e) {
        fwrite(STDERR, 'warn: i18n broadcast: ' . $e->getMessage() . PHP_EOL);
    }
    try {
        $redis = new Redis();
        if (@$redis->connect('127.0.0.1', 6379, 1.0)) {
            $redis->select(1);
            $keys = array_values(array_unique(array_merge(
                $redis->keys('*category*') ?: [],
                $redis->keys('*i18n*') ?: [],
                $redis->keys('*phrase*') ?: [],
                $redis->keys('*weline_product_storefront_category_tree*') ?: []
            )));
            if ($keys !== []) {
                $redis->del(...$keys);
            }
            echo json_encode(['cache_keys_cleared' => count($keys)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'warn: redis clear: ' . $e->getMessage() . PHP_EOL);
    }
}

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'website_id' => $websiteId,
    'categories_in_pack' => count($pack),
    'categories_skipped_no_pack' => $skipped,
    'name_writes' => $nameWrites,
    'summary_writes' => $summaryWrites,
    'description_writes' => $descWrites,
    'ui_dictionary_writes' => $uiWrites,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
