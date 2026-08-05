<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class QueueSnapshotControllerTest extends TestCase
{
    public function testSnapshotEndpointIsAjaxOnlyAndDirectNavigationReturnsToListing(): void
    {
        $source = $this->readControllerSource();
        $snapshotMethod = $this->extractMethodSource($source, 'getSnapshot');

        self::assertStringContainsString('!$this->request->isAjax()', $snapshotMethod);
        self::assertStringContainsString("return \$this->redirect('*/backend/queue', \$this->getQueueSnapshotRedirectParams());", $snapshotMethod);
        self::assertStringContainsString('$this->queueAdminListingView->snapshot(', $snapshotMethod);
        self::assertStringContainsString('return $this->fetchJson(', $snapshotMethod);
    }

    public function testListingAndSnapshotReadPathsDelegateToThePresentationView(): void
    {
        $source = $this->readControllerSource();
        $indexMethod = $this->extractMethodSource($source, 'index');
        $snapshotMethod = $this->extractMethodSource($source, 'getSnapshot');

        self::assertStringContainsString('$this->queueAdminListingView->state(', $indexMethod);
        self::assertStringContainsString('$this->queueAdminListingView->snapshot(', $snapshotMethod);
        self::assertStringNotContainsString('private function buildQueueListingState(', $source);
        self::assertStringNotContainsString('fetchHtml(', $this->readAdminServiceSource());
    }

    public function testSnapshotPaginationLinksRemainFullListingLinksInsideListingView(): void
    {
        $source = $this->readListingViewSource();

        self::assertStringContainsString("'pagination' =>", $source);
        self::assertStringContainsString("unset(\$queueListing->pagination['html']);", $source);
        self::assertStringContainsString("'module' => \$module", $source);
        self::assertStringContainsString("'status' => \$status", $source);
        self::assertStringContainsString("'q' => \$search", $source);
        self::assertStringContainsString("'biz_key' => \$bizKey", $source);
        self::assertStringContainsString("'queue_id' => \$queueId", $source);
        self::assertStringContainsString(
            '->pagination($page, self::PAGE_SIZE, $paginationParams)',
            $source
        );
        self::assertMatchesRegularExpression(
            '~\$queueListing->getPagination\(\s*\'pagination-rounded\',\s*\'queue/backend/queue\',\s*true,?\s*\)~',
            $source
        );
        self::assertStringNotContainsString("'*/backend/queue'", $source);

        $statsSource = $this->readStatsPartialSource();
        self::assertSame(6, \substr_count($statsSource, "@backend-url('queue/backend/queue')"));
        self::assertStringNotContainsString("@backend-url('*/backend/queue')", $statsSource);

        $listingSource = $this->readListingPartialSource();
        self::assertStringContainsString('<?php if (empty($queues)): ?>', $listingSource);
        self::assertStringNotContainsString('<empty name="queues">', $listingSource);
    }

    public function testLegacyFormPostAndAttributeEndpointCannotBypassBinQueryAcl(): void
    {
        $source = $this->readControllerSource();
        $formMethod = $this->extractMethodSource($source, 'form');
        $typeAttributesMethod = $this->extractMethodSource($source, 'getTypeAttributes');

        self::assertStringContainsString('!$this->request->isGet()', $formMethod);
        self::assertStringContainsString('$this->legacyMutationGone()', $formMethod);
        self::assertStringNotContainsString('$this->request->getPost()', $formMethod);
        self::assertStringNotContainsString('$this->queueAdminService->save(', $formMethod);
        self::assertStringContainsString('$this->legacyMutationGone()', $typeAttributesMethod);
        self::assertStringNotContainsString('$this->queueAdminService->typeAttributes(', $typeAttributesMethod);
    }

    public function testLegacyControllerMutationRoutesCannotBypassBinQueryAcl(): void
    {
        $queueSource = $this->readControllerSource();
        foreach (['getDelete', 'reset', 'stop', 'continue'] as $method) {
            $methodSource = $this->extractMethodSource($queueSource, $method);
            self::assertStringContainsString('$this->legacyMutationGone()', $methodSource);
            self::assertStringNotContainsString('$this->queueAdminService->action(', $methodSource);
        }
        self::assertStringContainsString("Weline_Queue::delete", $queueSource);
        foreach (['postApiAction', 'postApiBatch'] as $method) {
            $methodSource = $this->extractMethodSource($queueSource, $method);
            self::assertStringContainsString('$this->legacyMutationGone()', $methodSource);
            self::assertStringNotContainsString('$this->queueAdminService->', $methodSource);
        }
        self::assertStringContainsString('return Response::json([', $queueSource);
        self::assertStringContainsString('], 410);', $queueSource);

        $serviceSource = $this->readAdminServiceSource();
        self::assertStringNotContainsString('allowLegacyOperationalAction', $serviceSource);
        self::assertStringNotContainsString('legacyTakeover', $serviceSource);

        $typeSource = $this->readTypeControllerSource();
        foreach (['enable', 'disable'] as $method) {
            $methodSource = $this->extractMethodSource($typeSource, $method);
            self::assertStringContainsString('$this->legacyMutationGone()', $methodSource);
            self::assertStringNotContainsString('setTypeEnabled(', $methodSource);
        }
        self::assertStringContainsString("Weline_Queue::type_manage", $typeSource);
        self::assertStringContainsString('return Response::json([', $typeSource);
        self::assertStringContainsString('], 410);', $typeSource);
    }

    public function testCommittedMutationsRemainSuccessfulWhenPostCommitWorkFails(): void
    {
        $serviceSource = $this->readAdminServiceSource();
        $saveMethod = $this->extractMethodSource($serviceSource, 'save');
        $commitOffset = \strpos($saveMethod, '$transactionQuery->commit()');
        $notificationOffset = \strpos($saveMethod, '$this->dispatchAfterCommit(');

        self::assertNotFalse($commitOffset);
        self::assertNotFalse($notificationOffset);
        self::assertLessThan($notificationOffset, $commitOffset);
        self::assertStringContainsString('$this->currentUserData()->clearScope(\'queue\')', $saveMethod);
        self::assertStringContainsString("'warnings' => \$warnings", $saveMethod);

        $actionMethod = $this->extractMethodSource($serviceSource, 'action');
        self::assertStringContainsString('$this->dispatchAfterCommit(', $actionMethod);
        self::assertStringNotContainsString('$this->eventsManager->dispatch(', $actionMethod);
    }

    public function testTypeEnablementPersistsSmallintAsAnInteger(): void
    {
        $serviceSource = $this->readAdminServiceSource();
        $method = $this->extractMethodSource($serviceSource, 'setTypeEnabled');

        self::assertStringContainsString('setEnable($enabled)->save()', $method);

        $typeSource = $this->readTypeModelSource();
        self::assertStringContainsString(
            'setData(self::schema_fields_enable, $enable ? 1 : 0)',
            $typeSource
        );
    }

    private function readControllerSource(): string
    {
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/Queue.php');
        self::assertIsString($source);

        return $source;
    }

    private function readAdminServiceSource(): string
    {
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueAdminService.php');
        self::assertIsString($source);

        return $source;
    }

    private function readTypeControllerSource(): string
    {
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/Type.php');
        self::assertIsString($source);

        return $source;
    }

    private function readTypeModelSource(): string
    {
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/Model/Queue/Type.php');
        self::assertIsString($source);

        return $source;
    }

    private function readListingViewSource(): string
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/View/Backend/QueueAdminListingView.php'
        );
        self::assertIsString($source);

        return $source;
    }

    private function readStatsPartialSource(): string
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/Backend/Queue/partials/stats.phtml'
        );
        self::assertIsString($source);

        return $source;
    }

    private function readListingPartialSource(): string
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/Backend/Queue/partials/listing.phtml'
        );
        self::assertIsString($source);

        return $source;
    }

    private function extractMethodSource(string $source, string $methodName): string
    {
        $methodOffset = \strpos($source, 'function ' . $methodName);
        self::assertNotFalse($methodOffset, $methodName . ' missing');

        $nextMethodOffset = \strpos($source, "\n    private function ", $methodOffset + 1);
        $nextPublicMethodOffset = \strpos($source, "\n    public function ", $methodOffset + 1);
        $methodOffsets = \array_filter(
            [$nextMethodOffset, $nextPublicMethodOffset],
            static fn (int|false $offset): bool => $offset !== false
        );
        $endOffset = $methodOffsets === [] ? false : \min($methodOffsets);

        return $endOffset === false
            ? \substr($source, $methodOffset)
            : \substr($source, $methodOffset, $endOffset - $methodOffset);
    }
}
