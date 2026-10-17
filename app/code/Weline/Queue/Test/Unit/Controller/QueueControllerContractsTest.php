<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllQueueControllerContractsPass().
 */
final class QueueControllerContractsTest extends TestCase
{
    public function testAllQueueControllerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'QueueAdminBrowserRequest::testEveryInteractiveQueueAdminTemplateLoadsTheDedicatedResource' => function () {
            $this->assert_QueueAdminBrowserRequest_testEveryInteractiveQueueAdminTemplateLoadsTheDedicatedResource();
        },
            'QueueAdminBrowserRequest::testTemplatesInvokeOnlyTheirPublishedQueueAdminOperations' => function () {
            $this->assert_QueueAdminBrowserRequest_testTemplatesInvokeOnlyTheirPublishedQueueAdminOperations();
        },
            'QueueAdminBrowserRequest::testDynamicAttributeHtmlIsSanitizedAndLegacyScriptsAreNeverExecuted' => function () {
            $this->assert_QueueAdminBrowserRequest_testDynamicAttributeHtmlIsSanitizedAndLegacyScriptsAreNeverExecuted();
        },
            'QueueAdminBrowserRequest::testQueueFormBootAndAsyncResponsesCannotCorruptSubmittedData' => function () {
            $this->assert_QueueAdminBrowserRequest_testQueueFormBootAndAsyncResponsesCannotCorruptSubmittedData();
        },
            'QueueAdminBrowserRequest::testQueueFormReliesOnTheBackendLayoutFlashMessage' => function () {
            $this->assert_QueueAdminBrowserRequest_testQueueFormReliesOnTheBackendLayoutFlashMessage();
        },
            'QueueAdminBrowserRequest::testQueueIframeReusesOnlyItsSameOriginParentBackendApi' => function () {
            $this->assert_QueueAdminBrowserRequest_testQueueIframeReusesOnlyItsSameOriginParentBackendApi();
        },
            'QueueAdminBrowserRequest::testSuccessfulCreateIsTerminalAndAttributeControlsAreGrouped' => function () {
            $this->assert_QueueAdminBrowserRequest_testSuccessfulCreateIsTerminalAndAttributeControlsAreGrouped();
        },
            'QueueAdminBrowserRequest::testLiveListingDiscardsPreMutationSnapshotsAndTypeViewUsesOneOffcanvas' => function () {
            $this->assert_QueueAdminBrowserRequest_testLiveListingDiscardsPreMutationSnapshotsAndTypeViewUsesOneOffcanvas();
        },
            'QueueAdminBrowserRequest::testQueueConfirmationPlaceholdersSurviveServerTranslation' => function () {
            $this->assert_QueueAdminBrowserRequest_testQueueConfirmationPlaceholdersSurviveServerTranslation();
        },
        ];
    }

/** @var array<string,string> */
private const BROWSER_SOURCES_QueueAdminBrowserRequest = ['queue-list' => 'view/templates/backend/Queue/index.phtml', 'queue-form' => 'view/templates/backend/Queue/form.phtml', 'type-list' => 'view/templates/backend/Type/index.phtml', 'legacy-form-wizard' => 'view/statics/backend/js/pages/form-wizard.init.js'];

private function assert_QueueAdminBrowserRequest_testEveryInteractiveQueueAdminTemplateLoadsTheDedicatedResource(): void
{
    foreach (['queue-list' => 'view/templates/backend/Queue/index.phtml', 'queue-form' => 'view/templates/backend/Queue/form.phtml', 'type-list' => 'view/templates/backend/Type/index.phtml'] as $label => $relativePath) {
        $source = $this->removePhpBlocks_QueueAdminBrowserRequest($this->readModuleFile_QueueAdminBrowserRequest($relativePath));
        self::assertMatchesRegularExpression("/\\bresource\\s*\\(\\s*['\"]queue_admin['\"]\\s*\\)/", $source, $label . " must call api.resource('queue_admin')");
    }
}

