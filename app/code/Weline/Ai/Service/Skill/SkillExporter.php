<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Skill;

final class SkillExporter
{
    /**
     * @param array<string,mixed> $skill
     * @return array<string,mixed>
     */
    public function exportPackage(array $skill): array
    {
        $body = (string)($skill['normalized_body'] ?? $skill['body'] ?? '');
        return [
            'format' => 'ai_skill_package_v1',
            'code' => (string)($skill['code'] ?? ''),
            'name' => (string)($skill['name'] ?? $skill['code'] ?? ''),
            'description' => (string)($skill['description'] ?? ''),
            'body' => $body,
            'source_platform' => (string)($skill['source_platform'] ?? $skill['source_type'] ?? $skill['source'] ?? ''),
            'version' => (string)($skill['version'] ?? '1.0.0'),
            'exported_at' => \gmdate('c'),
            'body_hash' => (string)($skill['body_hash'] ?? \hash('sha256', $body)),
        ];
    }
}
