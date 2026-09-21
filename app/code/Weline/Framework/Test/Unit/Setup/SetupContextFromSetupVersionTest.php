<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

final class SetupContextFromSetupVersionTest extends TestCase
{
    public function testContextDefinesFromSetupVersionApi(): void
    {
        $path = dirname(__DIR__, 3) . '/Setup/Data/Context.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('string $from_setup_version = \'0.0.0\'', $src);
        self::assertStringContainsString('function getFromSetupVersion(): string', $src);
        self::assertStringContainsString("\$from_setup_version !== '' ? \$from_setup_version : '0.0.0'", $src);
    }

    public function testHandleInjectsFromSetupVersionIntoContext(): void
    {
        $path = dirname(__DIR__, 3) . '/Module/Handle.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('resolveFromSetupVersion(', $src);
        self::assertStringContainsString("'from_setup_version' => \$from_setup", $src);
        self::assertStringContainsString("'from_setup_version' => \$this->resolveFromSetupVersion(\$module)", $src);
    }
}
