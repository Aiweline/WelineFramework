<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Admin\Api\BackendMenuSignalItemsProviderInterface;
use Weline\Admin\Api\BackendMenuSignalProviderInterface;
use Weline\Inquiry\Extends\Module\Weline_Admin\BackendMenuSignalProvider\InquirySubmissionMenuSignalProvider;
use Weline\Inquiry\Service\SubmissionMenuSignalPublisher;

/**
 * 询盘菜单未读信号：provider 契约与菜单 source 对齐。
 */
final class InquiryMenuSignalProviderTest extends TestCase
{
    public function testProviderTargetsInquirySubmissionsMenu(): void
    {
        $provider = new InquirySubmissionMenuSignalProvider();

        self::assertSame('Weline_Inquiry::submissions', $provider->code());
        self::assertSame(0, $provider->count(0));
        self::assertSame([], $provider->items(0));
    }

    public function testProviderImplementsMenuSignalContracts(): void
    {
        $provider = new InquirySubmissionMenuSignalProvider();

        self::assertInstanceOf(BackendMenuSignalProviderInterface::class, $provider);
        self::assertInstanceOf(BackendMenuSignalItemsProviderInterface::class, $provider);
    }

    public function testProviderCodeMatchesBackendMenuXml(): void
    {
        $menuXml = (string)file_get_contents(
            '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry/etc/backend/menu.xml'
        );

        self::assertStringContainsString(
            'source="' . InquirySubmissionMenuSignalProvider::MENU_SOURCE_ID . '"',
            $menuXml
        );
    }

    public function testPublisherExposesPublishMarkReadAndHref(): void
    {
        self::assertTrue(class_exists(SubmissionMenuSignalPublisher::class));

        foreach (['publish', 'markRead', 'href'] as $method) {
            self::assertTrue(
                method_exists(SubmissionMenuSignalPublisher::class, $method),
                'SubmissionMenuSignalPublisher::' . $method . '() missing'
            );
        }
    }
}
