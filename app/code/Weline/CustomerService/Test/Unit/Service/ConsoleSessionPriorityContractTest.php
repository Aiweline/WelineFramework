<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\ChatService;

final class ConsoleSessionPriorityContractTest extends TestCase
{
    public function testSortPutsLongestUnreadWaitFirstThenNewest(): void
    {
        $ref = new \ReflectionClass(ChatService::class);
        /** @var ChatService $service */
        $service = $ref->newInstanceWithoutConstructor();

        $sorted = $service->sortConsoleSessionsByPriority([
            [
                'session_id' => 4,
                'unread_count' => 0,
                'last_message_at' => '2026-09-09T17:00:00+08:00',
                'waiting_since_at' => null,
            ],
            [
                'session_id' => 12,
                'unread_count' => 1,
                'last_message_at' => '2026-09-09T17:30:00+08:00',
                'waiting_since_at' => '2026-09-09T17:29:00+08:00',
            ],
            [
                'session_id' => 6,
                'unread_count' => 2,
                'last_message_at' => '2026-09-09T17:35:00+08:00',
                'waiting_since_at' => '2026-09-09T15:00:00+08:00',
            ],
        ]);

        self::assertSame([6, 12, 4], array_map(static fn(array $row): int => (int)$row['session_id'], $sorted));
    }

    public function testFrontendContractsDefaultSelectAndIncomingFeedback(): void
    {
        $base = dirname(__DIR__, 3);
        $js = (string)file_get_contents($base . '/view/statics/js/backend-console.js');
        $css = (string)file_get_contents($base . '/view/statics/css/backend-console.css');
        $ctrl = (string)file_get_contents($base . '/Controller/Backend/Console.php');
        $service = (string)file_get_contents($base . '/Service/ChatService.php');
        $proto = (string)file_get_contents($base . '/view/statics/prototype/console-session-priority-logic.html');
        $tpl = (string)file_get_contents($base . '/view/templates/Backend/Console/index.phtml');

        self::assertStringContainsString('autoSelectPreferredSession', $js);
        self::assertStringContainsString('notifyIncomingMessage', $js);
        self::assertStringContainsString('sortSessionsByPriority', $js);
        self::assertStringContainsString('cs-session-alert', $css);
        self::assertStringContainsString('defaultSessionId', $ctrl);
        self::assertStringContainsString('defaultSessionId', $tpl);
        self::assertStringContainsString('sortConsoleSessionsByPriority', $service);
        self::assertStringContainsString('enrichConsoleSessionRows', $service);
        self::assertStringContainsString('consoleSessionListTitle', $service);
        self::assertStringContainsString('customer_display_name', $service);
        self::assertStringContainsString('sessionListTitle', $js);
        self::assertStringContainsString('customer_kind', $tpl);
        self::assertStringContainsString('list_title', $tpl);
        self::assertStringContainsString('等待最久', $proto);
    }

    public function testConsoleSessionListTitlePrefersCustomerName(): void
    {
        $ref = new \ReflectionClass(ChatService::class);
        /** @var ChatService $service */
        $service = $ref->newInstanceWithoutConstructor();

        self::assertSame('张三', $service->consoleSessionListTitle([
            'session_id' => 6,
            'customer_kind' => 'customer',
            'customer_display_name' => '张三',
        ]));
        self::assertSame('#6', $service->consoleSessionListTitle([
            'session_id' => 6,
            'customer_kind' => 'guest',
            'customer_display_name' => 'guest@example.com',
        ]));
        self::assertSame('#6', $service->consoleSessionListTitle([
            'session_id' => 6,
            'customer_kind' => 'customer',
            'customer_display_name' => '',
        ]));
    }
}
