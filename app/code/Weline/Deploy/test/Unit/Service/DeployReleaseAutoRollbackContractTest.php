<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: release failure after Git mutation must auto-restore pre-release commit.
 */
final class DeployReleaseAutoRollbackContractTest extends TestCase
{
    public function testOrchestratorCatchPathInvokesAutoRollbackHelper(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/DeployOrchestratorService.php'
        );

        self::assertStringContainsString('autoRollbackAfterReleaseFailure', $source);
        self::assertStringContainsString("'auto_rollback'", $source);
        self::assertStringContainsString('restoreLocalCommit', $source);
        self::assertStringContainsString('$gitUpdateStarted = true', $source);
        self::assertStringContainsString('$versionStampWritten = true', $source);
        self::assertStringContainsString('isProductionDeploy', $source);
        self::assertStringContainsString('开发环境跳过发布失败自动回滚', $source);
    }

    public function testGitMetadataSupportsLocalRestoreWithoutFetch(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/DeployGitMetadataService.php'
        );
        $method = $this->extractMethod($source, 'restoreLocalCommit');

        self::assertNotSame('', $method);
        self::assertStringNotContainsString('->fetch(', $method);
        self::assertStringContainsString("'checkout', '-B'", $method);
        self::assertStringContainsString("'--detach'", $method);
    }

    private function extractMethod(string $source, string $name): string
    {
        $start = strpos($source, 'public function ' . $name . '(');
        self::assertNotFalse($start, 'method ' . $name . ' not found');

        $brace = strpos($source, '{', $start);
        self::assertNotFalse($brace);

        $depth = 0;
        $length = strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail('unclosed method ' . $name);
    }
}
