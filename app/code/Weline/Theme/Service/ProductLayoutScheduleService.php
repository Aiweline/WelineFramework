<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\DateTime\Timezone;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\Scope\ScopedConfigData;
use Weline\Theme\Model\ThemeLayoutSchedule;
use Weline\Theme\Model\ThemeVirtualLayout;

/**
 * CRUD + resolve-time matching for product layout schedules.
 */
final class ProductLayoutScheduleService
{
    public function __construct(
        private readonly ThemeLayoutSchedule $schedule,
        private readonly ThemeVirtualLayoutService $virtualLayout,
        private readonly ProductLayoutOptionService $options,
        private readonly ProductLayoutCacheBustService $cacheBust,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(array $input): array
    {
        $scheduleId = max(0, (int)($input['schedule_id'] ?? $input['id'] ?? 0));
        $name = trim((string)($input['name'] ?? ''));
        $layoutType = strtolower(trim((string)($input['layout_type'] ?? ProductLayoutOptionService::LAYOUT_TYPE)));
        $layoutType = preg_replace('/[^a-z0-9_-]+/', '-', $layoutType) ?? '';
        $layoutType = trim($layoutType, '-_');
        if ($layoutType === '') {
            $layoutType = ProductLayoutOptionService::LAYOUT_TYPE;
        }
        $layoutOption = $this->virtualLayout->normalizeLayoutOption((string)($input['layout_option'] ?? ''));
        $targetType = strtolower(trim((string)($input['target_type'] ?? '')));
        $targetId = max(0, (int)($input['target_id'] ?? 0));
        $scope = trim((string)($input['scope'] ?? ScopedConfigData::SCOPE_GLOBAL));
        if ($scope === '') {
            $scope = ScopedConfigData::SCOPE_GLOBAL;
        }
        $timezone = Timezone::resolveWebsiteTimezone(
            trim((string)($input['timezone'] ?? '')) !== '' ? (string)$input['timezone'] : null,
        );
        $startsAt = Timezone::localInputToUtcSql((string)($input['starts_at'] ?? ''), $timezone);
        $endsAt = Timezone::localInputToUtcSql((string)($input['ends_at'] ?? ''), $timezone);
        $priority = (int)($input['priority'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? ThemeLayoutSchedule::STATUS_ENABLED)));
        if (!in_array($status, [ThemeLayoutSchedule::STATUS_ENABLED, ThemeLayoutSchedule::STATUS_DISABLED], true)) {
            $status = ThemeLayoutSchedule::STATUS_ENABLED;
        }
        $websiteId = max(0, (int)($input['website_id'] ?? 0));
        $createdBy = isset($input['created_by']) ? (int)$input['created_by'] : null;

        if ($name === '' || $layoutOption === '' || $targetType === '' || $targetId <= 0 || $startsAt === null || $endsAt === null) {
            return ['success' => false, 'status' => 'invalid_payload', 'message' => (string)__('定时计划参数不完整')];
        }
        if (!in_array($targetType, [
            ThemeVirtualLayout::TARGET_PRODUCT,
            ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT,
        ], true)) {
            return ['success' => false, 'status' => 'invalid_target_type', 'message' => (string)__('仅支持产品或分类默认产品布局定时')];
        }
        if (!$this->options->optionExists($layoutOption)) {
            return ['success' => false, 'status' => 'invalid_layout_option', 'message' => (string)__('布局选项不存在')];
        }
        if ($endsAt <= $startsAt) {
            return ['success' => false, 'status' => 'invalid_window', 'message' => (string)__('结束时间必须晚于开始时间')];
        }

        /** @var ThemeLayoutSchedule $model */
        $model = ObjectManager::getInstance(ThemeLayoutSchedule::class);
        $model = clone $model;
        $model->clear()->clearQuery();
        $nowUtc = Timezone::utcNowSql();
        if ($scheduleId > 0) {
            $model->load($scheduleId);
            if (!(int)$model->getId()) {
                return ['success' => false, 'status' => 'not_found', 'message' => (string)__('计划不存在')];
            }
        } else {
            $model->clearData();
            $model->setData(ThemeLayoutSchedule::schema_fields_CREATE_TIME, $nowUtc);
            if ($createdBy !== null) {
                $model->setData(ThemeLayoutSchedule::schema_fields_CREATED_BY, $createdBy);
            }
        }

        $model->setData([
            ThemeLayoutSchedule::schema_fields_NAME => mb_substr($name, 0, 255),
            ThemeLayoutSchedule::schema_fields_LAYOUT_TYPE => $layoutType,
            ThemeLayoutSchedule::schema_fields_LAYOUT_OPTION => $layoutOption,
            ThemeLayoutSchedule::schema_fields_TARGET_TYPE => $targetType,
            ThemeLayoutSchedule::schema_fields_TARGET_ID => $targetId,
            ThemeLayoutSchedule::schema_fields_SCOPE => $scope,
            ThemeLayoutSchedule::schema_fields_TIMEZONE => mb_substr($timezone, 0, 64),
            ThemeLayoutSchedule::schema_fields_STARTS_AT => $startsAt,
            ThemeLayoutSchedule::schema_fields_ENDS_AT => $endsAt,
            ThemeLayoutSchedule::schema_fields_PRIORITY => $priority,
            ThemeLayoutSchedule::schema_fields_STATUS => $status,
            ThemeLayoutSchedule::schema_fields_WEBSITE_ID => $websiteId,
            ThemeLayoutSchedule::schema_fields_UPDATE_TIME => $nowUtc,
        ])->save();

        $id = (int)$model->getId();
        $bust = $this->cacheBust->bustForScheduleTarget(
            $targetType,
            $targetId,
            $websiteId,
            'theme_layout_schedule_save',
            'save',
            $id,
        );

        return [
            'success' => true,
            'status' => 'saved',
            'schedule' => $this->toArray($model),
            'cache_bust' => $bust,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function delete(int $scheduleId): array
    {
        $scheduleId = max(0, $scheduleId);
        if ($scheduleId <= 0) {
            return ['success' => false, 'status' => 'invalid_id'];
        }
        /** @var ThemeLayoutSchedule $model */
        $model = ObjectManager::getInstance(ThemeLayoutSchedule::class);
        $model = clone $model;
        $model->clear()->clearQuery()->load($scheduleId);
        if (!(int)$model->getId()) {
            return ['success' => false, 'status' => 'not_found'];
        }
        $snapshot = $this->toArray($model);
        $model->delete();
        $bust = $this->cacheBust->bustForScheduleTarget(
            (string)$snapshot['target_type'],
            (int)$snapshot['target_id'],
            (int)$snapshot['website_id'],
            'theme_layout_schedule_delete',
            'end',
            $scheduleId,
        );

        return ['success' => true, 'status' => 'deleted', 'schedule' => $snapshot, 'cache_bust' => $bust];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTarget(
        string $targetType,
        int $targetId,
        string $layoutType = ProductLayoutOptionService::LAYOUT_TYPE,
    ): array {
        $targetType = strtolower(trim($targetType));
        $targetId = max(0, $targetId);
        $layoutType = $layoutType !== '' ? $layoutType : ProductLayoutOptionService::LAYOUT_TYPE;
        if ($targetType === '' || $targetId <= 0) {
            return [];
        }
        /** @var ThemeLayoutSchedule $model */
        $model = ObjectManager::getInstance(ThemeLayoutSchedule::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayoutSchedule::schema_fields_TARGET_TYPE, $targetType)
            ->where(ThemeLayoutSchedule::schema_fields_TARGET_ID, $targetId)
            ->where(ThemeLayoutSchedule::schema_fields_LAYOUT_TYPE, $layoutType)
            ->order(ThemeLayoutSchedule::schema_fields_PRIORITY, 'DESC')
            ->order(ThemeLayoutSchedule::schema_fields_UPDATE_TIME, 'DESC')
            ->select()
            ->fetchArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = $this->rowToArray($row);
            }
        }

        return $out;
    }

    /**
     * @return array{schedule_id:int,layout_option:string,priority:int,name:string}|null
     */
    public function resolveActive(
        string $targetType,
        int $targetId,
        string $layoutType = ProductLayoutOptionService::LAYOUT_TYPE,
        ?string $scope = null,
        ?\DateTimeInterface $now = null,
        int $websiteId = 0,
    ): ?array {
        $targetType = strtolower(trim($targetType));
        $targetId = max(0, $targetId);
        if ($targetType === '' || $targetId <= 0) {
            return null;
        }
        $nowDt = $now instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowSql = $nowDt->format(Timezone::SQL_FORMAT);

        /** @var ThemeLayoutSchedule $model */
        $model = ObjectManager::getInstance(ThemeLayoutSchedule::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayoutSchedule::schema_fields_TARGET_TYPE, $targetType)
            ->where(ThemeLayoutSchedule::schema_fields_TARGET_ID, $targetId)
            ->where(ThemeLayoutSchedule::schema_fields_LAYOUT_TYPE, $layoutType !== '' ? $layoutType : ProductLayoutOptionService::LAYOUT_TYPE)
            ->where(ThemeLayoutSchedule::schema_fields_STATUS, ThemeLayoutSchedule::STATUS_ENABLED)
            ->where(ThemeLayoutSchedule::schema_fields_STARTS_AT, $nowSql, '<=')
            ->where(ThemeLayoutSchedule::schema_fields_ENDS_AT, $nowSql, '>')
            ->order(ThemeLayoutSchedule::schema_fields_PRIORITY, 'DESC')
            ->order(ThemeLayoutSchedule::schema_fields_UPDATE_TIME, 'DESC')
            ->select()
            ->fetchArray();

        $scope = $scope !== null ? trim($scope) : null;
        $best = null;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($websiteId > 0) {
                $rowWebsite = (int)($row[ThemeLayoutSchedule::schema_fields_WEBSITE_ID] ?? 0);
                if ($rowWebsite > 0 && $rowWebsite !== $websiteId) {
                    continue;
                }
            }
            if ($scope !== null && $scope !== '' && $scope !== ScopedConfigData::SCOPE_GLOBAL) {
                $rowScope = (string)($row[ThemeLayoutSchedule::schema_fields_SCOPE] ?? '');
                if ($rowScope !== '' && $rowScope !== ScopedConfigData::SCOPE_GLOBAL && $rowScope !== $scope) {
                    continue;
                }
            }
            $option = $this->virtualLayout->normalizeLayoutOption((string)($row[ThemeLayoutSchedule::schema_fields_LAYOUT_OPTION] ?? ''));
            if ($option === '' || !$this->options->optionExists($option)) {
                continue;
            }
            $best = [
                'schedule_id' => (int)($row[ThemeLayoutSchedule::schema_fields_ID] ?? 0),
                'layout_option' => $option,
                'priority' => (int)($row[ThemeLayoutSchedule::schema_fields_PRIORITY] ?? 0),
                'name' => (string)($row[ThemeLayoutSchedule::schema_fields_NAME] ?? ''),
            ];
            break;
        }

        return $best;
    }

