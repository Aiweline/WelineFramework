<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service\Resumable;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Cms\Model\PageLocale;
use Weline\Cms\Service\PageLocaleService;
use Weline\Cms\Service\PageService;
use Weline\Cms\Service\PageTranslationPolicy;
use Weline\Cms\Service\PageTranslationTaskProcessor;
use Weline\Cms\Service\Resumable\PageTranslationTaskHandler;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectReservation;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\TranslationService\Api\TranslationServiceInterface;

final class PageTranslationTaskHandlerTest extends TestCase
{
    public function testPrepareStartFreezesPageAndOwnerScopedBusinessKey(): void
    {
        $handler = new PageTranslationTaskHandler($this->processor());
        $owner = new TaskOwner('backend', 'backend:9', websiteId: 0);

        $request = $handler->prepareStart($owner, [
            'page_id' => 6,
            'store_id' => 4,
            'request_id' => 'cms-page-6-translate-1',
        ]);

        self::assertSame('cms.page_translation:backend:9:4:cms-page-6-translate-1', $request->businessKey);
        self::assertSame(['ru_RU'], $request->input['target_locales']);
        self::assertSame(6, $request->input['page_id']);
        self::assertSame(4, $request->input['store_id']);
    }

    public function testPrepareStartRejectsFrontendOwner(): void
    {
        $this->expectException(ResumableTaskAccessDeniedException::class);

        (new PageTranslationTaskHandler($this->processor()))->prepareStart(
            new TaskOwner('frontend', 'frontend:9', websiteId: 0),
            ['page_id' => 6, 'request_id' => 'cms-page-6-translate-1'],
        );
    }

    public function testExecuteLedgersTranslationAndPersistsEachTarget(): void
    {
        $localeService = $this->createMock(PageLocaleService::class);
        $localeService->method('buildEditorPayload')->willReturn($this->payload());
        $localeService->expects(self::once())
            ->method('fillMissingTitle')
            ->willReturn($this->createMock(PageLocale::class));
        $translator = $this->createMock(TranslationServiceInterface::class);
        $translator->expects(self::once())
            ->method('batchTranslate')
            ->willReturn(['О нашей команде']);
        $handler = new PageTranslationTaskHandler($this->processor($localeService, $translator));
        $owner = new TaskOwner('backend', 'backend:9', websiteId: 0);
        $request = $handler->prepareStart($owner, [
            'page_id' => 6,
            'store_id' => 4,
            'request_id' => 'cms-page-6-translate-1',
        ]);
        $context = new InMemoryPageTranslationContext();

        $result = $handler->execute($context, $request->input, null);

        self::assertSame('completed', $result->status->value);
        self::assertSame('saved', $result->data['results']['ru_RU']['status']);
        self::assertSame('О нашей команде', $context->effects['translate.ru_RU']['title']);
        self::assertSame('translation_completed', $context->checkpoint()?->cursor);
        self::assertSame(['start', 'progress', 'completed'], array_column($context->events, 'event'));
    }

    private function processor(
        ?PageLocaleService $localeService = null,
        ?TranslationServiceInterface $translator = null,
    ): PageTranslationTaskProcessor {
        $page = $this->createMock(Page::class);
        $page->method('getPageId')->willReturn(6);
        $page->method('getWebsiteId')->willReturn(0);
        $pageService = $this->createMock(PageService::class);
        $pageService->method('getPageModel')->willReturn($page);
        $localeService ??= $this->createMock(PageLocaleService::class);
        $localeService->method('buildEditorPayload')->willReturn($this->payload());
        $translator ??= $this->createMock(TranslationServiceInterface::class);

        return new PageTranslationTaskProcessor(
            $pageService,
            $localeService,
            new PageTranslationPolicy(),
            $translator,
        );
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'store_id' => 4,
            'store_code' => 'main',
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
}

final class InMemoryPageTranslationContext implements ResumableTaskContextInterface
{
    private ?TaskCheckpoint $current = null;
    private int $version = 0;
    /** @var list<array{event:string,payload:array<string|int,mixed>}> */
    public array $events = [];
    /** @var array<string,array<string|int,mixed>> */
    public array $effects = [];

    public function taskId(): string
    {
        return 'cms-translation-task-1';
    }

    public function attempt(): int
    {
        return 1;
    }

    public function checkpoint(): ?TaskCheckpoint
    {
        return $this->current;
    }

    public function saveCheckpoint(string $cursor, array $state, int $schemaVersion = 1): TaskCheckpoint
    {
        return $this->current = new TaskCheckpoint(
            $this->taskId(),
            ++$this->version,
            $cursor,
            $state,
            $schemaVersion,
        );
    }

    public function emit(string $event, array $payload, ?string $coalesceKey = null): int
    {
        $this->events[] = ['event' => $event, 'payload' => $payload];
        return count($this->events);
    }

    public function reserveEffect(string $effectKey): TaskEffectReservation
    {
        return new TaskEffectReservation(
            $this->taskId(),
            $effectKey,
            TaskEffectState::RESERVED,
        );
    }

    public function completeEffect(string $effectKey, array $result = []): void
    {
        $this->effects[$effectKey] = $result;
    }

    public function isStopRequested(): bool
    {
        return false;
    }

    public function throwIfStopRequested(): void
    {
    }

    public function heartbeat(): void
    {
    }
}
