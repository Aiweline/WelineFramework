<?php

declare(strict_types=1);

namespace Weline\Api\test\Unit\Setup;

use PHPUnit\Framework\TestCase;

final class ApiBootstrapCredentialTest extends TestCase
{
    public function testInstallSeedDoesNotCreateEnabledKnownDefaultApiCredential(): void
    {
        $installSource = (string) file_get_contents(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Setup' . DIRECTORY_SEPARATOR . 'Install.php'
        );

        self::assertStringContainsString("->setUsername('admin')", $installSource);
        self::assertStringNotContainsString("->setPassword('admin')", $installSource);
        self::assertStringContainsString('random_bytes(32)', $installSource);
        self::assertStringContainsString('->setIsEnabled(false)', $installSource);
        self::assertStringNotContainsString('->setIsEnabled(true)', $installSource);
    }

    public function testAuthLoginRejectsDisabledApiUsersBeforeMintingTokens(): void
    {
        $authSource = (string) file_get_contents(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . 'Rest'
            . DIRECTORY_SEPARATOR . 'V1' . DIRECTORY_SEPARATOR . 'Auth.php'
        );

        $disabledCheckPosition = strpos($authSource, 'if (!$user->getIsEnabled())');
        $tokenGenerationPosition = strpos($authSource, 'generateAccessToken($user');

        self::assertIsInt($disabledCheckPosition);
        self::assertIsInt($tokenGenerationPosition);
        self::assertLessThan($tokenGenerationPosition, $disabledCheckPosition);
    }
}
