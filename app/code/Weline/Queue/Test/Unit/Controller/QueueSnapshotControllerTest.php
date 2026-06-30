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
        self::assertStringContainsString('return $this->fetchJson([', $snapshotMethod);
    }

    public function testSnapshotPaginationLinksTargetFullQueueListing(): void
    {
        $source = $this->readControllerSource();
        $listingStateMethod = $this->extractMethodSource($source, 'buildQueueListingState');
        $paginationMethod = $this->extractMethodSource($source, 'getQueueListingPaginationHtml');

        self::assertStringContainsString("'pagination' => \$this->getQueueListingPaginationHtml(\$queueListing)", $listingStateMethod);
        self::assertStringContainsString("unset(\$queueListing->pagination['html']);", $paginationMethod);
        self::assertStringContainsString("\$queueListing->getPagination('pagination-rounded', '*/backend/queue', true)", $paginationMethod);
    }

    private function readControllerSource(): string
    {
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/Queue.php');
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
