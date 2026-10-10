<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;

/**
 * 供应商账户连通测试：从可测模型列表解析默认/最新模型代码。
 * 模型列表行的连通测试不走本解析器——直接测点击的那条 model_code。
 */
final class ConnectionTestModelResolver
{
    /**
     * @param array<int,array<string,mixed>> $models
     */
    public static function resolveDefaultModelCode(array $models, string $configuredDefault = ''): string
    {
        $configuredDefault = trim($configuredDefault);
        $candidates = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $code = trim((string)($model['code'] ?? $model['value'] ?? ''));
            if ($code === '') {
                continue;
            }
            $candidates[] = [
                'code' => $code,
                'is_default' => !empty($model['is_default']),
                'created' => (int)($model['created'] ?? $model['created_at'] ?? 0),
                'primary_modality' => AiModel::normalizePrimaryModality((string)($model['primary_modality'] ?? '')),
            ];
        }
        if ($candidates === []) {
            return $configuredDefault;
        }

        foreach ($candidates as $candidate) {
            if ($configuredDefault !== '' && strcasecmp($candidate['code'], $configuredDefault) === 0) {
                return $candidate['code'];
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate['is_default']) {
                return $candidate['code'];
            }
        }

        $chatCandidates = array_values(array_filter(
            $candidates,
            static fn(array $item): bool => $item['primary_modality'] === AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT
        ));
        $pool = $chatCandidates !== [] ? $chatCandidates : $candidates;

        usort($pool, static function (array $a, array $b): int {
            if ($a['created'] !== $b['created']) {
                return $b['created'] <=> $a['created'];
            }
            return strcasecmp($b['code'], $a['code']);
        });

        return (string)$pool[0]['code'];
    }
}
