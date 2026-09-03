<?php

declare(strict_types=1);

/**
 * Weline 框架内置 PayPal Sandbox 平台 REST App（local/dev 自动 fallback）。
 *
 * 由 Weline 官方在 PayPal Developer 创建一次 Sandbox Platform REST App 后写入此处；
 * App 须登记唯一 OAuth Return URL，例如：
 *   https://{website}.weline.test:9555/payment/frontend/callback/paypal
 *
 * 生产/预发必须通过 app/etc/env.php、环境变量或 app/etc/payment/paypal.platform.sandbox.php 覆盖。
 */
return [
    'client_id' => '',
    'client_secret' => '',
];
