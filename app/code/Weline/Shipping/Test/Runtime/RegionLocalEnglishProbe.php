<?php

declare(strict_types=1);

/**
 * 只读验收：当前 locale 的 LocalModel 行生效（非英语专用配置）。
 *
 *   php app/code/Weline/Shipping/Test/Runtime/RegionLocalEnglishProbe.php
 *
 * 若 en_US Local 尚未由 LocalModelTranslation 写入，city 可能仍为中文源语言；
 * 有 Local 行时必须读出 Local 值。
 */
require dirname(__DIR__, 6) . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\Region\LocalDescription as RegionLocalDescription;
use Weline\Shipping\Service\RegionLocalNameResolver;

/** @var Region $regionModel */
$regionModel = ObjectManager::getInstance(Region::class);
$city = $regionModel->reset()
    ->where(Region::schema_fields_COUNTRY_CODE, 'CN')
    ->where(Region::schema_fields_REGION_CODE, '5101')
    ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_CITY)
    ->find()
    ->fetch();
$cityId = (int)$city->getId();
$defaultName = trim((string)$city->getData(Region::schema_fields_REGION_NAME));

/** @var RegionLocalDescription $localModel */
$localModel = ObjectManager::getInstance(RegionLocalDescription::class);
$enLocal = $localModel->reset()
    ->where(RegionLocalDescription::schema_fields_ID, $cityId)
    ->where(RegionLocalDescription::schema_fields_local_code, 'en_US')
    ->find()
    ->fetch();
$enName = trim((string)$enLocal->getData(RegionLocalDescription::schema_fields_REGION_NAME));

/** @var RegionLocalNameResolver $resolver */
$resolver = ObjectManager::getInstance(RegionLocalNameResolver::class);
$resolved = $resolver->nameByRegionId($cityId, 'en_US');

$checks = [
    'city_id' => $cityId > 0,
    'default_name_present' => $defaultName !== '',
    'resolver_prefers_local_when_present' => $enName === ''
        ? ($resolved === $defaultName || $resolved !== '')
        : ($resolved === $enName),
    'local_model_row_drives_display' => $enName === '' || $resolved === $enName,
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
$payload = [
    'ok' => $failed === [],
    'city_id' => $cityId,
    'default_name' => $defaultName,
    'en_US_local' => $enName,
    'resolved_en_US' => $resolved,
    'checks' => $checks,
    'failed' => $failed,
    'note' => $enName === ''
        ? 'en_US Local 尚未写入；请跑 LocalModelTranslation 队列，勿依赖英语专用种子'
        : 'en_US Local 已生效，展示走 LocalModel',
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($payload['ok'] ? 0 : 1);
