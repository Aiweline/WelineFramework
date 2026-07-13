<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Image;

/** Data-only request for resolving and binding a text-to-image scenario. */
final readonly class TextToImageScenarioBindingRequest
{
    private string $scenarioCode;

    /** @var list<string> */
    private array $referenceScenarioCodes;

    private string $placeholderModelCode;

    /**
     * @param list<string> $referenceScenarioCodes
     */
    public function __construct(
        string $scenarioCode,
        array $referenceScenarioCodes = [],
        string $placeholderModelCode = '',
    ) {
        $this->scenarioCode = trim($scenarioCode);
        $this->referenceScenarioCodes = $this->normalizeScenarioCodes($referenceScenarioCodes);
        $this->placeholderModelCode = trim($placeholderModelCode);
    }

    public function getScenarioCode(): string
    {
        return $this->scenarioCode;
    }

    /** @return list<string> */
    public function getReferenceScenarioCodes(): array
    {
        return $this->referenceScenarioCodes;
    }

    public function getPlaceholderModelCode(): string
    {
        return $this->placeholderModelCode;
    }

    /**
     * @param list<string> $scenarioCodes
     * @return list<string>
     */
    private function normalizeScenarioCodes(array $scenarioCodes): array
    {
        $normalized = [];
        $seen = [];
        foreach ($scenarioCodes as $scenarioCode) {
            $scenarioCode = trim((string)$scenarioCode);
            if ($scenarioCode === '' || isset($seen[$scenarioCode])) {
                continue;
            }
            $seen[$scenarioCode] = true;
            $normalized[] = $scenarioCode;
        }

        return $normalized;
    }
}
