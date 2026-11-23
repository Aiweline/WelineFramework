<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

use Weline\Seo\Model\HealthPolicy;

/**
 * 处理规则的读取、合并与保存（阶段 2 起可编辑）。
 *
 * 取值优先级：站点行 > 全站默认行（website_id=0） > 分类字典默认值。
 */
final class HealthPolicyService
{
    public function __construct(private HealthPolicy $policyModel)
    {
    }

    /**
     * 规则矩阵：每个本地可测问题一行。
     *
     * @return list<array<string, mixed>>
     */
    public function matrix(int $websiteId): array
    {
        $rows = $this->loadRows($websiteId);
        $matrix = [];

        foreach (HealthIssueCatalog::issueCodes() as $code) {
            $effective = $this->merge($websiteId, $code, $rows);
            $meta = HealthIssueCatalog::meta($code);
            $matrix[] = [
                'issue_code' => $code,
                'label' => (string)$meta['label'],
                'group_label' => (string)$meta['group_label'],
                'description' => (string)$meta['description'],
                'severity' => (string)$meta['severity'],
                'tone' => HealthIssueCatalog::severityTone($code),
                'source' => (string)$meta['source'],
                'auto_mode' => (string)$effective['auto_mode'],
                'action' => (string)$effective['action'],
                'grace_runs' => (int)$effective['grace_runs'],
                'restore_enabled' => (bool)$effective['restore_enabled'],
                'restore_grace_runs' => (int)$effective['restore_grace_runs'],
                'notify_channels' => (array)$effective['notify_channels'],
                'is_enabled' => (bool)$effective['is_enabled'],
                'configured' => (bool)$effective['configured'],
                'scope' => (string)$effective['scope'],
            ];
        }

        return $matrix;
    }

    /**
     * @return array<string, mixed>
     */
    public function getEffective(int $websiteId, string $issueCode): array
    {
        return $this->merge($websiteId, $issueCode, $this->loadRows($websiteId));
    }

