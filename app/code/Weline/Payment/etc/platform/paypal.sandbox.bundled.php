<?php

declare(strict_types=1);

/**
 * Weline 框架内置 PayPal Sandbox 平台 REST App（可提交，供沙盒支付测试直接跑通）。
 *
 * local/dev 下 PayPalPlatformCredentialService 在没有本机覆盖时使用这里的 Client ID / Secret。
 * 本机 app/etc/payment/paypal.platform.sandbox.php、env.php 或环境变量仍优先。
 * 生产/预发不会使用本文件。
 *
 * 沙盒买家登录见同目录 paypal.sandbox.buyer.json。
 */
return [
    'client_id' => 'ASmwWnmY8Wi6Oz2FoY0yuHb03xss9bf7adHgsL9mS5dvUk1TK4lxJJw0GrQhzerZL9IRix5dh4ZNxXvD',
    'client_secret' => 'EItFCGmqB4czHVGmOQi_TwAd_5gV3ftQ4J-MUKv2E05ze7yQ4obquFnbXJjBi45h-BANefQ3OVz9UGbJ',
];
