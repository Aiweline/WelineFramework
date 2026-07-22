<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;

final class FrontendWorkerSessionServiceTest extends TestCase
{
    public function testStreamUrlUsesTheConfiguredRestFrontendPrefix(): void
    {
        $reflection = new \ReflectionClass(FrontendWorkerSessionService::class);
        /** @var FrontendWorkerSessionService $service */
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildStreamUrl');
        $ticket = 'stream-ticket';

        $url = (string)$method->invoke($service, $ticket);
        $prefix = \trim((string)(Env::getAreaRoutePrefix('rest_frontend') ?: 'api'), '/');
        $prefix = $prefix !== '' ? $prefix : 'api';

        self::assertSame('/' . $prefix . '/framework/stream?ticket=' . $ticket, $url);
    }

    public function testStreamTicketPreservesOwnerBinding(): void
    {
        $service = new FrontendWorkerSessionService();
        $owner = [
            'area' => 'backend',
            'principal' => 'backend:7',
        ];

        $created = $service->createStreamTicket('page_builder.aiSiteStream', ['public_id' => 'site-1'], $owner);
        self::assertSame($owner, $created['owner'] ?? null);

        $consumed = $service->consumeStreamTicket((string)$created['ticket']);
        self::assertSame('page_builder.aiSiteStream', $consumed['channel']);
        self::assertSame(['public_id' => 'site-1'], $consumed['params']);
        self::assertSame($owner, $consumed['owner']);
    }
}