    /**
     * 一次加载后返回全部问题的有效规则（供决策器循环使用，避免 N 次查询）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function effectiveMap(int $websiteId): array
    {
        $rows = $this->loadRows($websiteId);
        $map = [];
        foreach (HealthIssueCatalog::issueCodes() as $code) {
            $map[$code] = $this->merge($websiteId, $code, $rows);
        }

        return $map;
    }

    /**
     * 保存站点规则（upsert）。website_id=0 表示全站默认。
     *
     * @param array<string|int, array<string, mixed>> $policies
     * @return array{saved:int, errors:list<string>}
     */
    public function saveWebsitePolicies(int $websiteId, array $policies, int $updatedBy = 0): array
    {
        $allowedCodes = array_flip(HealthIssueCatalog::issueCodes());
        $allowedModes = [
            HealthPolicy::AUTO_OFF,
            HealthPolicy::AUTO_NOTIFY,
            HealthPolicy::AUTO_AUTO,
            HealthPolicy::AUTO_DRY_RUN,
        ];
        $allowedActions = [
            HealthPolicy::ACTION_REMOVE,
            HealthPolicy::ACTION_REPLACE,
            HealthPolicy::ACTION_REDIRECT,
            HealthPolicy::ACTION_NOTIFY,
            HealthPolicy::ACTION_EXCLUDE,
        ];
        $saved = 0;
        $errors = [];

        foreach ($policies as $key => $input) {
            if (!is_array($input)) {
                continue;
            }
            $code = (string)($input['issue_code'] ?? (is_string($key) ? $key : ''));
            if ($code === '' || !isset($allowedCodes[$code])) {
                $errors[] = sprintf('unknown issue code: %s', $code);
                continue;
            }

            $mode = (string)($input['auto_mode'] ?? HealthPolicy::AUTO_NOTIFY);
            if (!in_array($mode, $allowedModes, true)) {
                $errors[] = sprintf('%s: invalid auto_mode %s', $code, $mode);
                continue;
            }
            $action = (string)($input['action'] ?? HealthPolicy::ACTION_NOTIFY);
            if (!in_array($action, $allowedActions, true)) {
                $errors[] = sprintf('%s: invalid action %s', $code, $action);
                continue;
            }

            $channels = $input['notify_channels'] ?? ['backend'];
            if (is_string($channels)) {
                $channels = array_values(array_filter(array_map('trim', explode(',', $channels))));
            }
            if (!is_array($channels)) {
                $channels = ['backend'];
            }
            $channels = array_values(array_filter(array_map('strval', $channels), static fn (string $value): bool => $value !== ''));

            $record = $this->findPolicy($websiteId, $code) ?? (clone $this->policyModel)->reset();
            $record->setData([
                HealthPolicy::schema_fields_WEBSITE_ID => $websiteId,
                HealthPolicy::schema_fields_ISSUE_CODE => $code,
                HealthPolicy::schema_fields_AUTO_MODE => $mode,
                HealthPolicy::schema_fields_ACTION => $action,
                HealthPolicy::schema_fields_GRACE_RUNS => min(10, max(1, (int)($input['grace_runs'] ?? 2))),
                HealthPolicy::schema_fields_RESTORE_ENABLED => !empty($input['restore_enabled']) ? 1 : 0,
                HealthPolicy::schema_fields_RESTORE_GRACE_RUNS => min(10, max(1, (int)($input['restore_grace_runs'] ?? 3))),
                HealthPolicy::schema_fields_NOTIFY_CHANNELS => json_encode($channels, JSON_UNESCAPED_UNICODE),
                HealthPolicy::schema_fields_IS_ENABLED => !isset($input['is_enabled']) || !empty($input['is_enabled']) ? 1 : 0,
                HealthPolicy::schema_fields_UPDATED_BY => $updatedBy,
            ])->save();
            $saved++;
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    private function findPolicy(int $websiteId, string $issueCode): ?HealthPolicy
    {
        $record = (clone $this->policyModel)->reset()
            ->where(HealthPolicy::schema_fields_WEBSITE_ID, $websiteId)
            ->where(HealthPolicy::schema_fields_ISSUE_CODE, $issueCode)
            ->find()
            ->fetch();

        return $record instanceof HealthPolicy && (int)$record->getId() > 0 ? $record : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadRows(int $websiteId): array
    {
        $rows = [];
        $query = $this->policyModel->reset()
            ->where(HealthPolicy::schema_fields_WEBSITE_ID, array_values(array_unique([0, $websiteId])), 'IN');

        foreach ($query->select()->fetchArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = (string)($row[HealthPolicy::schema_fields_ISSUE_CODE] ?? '');
            if ($code === '') {
                continue;
            }
            $rows[$code . '|' . (int)($row[HealthPolicy::schema_fields_WEBSITE_ID] ?? 0)] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function merge(int $websiteId, string $issueCode, array $rows): array
    {
        $effective = HealthIssueCatalog::defaultPolicy($issueCode);
        $effective['configured'] = false;
        $effective['scope'] = 'default';

        $global = $rows[$issueCode . '|0'] ?? null;
        if (is_array($global)) {
            $effective = $this->applyRow($effective, $global);
            $effective['configured'] = true;
            $effective['scope'] = 'global';
        }

        $website = $rows[$issueCode . '|' . $websiteId] ?? null;
        if (is_array($website)) {
            $effective = $this->applyRow($effective, $website);
            $effective['configured'] = true;
            $effective['scope'] = 'website';
        }

        return $effective;
    }

    /**
     * @param array<string, mixed> $effective
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function applyRow(array $effective, array $row): array
    {
        $channels = $row[HealthPolicy::schema_fields_NOTIFY_CHANNELS] ?? '';
        if (is_string($channels) && $channels !== '') {
            $decoded = json_decode($channels, true);
            if (is_array($decoded)) {
                $effective['notify_channels'] = array_values($decoded);
            }
        }

        return array_merge($effective, [
            'auto_mode' => (string)($row[HealthPolicy::schema_fields_AUTO_MODE] ?? $effective['auto_mode']),
            'action' => (string)($row[HealthPolicy::schema_fields_ACTION] ?? $effective['action']),
            'grace_runs' => (int)($row[HealthPolicy::schema_fields_GRACE_RUNS] ?? $effective['grace_runs']),
            'restore_enabled' => (bool)(int)($row[HealthPolicy::schema_fields_RESTORE_ENABLED] ?? (int)$effective['restore_enabled']),
            'restore_grace_runs' => (int)($row[HealthPolicy::schema_fields_RESTORE_GRACE_RUNS] ?? $effective['restore_grace_runs']),
            'is_enabled' => (bool)(int)($row[HealthPolicy::schema_fields_IS_ENABLED] ?? (int)$effective['is_enabled']),
        ]);
    }
}
