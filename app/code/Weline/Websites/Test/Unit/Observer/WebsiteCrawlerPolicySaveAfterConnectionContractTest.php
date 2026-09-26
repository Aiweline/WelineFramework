<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\Event;
use Weline\Websites\Observer\WebsiteCrawlerPolicySaveAfter;
use Weline\Websites\Service\WebsiteCrawlerPolicyService;

/**
 * website_save_after crawler observer must reuse the outer write-intent connection.
 */
final class WebsiteCrawlerPolicySaveAfterConnectionContractTest extends TestCase
{
    public function testSaveForWebsiteReceivesEventConnection(): void
    {
        $connection = $this->createMock(ConnectionFactory::class);
        $service = $this->createMock(WebsiteCrawlerPolicyService::class);
        $service->expects(self::once())
            ->method('saveForWebsite')
            ->with(
                7,
                self::callback(static fn(array $input): bool => ($input['enabled'] ?? null) === '1'),
                $connection,
            )
            ->willReturn(['enabled' => true, 'block_duration' => 0, 'entries' => []]);

        $observer = new WebsiteCrawlerPolicySaveAfter($service);
        $event = new Event([
            'website_id' => 7,
            'post_data' => [
                'extensions' => [
                    'crawler' => ['enabled' => '1', 'entries' => []],
                ],
            ],
            'connection' => $connection,
        ]);
        $observer->execute($event);
    }

    public function testMissingConnectionFailsClosed(): void
    {
        $service = $this->createMock(WebsiteCrawlerPolicyService::class);
        $service->expects(self::never())->method('saveForWebsite');
        $observer = new WebsiteCrawlerPolicySaveAfter($service);
        $event = new Event([
            'website_id' => 7,
            'post_data' => [
                'extensions' => [
                    'crawler' => ['enabled' => '1'],
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $observer->execute($event);
    }

    public function testDispatchPayloadDocumentsConnectionKey(): void
    {
        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Admin/Website.php'
        );
        self::assertStringContainsString("'connection' => \$this->website->getConnection()", $controller);
        self::assertStringContainsString('JSON toast only', $controller);
    }
}
