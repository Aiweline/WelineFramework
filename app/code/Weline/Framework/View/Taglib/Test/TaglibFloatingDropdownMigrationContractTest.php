<?php

declare(strict_types=1);

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter;

final class TaglibFloatingDropdownMigrationContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function migratedTaglibPaths(): array
    {
        $root = dirname(__DIR__, 4);

        return [
            $root . '/Websites/Taglib/SearchableCodeSelect.php',
            $root . '/Websites/Taglib/WebsiteSelect.php',
            $root . '/Websites/Taglib/DomainSelect.php',
            $root . '/Websites/Taglib/RegistrarSelect.php',
            $root . '/ModuleManager/Taglib/ModuleSelect.php',
            $root . '/Acl/Taglib/TagSelect.php',
        ];
    }

    public function testMigratedSelectTaglibsUseEmitterNotThemeSmartDropdown(): void
    {
        foreach ($this->migratedTaglibPaths() as $path) {
            self::assertFileExists($path, $path);
            $content = (string) file_get_contents($path);

            self::assertStringContainsString(
                'FloatingDropdownEmitter::script()',
                $content,
                $path
            );
            self::assertStringContainsString(
                FloatingDropdownEmitter::GLOBAL_NAME,
                $content,
                $path
            );
            self::assertStringNotContainsString(
                'window.WelineSmartDropdown',
                $content,
                $path
            );
            self::assertStringNotContainsString(
                "if (!window.WelineSmartDropdown)",
                $content,
                $path
            );
        }
    }
}
