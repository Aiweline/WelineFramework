<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * PayPal 沙箱 OAuth PKCE 辅助（平台 App 可仅内置 client_id）。
 */
final class PayPalOAuthPkceHelper
{
    /**
     * @return array{code_verifier:string,code_challenge:string,method:string}
     */
    public static function createPair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            'code_verifier' => $verifier,
            'code_challenge' => $challenge,
            'method' => 'S256',
        ];
    }
}
