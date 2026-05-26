<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Setup\Db\Migration;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Database\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

class BindPagebuilderTextModelsToDeepseekV4Flash20260525V100 extends AbstractMigration
{
    private const TARGET_TEXT_MODEL = 'deepseek-v4-flash';
    private const TARGET_IMAGE_MODEL = 'gemini-3.1-flash-image-preview';

    public function getDescription(): string
    {
        return 'Bind pagebuilder_* scenario text models to deepseek-v4-flash';
    }

    public function getVersion(): string
    {
        return '1.0.0';
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
        return [AiScenarioAdapter::schema_table];
    }

    public function install(): bool
    {
        /** @var AiScenarioAdapter $adapterModel */
        $adapterModel = ObjectManager::getInstance(AiScenarioAdapter::class);
        $rows = $adapterModel->reset()
            ->where(AiScenarioAdapter::schema_fields_CODE, 'pagebuilder_%', 'LIKE')
            ->select(implode(',', [
                AiScenarioAdapter::schema_fields_ID,
                AiScenarioAdapter::schema_fields_CODE,
                AiScenarioAdapter::schema_fields_DEFAULT_MODEL,
                AiScenarioAdapter::schema_fields_MODEL_BINDINGS,
            ]))
            ->fetchArray();

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var AiScenarioAdapter $item */
            $item = ObjectManager::make(AiScenarioAdapter::class, ['data' => $row], '__construct');
            $code = (string)$row[AiScenarioAdapter::schema_fields_CODE];

            $bindings = $this->decodeBindings($item->getData(AiScenarioAdapter::schema_fields_MODEL_BINDINGS));
            $bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT] = self::TARGET_TEXT_MODEL;
            if ($code === 'pagebuilder_ai_site_assets' && empty($bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE])) {
                $bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE] = self::TARGET_IMAGE_MODEL;
            }

            $item->setData(AiScenarioAdapter::schema_fields_DEFAULT_MODEL, self::TARGET_TEXT_MODEL);
            $item->setData(
                AiScenarioAdapter::schema_fields_MODEL_BINDINGS,
                \json_encode($bindings, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            );
            $item->save();
            unset($item);
        }
        unset($rows);
        $adapterModel->clearData();

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /**
     * @return array<string,string>
     */
    private function decodeBindings(mixed $raw): array
    {
        if (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            return [];
        }

        $bindings = [];
        foreach ($raw as $modality => $modelCode) {
            $modality = AiModel::normalizePrimaryModality((string)$modality);
            $modelCode = \trim((string)$modelCode);
            if ($modality !== '' && $modelCode !== '') {
                $bindings[$modality] = $modelCode;
            }
        }

        return $bindings;
    }
}