    /**
     * Process schedule boundaries around now (save-time / cron helper).
     *
     * @return list<array<string,mixed>>
     */
    public function processBoundariesNear(\DateTimeInterface $now, int $lookbackSeconds = 120): array
    {
        $nowDt = \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone('UTC'));
        $from = $nowDt->modify('-' . max(1, $lookbackSeconds) . ' seconds')->format(Timezone::SQL_FORMAT);
        $to = $nowDt->modify('+' . max(1, $lookbackSeconds) . ' seconds')->format(Timezone::SQL_FORMAT);
        $nowSql = $nowDt->format(Timezone::SQL_FORMAT);

        /** @var ThemeLayoutSchedule $model */
        $model = ObjectManager::getInstance(ThemeLayoutSchedule::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayoutSchedule::schema_fields_STATUS, ThemeLayoutSchedule::STATUS_ENABLED)
            ->select()
            ->fetchArray();
        $events = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $starts = (string)($row[ThemeLayoutSchedule::schema_fields_STARTS_AT] ?? '');
            $ends = (string)($row[ThemeLayoutSchedule::schema_fields_ENDS_AT] ?? '');
            $boundary = null;
            if ($starts >= $from && $starts <= $to && $starts <= $nowSql) {
                $boundary = 'start';
            } elseif ($ends >= $from && $ends <= $to && $ends <= $nowSql) {
                $boundary = 'end';
            }
            if ($boundary === null) {
                continue;
            }
            $events[] = $this->cacheBust->bustForScheduleTarget(
                (string)($row[ThemeLayoutSchedule::schema_fields_TARGET_TYPE] ?? ''),
                (int)($row[ThemeLayoutSchedule::schema_fields_TARGET_ID] ?? 0),
                (int)($row[ThemeLayoutSchedule::schema_fields_WEBSITE_ID] ?? 0),
                'theme_layout_schedule_boundary_scan',
                $boundary,
                (int)($row[ThemeLayoutSchedule::schema_fields_ID] ?? 0),
            );
        }

        return $events;
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(ThemeLayoutSchedule $model): array
    {
        return $this->rowToArray($model->getData() ?: []);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function rowToArray(array $row): array
    {
        $tz = (string)($row[ThemeLayoutSchedule::schema_fields_TIMEZONE] ?? Timezone::resolveWebsiteTimezone());
        $startsUtc = (string)($row[ThemeLayoutSchedule::schema_fields_STARTS_AT] ?? '');
        $endsUtc = (string)($row[ThemeLayoutSchedule::schema_fields_ENDS_AT] ?? '');

        return [
            'schedule_id' => (int)($row[ThemeLayoutSchedule::schema_fields_ID] ?? 0),
            'name' => (string)($row[ThemeLayoutSchedule::schema_fields_NAME] ?? ''),
            'layout_type' => (string)($row[ThemeLayoutSchedule::schema_fields_LAYOUT_TYPE] ?? ProductLayoutOptionService::LAYOUT_TYPE),
            'layout_option' => (string)($row[ThemeLayoutSchedule::schema_fields_LAYOUT_OPTION] ?? ''),
            'target_type' => (string)($row[ThemeLayoutSchedule::schema_fields_TARGET_TYPE] ?? ''),
            'target_id' => (int)($row[ThemeLayoutSchedule::schema_fields_TARGET_ID] ?? 0),
            'scope' => (string)($row[ThemeLayoutSchedule::schema_fields_SCOPE] ?? ''),
            'timezone' => $tz !== '' ? $tz : Timezone::FALLBACK_TIMEZONE,
            'starts_at' => $startsUtc,
            'ends_at' => $endsUtc,
            'starts_at_local' => Timezone::utcSqlToLocalInput($startsUtc, $tz),
            'ends_at_local' => Timezone::utcSqlToLocalInput($endsUtc, $tz),
            'priority' => (int)($row[ThemeLayoutSchedule::schema_fields_PRIORITY] ?? 0),
            'status' => (string)($row[ThemeLayoutSchedule::schema_fields_STATUS] ?? ThemeLayoutSchedule::STATUS_ENABLED),
            'website_id' => (int)($row[ThemeLayoutSchedule::schema_fields_WEBSITE_ID] ?? 0),
            'created_by' => isset($row[ThemeLayoutSchedule::schema_fields_CREATED_BY])
                ? (int)$row[ThemeLayoutSchedule::schema_fields_CREATED_BY]
                : null,
            'create_time' => (string)($row[ThemeLayoutSchedule::schema_fields_CREATE_TIME] ?? ''),
            'update_time' => (string)($row[ThemeLayoutSchedule::schema_fields_UPDATE_TIME] ?? ''),
        ];
    }
}
