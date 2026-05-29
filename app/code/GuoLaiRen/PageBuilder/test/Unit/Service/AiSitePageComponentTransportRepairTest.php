<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AiSitePageComponentTransportRepairTest extends TestCase
{
    public function testPhpPrefixedJsonComponentPayloadIsRecovered(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new ReflectionMethod($service, 'decodeComponentPayloadWithRepair');
        $method->setAccessible(true);

        $payload = $method->invoke($service, '<?php {"extra_fields":"","php_variables":"","css_extra":"","css_responsive":"","html_content":"<section class=\'pb-c-root\'></section>","js_content":""}', 'content');

        self::assertIsArray($payload);
        self::assertSame("<section class='pb-c-root'></section>", $payload['html_content'] ?? null);
    }

    public function testGetConfigFallbackApostrophesAreEscapedBeforeValidation(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new ReflectionMethod($service, 'decodeAndNormalizeComponentContent');
        $method->setAccessible(true);

        $payload = $method->invoke($service, \json_encode([
            'extra_fields' => 'content.description => Description:textarea:Have questions about your team\'s approvals?',
            'php_variables' => '$contentDescription = $getConfig(\'content.description\', \'Have questions about your team\'s approvals?\');',
            'css_extra' => '',
            'css_responsive' => '',
            'html_content' => '<section class=\'pb-c-root\'><p><?= htmlspecialchars($contentDescription ?? \'\', ENT_QUOTES, \'UTF-8\') ?></p></section>',
            'js_content' => '',
        ], \JSON_THROW_ON_ERROR), 'content', 'bad json');

        self::assertIsArray($payload);
        self::assertSame(
            '$contentDescription = $getConfig(\'content.description\', \'Have questions about your team\\\'s approvals?\');',
            $payload['php_variables'] ?? null
        );
    }
}
