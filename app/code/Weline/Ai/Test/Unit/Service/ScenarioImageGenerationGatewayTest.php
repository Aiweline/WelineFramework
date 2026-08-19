<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;
use Weline\Ai\Service\ScenarioImageGenerationGateway;

final class ScenarioImageGenerationGatewayTest extends TestCase
{
    public function testReferenceImageSurvivesTheProviderNeutralMediaBoundary(): void
    {
        $reference = 'data:image/png;base64,' . \base64_encode('reference-image-bytes');
        $aiService = new class extends AiService {
            /** @var array<string,mixed> */
            public array $capturedParams = [];

            public function __construct()
            {
            }

            public function generateImage(
                string $prompt,
                ?string $modelCode = null,
                ?string $scenarioCode = null,
                array $params = []
            ): array {
                $this->capturedParams = $params;

                return ['images' => [], 'model' => 'image-model'];
            }
        };
        $gateway = new ScenarioImageGenerationGateway($aiService);

        $gateway->generate(
            'pagebuilder_ai_site_assets',
            [
                'scenario_invariants' => [],
                'site_context' => [],
                'task' => ['purpose' => 'Generate a premium card-game medal emblem'],
                'output_contract' => [],
                'validation_feedback' => ['status' => 'none'],
            ],
            'en_IN',
            ['image' => $reference, 'slot_id' => 'plan:theme:logo_generation:mark'],
            ['user_id' => 7, 'is_backend' => true],
        );

        self::assertSame($reference, $aiService->capturedParams['image'] ?? '');
    }

    public function testHumanRefinementAliasesSurviveTheProviderNeutralMediaBoundary(): void
    {
        $aiService = new class extends AiService {
            /** @var array<string,mixed> */
            public array $capturedParams = [];

            public function __construct()
            {
            }

            public function generateImage(
                string $prompt,
                ?string $modelCode = null,
                ?string $scenarioCode = null,
                array $params = []
            ): array {
                $this->capturedParams = $params;

                return ['images' => [], 'model' => 'image-model'];
            }
        };
        $gateway = new ScenarioImageGenerationGateway($aiService);

        $gateway->generate(
            'pagebuilder_ai_site_assets',
            [
                'scenario_invariants' => [],
                'site_context' => [],
                'task' => ['purpose' => 'Refine an existing hero image'],
                'output_contract' => [],
                'validation_feedback' => ['status' => 'none'],
            ],
            'en_IN',
            [
                'human_instruction' => 'Remove people and make the casino table the focal point.',
                'user_instruction' => 'Use a cinematic cyan and magenta atmosphere.',
                'instruction' => 'Keep all important content inside the safe crop area.',
            ],
        );

        self::assertSame(
            'Remove people and make the casino table the focal point.',
            $aiService->capturedParams['human_instruction'] ?? '',
        );
        self::assertSame(
            'Use a cinematic cyan and magenta atmosphere.',
            $aiService->capturedParams['user_instruction'] ?? '',
        );
        self::assertSame(
            'Keep all important content inside the safe crop area.',
            $aiService->capturedParams['instruction'] ?? '',
        );
    }
}
