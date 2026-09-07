<?php
declare(strict_types=1);
namespace Weline\Seo\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Seo\Observer\ScopeSaveAfter;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\SitemapRefreshService;
use Weline\Seo\Service\UrlSubmitService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class ScopeSaveAfterTest extends TestCase
{
    public function testDevelopmentStoreRefreshesVisibilityWithoutUrlSubmission(): void
    {
        $refresh = $this->createMock(SitemapRefreshService::class);
        $refresh->expects(self::once())->method('enqueue')->with(0, '');
        $submissions = $this->createMock(UrlSubmitService::class);
        $submissions->expects(self::never())->method('enqueueTargets');
        $observer = new ScopeSaveAfter($this->createMock(SeoWebsiteDirectory::class),
            $this->createMock(StoreCatalogInterface::class), $refresh, $submissions);
        $event = new Event('Weline_Websites::store_save_after', ['website_id' => 0,
            'store' => ['store_id' => 0, 'store_mode' => 'dev', 'url' => 'https://dev.example.test']]);
        $observer->execute($event);
    }

    public function testDefaultStoreChangesQueueSitemapWithoutSearchEngineAccounts(): void
    {
        $directory = $this->createMock(SeoWebsiteDirectory::class);
        $directory->method('getWebsiteById')->with(0)->willReturn(['website_id' => 0]);
        $directory->method('effectivePublicBaseUrl')->willReturn('https://example.test');
        $refresh = $this->createMock(SitemapRefreshService::class);
        $refresh->expects(self::once())->method('enqueue')->with(0, '');
        $submissions = $this->createMock(UrlSubmitService::class);
        $submissions->expects(self::exactly(2))->method('enqueueTargets')->willReturnCallback(function ($targets, $scope, $context) {
            self::assertSame('store', $scope);
            self::assertSame(0, $targets[0]['website_id']);
            self::assertSame(0, $context['subject_id']);
            self::assertSame(str_contains($targets[0]['url'], 'old') ? 'delete' : 'upsert', $context['action']);
            return ['skipped_unbound' => 1];
        });
        $observer = new ScopeSaveAfter($directory, $this->createMock(StoreCatalogInterface::class), $refresh, $submissions);
        $event = new Event('Weline_Websites::store_save_after', [
            'website_id' => 0, 'store' => ['store_id' => 0, 'url' => 'https://shop.example.test', 'enabled' => true],
            'before' => ['store_id' => 0, 'url' => 'https://old.example.test'],
        ]);
        $observer->execute($event);
    }
}
