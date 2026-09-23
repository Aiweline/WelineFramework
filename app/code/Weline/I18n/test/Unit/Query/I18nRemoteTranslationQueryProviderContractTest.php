<?php

declare(strict_types=1);

namespace {
    if (!\function_exists('__')) {
        function __(string $text, array $arguments = []): string
        {
            return $text;
        }
    }
}

namespace Weline\I18n\Test\Unit\Query {

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\I18n\Extends\Module\Weline_Framework\Query\I18nRemoteTranslationQueryProvider;
use Weline\I18n\Service\RemoteDictionaryAssistService;

final class I18nRemoteTranslationQueryProviderContractTest extends TestCase
{
    public function testProviderNameOpsAndAcl(): void
    {
        $provider = (new ReflectionClass(I18nRemoteTranslationQueryProvider::class))
            ->newInstanceWithoutConstructor();
        self::assertSame('i18n_remote_translation', $provider->getProviderName());

        $descriptor = $provider->getDescriptor();
        self::assertTrue((bool)($descriptor['demo'] ?? false));
        $ops = array_column(is_array($descriptor['operations'] ?? null) ? $descriptor['operations'] : [], 'name');
        self::assertSame([
            'remoteTranslationPending',
            'remoteTranslationIngest',
            'remoteTranslationCollectStart',
            'remoteTranslationCollectStatus',
        ], $ops);

        $expectedAcl = [
            'remoteTranslationPending' => 'Weline_I18n::rest_v1_remote_translation_pending',
            'remoteTranslationIngest' => 'Weline_I18n::rest_v1_remote_translation_ingest',
            'remoteTranslationCollectStart' => 'Weline_I18n::rest_v1_remote_translation_collect_start',
            'remoteTranslationCollectStatus' => 'Weline_I18n::rest_v1_remote_translation_collect_status',
        ];
        foreach ($descriptor['operations'] as $operation) {
            $name = (string)($operation['name'] ?? '');
            self::assertTrue((bool)($operation['frontend'] ?? false));
            self::assertFalse((bool)($operation['external'] ?? true));
            self::assertSame('backend', $operation['auth'] ?? null);
            self::assertSame('source', $operation['backend_acl']['kind'] ?? null);
            self::assertSame($expectedAcl[$name] ?? '', (string)($operation['backend_acl']['source_id'] ?? ''));
            $paramNames = array_column(
                is_array($operation['params'] ?? null) ? $operation['params'] : [],
                'name'
            );
            if (in_array($name, [
                'remoteTranslationPending',
                'remoteTranslationIngest',
                'remoteTranslationCollectStart',
            ], true)) {
                self::assertContains('type', $paramNames, $name . ' must document type');
            }
        }
    }

    public function testCollectServiceSupportsEnqueueFlag(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DictionaryCollectService.php');
        self::assertStringContainsString('bool $enqueueAiTranslation = true', $src);
        self::assertStringContainsString('if ($enqueueAiTranslation && $collectedCount > 0)', $src);
    }

    public function testRemoteCollectUsesEnqueueFalse(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/RemoteCollectTaskService.php');
        self::assertStringContainsString("            false,\n        );", $src);
        self::assertStringContainsString('bin2hex(random_bytes(16))', $src);
        self::assertStringContainsString("STATUS_EXPIRED = 'expired'", $src);
        self::assertStringNotContainsString('enqueueAiTranslation: true', $src);
    }

    public function testRestThinShellAndAclSources(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Rest/V1/RemoteTranslation.php');
        self::assertStringContainsString("w_query('i18n_remote_translation'", $src);
        self::assertStringContainsString('Weline_I18n::rest_v1_remote_translation_pending', $src);
        self::assertStringContainsString('Weline_I18n::rest_v1_remote_translation_ingest', $src);
        self::assertStringContainsString('Weline_I18n::rest_v1_remote_translation_collect_start', $src);
        self::assertStringContainsString('Weline_I18n::rest_v1_remote_translation_collect_status', $src);
        self::assertStringContainsString("'type' => (string)(\$body['type'] ?? '')", $src);
        self::assertStringNotContainsString('DictionaryCollectService', $src);
    }

    public function testAssistLimitsMatchContracts(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/RemoteDictionaryAssistService.php');
        self::assertStringContainsString('LIMIT_MAX = 200', $src);
        self::assertStringContainsString('ITEMS_MAX = 100', $src);
        self::assertStringContainsString('FIELD_MAX_LEN = 8000', $src);
        self::assertStringContainsString('publishLocale', $src);
        self::assertStringContainsString('locale_not_allowed', $src);
        self::assertStringContainsString('duplicate_in_batch', $src);
        self::assertStringContainsString('TYPE_PHRASE', $src);
        self::assertStringContainsString('TYPE_META', $src);
        self::assertStringContainsString('TYPE_LOCAL_MODEL', $src);
        self::assertStringContainsString('wrong_shape_for_type', $src);
        self::assertStringContainsString("NOT LIKE ", $src);
        self::assertStringContainsString("LIKE ", $src);
        self::assertStringContainsString('@meta::%', $src);
    }

    public function testNormalizeTypeDefaultsAndRejectsUnknown(): void
    {
        self::assertSame(
            RemoteDictionaryAssistService::TYPE_PHRASE,
            RemoteDictionaryAssistService::normalizeType(null)
        );
        self::assertSame(
            RemoteDictionaryAssistService::TYPE_META,
            RemoteDictionaryAssistService::normalizeType('META')
        );
        self::assertSame(
            RemoteDictionaryAssistService::TYPE_LOCAL_MODEL,
            RemoteDictionaryAssistService::normalizeType('local_model')
        );
        $this->expectException(\InvalidArgumentException::class);
        RemoteDictionaryAssistService::normalizeType('widgets');
    }

    public function testQueryProviderRoutesLocalModelAndCollectGate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/I18nRemoteTranslationQueryProvider.php'
        );
        self::assertStringContainsString('TYPE_LOCAL_MODEL', $src);
        self::assertStringContainsString('remotePending', $src);
        self::assertStringContainsString('remoteIngest', $src);
        self::assertStringContainsString('local_model 不支持词典 collect', $src);
    }

    public function testLocalModelRemoteAssistSurface(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LocalModelTranslation/LocalModelTranslationService.php'
        );
        self::assertStringContainsString('function remotePending', $src);
        self::assertStringContainsString('function remoteIngest', $src);
        self::assertStringContainsString('locale_not_allowed', $src);
        self::assertStringContainsString('TYPE_LOCAL_MODEL', $src);
        self::assertStringContainsString('upsertLocalValue', $src);
    }

    public function testDemoScriptsDocumentRemoteType(): void
    {
        $readme = (string)file_get_contents(
            dirname(__DIR__, 3) . '/source/api-demo/i18n_remote_translation/README.md'
        );
        $php = (string)file_get_contents(
            dirname(__DIR__, 3) . '/source/api-demo/i18n_remote_translation/php/run.php'
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/source/api-demo/i18n_remote_translation/js/index.js'
        );
        self::assertStringContainsString('WELINE_REMOTE_TYPE', $readme);
        self::assertStringContainsString('local_model', $readme);
        self::assertStringContainsString('WidgetI18n', $readme);
        self::assertStringContainsString('WELINE_REMOTE_TYPE', $php);
        self::assertStringContainsString("'type' => \$type", $php);
        self::assertStringContainsString('WELINE_REMOTE_TYPE', $js);
        self::assertStringContainsString('type,', $js);
    }
}
}
