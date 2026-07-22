<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendBinQueryWorkerReuseContractTest extends TestCase
{
    public function testDevCacheBustIsCachedAndWorkerIsNotTerminatedOnResend(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js'
        );

        self::assertStringContainsString('cachedDevWorkerUrls', $source);
        self::assertStringContainsString('Reuse the live worker for the whole page', $source);
        self::assertStringContainsString('if (this.worker) {', $source);
        self::assertStringNotContainsString(
            'if (this.worker && this.workerUrl === config.workerUrl)',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/BackendQueryBinClient\.prototype\.ensureWorker[\s\S]{0,400}?this\.worker\.terminate\(/',
            $source
        );
    }
}