private function assert_QueueAdminBrowserRequest_testTemplatesInvokeOnlyTheirPublishedQueueAdminOperations(): void
{
    $queueList = $this->removePhpBlocks_QueueAdminBrowserRequest($this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/index.phtml'));
    foreach (['snapshot', 'action', 'batchAction'] as $operation) {
        self::assertStringContainsString("queueAdminCall('" . $operation . "'", $queueList);
    }
    $queueForm = $this->removePhpBlocks_QueueAdminBrowserRequest($this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/form.phtml'));
    foreach (['save', 'searchTypes', 'typeAttributes', 'resolveAttributeDependence'] as $operation) {
        self::assertStringContainsString("queueAdminCall('" . $operation . "'", $queueForm);
    }
    $typeList = $this->removePhpBlocks_QueueAdminBrowserRequest($this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Type/index.phtml'));
    self::assertStringContainsString('api.setTypeEnabled(', $typeList);
}

private function assert_QueueAdminBrowserRequest_testDynamicAttributeHtmlIsSanitizedAndLegacyScriptsAreNeverExecuted(): void
{
    $queueForm = $this->removePhpBlocks_QueueAdminBrowserRequest($this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/form.phtml'));
    self::assertStringContainsString('sanitizeAttributeHtml(', $queueForm);
    self::assertStringContainsString('script, style, iframe, object, embed, link, meta, base', $queueForm);
    self::assertStringContainsString("name.indexOf('on') === 0", $queueForm);
    self::assertStringContainsString("name === 'srcdoc'", $queueForm);
    self::assertStringContainsString('data:text\/html', $queueForm);
    self::assertStringContainsString('item.dependence', $queueForm);
    self::assertStringNotContainsString('wrapper.innerHTML', $queueForm);
    self::assertDoesNotMatchRegularExpression('/\beval\s*\(/i', $queueForm);
    $serviceSource = $this->readModuleFile_QueueAdminBrowserRequest('Service/QueueAdminService.php');
    self::assertStringContainsString('stripAttributeScripts(', $serviceSource);
    self::assertStringContainsString("'dependence' =>", $serviceSource);
}

private function assert_QueueAdminBrowserRequest_testQueueFormBootAndAsyncResponsesCannotCorruptSubmittedData(): void
{
    $queueForm = $this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/form.phtml');
    self::assertStringContainsString('JSON_HEX_TAG', $queueForm);
    self::assertStringContainsString('JSON_HEX_AMP', $queueForm);
    self::assertStringContainsString('JSON_HEX_APOS', $queueForm);
    self::assertStringContainsString('JSON_HEX_QUOT', $queueForm);
    self::assertStringContainsString('var queueBoot =', $queueForm);
    self::assertStringNotContainsString("name: '{{queueData.name}}'", $queueForm);
    self::assertStringNotContainsString("biz_key: '{{queueData.biz_key}}'", $queueForm);
    self::assertStringContainsString("!in_array(\$queueFormActiveName, ['progress-select-queueData', 'progress-params'], true)", $queueForm);
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
    self::assertStringNotContainsString("if (!this.isLoadingAttributes) {\n                        this.renderSearchQueueTypeAttributes();", $queueForm);
}

private function assert_QueueAdminBrowserRequest_testQueueFormReliesOnTheBackendLayoutFlashMessage(): void
{
    $queueForm = $this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/form.phtml');
    self::assertStringNotContainsString('<template>Weline_Component::message.phtml</template>', $queueForm);
    self::assertStringContainsString('<div class="weline-queue-form" id="QueuePage">', $queueForm);
}

private function assert_QueueAdminBrowserRequest_testQueueIframeReusesOnlyItsSameOriginParentBackendApi(): void
{
    $queueForm = $this->removePhpBlocks_QueueAdminBrowserRequest($this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/form.phtml'));
    self::assertStringContainsString('window.parent === window', $queueForm);
    self::assertStringContainsString('window.parent.location.origin === window.location.origin', $queueForm);
    self::assertStringContainsString("typeof window.parent.Weline.load === 'function'", $queueForm);
    self::assertStringContainsString("await apiHost.Weline.load('api')", $queueForm);
    self::assertStringNotContainsString("await Weline.load('api')", $queueForm);
}

private function assert_QueueAdminBrowserRequest_testSuccessfulCreateIsTerminalAndAttributeControlsAreGrouped(): void
{
    $queueForm = $this->removePhpBlocks_QueueAdminBrowserRequest($this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/form.phtml'));
    self::assertStringContainsString('this.params.queue_id = String(savedQueueId)', $queueForm);
    self::assertStringContainsString('allowEscapeKey: false', $queueForm);
    self::assertStringContainsString('allowOutsideClick: false', $queueForm);
    self::assertStringContainsString('this.isSubmitting = committed', $queueForm);
    self::assertStringContainsString('collectQueueAttributes(', $queueForm);
    self::assertStringContainsString("type === 'checkbox'", $queueForm);
    self::assertStringContainsString("type === 'radio'", $queueForm);
    self::assertStringContainsString('return element.checked;', $queueForm);
}

private function assert_QueueAdminBrowserRequest_testLiveListingDiscardsPreMutationSnapshotsAndTypeViewUsesOneOffcanvas(): void
{
    $queueList = $this->removePhpBlocks_QueueAdminBrowserRequest($this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/index.phtml'));
    self::assertStringContainsString('snapshotMutationEpoch', $queueList);
    self::assertStringContainsString('requestEpoch !== snapshotMutationEpoch', $queueList);
    self::assertStringContainsString('snapshotRefreshPending = true', $queueList);
    self::assertStringContainsString('requestQueueMutationRefresh(', $queueList);
    $typeList = $this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Type/index.phtml');
    self::assertSame(1, \substr_count($typeList, "id='queue_type_shared_view'"));
    self::assertSame(1, \substr_count($typeList, 'Weline\Component\Block\OffCanvas'));
    self::assertStringContainsString('weline-queue-type-view-btn', $typeList);
    self::assertStringContainsString('openTypeOffcanvas(', $typeList);
}

private function assert_QueueAdminBrowserRequest_testQueueConfirmationPlaceholdersSurviveServerTranslation(): void
{
    $queueList = $this->readModuleFile_QueueAdminBrowserRequest('view/templates/backend/Queue/index.phtml');
    self::assertSame(2, \substr_count($queueList, "'__QUEUE_NAME__'"));
    self::assertSame(4, \substr_count($queueList, "'__QUEUE_COUNT__'"));
    self::assertStringContainsString("deleteConfirm.replace('__QUEUE_NAME__', queueName)", $queueList);
    self::assertStringContainsString("confirmMsg.replace('__QUEUE_COUNT__', String(ids.length))", $queueList);
}

/** @return iterable<string,array{0:string,1:string}> */
private function browserSourceProvider_QueueAdminBrowserRequest(): iterable
{
    foreach (self::BROWSER_SOURCES_QueueAdminBrowserRequest as $label => $relativePath) {
        yield $label => [$label, $relativePath];
    }
}

private function readModuleFile_QueueAdminBrowserRequest(string $relativePath): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Queue' . '/' . $relativePath;
    $source = \file_get_contents($path);
    self::assertIsString($source, $path . ' must be readable');
    return $source;
}

private function removePhpBlocks_QueueAdminBrowserRequest(string $source): string
{
    $withoutPhp = \preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i', '', $source);
    self::assertIsString($withoutPhp);
    return $withoutPhp;
}
}
