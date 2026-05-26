<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Setup\Db\Migration;

use Weline\Ai\Model\AiModel;
use Weline\Database\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

class SyncDeepseekV4FlashCapabilities20260525V101 extends AbstractMigration
{
    private const MODEL_CODE = 'deepseek-v4-flash';
    private const UNSUPPORTED_JSON_SCHEMA_CAPABILITIES = [
        'structured_outputs',
        'json_schema',
        'json_schema_response_format',
        'response_format_json_schema',
    ];

    public function getDescription(): string
    {
        return 'Sync deepseek-v4-flash capabilities with supported response formats';
    }

    public function getVersion(): string
    {
        return '1.0.1';
    }

    public function getDate(): string
    {
        return '2026-05-25';
    }

    /**
     * @return array<int,string>
     */
    public function getAffectedTables(): array
    {
        return [AiModel::schema_table];
    }

    public function install(): bool
    {
        /** @var AiModel $model */
        $model = ObjectManager::getInstance(AiModel::class)
            ->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, self::MODEL_CODE)
            ->find()
            ->fetch();

        if (!$model || !$model->getId()) {
            return true;
        }

        $capabilities = [];
        foreach ($this->decodeCapabilities($model->getData(AiModel::schema_fields_CAPABILITIES)) as $capability) {
            if (\in_array(\strtolower($capability), self::UNSUPPORTED_JSON_SCHEMA_CAPABILITIES, true)) {
                continue;
            }
            $capabilities[] = $capability;
        }

        $model->setData(
            AiModel::schema_fields_CAPABILITIES,
            \json_encode(\array_values(\array_unique($capabilities)), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
        );
        $config = $model->getConfig();
        $config['response_format_json_schema'] = false;
        $model->setData(
            AiModel::schema_fields_CONFIG,
            \json_encode($config, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
        );
        $model->save();

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    private function decodeCapabilities(mixed $raw): array
    {
        if (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            return [];
        }

        $capabilities = [];
        foreach ($raw as $key => $value) {
            if (\is_string($key)) {
                if ($value === false || $value === 0 || $value === '0' || $value === null || $value === '') {
                    continue;
                }
                $candidate = $key;
            } else {
                $candidate = (string)$value;
            }

            $candidate = \trim($candidate);
            if ($candidate !== '') {
                $capabilities[] = $candidate;
            }
        }

        return \array_values(\array_unique($capabilities));
    }
}
