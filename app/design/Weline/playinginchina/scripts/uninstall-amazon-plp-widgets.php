<?php
/**
 * PIC P0：对本站 playinginchina 主题卸载 Amazon PLP 壳 required 默认注入。
 *
 * markUserDeleted → bake/omissions 不再固化；配合 category 布局去槽 + footer XOR 占位。
 *
 * 用法：php app/design/Weline/playinginchina/scripts/uninstall-amazon-plp-widgets.php
 */

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

if (!defined('BP')) {
    require dirname(__DIR__, 5) . '/app/bootstrap.php';
}

$om = ObjectManager::getInstance();
/** @var WelineTheme $themeModel */
$themeModel = $om->get(WelineTheme::class);

$themeId = 0;
$rows = (clone $themeModel)->clear()->select()->fetchArray() ?: [];
if (!isset($rows[0]) && isset($rows[WelineTheme::schema_fields_ID])) {
    $rows = [$rows];
}
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $path = (string)($row[WelineTheme::schema_fields_PATH] ?? '');
    $name = strtolower((string)($row[WelineTheme::schema_fields_NAME] ?? ''));
    $module = strtolower((string)($row[WelineTheme::schema_fields_MODULE_NAME] ?? ''));
    if (
        str_contains(strtolower($path), 'playinginchina')
        || $name === 'playinginchina'
        || str_contains($module, 'playinginchina')
    ) {
        $themeId = (int)($row[WelineTheme::schema_fields_ID] ?? 0);
        break;
    }
}
if ($themeId < 1) {
    fwrite(STDERR, "FAIL: playinginchina theme not found\n");
    exit(1);
}

/** @var WidgetDefaultInjectionService $injections */
$injections = $om->get(WidgetDefaultInjectionService::class);

$identity = [
    'layout_option' => 'default',
    'scope' => 'default',
    'locale_code' => 'default',
    'target_type' => 'global',
    'target_id' => 0,
];

$pageTypes = [
    'category',
    'homepage',
    'product',
    'products',
    'blog',
    'blog_category',
    'about',
    'contact',
    'guide',
    'default',
];

$targets = [
    [
        'page_types' => ['category'],
        'module' => 'Weline_Product',
        'type' => 'product',
        'code' => 'recommended-products',
        'slot_id' => 'design-category-recommendations',
        'area' => 'content',
    ],
    [
        'page_types' => ['category'],
        'module' => 'Weline_Filters',
        'type' => 'sidebar',
        'code' => 'category-filters',
        'slot_id' => 'category-filters',
        'area' => 'sidebar',
    ],
    [
        'page_types' => $pageTypes,
        'module' => 'Weline_CustomerService',
        'type' => 'content',
        'code' => 'customer-service-float',
        'slot_id' => 'storefront-float-end',
        'area' => 'footer',
    ],
    [
        'page_types' => $pageTypes,
        'module' => 'Weline_StoreMusic',
        'type' => 'content',
        'code' => 'store-music',
        'slot_id' => 'storefront-float-start',
        'area' => 'footer',
    ],
];

$marked = 0;
$errors = [];
foreach ($targets as $target) {
    foreach ($target['page_types'] as $pageType) {
        try {
            $injections->markUserDeleted(
                $themeId,
                $pageType,
                $identity,
                [
                    'module' => $target['module'],
                    'type' => $target['type'],
                    'code' => $target['code'],
                    'slot_id' => $target['slot_id'],
                    'area' => $target['area'],
                ],
                PreviewContextService::AREA_FRONTEND
            );
            $marked++;
            echo "marked {$pageType} {$target['code']}\n";
        } catch (Throwable $e) {
            $errors[] = "{$pageType}/{$target['code']}: " . $e->getMessage();
        }
    }
}

echo "theme_id={$themeId}\n";
echo "marked_user_deleted={$marked}\n";
if ($errors !== []) {
    echo "errors=" . count($errors) . "\n";
    foreach (array_slice($errors, 0, 30) as $err) {
        echo "  - {$err}\n";
    }
    exit(2);
}
echo "OK\n";
