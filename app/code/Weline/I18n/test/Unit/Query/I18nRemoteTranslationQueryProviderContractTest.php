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

final class I18nRemoteTranslationQueryProviderContractTest extends TestCase
{
    public function testProviderNameOpsAndAcl(): void
    {
        $provider = (new ReflectionClass(I18nRemoteTranslationQueryProvider::class))
            ->newInstanceWithoutConstructor();
        self::assertSame('i18n_remote_translation', $provider->getProviderName());

        $descriptor = $provider->getDescriptor();
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
            self::assertFalse((bool)($operation['frontend'] ?? true));
            self::assertFalse((bool)($operation['external'] ?? true));
            self::assertSame('backend', $operation['auth'] ?? null);
            self::assertSame('source', $operation['backend_acl']['kind'] ?? null);
            self::assertSame($expectedAcl[$name] ?? '', (string)($operation['backend_acl']['source_id'] ?? ''));
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
    }
}
}
