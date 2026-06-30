<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;

final class LoggingContractTest extends TestCase
{
    public function testEnvLogErrorCallsUseStringMessageContract(): void
    {
        $moduleRoot = realpath(__DIR__ . '/../..');
        $this->assertIsString($moduleRoot);

        $files = [
            'Setup/Install.php',
            'Observer/ModuleInstalledObserver.php',
            'Observer/DownloadCompleteObserver.php',
        ];

        foreach ($files as $file) {
            $source = file_get_contents($moduleRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
            $this->assertIsString($source);

            foreach ($this->extractEnvLogErrorArguments($source) as $arguments) {
                $this->assertCount(2, $arguments, $file . ' Env::log_error must receive filename and message only.');

                $filename = ltrim($arguments[0]);
                $message = ltrim($arguments[1]);

                $this->assertStringStartsWith("'appstore/", $filename, $file . ' must use an AppStore log channel string.');
                $this->assertFalse(str_starts_with($message, '['), $file . ' must not pass an array as the log message.');
                $this->assertFalse(str_starts_with(strtolower($message), 'array('), $file . ' must not pass array() as the log message.');
            }
        }
    }

    /**
     * @return list<list<string>>
     */
    private function extractEnvLogErrorArguments(string $source): array
    {
        $calls = [];
        $offset = 0;

        while (($position = strpos($source, 'Env::log_error(', $offset)) !== false) {
            $openParen = strpos($source, '(', $position);
            if ($openParen === false) {
                break;
            }

            $calls[] = $this->splitTopLevelArguments($source, $openParen);
            $offset = $openParen + 1;
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelArguments(string $source, int $openParen): array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($index = $openParen + 1; $index < $length; $index++) {
            $char = $source[$index];

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $index + 1 < $length) {
                    $current .= $source[++$index];
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
                $current .= $char;
                continue;
            }

            if ($char === ')' || $char === ']') {
                if ($depth === 0 && $char === ')') {
                    $arguments[] = trim($current);
                    return $arguments;
                }
                $depth--;
                $current .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        return $arguments;
    }
}
