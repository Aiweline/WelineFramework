<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * The legacy async Worker/Protocol stack is deprecated and must have no live
 * callers outside its own cluster. Removal is a follow-up release step.
 */
final class LegacyAsyncWorkerStackDeprecationTest extends TestCase
{
    private const CLUSTER = [
        'Weline/Server/Worker.php',
        'Weline/Server/Service/HttpServer.php',
        'Weline/Server/Protocol/Http.php',
        'Weline/Server/Protocol/Text.php',
        'Weline/Server/Protocol/WebSocket.php',
        'Weline/Server/Protocol/ProtocolInterface.php',
        'Weline/Server/Protocol/Request.php',
        'Weline/Server/Protocol/Response.php',
        'Weline/Server/Connection/TcpConnection.php',
        'Weline/Server/Event/Event.php',
        'Weline/Server/Event/Select.php',
    ];

    private const NEEDLES = [
        'Weline\\Server\\Worker',
        'Weline\\Server\\Service\\HttpServer',
        'Weline\\Server\\Protocol\\Http',
        'Weline\\Server\\Protocol\\Text',
        'Weline\\Server\\Protocol\\WebSocket',
        'Weline\\Server\\Connection\\TcpConnection',
        'Weline\\Server\\Event\\Event',
        'Weline\\Server\\Event\\Select',
    ];

    public function testClusterIsExplicitlyDeprecated(): void
    {
        $root = \dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR;
        foreach (self::CLUSTER as $relative) {
            $source = (string)\file_get_contents($root . \str_replace('/', \DIRECTORY_SEPARATOR, $relative));
            self::assertStringContainsString('@deprecated', $source, $relative . ' must be marked deprecated.');
        }
    }

    public function testNoLiveReferencesOutsideTheDeprecatedCluster(): void
    {
        $root = \dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR;
        $allowed = \array_flip(\array_map(
            static fn(string $path): string => \str_replace('/', \DIRECTORY_SEPARATOR, $path),
            self::CLUSTER,
        ));

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = \str_replace($root, '', $file->getPathname());
            if (\str_contains($relative, 'Test' . \DIRECTORY_SEPARATOR)
                || \str_contains($relative, 'test' . \DIRECTORY_SEPARATOR)
                || isset($allowed[$relative])
            ) {
                continue;
            }
            $source = (string)\file_get_contents($file->getPathname());
            foreach (self::NEEDLES as $needle) {
                // Boundary-aware: Protocol\Http must not match Http2/Http3, and
                // Event\Event must not match Event\EventInterface.
                if (\preg_match('/' . \preg_quote($needle, '/') . '(?![A-Za-z0-9_])/', $source) === 1) {
                    $violations[] = $relative . ' -> ' . $needle;
                    break;
                }
            }
        }

        self::assertSame([], $violations, "Legacy async stack still has live references:\n" . \implode("\n", $violations));
    }
}
