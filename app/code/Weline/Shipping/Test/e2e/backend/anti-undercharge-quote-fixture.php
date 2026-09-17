<?php

declare(strict_types=1);

/**
 * Anti-undercharge Ch1–3 quote harness for Playwright e2e.
 * stdin JSON: { "action": "ch1_americas_2kg"|..., "website_id"?: 0, "evidence_dir"?: "..." }
 * stdout: one JSON line { ok, scenario, ... }
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ShippingCheckoutAddon;
use Weline\Shipping\Model\ShippingProfile;
use Weline\Shipping\Model\ShippingSeasonalRule;
use Weline\Shipping\Service\ChargeableWeightService;
use Weline\Shipping\Service\DefaultShippingLaneSeedService;
use Weline\Shipping\Service\SeedWeightBracketFactory;
use Weline\Shipping\Service\ShippingCapabilityGate;
use Weline\Shipping\Service\ShippingFacade;
use Weline\Shipping\Service\ShippingSurchargeService;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

$raw = stream_get_contents(STDIN);
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

$action = trim((string)($payload['action'] ?? ''));
$websiteId = max(0, (int)($payload['website_id'] ?? 0));
$evidenceDir = trim((string)($payload['evidence_dir'] ?? ''));

try {
    /** @var DefaultShippingLaneSeedService $seed */
    $seed = ObjectManager::getInstance(DefaultShippingLaneSeedService::class);
    $seedResult = $seed->seedDefaultWebsite($websiteId);
    $context = [
        'website_id' => $websiteId,
        'scope_type' => 'website',
        'scope_id' => $websiteId,
    ];
    /** @var ShippingFacade $facade */
    $facade = ObjectManager::getInstance(ShippingFacade::class);

    $result = match ($action) {
        'ch1_seed_profiles' => runCh1Seed($seed, $websiteId, $seedResult),
        'ch1_americas_2kg' => runQuoteScenario($facade, $context, [
            'scenario' => 'ch1_americas_2kg',
            'address' => usAddress(),
            'lines' => [line(2000, 10, 10, 10)],
            'currency' => baseCurrency(),
            'expect' => [
                'has_service' => 'SEED_LANE_AMERICAS',
                'amount_minor' => americasAmountMinor(2.0),
            ],
        ]),
        'ch1_general_35kg_refuse' => runQuoteScenario($facade, $context, [
            'scenario' => 'ch1_general_35kg_refuse',
            'address' => usAddress(),
            'lines' => [line(35000, 20, 20, 20)],
            'currency' => baseCurrency(),
            'expect' => [
                'missing_service' => 'SEED_LANE_AMERICAS',
                'allow_conflict' => true,
            ],
        ]),
        'ch1_heavy_35kg_quote' => runQuoteScenario($facade, $context, [
            'scenario' => 'ch1_heavy_35kg_quote',
            'address' => usAddress(),
            'lines' => [line(35000, 20, 20, 20, ShippingProfile::SEED_HEAVY)],
            'currency' => baseCurrency(),
            'expect' => [
                'has_service' => 'SEED_LANE_HEAVY_INTERNATIONAL',
                'min_amount_minor' => heavyIntlFirstBracketMinor(),
                'gt_americas_2kg' => americasAmountMinor(2.0),
            ],
        ]),
        'ch1_missing_dims_refuse' => runMissingDims($facade, $context),
        'ch2_cn_xj_surcharge' => runQuoteScenario($facade, $context, [
            'scenario' => 'ch2_cn_xj_surcharge',
            'address' => cnAddress('新疆'),
            'lines' => [line(1000, 10, 10, 10)],
            'currency' => baseCurrency(),
            'expect' => [
                'has_service' => 'SEED_LANE_DOMESTIC',
                'surcharge_code' => 'SEED_SURCHARGE_CN_XJ',
                'min_surcharge_minor' => 2500,
            ],
        ]),
        'ch2_pobox_refuse' => runQuoteScenario($facade, $context, [
            'scenario' => 'ch2_pobox_refuse',
            'address' => usAddress('pobox'),
            'lines' => [line(2000, 10, 10, 10)],
            'currency' => baseCurrency(),
            'expect' => [
                'allow_conflict' => true,
                'gate_pobox_false' => true,
            ],
        ]),
        'ch2_hazard_refuse' => runQuoteScenario($facade, $context, [
            'scenario' => 'ch2_hazard_refuse',
            'address' => usAddress(),
            'lines' => [array_merge(line(2000, 10, 10, 10), [
                'shipping_hazard_class' => 'battery_lithium',
                'fulfillment_metadata' => ['shipping_hazard_class' => 'battery_lithium'],
            ])],
            'currency' => baseCurrency(),
            'expect' => [
                'allow_conflict' => true,
            ],
        ]),
        'ch2_external_no_shop_surcharge' => runExternalNoSurchargeContract(),
        'ch2_free_keeps_remote' => runQuoteScenario($facade, $context, [
            'scenario' => 'ch2_free_keeps_remote',
            'address' => cnAddress('新疆'),
            'lines' => [line(1000, 10, 10, 10)],
            'currency' => baseCurrency(),
            'free_subtotal_minor' => 9900,
            'expect' => [
                'has_service' => 'SEED_LANE_DOMESTIC',
                'free_reason' => true,
                'surcharge_code' => 'SEED_SURCHARGE_CN_XJ',
                'min_amount_minor' => 2500,
            ],
        ]),
        'ch3_split_boxes' => runCh3Split($facade, $context),
        'ch3_signature_addon' => runCh3Signature($facade, $context),
        'ch3_seasonal_on' => runCh3Seasonal($facade, $context, $websiteId),
        'ch3_split_after_warehouse' => runCh3Split($facade, $context, 'ch3_split_after_warehouse'),
        'ch4_ddp_notice' => runCh4DdpNotice($facade, $context, $websiteId),
        'ch4_return_buyer' => runCh4Return($facade, $context, 'buyer_pays'),
        'ch4_return_seller' => runCh4Return($facade, $context, 'seller_pays'),
        'ch4_cod_in_grand_total' => runCh4CodGrandTotal(),
        'ch4_split_first_only' => runCh4Split($context, 'first_only'),
        'ch4_split_each_shipment' => runCh4Split($context, 'each_shipment'),
        'suite_critical' => runSuiteCritical($facade, $context, $seed, $websiteId, $seedResult),
        default => throw new RuntimeException('unknown_action:' . $action),
    };

    if ($evidenceDir !== '' && is_dir($evidenceDir)) {
        $file = rtrim($evidenceDir, '/\\') . '/harness-' . ($result['scenario'] ?? $action) . '.json';
        @file_put_contents(
            $file,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
        $result['evidence_file'] = $file;
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'scenario' => $action,
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;
}

/**
 * @param array<string,mixed> $seedResult
 * @return array<string,mixed>
 */
function runCh1Seed(DefaultShippingLaneSeedService $seed, int $websiteId, array $seedResult): array
{
    $codes = DefaultShippingLaneSeedService::expectedSeedTemplateCodes();
    /** @var RateTemplate $tpl */
    $tpl = ObjectManager::getInstance(RateTemplate::class, [], false);
    $found = [];
    foreach ($codes as $code) {
        $items = $tpl->reset()
            ->where(RateTemplate::schema_fields_SCOPE_TYPE, 'website')
            ->where(RateTemplate::schema_fields_SCOPE_ID, $websiteId)
            ->where(RateTemplate::schema_fields_TEMPLATE_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        $row = is_array($items) ? ($items[0] ?? null) : null;
        if ($row instanceof RateTemplate && (int)$row->getId() > 0) {
            $found[] = $code;
        }
    }
    /** @var ShippingProfile $profile */
    $profile = ObjectManager::getInstance(ShippingProfile::class, [], false);
    $profiles = [];
    foreach ([ShippingProfile::SEED_GENERAL, ShippingProfile::SEED_HEAVY] as $pcode) {
        $items = $profile->reset()
            ->where(ShippingProfile::schema_fields_SCOPE_TYPE, 'website')
            ->where(ShippingProfile::schema_fields_SCOPE_ID, $websiteId)
            ->where(ShippingProfile::schema_fields_PROFILE_CODE, $pcode)
            ->select()
            ->fetch()
            ->getItems();
        $row = is_array($items) ? ($items[0] ?? null) : null;
        if ($row instanceof ShippingProfile && (int)$row->getId() > 0) {
            $profiles[] = $pcode;
        }
    }

    return [
        'ok' => count($found) === count($codes) && count($profiles) === 2,
        'scenario' => 'ch1_seed_profiles',
        'template_codes_found' => $found,
        'template_codes_expected' => $codes,
        'profiles' => $profiles,
        'seed' => $seedResult,
        'assertions' => [
            'template_count' => count($codes),
            'templates_ok' => count($found) === count($codes),
            'profiles_ok' => count($profiles) === 2,
        ],
    ];
}

/**
 * @param array<string,mixed> $context
 * @param array<string,mixed> $cfg
 * @return array<string,mixed>
 */
function runQuoteScenario(ShippingFacade $facade, array $context, array $cfg): array
{
    $scenario = (string)$cfg['scenario'];
    $expect = is_array($cfg['expect'] ?? null) ? $cfg['expect'] : [];
    $conflict = false;
    $rates = [];
    $error = null;
    try {
        $rates = $facade->listRates(
            $cfg['address'],
            $cfg['lines'],
            (string)$cfg['currency'],
            2,
            $context,
            null,
            isset($cfg['free_subtotal_minor']) ? (int)$cfg['free_subtotal_minor'] : null,
        );
    } catch (Throwable $e) {
        $conflict = str_contains($e->getMessage(), 'shipping_profile_conflict');
        $error = $e->getMessage();
        if (empty($expect['allow_conflict']) || !$conflict) {
            throw $e;
        }
    }

    $assertions = ['conflict' => $conflict];
    $ok = true;

    if (!empty($expect['gate_pobox_false'])) {
        $gate = new ShippingCapabilityGate();
        $assertions['gate_pobox'] = $gate->allowsPointType(
            ShippingCapabilityGate::DEFAULT_ALLOWED_POINTS,
            'pobox',
        );
        $ok = $ok && $assertions['gate_pobox'] === false;
        $ok = $ok && ($conflict || $rates === []);
    }

    if (isset($expect['has_service'])) {
        $code = (string)$expect['has_service'];
        $assertions['has_service'] = isset($rates[$code]);
        $ok = $ok && $assertions['has_service'];
        if (isset($rates[$code])) {
            $assertions['amount_minor'] = (int)($rates[$code]['amount_minor'] ?? 0);
            $assertions['package_count'] = (int)($rates[$code]['package_count'] ?? 1);
            $assertions['surcharges'] = $rates[$code]['surcharges'] ?? [];
            $assertions['addons'] = $rates[$code]['addons'] ?? [];
            $assertions['seasonal'] = $rates[$code]['seasonal'] ?? [];
            $assertions['free_reason'] = $rates[$code]['free_reason'] ?? null;
        }
    }

    if (isset($expect['missing_service'])) {
        $code = (string)$expect['missing_service'];
        $assertions['missing_service'] = !isset($rates[$code]);
        $ok = $ok && ($assertions['missing_service'] || $conflict);
    }

    if (isset($expect['amount_minor']) && isset($expect['has_service'])) {
        $code = (string)$expect['has_service'];
        $got = (int)($rates[$code]['amount_minor'] ?? -1);
        $assertions['amount_match'] = $got === (int)$expect['amount_minor'];
        $ok = $ok && $assertions['amount_match'];
    }

    if (isset($expect['min_amount_minor']) && isset($expect['has_service'])) {
        $code = (string)$expect['has_service'];
        $got = (int)($rates[$code]['amount_minor'] ?? 0);
        $assertions['min_amount_ok'] = $got >= (int)$expect['min_amount_minor'];
        $ok = $ok && $assertions['min_amount_ok'];
    }

    if (isset($expect['gt_americas_2kg']) && isset($expect['has_service'])) {
        $code = (string)$expect['has_service'];
        $got = (int)($rates[$code]['amount_minor'] ?? 0);
        $assertions['gt_americas_2kg'] = $got > (int)$expect['gt_americas_2kg'];
        $ok = $ok && $assertions['gt_americas_2kg'];
    }

    if (!empty($expect['surcharge_code']) && isset($expect['has_service'])) {
        $code = (string)$expect['has_service'];
        $hits = $rates[$code]['surcharges'] ?? [];
        $found = false;
        $sum = 0;
        foreach (is_array($hits) ? $hits : [] as $hit) {
            if (($hit['rule_code'] ?? '') === $expect['surcharge_code']) {
                $found = true;
            }
            $sum += (int)($hit['amount_minor'] ?? 0);
        }
        $assertions['surcharge_found'] = $found;
        $assertions['surcharge_sum'] = $sum;
        $ok = $ok && $found;
        if (isset($expect['min_surcharge_minor'])) {
            $assertions['surcharge_min_ok'] = $sum >= (int)$expect['min_surcharge_minor'];
            $ok = $ok && $assertions['surcharge_min_ok'];
        }
    }

    if (!empty($expect['free_reason']) && isset($expect['has_service'])) {
        $code = (string)$expect['has_service'];
        $fr = $rates[$code]['free_reason'] ?? null;
        $assertions['has_free_reason'] = is_string($fr) && $fr !== '';
        $ok = $ok && $assertions['has_free_reason'];
    }

    if (!empty($expect['allow_conflict']) && empty($expect['has_service']) && empty($expect['gate_pobox_false'])) {
        $ok = $ok && ($conflict || $rates === []);
        $assertions['refused'] = $conflict || $rates === [];
    }

    return [
        'ok' => $ok,
        'scenario' => $scenario,
        'rates' => summarizeRates($rates),
        'diagnostics' => $facade->getLastQuoteDiagnostics(),
        'error' => $error,
        'assertions' => $assertions,
    ];
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function runMissingDims(ShippingFacade $facade, array $context): array
{
    $charge = new ChargeableWeightService();
    $missingWeight = $charge->summarize([line(0, 10, 10, 10)]);
    $missingDims = $charge->summarize([
        [
            'requires_shipping' => true,
            'qty_minor' => 1,
            'weight_minor' => 2000,
            'length_cm' => 0,
            'width_cm' => 0,
            'height_cm' => 0,
        ],
    ]);
    $quote = runQuoteScenario($facade, $context, [
        'scenario' => 'ch1_missing_dims_refuse',
        'address' => usAddress(),
        'lines' => [line(0, 10, 10, 10)],
        'currency' => baseCurrency(),
        'expect' => [
            'missing_service' => 'SEED_LANE_AMERICAS',
            'allow_conflict' => true,
        ],
    ]);
    $ok = !empty($missingWeight['missing_weight'])
        && !empty($missingDims['missing_dims'])
        && !empty($quote['ok']);

    return [
        'ok' => $ok,
        'scenario' => 'ch1_missing_dims_refuse',
        'chargeable' => [
            'missing_weight' => $missingWeight,
            'missing_dims' => $missingDims,
        ],
        'quote' => $quote,
        'assertions' => [
            'missing_weight' => (bool)$missingWeight['missing_weight'],
            'missing_dims' => (bool)$missingDims['missing_dims'],
            'quote_refused' => (bool)$quote['ok'],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function runExternalNoSurchargeContract(): array
{
    $local = (string)file_get_contents(
        dirname(__DIR__, 3) . '/Service/Provider/LocalTemplatePricingService.php',
    );
    $mgr = (string)file_get_contents(
        dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php',
    );
    $ok = str_contains($local, 'ShippingSurchargeService')
        && str_contains($mgr, 'provider_code')
        && !str_contains($mgr, 'ShippingSurchargeService');

    return [
        'ok' => $ok,
        'scenario' => 'ch2_external_no_shop_surcharge',
        'assertions' => [
            'local_has_surcharge' => str_contains($local, 'ShippingSurchargeService'),
            'manager_no_direct_surcharge' => !str_contains($mgr, 'ShippingSurchargeService'),
        ],
    ];
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function runCh3Split(ShippingFacade $facade, array $context, string $scenario = 'ch3_split_boxes'): array
{
    $single = runQuoteScenario($facade, $context, [
        'scenario' => $scenario . '_single',
        'address' => usAddress(),
        'lines' => [line(20000, 20, 20, 20)],
        'currency' => baseCurrency(),
        'expect' => ['has_service' => 'SEED_LANE_AMERICAS'],
    ]);
    $split = runQuoteScenario($facade, $context, [
        'scenario' => $scenario,
        'address' => usAddress(),
        'lines' => [line(20000, 20, 20, 20, null, 2)],
        'currency' => baseCurrency(),
        'expect' => ['has_service' => 'SEED_LANE_AMERICAS'],
    ]);
    $singleAmt = (int)($single['assertions']['amount_minor'] ?? 0);
    $splitAmt = (int)($split['assertions']['amount_minor'] ?? 0);
    $pkg = (int)($split['assertions']['package_count'] ?? 0);
    $ok = !empty($split['ok']) && $pkg >= 2 && $splitAmt >= $singleAmt;

    return [
        'ok' => $ok,
        'scenario' => $scenario,
        'single' => $single,
        'split' => $split,
        'assertions' => [
            'package_count' => $pkg,
            'split_ge_single' => $splitAmt >= $singleAmt,
            'single_amount' => $singleAmt,
            'split_amount' => $splitAmt,
        ],
    ];
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function runCh3Signature(ShippingFacade $facade, array $context): array
{
    $base = runQuoteScenario($facade, $context, [
        'scenario' => 'ch3_signature_addon_base',
        'address' => usAddress(),
        'lines' => [line(2000, 10, 10, 10)],
        'currency' => baseCurrency(),
        'expect' => ['has_service' => 'SEED_LANE_AMERICAS'],
    ]);
    $with = runQuoteScenario($facade, $context, [
        'scenario' => 'ch3_signature_addon',
        'address' => array_merge(usAddress(), ['addons' => ['signature' => true]]),
        'lines' => [line(2000, 10, 10, 10)],
        'currency' => baseCurrency(),
        'expect' => ['has_service' => 'SEED_LANE_AMERICAS'],
    ]);
    $baseAmt = (int)($base['assertions']['amount_minor'] ?? 0);
    $withAmt = (int)($with['assertions']['amount_minor'] ?? 0);
    $addons = $with['assertions']['addons'] ?? [];
    $hasSig = false;
    foreach (is_array($addons) ? $addons : [] as $hit) {
        if (($hit['addon_code'] ?? '') === ShippingCheckoutAddon::SEED_SIGNATURE
            || ($hit['addon_type'] ?? '') === ShippingCheckoutAddon::TYPE_SIGNATURE
        ) {
            $hasSig = true;
        }
    }
    $ok = !empty($with['ok']) && $withAmt > $baseAmt && $hasSig;

    return [
        'ok' => $ok,
        'scenario' => 'ch3_signature_addon',
        'base' => $base,
        'with_signature' => $with,
        'assertions' => [
            'amount_increased' => $withAmt > $baseAmt,
            'has_signature_addon' => $hasSig,
            'base_amount' => $baseAmt,
            'with_amount' => $withAmt,
        ],
    ];
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function runCh3Seasonal(ShippingFacade $facade, array $context, int $websiteId): array
{
    /** @var ShippingSeasonalRule $model */
    $model = ObjectManager::getInstance(ShippingSeasonalRule::class, [], false);
    $items = $model->reset()
        ->where(ShippingSeasonalRule::schema_fields_SCOPE_TYPE, 'website')
        ->where(ShippingSeasonalRule::schema_fields_SCOPE_ID, $websiteId)
        ->where(ShippingSeasonalRule::schema_fields_RULE_CODE, ShippingSeasonalRule::SEED_FUEL)
        ->select()
        ->fetch()
        ->getItems();
    $row = is_array($items) ? ($items[0] ?? null) : null;
    if (!$row instanceof ShippingSeasonalRule || (int)$row->getId() <= 0) {
        throw new RuntimeException('seasonal_seed_missing');
    }
    $prevActive = (int)$row->getData(ShippingSeasonalRule::schema_fields_IS_ACTIVE);
    $prevStart = (string)$row->getData(ShippingSeasonalRule::schema_fields_START_DATE);
    $prevEnd = (string)$row->getData(ShippingSeasonalRule::schema_fields_END_DATE);
    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    try {
        $off = runQuoteScenario($facade, $context, [
            'scenario' => 'ch3_seasonal_off',
            'address' => usAddress(),
            'lines' => [line(2000, 10, 10, 10)],
            'currency' => baseCurrency(),
            'expect' => ['has_service' => 'SEED_LANE_AMERICAS'],
        ]);
        $row->setData([
            ShippingSeasonalRule::schema_fields_IS_ACTIVE => 1,
            ShippingSeasonalRule::schema_fields_START_DATE => $today,
            ShippingSeasonalRule::schema_fields_END_DATE => $today,
        ])->save();
        $on = runQuoteScenario($facade, $context, [
            'scenario' => 'ch3_seasonal_on',
            'address' => usAddress(),
            'lines' => [line(2000, 10, 10, 10)],
            'currency' => baseCurrency(),
            'expect' => ['has_service' => 'SEED_LANE_AMERICAS'],
        ]);
        $offAmt = (int)($off['assertions']['amount_minor'] ?? 0);
        $onAmt = (int)($on['assertions']['amount_minor'] ?? 0);
        $seasonal = $on['assertions']['seasonal'] ?? [];
        $ok = !empty($on['ok']) && $onAmt > $offAmt && is_array($seasonal) && $seasonal !== [];

        return [
            'ok' => $ok,
            'scenario' => 'ch3_seasonal_on',
            'off' => $off,
            'on' => $on,
            'assertions' => [
                'amount_increased' => $onAmt > $offAmt,
                'has_seasonal' => is_array($seasonal) && $seasonal !== [],
                'off_amount' => $offAmt,
                'on_amount' => $onAmt,
            ],
        ];
    } finally {
        $row->setData([
            ShippingSeasonalRule::schema_fields_IS_ACTIVE => $prevActive,
            ShippingSeasonalRule::schema_fields_START_DATE => $prevStart,
            ShippingSeasonalRule::schema_fields_END_DATE => $prevEnd,
        ])->save();
    }
}

/**
 * @param array<string,mixed> $context
 * @param array<string,mixed> $seedResult
 * @return array<string,mixed>
 */
function runSuiteCritical(
    ShippingFacade $facade,
    array $context,
    DefaultShippingLaneSeedService $seed,
    int $websiteId,
    array $seedResult,
): array {
    $parts = [
        runCh1Seed($seed, $websiteId, $seedResult),
        runQuoteScenario($facade, $context, [
            'scenario' => 'ch1_americas_2kg',
            'address' => usAddress(),
            'lines' => [line(2000, 10, 10, 10)],
            'currency' => baseCurrency(),
            'expect' => [
                'has_service' => 'SEED_LANE_AMERICAS',
                'amount_minor' => americasAmountMinor(2.0),
            ],
        ]),
        runQuoteScenario($facade, $context, [
            'scenario' => 'ch2_cn_xj_surcharge',
            'address' => cnAddress('新疆'),
            'lines' => [line(1000, 10, 10, 10)],
            'currency' => baseCurrency(),
            'expect' => [
                'has_service' => 'SEED_LANE_DOMESTIC',
                'surcharge_code' => 'SEED_SURCHARGE_CN_XJ',
                'min_surcharge_minor' => 2500,
            ],
        ]),
        runCh3Split($facade, $context),
        runCh3Signature($facade, $context),
        runCh4DdpNotice($facade, $context, $websiteId),
        runCh4Return($facade, $context, 'buyer_pays'),
        runCh4CodGrandTotal(),
        runCh4Split($context, 'each_shipment'),
    ];
    $ok = true;
    foreach ($parts as $p) {
        $ok = $ok && !empty($p['ok']);
    }

    return [
        'ok' => $ok,
        'scenario' => 'suite_critical',
        'parts' => $parts,
    ];
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function runCh4DdpNotice(ShippingFacade $facade, array $context, int $websiteId): array
{
    /** @var \Weline\Shipping\Model\ShippingService $model */
    $model = ObjectManager::getInstance(\Weline\Shipping\Model\ShippingService::class, [], false);
    $items = $model->reset()
        ->where(\Weline\Shipping\Model\ShippingService::schema_fields_SCOPE_TYPE, 'website')
        ->where(\Weline\Shipping\Model\ShippingService::schema_fields_SCOPE_ID, $websiteId)
        ->where(\Weline\Shipping\Model\ShippingService::schema_fields_SERVICE_CODE, 'SEED_LANE_AMERICAS')
        ->select()
        ->fetch()
        ->getItems();
    $row = is_array($items) ? ($items[0] ?? null) : null;
    if (!$row instanceof \Weline\Shipping\Model\ShippingService || (int)$row->getId() <= 0) {
        throw new RuntimeException('americas_service_missing');
    }
    $prev = (string)$row->getData(\Weline\Shipping\Model\ShippingService::schema_fields_INCOTERM);
    try {
        $row->setData(\Weline\Shipping\Model\ShippingService::schema_fields_INCOTERM, 'ddu')->save();
        $ddu = $facade->listRates(usAddress(), [line(2000, 10, 10, 10)], baseCurrency(), 2, $context);
        $row->setData(\Weline\Shipping\Model\ShippingService::schema_fields_INCOTERM, 'ddp')->save();
        $ddp = $facade->listRates(usAddress(), [line(2000, 10, 10, 10)], baseCurrency(), 2, $context);
        $dduAmt = (int)($ddu['SEED_LANE_AMERICAS']['amount_minor'] ?? -1);
        $ddpAmt = (int)($ddp['SEED_LANE_AMERICAS']['amount_minor'] ?? -2);
        $notice = (string)($ddp['SEED_LANE_AMERICAS']['duty_notice'] ?? '');
        $incoterm = (string)($ddp['SEED_LANE_AMERICAS']['incoterm'] ?? '');
        $ok = $dduAmt === $ddpAmt && $dduAmt > 0 && $incoterm === 'ddp' && $notice !== '';

        return [
            'ok' => $ok,
            'scenario' => 'ch4_ddp_notice',
            'assertions' => [
                'amount_equal' => $dduAmt === $ddpAmt,
                'ddu_amount' => $dduAmt,
                'ddp_amount' => $ddpAmt,
                'incoterm' => $incoterm,
                'duty_notice' => $notice,
            ],
        ];
    } finally {
        $row->setData(\Weline\Shipping\Model\ShippingService::schema_fields_INCOTERM, $prev !== '' ? $prev : 'ddu')->save();
    }
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function runCh4Return(ShippingFacade $facade, array $context, string $policy): array
{
    /** @var \Weline\Shipping\Service\ShippingCommercePolicyService $commerce */
    $commerce = ObjectManager::getInstance(\Weline\Shipping\Service\ShippingCommercePolicyService::class);
    $prev = $commerce->resolve($context);
    try {
        $commerce->setPolicies(
            $policy,
            $prev['split_shipment_shipping'],
            (string)($context['scope_type'] ?? 'website'),
            (int)($context['scope_id'] ?? 0),
        );
        $quote = $facade->quoteReturnShipping(
            [line(1000, 10, 10, 10)],
            cnAddress('北京'),
            baseCurrency(),
            2,
            $context,
        );
        $amount = (int)($quote['amount_minor'] ?? -1);
        $ok = $policy === 'seller_pays'
            ? ($amount === 0 && (($quote['reason'] ?? '') === 'seller_pays'))
            : ($amount > 0);

        return [
            'ok' => $ok,
            'scenario' => $policy === 'seller_pays' ? 'ch4_return_seller' : 'ch4_return_buyer',
            'quote' => $quote,
            'assertions' => [
                'amount_minor' => $amount,
                'return_policy' => (string)($quote['return_policy'] ?? ''),
                'seller_zero' => $policy === 'seller_pays' ? $amount === 0 : null,
                'buyer_positive' => $policy === 'buyer_pays' ? $amount > 0 : null,
            ],
        ];
    } finally {
        $commerce->setPolicies(
            $prev['return_policy'],
            $prev['split_shipment_shipping'],
            (string)($context['scope_type'] ?? 'website'),
            (int)($context['scope_id'] ?? 0),
        );
    }
}

/**
 * @return array<string,mixed>
 */
function runCh4CodGrandTotal(): array
{
    $om = ObjectManager::getInstance();
    /** @var \Weline\Payment\Service\CodFeeCalculator $calc */
    $calc = $om->getInstance(\Weline\Payment\Service\CodFeeCalculator::class);
    $baseline = 11500;
    $fee = $calc->fromConfig(['fee' => 5.00], $baseline, 2);
    $grand = $baseline + $fee;
    $money = (new \Weline\Order\Api\Data\MoneySnapshot(
        currency: 'CNY',
        subtotalMinor: 10000,
        shippingAmountMinor: 1500,
        taxAmountMinor: 0,
        discountAmountMinor: 0,
        grandTotalMinor: 0,
        codFeeAmountMinor: $fee,
    ))->withComputedGrandTotal();
    $ok = $fee === 500 && $grand === 12000 && $money->grandTotalMinor === 12000
        && (($money->toArray()['cod_fee_amount_minor'] ?? 0) === 500);

    return [
        'ok' => $ok,
        'scenario' => 'ch4_cod_in_grand_total',
        'assertions' => [
            'cod_fee_amount_minor' => $fee,
            'baseline' => $baseline,
            'grand_total_minor' => $grand,
            'money_grand' => $money->grandTotalMinor,
        ],
    ];
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function runCh4Split(array $context, string $strategy): array
{
    /** @var \Weline\Shipping\Service\ShippingCommercePolicyService $commerce */
    $commerce = ObjectManager::getInstance(\Weline\Shipping\Service\ShippingCommercePolicyService::class);
    $prev = $commerce->resolve($context);
    try {
        $commerce->setPolicies(
            $prev['return_policy'],
            $strategy,
            (string)($context['scope_type'] ?? 'website'),
            (int)($context['scope_id'] ?? 0),
        );
        /** @var \Weline\Shipping\Service\SplitShipmentShippingService $split */
        $split = ObjectManager::getInstance(\Weline\Shipping\Service\SplitShipmentShippingService::class);
        $snap = $split->buildCheckoutSnapshot(11500, $context);
        if ($strategy === 'first_only') {
            $second = $split->quoteSubsequentShipment(2, [line(2000, 10, 10, 10)], $snap);
            $ok = $second['amount_minor'] === 0;

            return [
                'ok' => $ok,
                'scenario' => 'ch4_split_first_only',
                'assertions' => [
                    'strategy' => $snap['split_shipment_shipping'],
                    'second_amount_minor' => $second['amount_minor'],
                ],
            ];
        }
        /** @var \Weline\Shipping\Model\RateTemplate $tplModel */
        $tplModel = ObjectManager::getInstance(\Weline\Shipping\Model\RateTemplate::class, [], false);
        $items = $tplModel->reset()
            ->where(\Weline\Shipping\Model\RateTemplate::schema_fields_TEMPLATE_CODE, 'SEED_TPL_AMERICAS')
            ->where(\Weline\Shipping\Model\RateTemplate::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        $tpl = is_array($items) ? ($items[0] ?? null) : null;
        if (!$tpl instanceof \Weline\Shipping\Model\RateTemplate) {
            throw new RuntimeException('americas_template_missing');
        }
        $second = $split->quoteSubsequentShipment(2, [line(2000, 10, 10, 10)], $snap, $tpl);
        $ok = $second['amount_minor'] > 0 && $second['strategy'] === 'each_shipment';

        return [
            'ok' => $ok,
            'scenario' => 'ch4_split_each_shipment',
            'assertions' => [
                'strategy' => $second['strategy'],
                'second_amount_minor' => $second['amount_minor'],
                'positive' => $second['amount_minor'] > 0,
            ],
        ];
    } finally {
        $commerce->setPolicies(
            $prev['return_policy'],
            $prev['split_shipment_shipping'],
            (string)($context['scope_type'] ?? 'website'),
            (int)($context['scope_id'] ?? 0),
        );
    }
}

/**
 * @return array<string,mixed>
 */
function usAddress(string $pointType = 'residential'): array
{
    return [
        'country_code' => 'US',
        'province' => 'NY',
        'city' => 'New York',
        'postcode' => '10001',
        'delivery_point_type' => $pointType,
    ];
}

/**
 * @return array<string,mixed>
 */
function cnAddress(string $province): array
{
    return [
        'country_code' => 'CN',
        'province' => $province,
        'city' => '乌鲁木齐',
        'postcode' => '830000',
        'delivery_point_type' => 'residential',
    ];
}

/**
 * @return array<string,mixed>
 */
function line(
    int $weightMinor,
    float $l,
    float $w,
    float $h,
    ?string $profile = null,
    int $qty = 1,
): array {
    $row = [
        'requires_shipping' => true,
        'qty_minor' => max(1, $qty),
        'weight_minor' => $weightMinor,
        'length_cm' => $l,
        'width_cm' => $w,
        'height_cm' => $h,
        'unit_price_minor' => 5000,
        'row_total_minor' => 5000 * max(1, $qty),
    ];
    if ($profile !== null && $profile !== '') {
        $row['shipping_profile_code'] = $profile;
    }

    return $row;
}

function baseCurrency(): string
{
    try {
        /** @var \Weline\Currency\Service\CurrencyRateService $svc */
        $svc = ObjectManager::getInstance(\Weline\Currency\Service\CurrencyRateService::class);
        $code = strtoupper(trim((string)$svc->getBaseCurrencyCode()));

        return $code !== '' ? $code : 'CNY';
    } catch (Throwable) {
        return 'CNY';
    }
}

function americasAmountMinor(float $weightKg): int
{
    $brackets = SeedWeightBracketFactory::fromLinear(45, 14, SeedWeightBracketFactory::GENERAL_BOUNDS);
    foreach ($brackets as $i => $bracket) {
        $min = (float)$bracket['min'];
        $max = $bracket['max'];
        $isLast = $i === array_key_last($brackets);
        if ($max === null) {
            $in = $weightKg >= $min;
        } elseif ($isLast) {
            $in = $weightKg >= $min && $weightKg <= (float)$max + 0.0000001;
        } else {
            $in = $weightKg >= $min && $weightKg < (float)$max;
        }
        if ($in) {
            return (int)round(((float)$bracket['price']) * 100);
        }
    }

    throw new RuntimeException('americas_bracket_miss');
}

function heavyIntlFirstBracketMinor(): int
{
    $brackets = SeedWeightBracketFactory::fromLinear(220, 45, SeedWeightBracketFactory::HEAVY_BOUNDS);

    return (int)round(((float)$brackets[0]['price']) * 100);
}

/**
 * @param array<string,array<string,mixed>> $rates
 * @return array<string,array<string,mixed>>
 */
function summarizeRates(array $rates): array
{
    $out = [];
    foreach ($rates as $code => $rate) {
        $out[$code] = [
            'amount_minor' => (int)($rate['amount_minor'] ?? 0),
            'label' => (string)($rate['label'] ?? ''),
            'package_count' => (int)($rate['package_count'] ?? 1),
            'free_reason' => $rate['free_reason'] ?? null,
            'surcharges' => $rate['surcharges'] ?? [],
            'seasonal' => $rate['seasonal'] ?? [],
            'addons' => $rate['addons'] ?? [],
        ];
    }

    return $out;
}
