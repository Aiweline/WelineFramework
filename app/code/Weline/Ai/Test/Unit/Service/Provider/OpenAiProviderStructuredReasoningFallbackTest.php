<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\OpenAiProvider;

final class OpenAiProviderStructuredReasoningFallbackTest extends TestCase
{
    public function testFallbackRequiresExplicitOptInAndBlankContent(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(
            OpenAiProvider::class,
            'resolveStructuredReasoningJsonFallback',
        );

        self::assertSame(
            '',
            $method->invoke($provider, [], '', '{"ok":true}'),
        );
        self::assertSame(
            '{"content":true}',
            $method->invoke(
                $provider,
                ['structured_reasoning_json_fallback' => true],
                '{"content":true}',
                '{"reasoning":true}',
            ),
        );
    }

    public function testFallbackSelectsLastCompleteTopLevelJsonPayload(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(
            OpenAiProvider::class,
            'resolveStructuredReasoningJsonFallback',
        );
        $reasoning = <<<'TEXT'
I will first inspect {"example":true}.
The final payload is:
```json
{"extra_fields":"content.title => Title:text:可用","html_extra":"<section></section>"}
```
TEXT;

        self::assertSame(
            '{"extra_fields":"content.title => Title:text:可用","html_extra":"<section></section>"}',
            $method->invoke(
                $provider,
                ['structured_reasoning_json_fallback' => true],
                '',
                $reasoning,
            ),
        );
    }

    public function testFallbackRejectsIncompleteOrNonJsonReasoning(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(
            OpenAiProvider::class,
            'resolveStructuredReasoningJsonFallback',
        );

        self::assertSame(
            '',
            $method->invoke(
                $provider,
                ['structured_reasoning_json_fallback' => true],
                '',
                'analysis only {"unfinished":',
            ),
        );
    }

    public function testFallbackReplacesNonBlankInvalidContentOnlyWithMatchingSchema(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(
            OpenAiProvider::class,
            'resolveStructuredReasoningJsonFallback',
        );
        $params = [
            'structured_reasoning_json_fallback' => true,
            'structured_reasoning_json_required_keys' => [
                'extra_fields',
                'php_variables',
                'css_extra',
                'css_responsive',
                'js_content',
                'html_content',
            ],
        ];
        $payload = '{"extra_fields":"","php_variables":"","css_extra":".x{}","css_responsive":"@media(max-width:768px){.x{display:block}}","js_content":"","html_content":"<section class=\\"x\\">Ready</section>"}';
        $reasoning = 'Draft {"example":true}. Final payload: ' . $payload . ' Later note {"not_the_payload":true}.';

        self::assertSame(
            $payload,
            $method->invoke($provider, $params, 'I could not place the JSON in content.', $reasoning),
        );
        self::assertSame(
            'I could not place the JSON in content.',
            $method->invoke(
                $provider,
                $params,
                'I could not place the JSON in content.',
                'Only {"example":true} is available.',
            ),
        );
    }

    public function testValidSchemaContentAlwaysWinsOverReasoningFallback(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(
            OpenAiProvider::class,
            'resolveStructuredReasoningJsonFallback',
        );
        $content = '{"extra_fields":"","html_content":"<section>Content</section>"}';

        self::assertSame(
            $content,
            $method->invoke(
                $provider,
                [
                    'structured_reasoning_json_fallback' => true,
                    'structured_reasoning_json_required_keys' => ['extra_fields', 'html_content'],
                ],
                $content,
                '{"extra_fields":"","html_content":"<section>Reasoning</section>"}',
            ),
        );
    }
}
