<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Protects MediaManager browser upload/connector migration to media_manager resource.
 */
final class ManagerJsBinQueryContractTest extends TestCase
{
    public function testManagerJsUsesResourceConnectorAndBase64Upload(): void
    {
        $path = BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("resource('media_manager')", $source);
        self::assertStringContainsString("mmResource('connector'", $source);
        self::assertStringContainsString('function resolveBackendApiHost()', $source);
        self::assertStringContainsString(
            'window.parent.location.origin === window.location.origin',
            $source
        );
        self::assertStringContainsString('var host = resolveBackendApiHost();', $source);
        self::assertStringContainsString('host.Weline.load(\'api\')', $source);
        self::assertStringContainsString('upload_base64', $source);
        self::assertStringContainsString('formDataToConnectorPayload', $source);
        self::assertStringContainsString("cmd: 'tree'", $source);
        self::assertDoesNotMatchRegularExpression('/tree:\s*1\b/', $source);
        self::assertStringNotContainsString('Weline.Api.request(', $source);
        self::assertStringNotContainsString('Weline.Api.get(', $source);
        self::assertStringNotContainsString('Weline.Api.post(', $source);
        self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $source);
    }

    public function testDirectoryItemsRemainAccessibleWhenDoubleClickDeliveryIsInterrupted(): void
    {
        $path = BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('role="button" tabindex="0"', $source);
        self::assertStringContainsString('function openDirectoryFromInteraction(hash, renderedMime)', $source);
        self::assertStringContainsString('var requestSerial = ++OPEN_REQUEST_SERIAL', $source);
        self::assertStringContainsString('requestSerial !== OPEN_REQUEST_SERIAL', $source);
        self::assertStringContainsString("wrap.dataset.openState = 'pending'", $source);
        self::assertStringContainsString("wrap.dataset.openState = 'done'", $source);
        self::assertStringContainsString('FILES[data.cwd.hash] = data.cwd', $source);
        self::assertStringContainsString('openDirectoryFromInteraction(hash, el.dataset.mime)', $source);
        self::assertStringContainsString(
            'e.detail >= 2 && openDirectoryFromInteraction(hash, el.dataset.mime)',
            $source
        );
        self::assertStringContainsString("e.key !== 'Enter'", $source);
        self::assertStringContainsString(
            'openDirectoryFromInteraction(selectedHash, renderedMime)',
            $source
        );
        self::assertStringContainsString(
            "} else if (window.parent && window.parent !== window) {",
            $source
        );
        self::assertStringContainsString("CONFIG.target = String(e.data.target).trim()", $source);
        self::assertStringContainsString("target: CONFIG.target || ''", $source);
    }

    public function testIframeSingleSelectAlwaysRendersOneConfirmControl(): void
    {
        $templatePath = BP . '/app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml';
        $stylePath = BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css';
        $template = (string)file_get_contents($templatePath);
        $style = (string)file_get_contents($stylePath);

        self::assertStringContainsString(
            'type="button" class="mmf-btn mmf-btn-primary mmf-iframe-confirm" id="mmf-btn-select"',
            $template
        );
        self::assertStringContainsString('id="mmf-btn-confirm-select"', $template);
        self::assertDoesNotMatchRegularExpression(
            '/<notempty name="is_iframe">(?:(?!<\/notempty>).)*id="mmf-btn-select"/s',
            $template
        );
        self::assertDoesNotMatchRegularExpression(
            '/<notempty name="is_iframe">(?:(?!<\/notempty>).)*id="mmf-select-bar"/s',
            $template
        );
        self::assertStringContainsString('.mmf-iframe-confirm {', $style);
        self::assertStringContainsString('.mmf-iframe-mode .mmf-iframe-confirm {', $style);
    }

    public function testDescriptorPublishesConnectorAndGenerate(): void
    {
        $path = BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("'name' => 'connector'", $source);
        self::assertStringContainsString("'name' => 'generate'", $source);
        self::assertStringContainsString("'name' => 'polishPrompt'", $source);
        self::assertStringContainsString('MediaUploadBase64Hydrator', $source);
    }

    public function testConnectorDescriptorAcceptsFrontendOpenPayloadKeys(): void
    {
        $provider = new \ReflectionClass(
            \Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerQueryProvider::class
        );
        $instance = $provider->newInstanceWithoutConstructor();
        $descriptor = $instance->getDescriptor();
        $connector = null;
        foreach ($descriptor['operations'] as $operation) {
            if (($operation['name'] ?? '') === 'connector') {
                $connector = $operation;
                break;
            }
        }
        self::assertNotNull($connector);
        $names = array_column($connector['params'], 'name');
        foreach (['cmd', 'target', 'path', 'storage', 'init', 'tree', 'startPath', 'upload_base64'] as $required) {
            self::assertContains($required, $names, 'connector must declare FE payload key: ' . $required);
        }
    }

    public function testGenerateDescriptorAcceptsFrontendAiPayloadKeys(): void
    {
        $provider = new \ReflectionClass(
            \Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerQueryProvider::class
        );
        // Construct without heavy deps: only need getDescriptor().
        $instance = $provider->newInstanceWithoutConstructor();
        $descriptor = $instance->getDescriptor();
        $generate = null;
        foreach ($descriptor['operations'] as $operation) {
            if (($operation['name'] ?? '') === 'generate') {
                $generate = $operation;
                break;
            }
        }
        self::assertNotNull($generate);
        $names = array_column($generate['params'], 'name');
        foreach ([
            'prompt', 'prompts', 'mode', 'session_id', 'target',
            'size', 'output_format', 'aspect_ratio',
            'source_file_hash', 'parent_generation_id', 'batch_count',
        ] as $required) {
            self::assertContains($required, $names, 'generate must declare FE payload key: ' . $required);
        }
    }
}
