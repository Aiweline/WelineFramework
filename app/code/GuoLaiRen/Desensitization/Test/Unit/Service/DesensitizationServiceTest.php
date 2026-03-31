<?php

declare(strict_types=1);

namespace GuoLaiRen\Desensitization\Test\Unit\Service;

use GuoLaiRen\Desensitization\Model\DesensitizationLog;
use GuoLaiRen\Desensitization\Model\DesensitizationRule;
use GuoLaiRen\Desensitization\Service\DesensitizationService;
use PHPUnit\Framework\TestCase;

class DesensitizationServiceTest extends TestCase
{
    public function testMaskChineseNameStrategyIsAppliedWithoutEval(): void
    {
        $rule = $this->createRule('/[\x{4e00}-\x{9fa5}]{2,4}/u', 'mask_chinese_name', 1);
        $service = $this->createServiceWithRules([$rule]);

        $result = $service->desensitize('张三', 'regex');

        self::assertSame('张*', $result);
    }

    public function testDangerousFunctionReplacementIsIgnored(): void
    {
        $rule = $this->createRule('/李雷/u', 'function($m){ return "HACK"; }', 2);
        $service = $this->createServiceWithRules([$rule]);

        $result = $service->desensitize('李雷', 'regex');

        self::assertSame('李雷', $result);
    }

    public function testReplacePhrasesInContentUsesByteOffsetsSafely(): void
    {
        $service = $this->createServiceWithRules([]);
        $content = '请不要自残行为';
        $target = '自残';
        $start = strpos($content, $target);
        self::assertNotFalse($start);
        $start = (int)$start;
        $end = $start + strlen($target);

        $method = new \ReflectionMethod($service, 'replacePhrasesInContent');
        $method->setAccessible(true);
        $result = $method->invoke(
            $service,
            $content,
            [['match' => $target, 'start' => $start, 'end' => $end]],
            [0 => '自我伤害']
        );

        self::assertSame('请不要自我伤害行为', $result);
    }

    /**
     * @param array<int, object> $rules
     */
    private function createServiceWithRules(array $rules): DesensitizationService
    {
        $ruleModel = new class($rules) extends DesensitizationRule {
            /**
             * @param array<int, object> $rules
             */
            public function __construct(private readonly array $rules)
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function getActiveRules(): self
            {
                return $this;
            }

            public function getByType(string $type): self
            {
                return $this;
            }

            public function select(): self
            {
                return $this;
            }

            /**
             * @return array<int, object>
             */
            public function fetch(): array
            {
                return $this->rules;
            }
        };

        $logModel = new class() extends DesensitizationLog {
            public function __construct()
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function logOperation(
                string $original,
                string $desensitized,
                int $ruleId,
                string $method,
                float $executionTime
            ): self
            {
                return $this;
            }
        };

        return new DesensitizationService($ruleModel, $logModel);
    }

    private function createRule(string $pattern, string $replacement, int $ruleId): object
    {
        return new class($pattern, $replacement, $ruleId) {
            public function __construct(
                private readonly string $pattern,
                private readonly string $replacement,
                private readonly int $ruleId
            ) {
            }

            public function getData(string $key): string
            {
                return match ($key) {
                    'pattern' => $this->pattern,
                    'replacement' => $this->replacement,
                    default => '',
                };
            }

            public function getRuleId(): int
            {
                return $this->ruleId;
            }
        };
    }
}

