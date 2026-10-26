<?php

declare(strict_types=1);

/**
 * SEO 商家信息（商家信息配置组 + 网站/店铺/渠道继承 + 商品 JSON-LD 装填）验收。
 *
 * 用法：
 *   php tests/accept_seo_merchant_info.php                 # 全量
 *   php tests/accept_seo_merchant_info.php --config-only   # 配置组声明
 *   php tests/accept_seo_merchant_info.php --hooks-only    # 三处表单 hook
 *   php tests/accept_seo_merchant_info.php --live          # 本机 PDP 真通路 JSON-LD
 */

require __DIR__ . '/../app/bootstrap.php';

if (!defined('SANDBOX')) {
    define('SANDBOX', true);
}

function accept_fail(string $reason): never
{
    echo "ACCEPT FAIL: {$reason}\n";
    exit(1);
}

function accept_assert(bool $condition, string $reason): void
{
    if (!$condition) {
        accept_fail($reason);
    }
}

$argv = $_SERVER['argv'] ?? [];
$mode = '';
foreach ($argv as $arg) {
    if (in_array($arg, ['--config-only', '--hooks-only', '--live'], true)) {
        $mode = $arg;
    }
}

const MERCHANT_GROUP = 'seo_merchant';
const MERCHANT_MODULE = 'Weline_Seo';

/** @var array<string, string> key => 期望默认值 */
$expectedDefaults = [
    'seo/merchant/name' => '',
    'seo/merchant/brand' => '',
    'seo/merchant/email' => '',
    'seo/merchant/phone' => '',
    'seo/merchant/address' => '',
    'seo/merchant/return_policy_url' => '/policy/refund',
    'seo/merchant/shipping_policy_url' => '/policy/shipping',
    'seo/merchant/return_days' => '30',
    'seo/merchant/return_fees' => 'buyer_pays',
    'seo/merchant/return_method' => 'mail',
    'seo/merchant/country' => 'WW',
];

