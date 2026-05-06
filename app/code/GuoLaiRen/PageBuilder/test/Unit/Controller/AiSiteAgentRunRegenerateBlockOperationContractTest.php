<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentRunRegenerateBlockOperationContractTest extends TestCase
{
    public function testRunRegenerateBlockOperationDoesNotContainDebugStops(): void
    {
        $controllerPath = (new \ReflectionClass(AiSiteAgent::class))->getFileName();
        self::assertIsString($controllerPath);

        $source = (string)\file_get_contents($controllerPath);
        $method = $this->extractControllerMethodSource($source, 'runRegenerateBlockOperation');

        self::assertStringNotContainsString('dd(', $method);
        self::assertStringNotContainsString('var_dump(', $method);
        self::assertStringNotContainsString('dump(', $method);
        self::assertStringNotContainsString('die(', $method);
        self::assertStringNotContainsString('exit(', $method);
        self::assertStringContainsString('return [', $method);
    }

    private function extractControllerMethodSource(string $source, string $methodName): string
    {
        $needle = 'function ' . $methodName . '(';
        $start = \strpos($source, $needle);
        self::assertNotFalse($start, $methodName . ' method missing.');

        $braceStart = \strpos($source, '{', $start);
        self::assertNotFalse($braceStart, $methodName . ' method body missing.');

        $depth = 0;
        $length = \strlen($source);
        for ($i = $braceStart; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return \substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail($methodName . ' method body is not balanced.');
    }
}
