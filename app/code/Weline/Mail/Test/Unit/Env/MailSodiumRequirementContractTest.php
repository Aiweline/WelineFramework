<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Env;

use PHPUnit\Framework\TestCase;

/**
 * Mail env:install must require sodium so Stalwart credential sealing cannot be skipped.
 */
final class MailSodiumRequirementContractTest extends TestCase
{
    public function testMailRequirementsDeclareSodiumExtension(): void
    {
        $req = require dirname(__DIR__, 3) . '/env/requirements.php';
        self::assertIsArray($req);
        self::assertContains('sodium', $req['extensions'] ?? []);
    }

    public function testManagementAdapterFailsClosedWithoutSodium(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StalwartManagementAdapter.php',
        );
        self::assertStringContainsString('sodium_crypto_secretbox', $src);
        self::assertStringContainsString('PHP Sodium 扩展不可用', $src);
    }
}
