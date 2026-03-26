<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\WarmupUrl;
use Weline\Cdn\Observer\WarmupSend;
use Weline\Cdn\Service\UrlSiteResolver;
use Weline\Framework\Event\Event;

class WarmupSendTest extends TestCase
{
    private WarmupSend $observer;
    private WarmupUrl $warmupUrlModel;
    private UrlSiteResolver $urlSiteResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warmupUrlModel = $this->getMockBuilder(WarmupUrl::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'setData', 'save'])
            ->addMethods(['reset', 'where', 'find', 'fetch'])
            ->getMock();

        $this->urlSiteResolver = $this->createMock(UrlSiteResolver::class);
        $this->observer = new WarmupSend($this->warmupUrlModel, $this->urlSiteResolver);
    }

    public function testObserverInstantiation(): void
    {
        $this->assertInstanceOf(WarmupSend::class, $this->observer);
    }

    public function testExecuteModuleEmpty(): void
    {
        $event = new Event('Weline_Cdn::send_warmup', []);
        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    public function testExecuteUrlsEmpty(): void
    {
        $event = new Event('Weline_Cdn::send_warmup', ['module' => 'TestModule']);
        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    public function testExecuteUrlsNotArray(): void
    {
        $event = new Event('Weline_Cdn::send_warmup', [
            'module' => 'TestModule',
            'urls' => 'not-an-array',
        ]);
        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    public function testExecuteStringUrls(): void
    {
        $this->configureInsertFlow();
        $this->urlSiteResolver->method('resolveDomainByUrl')->willReturn(null);

        $event = new Event('Weline_Cdn::send_warmup', [
            'module' => 'TestModule',
            'urls' => ['https://example.com/page1', 'https://example.com/page2'],
        ]);

        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['inserted_count']);
        $this->assertSame(0, $result['updated_count']);
    }

    public function testExecuteArrayUrls(): void
    {
        $this->configureInsertFlow();

        $event = new Event('Weline_Cdn::send_warmup', [
            'module' => 'TestModule',
            'urls' => [
                [
                    'url' => 'https://example.com/page1',
                    'site_id' => 1,
                    'domain_id' => 1,
                ],
            ],
        ]);

        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['inserted_count']);
    }

    public function testExecuteDedupe(): void
    {
        $existingUrl = $this->getMockBuilder(WarmupUrl::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'setData', 'save'])
            ->getMock();
        $existingUrl->method('getData')->willReturnCallback(
            static fn(string $field) => $field === WarmupUrl::schema_fields_WARMUP_URL_ID ? 1 : null
        );
        $existingUrl->method('setData')->willReturnSelf();
        $existingUrl->method('save')->willReturn(true);

        $this->warmupUrlModel->method('reset')->willReturnSelf();
        $this->warmupUrlModel->method('where')->willReturnSelf();
        $this->warmupUrlModel->method('find')->willReturnSelf();
        $this->warmupUrlModel->method('fetch')->willReturn($existingUrl);

        $event = new Event('Weline_Cdn::send_warmup', [
            'module' => 'TestModule',
            'urls' => ['https://example.com/page'],
            'dedupe' => true,
        ]);

        $this->observer->execute($event);

        $result = $event->getData('result');
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['inserted_count']);
        $this->assertSame(1, $result['updated_count']);
    }

    private function configureInsertFlow(): void
    {
        $this->warmupUrlModel->method('reset')->willReturnSelf();
        $this->warmupUrlModel->method('where')->willReturnSelf();
        $this->warmupUrlModel->method('find')->willReturnSelf();
        $this->warmupUrlModel->method('fetch')->willReturnSelf();
        $this->warmupUrlModel->method('getData')->willReturn(null);
        $this->warmupUrlModel->method('setData')->willReturnSelf();
        $this->warmupUrlModel->method('save')->willReturn(true);
    }
}
