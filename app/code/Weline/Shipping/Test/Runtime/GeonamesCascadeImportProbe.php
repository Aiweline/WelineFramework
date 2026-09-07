<?php
declare(strict_types=1);

require dirname(__DIR__, 6) . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Region;

/** @var Region $region */
$region = ObjectManager::getInstance(Region::class);

$out = [];
foreach (['EG', 'PY'] as $cc) {
    $provinces = $region->reset()
        ->where(Region::schema_fields_COUNTRY_CODE, $cc)
        ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_PROVINCE)
        ->select()
        ->fetch();
    $cities = $region->reset()
        ->where(Region::schema_fields_COUNTRY_CODE, $cc)
        ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_CITY)
        ->select()
        ->fetch();
    $provItems = method_exists($provinces, 'getItems') ? $provinces->getItems() : (array)$provinces;
    $cityItems = method_exists($cities, 'getItems') ? $cities->getItems() : (array)$cities;
    $sample = [];
    foreach (array_slice($provItems, 0, 3) as $item) {
        if (is_object($item) && method_exists($item, 'getData')) {
            $sample[] = [
                'code' => $item->getData(Region::schema_fields_REGION_CODE),
                'name' => $item->getData(Region::schema_fields_REGION_NAME),
            ];
        }
    }
    $out[$cc] = [
        'provinces' => count($provItems),
        'cities' => count($cityItems),
        'sample_provinces' => $sample,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
