<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Agent;

use Weline\Ai\Api\AgentCatalogInterface;
use Weline\Ai\Api\ToolInterface;
use Weline\Ai\Model\AiAgent;
use Weline\Ai\Model\AiAgentTool;
use Weline\Framework\Manager\ObjectManager;

final class AgentCatalogService implements AgentCatalogInterface
{
    public function __construct(
        private readonly ?AiAgent $agentModel = null,
        private readonly ?AiAgentTool $toolModel = null,
    ) {
    }

    public function listCatalog(bool $includeInactive = true): array
    {
        $query = $this->agentModel()->reset()->clearQuery();
        if (!$includeInactive) {
            $query->where(AiAgent::schema_fields_IS_ACTIVE, 1);
        }
        $rows = $query->order(AiAgent::schema_fields_CODE, 'ASC')->select()->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = $this->rowToAgentItem($row);
            if ($item['code'] !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function findByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $row = $this->agentModel()->reset()->clearQuery()
            ->where(AiAgent::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        if (!$row || !$row->getId()) {
            return null;
        }

        return $this->rowToAgentItem($row->getData());
    }

    public function toolPolicy(string $agentCode): array
    {
        $policy = [];
        foreach ($this->listToolRows($agentCode) as $row) {
            $name = trim((string)($row[AiAgentTool::schema_fields_TOOL_NAME] ?? ''));
            if ($name === '') {
                continue;
            }
            $policy[$name] = [
                'enabled' => (int)($row[AiAgentTool::schema_fields_IS_ENABLED] ?? 1) === 1,
                'present' => (int)($row[AiAgentTool::schema_fields_IS_PRESENT] ?? 1) === 1,
                'description_override' => $this->nullableString($row[AiAgentTool::schema_fields_DESCRIPTION_OVERRIDE] ?? null),
            ];
        }

        return $policy;
    }

    public function syncToolsFromAgent(string $agentCode, array $tools): void
    {
        $agentCode = trim($agentCode);
        if ($agentCode === '') {
            return;
        }

        $seen = [];
        $order = 0;
        foreach ($tools as $tool) {
            if (!is_object($tool) || !method_exists($tool, 'getName')) {
                continue;
            }
            $name = trim((string)$tool->getName());
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $description = method_exists($tool, 'getDescription') ? (string)$tool->getDescription() : '';
            $parameters = method_exists($tool, 'getParameters') ? $tool->getParameters() : [];
            if (!is_array($parameters)) {
                $parameters = [];
            }
            $className = $tool instanceof ToolInterface ? get_class($tool) : get_class($tool);
            $this->upsertToolSource($agentCode, $name, $description, $parameters, $order, $className);
            $order++;
        }

        $this->markMissingTools($agentCode, array_keys($seen));
        $this->refreshToolsCount($agentCode);
    }

    public function markMissingAgents(array $presentCodes): void
    {
        $present = [];
        foreach ($presentCodes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $present[$code] = true;
            }
        }

        $rows = $this->agentModel()->reset()->clearQuery()->select()->fetchArray();
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[AiAgent::schema_fields_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $shouldPresent = isset($present[$code]) ? 1 : 0;
            if ((int)($row[AiAgent::schema_fields_IS_PRESENT] ?? 1) === $shouldPresent) {
                continue;
            }
            $model = $this->loadAgentByCode($code);
            if ($model === null) {
                continue;
            }
            $model->setData(AiAgent::schema_fields_IS_PRESENT, $shouldPresent)->save();
        }
    }

    public function saveOverrides(string $code, ?string $nameOverride, ?string $descriptionOverride): array
    {
        $model = $this->requireAgent($code);
        $model->setData(AiAgent::schema_fields_NAME_OVERRIDE, $this->persistOverride($nameOverride));
        $model->setData(AiAgent::schema_fields_DESCRIPTION_OVERRIDE, $this->persistOverride($descriptionOverride));
        $model->save();

        return $this->findByCode($code) ?? [];
    }

    public function setActive(string $code, bool $active): array
    {
        $model = $this->requireAgent($code);
        $present = (int)$model->getData(AiAgent::schema_fields_IS_PRESENT) === 1;
        if ($active && !$present) {
            throw new \InvalidArgumentException((string)__('缺失的智能体不能启用。'));
        }
        $model->setData(AiAgent::schema_fields_IS_ACTIVE, $active ? 1 : 0)->save();

        return $this->findByCode($code) ?? [];
    }

    public function saveToolOverride(string $agentCode, string $toolName, ?string $descriptionOverride): array
    {
        $tool = $this->requireTool($agentCode, $toolName);
        $tool->setData(AiAgentTool::schema_fields_DESCRIPTION_OVERRIDE, $this->persistOverride($descriptionOverride));
        $tool->save();

        return $this->findByCode($agentCode) ?? [];
    }

    public function setToolEnabled(string $agentCode, string $toolName, bool $enabled): array
    {
        $tool = $this->requireTool($agentCode, $toolName);
        $present = (int)$tool->getData(AiAgentTool::schema_fields_IS_PRESENT) === 1;
        if ($enabled && !$present) {
            throw new \InvalidArgumentException((string)__('缺失的工具不能启用。'));
        }
        $tool->setData(AiAgentTool::schema_fields_IS_ENABLED, $enabled ? 1 : 0)->save();

        return $this->findByCode($agentCode) ?? [];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function upsertToolSource(
        string $agentCode,
        string $toolName,
        string $description,
        array $parameters,
        int $sortOrder,
        string $className,
    ): void {
        $existing = $this->findToolModel($agentCode, $toolName);
        $payload = [
            AiAgentTool::schema_fields_AGENT_CODE => $agentCode,
            AiAgentTool::schema_fields_TOOL_NAME => $toolName,
            AiAgentTool::schema_fields_DESCRIPTION => $description,
            AiAgentTool::schema_fields_PARAMETERS_JSON => json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            AiAgentTool::schema_fields_CLASS_NAME => $className,
            AiAgentTool::schema_fields_SORT_ORDER => $sortOrder,
            AiAgentTool::schema_fields_IS_PRESENT => 1,
        ];
        if ($existing === null) {
            $payload[AiAgentTool::schema_fields_IS_ENABLED] = 1;
            $model = $this->toolModel()->reset();
            $model->setData($payload)->save();
            return;
        }
        foreach ($payload as $field => $value) {
            $existing->setData($field, $value);
        }
        $existing->save();
    }

    /** @param list<string> $presentNames */
    private function markMissingTools(string $agentCode, array $presentNames): void
    {
        $present = array_fill_keys($presentNames, true);
        foreach ($this->listToolRows($agentCode) as $row) {
            $name = trim((string)($row[AiAgentTool::schema_fields_TOOL_NAME] ?? ''));
            if ($name === '') {
                continue;
            }
            $shouldPresent = isset($present[$name]) ? 1 : 0;
            if ((int)($row[AiAgentTool::schema_fields_IS_PRESENT] ?? 1) === $shouldPresent) {
                continue;
            }
            $tool = $this->findToolModel($agentCode, $name);
            if ($tool === null) {
                continue;
            }
            $tool->setData(AiAgentTool::schema_fields_IS_PRESENT, $shouldPresent)->save();
        }
    }

    private function refreshToolsCount(string $agentCode): void
    {
        $agent = $this->loadAgentByCode($agentCode);
        if ($agent === null) {
            return;
        }
        $presentCount = 0;
        foreach ($this->listToolRows($agentCode) as $row) {
            if ((int)($row[AiAgentTool::schema_fields_IS_PRESENT] ?? 0) === 1) {
                $presentCount++;
            }
        }
        $agent->setData(AiAgent::schema_fields_TOOLS_COUNT, $presentCount)->save();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToAgentItem(array $row): array
    {
        $code = trim((string)($row[AiAgent::schema_fields_CODE] ?? ''));
        $name = (string)($row[AiAgent::schema_fields_NAME] ?? '');
        $nameOverride = $this->nullableString($row[AiAgent::schema_fields_NAME_OVERRIDE] ?? null);
        $description = (string)($row[AiAgent::schema_fields_DESCRIPTION] ?? '');
        $descriptionOverride = $this->nullableString($row[AiAgent::schema_fields_DESCRIPTION_OVERRIDE] ?? null);
        $isActive = (int)($row[AiAgent::schema_fields_IS_ACTIVE] ?? 1) === 1;
        $isPresent = (int)($row[AiAgent::schema_fields_IS_PRESENT] ?? 1) === 1;
        $scenarios = $row[AiAgent::schema_fields_SCENARIOS] ?? [];
        if (is_string($scenarios)) {
            $decoded = json_decode($scenarios, true);
            $scenarios = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($scenarios)) {
            $scenarios = [];
        }

        $status = 'active';
        if (!$isPresent) {
            $status = 'missing';
        } elseif (!$isActive) {
            $status = 'disabled';
        }

        return [
            'id' => (int)($row[AiAgent::schema_fields_ID] ?? 0),
            'code' => $code,
            'name' => $name,
            'name_override' => $nameOverride,
            'effective_name' => $nameOverride ?? $name,
            'description' => $description,
            'description_override' => $descriptionOverride,
            'effective_description' => $descriptionOverride ?? $description,
            'version' => (string)($row[AiAgent::schema_fields_VERSION] ?? ''),
            'class_name' => (string)($row[AiAgent::schema_fields_CLASS_NAME] ?? ''),
            'file_path' => (string)($row[AiAgent::schema_fields_FILE_PATH] ?? ''),
            'scenarios' => array_values(array_filter(array_map('strval', $scenarios))),
            'tools_count' => (int)($row[AiAgent::schema_fields_TOOLS_COUNT] ?? 0),
            'max_iterations' => (int)($row[AiAgent::schema_fields_MAX_ITERATIONS] ?? 0),
            'module' => (string)($row[AiAgent::schema_fields_MODULE] ?? ''),
            'is_active' => $isActive,
            'is_present' => $isPresent,
            'status' => $status,
            'created_time' => (int)($row[AiAgent::schema_fields_CREATED_TIME] ?? 0),
            'updated_time' => (int)($row[AiAgent::schema_fields_UPDATED_TIME] ?? 0),
            'tools' => $this->listToolItems($code),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listToolItems(string $agentCode): array
    {
        $items = [];
        foreach ($this->listToolRows($agentCode) as $row) {
            $name = trim((string)($row[AiAgentTool::schema_fields_TOOL_NAME] ?? ''));
            if ($name === '') {
                continue;
            }
            $description = (string)($row[AiAgentTool::schema_fields_DESCRIPTION] ?? '');
            $override = $this->nullableString($row[AiAgentTool::schema_fields_DESCRIPTION_OVERRIDE] ?? null);
            $parameters = [];
            $rawParameters = $row[AiAgentTool::schema_fields_PARAMETERS_JSON] ?? '{}';
            if (is_string($rawParameters)) {
                $decoded = json_decode($rawParameters, true);
                $parameters = is_array($decoded) ? $decoded : [];
            } elseif (is_array($rawParameters)) {
                $parameters = $rawParameters;
            }
            $isEnabled = (int)($row[AiAgentTool::schema_fields_IS_ENABLED] ?? 1) === 1;
            $isPresent = (int)($row[AiAgentTool::schema_fields_IS_PRESENT] ?? 1) === 1;
            $items[] = [
                'tool_name' => $name,
                'description' => $description,
                'description_override' => $override,
                'effective_description' => $override ?? $description,
                'parameters' => $parameters,
                'class_name' => (string)($row[AiAgentTool::schema_fields_CLASS_NAME] ?? ''),
                'sort_order' => (int)($row[AiAgentTool::schema_fields_SORT_ORDER] ?? 0),
                'is_enabled' => $isEnabled,
                'is_present' => $isPresent,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listToolRows(string $agentCode): array
    {
        if (trim($agentCode) === '') {
            return [];
        }
        $rows = $this->toolModel()->reset()->clearQuery()
            ->where(AiAgentTool::schema_fields_AGENT_CODE, $agentCode)
            ->order(AiAgentTool::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function requireAgent(string $code): AiAgent
    {
        $model = $this->loadAgentByCode($code);
        if ($model === null) {
            throw new \InvalidArgumentException((string)__('智能体不存在：%{1}', [trim($code)]));
        }

        return $model;
    }

    private function loadAgentByCode(string $code): ?AiAgent
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $model = $this->agentModel()->reset()->clearQuery()
            ->where(AiAgent::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $model && $model->getId() ? $model : null;
    }

    private function requireTool(string $agentCode, string $toolName): AiAgentTool
    {
        $tool = $this->findToolModel($agentCode, $toolName);
        if ($tool === null) {
            throw new \InvalidArgumentException((string)__('工具不存在：%{1}', [trim($toolName)]));
        }

        return $tool;
    }

    private function findToolModel(string $agentCode, string $toolName): ?AiAgentTool
    {
        $agentCode = trim($agentCode);
        $toolName = trim($toolName);
        if ($agentCode === '' || $toolName === '') {
            return null;
        }
        $model = $this->toolModel()->reset()->clearQuery()
            ->where(AiAgentTool::schema_fields_AGENT_CODE, $agentCode)
            ->where(AiAgentTool::schema_fields_TOOL_NAME, $toolName)
            ->find()
            ->fetch();

        return $model && $model->getId() ? $model : null;
    }

    private function persistOverride(mixed $value): string
    {
        return trim((string)$value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);

        return $text === '' ? null : $text;
    }

    private function agentModel(): AiAgent
    {
        $model = $this->agentModel ?? ObjectManager::getInstance(AiAgent::class);

        return clone $model;
    }

    private function toolModel(): AiAgentTool
    {
        $model = $this->toolModel ?? ObjectManager::getInstance(AiAgentTool::class);

        return clone $model;
    }
}
