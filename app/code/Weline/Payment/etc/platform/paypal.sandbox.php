<?php

declare(strict_types=1);

/**
 * 平台级 PayPal Sandbox REST App 凭据（由运维/环境注入，勿提交 Secret 到仓库）。
 *
 * 优先使用环境变量：
 * - WELINE_PAYPAL_SANDBOX_CLIENT_ID
 * - WELINE_PAYPAL_SANDBOX_CLIENT_SECRET
 */
return [
    'client_id' => (string) (getenv('WELINE_PAYPAL_SANDBOX_CLIENT_ID') ?: ''),
    'client_secret' => (string) (getenv('WELINE_PAYPAL_SANDBOX_CLIENT_SECRET') ?: ''),
];
