<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Event\EventRegistry;
use Weline\Framework\Event\EventScanner;

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('CLI')) {
    define('CLI', true);
}
if (!defined('PROD')) {
    define('PROD', false);
}
require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Framework/func_log.php';

final class EventRegistryPersistGuardTest extends TestCase
{
    public function testRefuseWipeWhenExistingFileHasObservers(): void
    {
        $registry = $this->newRegistry();
        $existing = [
            'events' => [
                'Weline_Framework_Controller::fetch_file_before' => [
                    'observers' => [
                        ['name' => 'theme_before', 'instance' => 'Weline\\Theme\\Observer\\ControllerFetchFileBefore'],
                    ],
                ],
            ],
            'dynamic_patterns' => [],
        ];
        $incoming = [
            'events' => [
                'Weline_Framework_Controller::fetch_file_before' => [
                    'observers' => [],
                ],
            ],
            'dynamic_patterns' => [],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/persist refused.*keeping existing/');
        $registry->assertRegistryReadyToPersist($incoming, $existing);
    }

    public function testAllowFirstInstallWithoutExistingFile(): void
    {
        $registry = $this->newRegistry();
        $incoming = [
            'events' => [],
            'dynamic_patterns' => [],
        ];
        // Explicit empty prior snapshot (do not read live generated/events.php).
        $registry->assertRegistryReadyToPersist($incoming, ['events' => [], 'dynamic_patterns' => []]);
        self::assertSame(0, EventRegistry::countEventsWithObservers($incoming));
    }

    public function testAllowHealthyReplaceWithObservers(): void
    {
        $registry = $this->newRegistry();
        $existing = [
            'events' => [
                'a' => ['observers' => [['name' => 'old']]],
            ],
        ];
        $incoming = [
            'events' => [
                'a' => ['observers' => [['name' => 'new']]],
                'b' => ['observers' => [['name' => 'other']]],
            ],
        ];
        $registry->assertRegistryReadyToPersist($incoming, $existing);
        self::assertSame(2, EventRegistry::countEventsWithObservers($incoming));
    }

    public function testRefuseMissingEventsSection(): void
    {
        $registry = $this->newRegistry();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing events section/');
        $registry->assertRegistryReadyToPersist(['dynamic_patterns' => []], ['events' => []]);
    }

    private function newRegistry(): EventRegistry
    {
        return new EventRegistry(
            $this->createMock(EventScanner::class),
            $this->createMock(XmlReader::class),
        );
    }
}
