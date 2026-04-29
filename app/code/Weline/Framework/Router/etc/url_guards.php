<?php

declare(strict_types=1);

/**
 * URL Guard 默认配置（出厂样例）
 *
 * 默认返回空数组（不主动启用任何 guard）。
 * 业务模块可通过 `app/etc/env.php` 的 `router.url_guards` 字段或在自身 `register.php`
 * 里调用 `UrlGuardRegistry::register()` 追加规则。
 *
 * 模板示例（已注释，按需启用）：
 *
 * return [
 *     [
 *         'name' => 'product_id_max',
 *         'config' => [
 *             'pattern' => '#^/(?:[a-z]{2}_[A-Z]{2}/)?product/(?<id>\d+)#',
 *             'param_source' => 'pattern',
 *             'param_name' => 'id',
 *             'min' => 1,
 *             'max' => 1000000,
 *             'reject_status' => 410,
 *             'reject_reason' => 'product_id_overflow',
 *         ],
 *     ],
 *     [
 *         'name' => 'page_param_whitelist',
 *         'config' => [
 *             'pattern' => '#^/category/#',
 *             'param_source' => 'get',
 *             'param_name' => 'sort',
 *             'allow' => ['price_asc', 'price_desc', 'recent', 'hot'],
 *         ],
 *     ],
 * ];
 */

return [];
