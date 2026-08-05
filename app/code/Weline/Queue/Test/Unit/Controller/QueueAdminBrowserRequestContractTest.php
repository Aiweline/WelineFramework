<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class QueueAdminBrowserRequestContractTest extends TestCase
{
    /** @var array<string,string> */
    private const BROWSER_SOURCES = [
        'queue-list' => 'view/templates/Backend/Queue/index.phtml',
        'queue-form' => 'view/templates/Backend/Queue/form.phtml',
        'type-list' => 'view/templates/Backend/Type/index.phtml',
        'legacy-form-wizard' => 'view/statics/backend/js/pages/form-wizard.init.js',
    ];

    /** @dataProvider browserSourceProvider */
    public function testQueueBackendBrowserSourcesDoNotUseForbiddenBusinessTransports(
        string $label,
        string $relativePath
    ): void {
        $source = $this->readModuleFile($relativePath);
        $browserSource = $this->removePhpBlocks($source);
        $forbiddenPatterns = [
            'native fetch' => '/\bfetch\s*\(/i',
            'jQuery ajax' => '/\$\s*\.\s*ajax\s*\(/i',
            'XMLHttpRequest' => '/\bXMLHttpRequest\b/i',
            'axios' => '/\baxios\s*(?:\.|\()/i',
            'Weline.Api request/get/post' => '/\bWeline\s*\.\s*Api\s*\.\s*(?:request|get|post)\s*\(/i',
            'handwritten query-bin URL' => '~(?:/|\\b)query-bin(?:/|\\b)~i',
        ];

        foreach ($forbiddenPatterns as $transport => $pattern) {
            self::assertDoesNotMatchRegularExpression(
                $pattern,
                $browserSource,
                $label . ' must not use ' . $transport . ' for Queue business requests'
            );
        }
    }

    public function testEveryInteractiveQueueAdminTemplateLoadsTheDedicatedResource(): void
    {
        foreach ([
            'queue-list' => 'view/templates/Backend/Queue/index.phtml',
            'queue-form' => 'view/templates/Backend/Queue/form.phtml',
            'type-list' => 'view/templates/Backend/Type/index.phtml',
        ] as $label => $relativePath) {
            $source = $this->removePhpBlocks($this->readModuleFile($relativePath));
            self::assertMatchesRegularExpression(
                "/\\bresource\\s*\\(\\s*['\"]queue_admin['\"]\\s*\\)/",
                $source,
                $label . " must call api.resource('queue_admin')"
            );
        }
    }

    public function testTemplatesInvokeOnlyTheirPublishedQueueAdminOperations(): void
    {
        $queueList = $this->removePhpBlocks(
            $this->readModuleFile('view/templates/Backend/Queue/index.phtml')
        );
        foreach (['snapshot', 'action', 'batchAction'] as $operation) {
            self::assertStringContainsString("queueAdminCall('" . $operation . "'", $queueList);
        }

        $queueForm = $this->removePhpBlocks(
            $this->readModuleFile('view/templates/Backend/Queue/form.phtml')
        );
        foreach (['save', 'searchTypes', 'typeAttributes', 'resolveAttributeDependence'] as $operation) {
            self::assertStringContainsString("queueAdminCall('" . $operation . "'", $queueForm);
        }

        $typeList = $this->removePhpBlocks(
            $this->readModuleFile('view/templates/Backend/Type/index.phtml')
        );
        self::assertStringContainsString('api.setTypeEnabled(', $typeList);
    }

    public function testDynamicAttributeHtmlIsSanitizedAndLegacyScriptsAreNeverExecuted(): void
    {
        $queueForm = $this->removePhpBlocks(
            $this->readModuleFile('view/templates/Backend/Queue/form.phtml')
        );
        self::assertStringContainsString('sanitizeAttributeHtml(', $queueForm);
        self::assertStringContainsString(
            'script, style, iframe, object, embed, link, meta, base',
            $queueForm
        );
        self::assertStringContainsString("name.indexOf('on') === 0", $queueForm);
        self::assertStringContainsString("name === 'srcdoc'", $queueForm);
        self::assertStringContainsString('data:text\\/html', $queueForm);
        self::assertStringContainsString('item.dependence', $queueForm);
        self::assertStringNotContainsString('wrapper.innerHTML', $queueForm);
        self::assertDoesNotMatchRegularExpression('/\beval\s*\(/i', $queueForm);

        $serviceSource = $this->readModuleFile('Service/QueueAdminService.php');
        self::assertStringContainsString('stripAttributeScripts(', $serviceSource);
        self::assertStringContainsString("'dependence' =>", $serviceSource);
    }

    public function testQueueFormBootAndAsyncResponsesCannotCorruptSubmittedData(): void
    {
        $queueForm = $this->readModuleFile('view/templates/Backend/Queue/form.phtml');
        self::assertStringContainsString('JSON_HEX_TAG', $queueForm);
        self::assertStringContainsString('JSON_HEX_AMP', $queueForm);
        self::assertStringContainsString('JSON_HEX_APOS', $queueForm);
        self::assertStringContainsString('JSON_HEX_QUOT', $queueForm);
        self::assertStringContainsString('var queueBoot =', $queueForm);
        self::assertStringNotContainsString("name: '{{queueData.name}}'", $queueForm);
        self::assertStringNotContainsString("biz_key: '{{queueData.biz_key}}'", $queueForm);
        self::assertStringContainsString(
            "!in_array(\$queueFormActiveName, ['progress-select-queueData', 'progress-params'], true)",
            $queueForm
        );
        self::assertStringContainsString("'active_name' => \$queueFormActiveName", $queueForm);

        self::assertStringContainsString('attributeRequestVersion', $queueForm);
        self::assertStringContainsString('requestVersion !== this.attributeRequestVersion', $queueForm);
        self::assertStringContainsString('var applied = false;', $queueForm);
        self::assertStringContainsString('setAttributeAvailability(currentElement, currentWrapper, applied)', $queueForm);
        self::assertStringContainsString('dependenceGeneration !== vm.dependenceGeneration', $queueForm);
        self::assertStringContainsString('dependenceGeneration === vm.dependenceGeneration', $queueForm);
        self::assertStringContainsString('unresolvedDependenceCodes', $queueForm);
        self::assertStringContainsString('setDependenceUnresolved(item.code, true, dependenceGeneration)', $queueForm);
        self::assertStringContainsString('retryDependenceResolution', $queueForm);
        self::assertStringContainsString('dependenceRetryHandlers', $queueForm);
        self::assertStringContainsString('registerDependenceRetry(item.code', $queueForm);
        self::assertStringContainsString('var lastDependenceCode = dependenceElements[0].code', $queueForm);
        self::assertStringContainsString('lastDependenceCode = requestedDependence.code', $queueForm);
        self::assertStringContainsString('return refresh(lastDependenceCode)', $queueForm);
        self::assertStringContainsString('this.unresolvedDependenceCodes.length > 0', $queueForm);
        self::assertStringContainsString("this.activeName === 'progress-confirm'", $queueForm);
        self::assertStringContainsString('!this.isLoadingAttributes', $queueForm);
        self::assertStringContainsString('this.clickedParamsProcess', $queueForm);
        self::assertStringContainsString(":disabled='!canSubmitQueue'", $queueForm);
        self::assertStringContainsString('if (!this.canSubmitQueue)', $queueForm);
        self::assertStringNotContainsString(
            "if (!this.isLoadingAttributes) {\n                        this.renderSearchQueueTypeAttributes();",
            $queueForm
        );
    }

    public function testQueueFormReliesOnTheBackendLayoutFlashMessage(): void
    {
        $queueForm = $this->readModuleFile('view/templates/Backend/Queue/form.phtml');

        self::assertStringNotContainsString(
            '<template>Weline_Component::message.phtml</template>',
            $queueForm
        );
        self::assertStringContainsString('<div class="weline-queue-form" id="QueuePage">', $queueForm);
    }

    public function testQueueIframeReusesOnlyItsSameOriginParentBackendApi(): void
    {
        $queueForm = $this->removePhpBlocks(
            $this->readModuleFile('view/templates/Backend/Queue/form.phtml')
        );

        self::assertStringContainsString('window.parent === window', $queueForm);
        self::assertStringContainsString(
            'window.parent.location.origin === window.location.origin',
            $queueForm
        );
        self::assertStringContainsString("typeof window.parent.Weline.load === 'function'", $queueForm);
        self::assertStringContainsString("await apiHost.Weline.load('api')", $queueForm);
        self::assertStringNotContainsString("await Weline.load('api')", $queueForm);
    }

    public function testSuccessfulCreateIsTerminalAndAttributeControlsAreGrouped(): void
    {
        $queueForm = $this->removePhpBlocks(
            $this->readModuleFile('view/templates/Backend/Queue/form.phtml')
        );
        self::assertStringContainsString('this.params.queue_id = String(savedQueueId)', $queueForm);
        self::assertStringContainsString('allowEscapeKey: false', $queueForm);
        self::assertStringContainsString('allowOutsideClick: false', $queueForm);
        self::assertStringContainsString('this.isSubmitting = committed', $queueForm);
        self::assertStringContainsString('collectQueueAttributes(', $queueForm);
        self::assertStringContainsString("type === 'checkbox'", $queueForm);
        self::assertStringContainsString("type === 'radio'", $queueForm);
        self::assertStringContainsString('return element.checked;', $queueForm);
    }

    public function testLiveListingDiscardsPreMutationSnapshotsAndTypeViewUsesOneOffcanvas(): void
    {
        $queueList = $this->removePhpBlocks(
            $this->readModuleFile('view/templates/Backend/Queue/index.phtml')
        );
        self::assertStringContainsString('snapshotMutationEpoch', $queueList);
        self::assertStringContainsString('requestEpoch !== snapshotMutationEpoch', $queueList);
        self::assertStringContainsString('snapshotRefreshPending = true', $queueList);
        self::assertStringContainsString('requestQueueMutationRefresh(', $queueList);

        $typeList = $this->readModuleFile('view/templates/Backend/Type/index.phtml');
        self::assertSame(1, \substr_count($typeList, "id='queue_type_shared_view'"));
        self::assertSame(1, \substr_count($typeList, 'Weline\\Component\\Block\\OffCanvas'));
        self::assertStringContainsString('weline-queue-type-view-btn', $typeList);
        self::assertStringContainsString('openTypeOffcanvas(', $typeList);
    }

    public function testQueueConfirmationPlaceholdersSurviveServerTranslation(): void
    {
        $queueList = $this->readModuleFile('view/templates/Backend/Queue/index.phtml');

        self::assertSame(2, \substr_count($queueList, "'__QUEUE_NAME__'"));
        self::assertSame(4, \substr_count($queueList, "'__QUEUE_COUNT__'"));
        self::assertStringContainsString(
            "deleteConfirm.replace('__QUEUE_NAME__', queueName)",
            $queueList
        );
        self::assertStringContainsString(
            "confirmMsg.replace('__QUEUE_COUNT__', String(ids.length))",
            $queueList
        );
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function browserSourceProvider(): iterable
    {
        foreach (self::BROWSER_SOURCES as $label => $relativePath) {
            yield $label => [$label, $relativePath];
        }
    }

    private function readModuleFile(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . '/' . $relativePath;
        $source = \file_get_contents($path);
        self::assertIsString($source, $path . ' must be readable');

        return $source;
    }

    private function removePhpBlocks(string $source): string
    {
        $withoutPhp = \preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i', '', $source);
        self::assertIsString($withoutPhp);

        return $withoutPhp;
    }
}
