<?php

declare(strict_types=1);

namespace Weline\Hook\Test\Unit;

use PHPUnit\Framework\TestCase;

final class HookDocumentationPathsTest extends TestCase
{
    public function testAllHookDocumentationFilesExist(): void
    {
        $projectRoot = dirname(__DIR__, 6);
        $hookFiles = glob($projectRoot . '/app/code/*/*/hook.php');

        self::assertIsArray($hookFiles);

        sort($hookFiles);

        $missingDocs = [];

        foreach ($hookFiles as $hookFile) {
            $content = file_get_contents($hookFile);
            self::assertNotFalse($content, sprintf('Unable to read hook config: %s', $hookFile));

            preg_match_all("/'doc'\\s*=>\\s*'([^']+)'/", $content, $matches);

            foreach ($matches[1] as $docPath) {
                $normalizedDocPath = preg_replace('~[\\\\/]~', DIRECTORY_SEPARATOR, $docPath);
                self::assertIsString($normalizedDocPath);

                $docFile = dirname($hookFile) . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . 'hook' . DIRECTORY_SEPARATOR . $normalizedDocPath;

                if (!is_file($docFile)) {
                    $missingDocs[] = sprintf('%s -> %s', $hookFile, $docFile);
                }
            }
        }

        self::assertSame([], $missingDocs, "Missing hook documentation files:\n" . implode("\n", $missingDocs));
    }
}
