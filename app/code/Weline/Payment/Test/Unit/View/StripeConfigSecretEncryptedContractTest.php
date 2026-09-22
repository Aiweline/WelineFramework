<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class StripeConfigSecretEncryptedContractTest extends TestCase
{
    public function testStripeSecretFieldsUseEncryptedValueType(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/stripe.phtml'
        );
        foreach ([
            'sandbox_secret_key',
            'sandbox_webhook_secret',
            'live_secret_key',
            'live_webhook_secret',
        ] as $field) {
            self::assertTrue(
                (bool) preg_match(
                    '/key="payment\/method\/stripe\/' . preg_quote($field, '/') . '"([\s\S]*?)\/>/',
                    $template,
                    $m
                ),
                $field . ' field missing'
            );
            self::assertStringContainsString('value-type="encrypted"', $m[1], $field);
            self::assertStringContainsString('type="secret"', $m[1], $field);
            self::assertStringNotContainsString('value-type="string"', $m[1], $field);
        }
    }
}
