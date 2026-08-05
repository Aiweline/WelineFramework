<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Cms\Model\PageLocale;
use Weline\Cms\Service\PageLocaleService;
use Weline\Cms\Service\PageService;
use Weline\Cms\Service\PageTranslationPolicy;
use Weline\Cms\Service\PageTranslationTaskProcessor;
use Weline\TranslationService\Api\TranslationServiceInterface;

final class PageTranslationTaskProcessorTest extends TestCase
{
    public function testFreezeInputContainsOnlyMissingWebsiteLocales(): void
    {
        $page = $this->page();
        $pageService = $this->createMock(PageService::class);
        $pageService->method('getPageModel')->with(6)->willReturn($page);
        $localeService = $this->createMock(PageLocaleService::class);
        $localeService->method('buildEditorPayload')->with($page, '')->willReturn($this->payload());
        $translator = $this->createMock(TranslationServiceInterface::class);

        $processor = new PageTranslationTaskProcessor(
            $pageService,
            $localeService,
            new PageTranslationPolicy(),
            $translator,
        );

        self::assertSame(
            [
                'request_id' => 'cms-page-6-translate-1',
                'page_id' => 6,
                'website_id' => 0,
                'source_locale' => 'en_US',
                'source_title' => 'About our team',
                'source_hash' => hash('sha256', 'About our team'),
                'supported_locales' => ['en_US', 'zh_Hans_CN', 'ru_RU'],
                'target_locales' => ['ru_RU'],
            ],
            $processor->freezeInput(6, 'cms-page-6-translate-1'),
        );
    }

    public function testTranslateTargetUsesPublishedTranslationContract(): void
    {
        $translator = $this->createMock(TranslationServiceInterface::class);
        $translator->expects(self::once())
            ->method('batchTranslate')
            ->with(
                ['About our team'],
                'ru_RU',
                'en_US',
                null,
                self::callback(static fn(array $options): bool => ($options['module_name'] ?? '') === 'Weline_Cms'
                    && ($options['operation'] ?? '') === 'cms_page_title'
                    && ($options['page_id'] ?? 0) === 6),
            )
            ->willReturn(['О нашей команде']);

        $processor = new PageTranslationTaskProcessor(
            $this->createMock(PageService::class),
            $this->createMock(PageLocaleService::class),
            new PageTranslationPolicy(),
            $translator,
        );

        self::assertSame('О нашей команде', $processor->translateTarget($this->frozenInput(), 'ru_RU'));
    }

    public function testPersistTargetDoesNotOverwriteManualTitle(): void
    {
        $page = $this->page();
        $pageService = $this->createMock(PageService::class);
        $pageService->method('getPageModel')->with(6)->willReturn($page);
        $localeService = $this->createMock(PageLocaleService::class);
        $payload = $this->payload();
        $payload['titles']['ru_RU'] = 'Ручной заголовок';
        $localeService->method('buildEditorPayload')->with($page, '')->willReturn($payload);
        $localeService->expects(self::never())->method('fillMissingTitle');

        $processor = new PageTranslationTaskProcessor(
            $pageService,
            $localeService,
            new PageTranslationPolicy(),
            $this->createMock(TranslationServiceInterface::class),
        );

        self::assertSame(
            'already_filled',
            $processor->persistTarget($this->frozenInput(), 'ru_RU', 'О нашей команде'),
        );
    }

    public function testPersistTargetRejectsTranslationWhenSourceChanged(): void
    {
        $page = $this->page();
        $pageService = $this->createMock(PageService::class);
        $pageService->method('getPageModel')->with(6)->willReturn($page);
        $localeService = $this->createMock(PageLocaleService::class);
        $payload = $this->payload();
        $payload['titles']['en_US'] = 'A changed source title';
        $localeService->method('buildEditorPayload')->with($page, '')->willReturn($payload);
        $localeService->expects(self::never())->method('fillMissingTitle');

        $processor = new PageTranslationTaskProcessor(
            $pageService,
            $localeService,
            new PageTranslationPolicy(),
            $this->createMock(TranslationServiceInterface::class),
        );

        self::assertSame(
            'source_changed',
            $processor->persistTarget($this->frozenInput(), 'ru_RU', 'О нашей команде'),
        );
    }

    private function page(): Page
    {
        $page = $this->createMock(Page::class);
        $page->method('getPageId')->willReturn(6);
        $page->method('getWebsiteId')->willReturn(0);

        return $page;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'supported_locales' => ['en_US', 'zh_Hans_CN', 'ru_RU'],
            'source_locale' => 'en_US',
            'current_locale' => 'en_US',
            'current_title' => 'About our team',
            'titles' => [
                'en_US' => 'About our team',
                'zh_Hans_CN' => '关于我们',
                'ru_RU' => '',
            ],
            'entries' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function frozenInput(): array
    {
        return [
            'request_id' => 'cms-page-6-translate-1',
            'page_id' => 6,
            'website_id' => 0,
            'source_locale' => 'en_US',
            'source_title' => 'About our team',
            'source_hash' => hash('sha256', 'About our team'),
            'supported_locales' => ['en_US', 'zh_Hans_CN', 'ru_RU'],
            'target_locales' => ['ru_RU'],
        ];
    }
}