$checkConfig = static function () use ($expectedDefaults): void {
    $file = BP . 'app/code/Weline/Seo/extends/module/Weline_SystemConfig/Config/backend/seo-merchant.phtml';
    accept_assert(is_file($file), 'config template missing: ' . $file);
    $source = (string) file_get_contents($file);
    accept_assert(str_contains($source, 'code="' . MERCHANT_GROUP . '"'), 'group code missing');
    accept_assert(str_contains($source, '@config.area {backend}'), '@config.area backend missing');

    foreach ($expectedDefaults as $key => $default) {
        accept_assert(
            preg_match('/key="' . preg_quote($key, '/') . '"/', $source) === 1,
            'field key missing: ' . $key
        );
    }
    accept_assert(
        preg_match_all('/scope="global,website,store"/', $source) >= count($expectedDefaults),
        'every field must allow global,website,store scopes'
    );
    accept_assert(str_contains($source, 'default="/policy/refund"'), 'default return policy url missing');
    accept_assert(str_contains($source, 'default="/policy/shipping"'), 'default shipping policy url missing');
    accept_assert(str_contains($source, 'default="30"'), 'default return days missing');

    try {
        /** @var \Weline\Framework\Extends\ExtendsRegistry $registry */
        $registry = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Extends\ExtendsRegistry::class);
        // 必须全量重建：refreshForModules() 只重建该模块自身的 extends，
        // 会把别的模块 extended_by 条目（如 Product/Theme 的 SeoProfileProvider）从注册表里挤掉，
        // 导致 SEO Provider 静默全失、PDP JSON-LD 掉 offers。
        $registry->refresh();
        \Weline\Framework\Compilation\CompiledPhpRegistry::forgetForTests(BP . 'generated/extends.php');
    } catch (\Throwable $throwable) {
        accept_fail('extends registry refresh failed: ' . $throwable->getMessage());
    }

    /** @var \Weline\SystemConfig\Service\SystemConfigTemplateService $templates */
    $templates = \Weline\Framework\Manager\ObjectManager::getInstance(
        \Weline\SystemConfig\Service\SystemConfigTemplateService::class
    );
    $codes = [];
    foreach ($templates->getTemplates(MERCHANT_MODULE, 'backend', true) as $summary) {
        $codes[] = (string) ($summary['code'] ?? '');
    }
    accept_assert(in_array('seo-merchant', $codes, true), 'template not registered in extends registry');

    $meta = $templates->getTemplateMeta(MERCHANT_MODULE, 'backend', 'seo-merchant', true);
    accept_assert(is_array($meta), 'template meta parse failed');
    $fields = [];
    $groups = [];
    foreach ((array) ($meta['fields'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $fields[(string) ($field['key'] ?? '')] = $field;
        $groups[(string) ($field['group'] ?? '')] = true;
    }
    foreach ($expectedDefaults as $key => $default) {
        accept_assert(isset($fields[$key]), 'meta field missing: ' . $key);
        accept_assert(
            (string) ($fields[$key]['default'] ?? '') === $default,
            'meta default mismatch for ' . $key . ' => ' . (string) ($fields[$key]['default'] ?? '')
        );
        accept_assert((string) ($fields[$key]['group'] ?? '') === MERCHANT_GROUP, 'meta group mismatch: ' . $key);
    }
    accept_assert(isset($groups[MERCHANT_GROUP]), 'meta group ' . MERCHANT_GROUP . ' missing');
};

$checkHooks = static function (): void {
    $hooks = [
        'website' => BP . 'app/code/Weline/Seo/view/hooks/Weline_Websites/backend/website/form/sections-after.phtml',
        'store' => BP . 'app/code/Weline/Seo/view/hooks/Weline_Websites/backend/store/form/sections-after.phtml',
        'channel' => BP . 'app/code/Weline/Seo/view/hooks/Weline_Websites/backend/channel/form/sections-after.phtml',
    ];
    foreach ($hooks as $scope => $file) {
        accept_assert(is_file($file), $scope . ' hook missing: ' . $file);
        $source = (string) file_get_contents($file);
        accept_assert(str_contains($source, 'Weline_Seo'), $scope . ' hook must belong to Weline_Seo');
        accept_assert(str_contains($source, 'merchant'), $scope . ' hook must render merchant section');
        accept_assert(
            str_contains($source, 'merchant-info-section.phtml') || str_contains($source, MERCHANT_GROUP),
            $scope . ' hook must fetch the shared merchant section or embed ' . MERCHANT_GROUP
        );
    }

    $partial = BP . 'app/code/Weline/Seo/view/templates/backend/partials/merchant-info-section.phtml';
    accept_assert(is_file($partial), 'shared merchant section partial missing: ' . $partial);
    $partialSource = (string) file_get_contents($partial);
    accept_assert(str_contains($partialSource, MERCHANT_GROUP), 'merchant partial does not embed ' . MERCHANT_GROUP);
    accept_assert(str_contains($partialSource, 'target_scope'), 'merchant partial must lock target_scope');
    accept_assert(str_contains($partialSource, 'ConfigEmbedRenderer'), 'merchant partial must use ConfigEmbedRenderer');
    accept_assert(str_contains($partialSource, 'resolveScopes'), 'merchant partial must show effective inherited values');

    // 默认站/默认店/默认渠道主键都是 0：渠道页不能用 SiteContactScopeMapper 判身份（store_id=0 会返回失败）。
    $channelHook = (string) file_get_contents($hooks['channel']);
    accept_assert(
        str_contains($channelHook, 'SystemConfigTargetScopeService'),
        'channel hook must resolve channel scope via SystemConfigTargetScopeService (default store_id=0)'
    );
    accept_assert(
        !str_contains($channelHook, 'SiteContactScopeMapper'),
        'channel hook must not depend on SiteContactScopeMapper (fails for default store_id=0)'
    );
};

$checkResolver = static function (): void {
    $class = 'Weline\Seo\Service\Merchant\MerchantInfoConfigResolver';
    accept_assert(class_exists($class), 'merchant info resolver missing');
    $reflection = new ReflectionClass($class);
    accept_assert(($reflection->getConstructor()?->getNumberOfParameters() ?? 0) === 0, 'resolver ctor must be parameterless');
    accept_assert($reflection->hasMethod('resolve'), 'resolver resolve() missing');
    $resolver = new $class();
    $info = $resolver->resolve('default.default.default');
    accept_assert(is_array($info), 'resolver must return array');
    foreach (['return_policy_url', 'shipping_policy_url', 'return_days', 'return_fees', 'return_method', 'country', 'brand', 'name', 'email', 'phone', 'address'] as $key) {
        accept_assert(array_key_exists($key, $info), 'resolver result missing ' . $key);
    }
    accept_assert($info['return_policy_url'] === '/policy/refund', 'default return policy url must be /policy/refund');
    accept_assert($info['shipping_policy_url'] === '/policy/shipping', 'default shipping policy url must be /policy/shipping');
    accept_assert((int) $info['return_days'] === 30, 'default return days must be 30');
    accept_assert($info['return_fees'] === 'buyer_pays', 'default return fees must be buyer_pays');
    accept_assert($info['return_method'] === 'mail', 'default return method must be mail');
    accept_assert($info['country'] === 'WW', 'default country must be WW');

    accept_assert($reflection->hasMethod('resolveScopes'), 'resolver resolveScopes() missing');
    $chained = $resolver->resolveScopes(['default.__store__.__channel__', 'default.__website__.default', 'default.default.default']);
    accept_assert(is_array($chained), 'resolveScopes must return array');
    foreach (['return_days', 'return_policy_url', 'country'] as $key) {
        accept_assert(array_key_exists($key, $chained), 'resolveScopes result missing ' . $key);
    }
};

$checkFacts = static function (): void {
    $class = 'Weline\Seo\Service\Head\ProductCommerceFactsResolver';
    accept_assert(class_exists($class), 'commerce facts resolver missing');
    $reflection = new ReflectionClass($class);
    accept_assert(($reflection->getConstructor()?->getNumberOfParameters() ?? 0) === 0, 'commerce resolver ctor must be parameterless');
    $resolver = new $class();
    $facts = $resolver->resolve(
        ['shipping_profile_code' => 'SEED_PROFILE_GENERAL'],
        ['website_id' => 0, 'currency' => 'USD', 'canonical_url' => 'https://example.com/product/x']
    );
    accept_assert(is_array($facts), 'commerce facts must be array');
    $policy = $facts['merchant_return_policy'] ?? null;
    accept_assert(is_array($policy), 'merchant_return_policy missing (shipping commerce policy should resolve)');
    foreach (['@type', 'applicableCountry', 'returnPolicyCategory', 'merchantReturnDays', 'returnFees', 'returnMethod', 'merchantReturnLink'] as $key) {
        accept_assert(array_key_exists($key, $policy), 'merchant_return_policy missing ' . $key);
    }
    accept_assert((int) $policy['merchantReturnDays'] > 0, 'merchantReturnDays must be positive');
    accept_assert(str_starts_with((string) $policy['merchantReturnLink'], 'http'), 'merchantReturnLink must be absolute url');
};

$checkLive = static function (): void {
    $url = 'https://p05113ef3.test.weline.com:9555/en_US/product/huazhaoji-56b9d491-t1473-sd2a5';
    $secretFile = BP . 'generated/framework/fpc_pierce_secret';
    $secret = is_file($secretFile) ? trim((string) file_get_contents($secretFile)) : '';
    $requestUrl = $url . '?_wb_nc=' . time() . ($secret !== '' ? '&w_fpc_pierce=' . rawurlencode($secret) : '');

    $body = '';
    $status = 0;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
        ]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 200 && $body !== '') {
            break;
        }
        usleep(500000);
    }
    accept_assert($status === 200, 'live PDP status ' . $status . ' for ' . $requestUrl);

    preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#s', $body, $matches);
    accept_assert(($matches[1] ?? []) !== [], 'live PDP has no JSON-LD block');
    $graph = null;
    foreach ($matches[1] as $raw) {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded) && isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            $graph = $decoded['@graph'];
            break;
        }
    }
    accept_assert(is_array($graph), 'live PDP JSON-LD @graph missing');

    $product = null;
    $organization = null;
    foreach ($graph as $node) {
        if (!is_array($node)) {
            continue;
        }
        if (in_array($node['@type'] ?? '', ['ProductGroup', 'Product'], true) && $product === null) {
            $product = $node;
        }
        if (($node['@type'] ?? '') === 'OnlineStore') {
            $organization = $node;
        }
    }
    accept_assert(is_array($product), 'live PDP Product node missing');
    accept_assert(isset($product['brand']) && trim((string) ($product['brand']['name'] ?? '')) !== '', 'live PDP product brand missing');
    accept_assert(is_array($organization), 'live PDP OnlineStore node missing');
    // 电话可能未配置（不编造号码）：存在时必须是字符串；邮箱与地址必须有。
    foreach (['email', 'address'] as $key) {
        accept_assert(!empty($organization[$key]), 'live PDP organization missing ' . $key);
    }
    if (array_key_exists('telephone', $organization)) {
        accept_assert(trim((string) $organization['telephone']) !== '', 'live PDP organization telephone empty');
    }

    $offers = $product['offers'] ?? null;
    accept_assert(is_array($offers), 'live PDP offers missing');
    $offerList = isset($offers['offers']) && is_array($offers['offers']) ? $offers['offers'] : [$offers];
    accept_assert($offerList !== [], 'live PDP offer list empty');
    foreach ($offerList as $offer) {
        accept_assert(is_array($offer), 'live PDP offer invalid');
        $policy = $offer['hasMerchantReturnPolicy'] ?? null;
        accept_assert(is_array($policy), 'live PDP offer missing hasMerchantReturnPolicy');
        accept_assert(($policy['@type'] ?? '') === 'MerchantReturnPolicy', 'live PDP return policy @type invalid');
        accept_assert((int) ($policy['merchantReturnDays'] ?? 0) > 0, 'live PDP merchantReturnDays missing');
        accept_assert(!empty($policy['returnFees']), 'live PDP returnFees missing');
        accept_assert(!empty($policy['returnMethod']), 'live PDP returnMethod missing');
        accept_assert(str_starts_with((string) ($policy['merchantReturnLink'] ?? ''), 'http'), 'live PDP merchantReturnLink missing');
        $shipping = $offer['shippingDetails'] ?? null;
        accept_assert(is_array($shipping), 'live PDP offer missing shippingDetails');
        accept_assert(isset($shipping['shippingDestination']), 'live PDP shippingDetails.shippingDestination missing');
        accept_assert(isset($shipping['shippingRate']), 'live PDP shippingDetails.shippingRate missing');
    }
};

if ($mode === '--config-only') {
    $checkConfig();
    echo "ACCEPT CONFIG PASS\n";
    exit(0);
}
if ($mode === '--hooks-only') {
    $checkHooks();
    echo "ACCEPT HOOKS PASS\n";
    exit(0);
}
if ($mode === '--live') {
    $checkLive();
    echo "ACCEPT LIVE PASS\n";
    exit(0);
}

$checkConfig();
$checkHooks();
$checkResolver();
$checkFacts();
$checkLive();
echo "ACCEPT ALL PASS\n";
exit(0);
