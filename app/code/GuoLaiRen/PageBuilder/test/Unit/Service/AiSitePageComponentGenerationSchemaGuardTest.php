<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentGenerationSchemaGuardTest extends TestCase
{
    public function testComponentGenerationRequestsStrictJsonSchemaEnvelope(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $format = (function (): array {
            return $this->buildComponentResponseFormat('content');
        })->call($service);

        self::assertSame('json_schema', $format['type'] ?? null);
        self::assertTrue((bool)($format['json_schema']['strict'] ?? false));
        self::assertFalse((bool)($format['json_schema']['schema']['additionalProperties'] ?? true));
        self::assertSame(
            ['extra_fields', 'php_variables', 'css_extra', 'css_responsive', 'html_content', 'js_content'],
            $format['json_schema']['schema']['required'] ?? []
        );
    }

    public function testComponentJsonGuardForbidsTopLevelPhpTransport(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->prependComponentJsonOnlyGuard('Base prompt', true);
        })->call($service);

        self::assertStringContainsString('never start the response with `<?php`', $prompt);
        self::assertStringContainsString('The raw final response must start with `{`', $prompt);
        self::assertStringContainsString('php_variables` is a JSON string containing assignment lines only', $prompt);
        self::assertStringContainsString('Never output bare locale words such as', $prompt);
    }

    public function testActionContractFailuresAreRetryable(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $retryable = (function (): bool {
            return $this->shouldRetryAiComponentGeneration(
                new \RuntimeException(
                    'AI component CTA/action contract failed: CTA must be a real anchor with href or button with data-pb-ai-action'
                )
            );
        })->call($service);

        self::assertTrue($retryable);
    }

    public function testPhpPrefixedJsonFailureAddsTransportRecoveryContract(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->buildFailureSpecificRecoveryContract(
                new \RuntimeException('AI did not return a valid component JSON payload: component_json.parse found=<?php {"extra_fields": "..."}'),
                'content/blog-post-related-resources',
                'pb-c',
                false,
                []
            );
        })->call($service);

        self::assertStringContainsString('FAILURE_FIX_JSON_TRANSPORT_PREFIX', $prompt);
        self::assertStringContainsString('first byte must be `{`', $prompt);
        self::assertStringContainsString('Do not output `<?php`', $prompt);
    }

    public function testFormEmailPlaceholdersAreExplicitlyForbidden(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringContainsString('FAILURE_FIX_FORM_EMAIL_PLACEHOLDER', $source);
        self::assertStringContainsString('Form email inputs may exist', $source);
        self::assertStringContainsString('email placeholders/defaults must be localized words with no `@`', $source);
    }

    public function testFormGuidanceNotesAreExplicitEditableFields(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringContainsString('form.note_text', $source);
        self::assertStringContainsString('privacy/security note', $source);
        self::assertStringContainsString('small microcopy', $source);
    }
}
