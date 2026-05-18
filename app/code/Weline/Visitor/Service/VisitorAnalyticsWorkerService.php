<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\AbTest;
use Weline\Visitor\Model\Pixel;

class VisitorAnalyticsWorkerService
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function businessValue(array $params): array
    {
        $period = $this->stringParam($params, 'period', 'daily');
        $allowedPeriods = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
        if (!\in_array($period, $allowedPeriods, true)) {
            return $this->error('Invalid period. Supported: ' . \implode(', ', $allowedPeriods), 400);
        }

        $data = Pixel::getBusinessValueByPeriod(
            $this->websiteId($params),
            $period,
            $this->nullableStringParam($params, 'startDate'),
            $this->nullableStringParam($params, 'endDate')
        );

        return $this->success('Business value loaded.', $data);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function dashboard(array $params): array
    {
        $interval = $this->intParam($params, 'interval', 10);
        if (!\in_array($interval, [10, 30], true)) {
            $interval = 10;
        }

        $data = Pixel::getDashboardData(
            $this->websiteId($params),
            $interval,
            $this->boundedIntParam($params, 'hours', 24, 1, 720)
        );

        return $this->success('Dashboard loaded.', $data);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function changePercentage(array $params): array
    {
        $interval = $this->intParam($params, 'interval', 10);
        if (!\in_array($interval, [10, 30], true)) {
            $interval = 10;
        }

        $data = Pixel::getChangePercentageData(
            $this->websiteId($params),
            $interval,
            $this->boundedIntParam($params, 'hours', 24, 1, 720)
        );

        return $this->success('Change percentage loaded.', $data);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function dailyComparison(array $params): array
    {
        $data = Pixel::getDailyComparisonData(
            $this->websiteId($params),
            $this->boundedIntParam($params, 'days', 7, 1, 365)
        );

        return $this->success('Daily comparison loaded.', $data);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function abTest(array $params): array
    {
        $testId = $this->stringParam($params, 'testId', '');
        if ($testId === '') {
            return $this->error('testId is required.', 400);
        }

        $data = Pixel::getAbTestData(
            $this->websiteId($params),
            $testId,
            $this->nullableStringParam($params, 'variant')
        );

        return $this->success('A/B test loaded.', $data);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function abTestList(array $params): array
    {
        /** @var AbTest $abTest */
        $abTest = ObjectManager::getInstance(AbTest::class);
        $model = $abTest->reset();
        $websiteId = $this->websiteId($params);
        if ($websiteId > 0) {
            $model->where(AbTest::schema_fields_WEBSITE_ID, $websiteId);
        }

        $status = $this->nullableStringParam($params, 'status');
        if ($status) {
            $model->where(AbTest::schema_fields_STATUS, $status);
        }

        $tests = $model->select()->fetchArray();
        return $this->success('A/B test list loaded.', [
            'tests' => $tests,
            'count' => \count($tests),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function abTestCreate(array $params): array
    {
        $testId = $this->stringParam($params, 'testId', '');
        $name = $this->stringParam($params, 'name', '');
        if ($testId === '') {
            return $this->error('testId is required.', 400);
        }
        if ($name === '') {
            return $this->error('name is required.', 400);
        }
        if (AbTest::getByTestId($testId)) {
            return $this->error('testId already exists.', 400);
        }

        /** @var AbTest $abTest */
        $abTest = ObjectManager::getInstance(AbTest::class);
        $id = $abTest->setTestId($testId)
            ->setWebsiteId($this->websiteId($params))
            ->setName($name)
            ->setDescription($this->stringParam($params, 'description', ''))
            ->setStatus($this->stringParam($params, 'status', AbTest::status_DRAFT))
            ->setStartDate($this->nullableStringParam($params, 'startDate'))
            ->setEndDate($this->nullableStringParam($params, 'endDate'))
            ->setData(AbTest::schema_fields_VARIANT_A, \json_encode($this->mapParam($params, 'variantA'), JSON_UNESCAPED_UNICODE) ?: '{}')
            ->setData(AbTest::schema_fields_VARIANT_B, \json_encode($this->mapParam($params, 'variantB'), JSON_UNESCAPED_UNICODE) ?: '{}')
            ->setData(AbTest::schema_fields_TRAFFIC_SPLIT, $this->stringParam($params, 'trafficSplit', '50:50'))
            ->save();

        if (!$id) {
            return $this->error('Failed to create A/B test.', 500);
        }

        return $this->success('A/B test created.', [
            'test_id' => $testId,
            'id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function report(array $params): array
    {
        $websiteId = $this->websiteId($params);
        $startDate = $this->nullableStringParam($params, 'startDate');
        $endDate = $this->nullableStringParam($params, 'endDate');
        if (!$startDate || !$endDate) {
            $endDate = \date('Y-m-d 23:59:59');
            $startDate = \date('Y-m-d 00:00:00', \strtotime('-30 days'));
        }

        $model = \w_obj(Pixel::class)->reset()
            ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
            ->field(Pixel::schema_fields_EVENT)
            ->group(Pixel::schema_fields_EVENT);
        $model->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
        $model->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');

        $eventStats = [];
        foreach (\array_column($model->select()->fetchArray(), Pixel::schema_fields_EVENT) as $event) {
            $eventModel = \w_obj(Pixel::class)->reset()
                ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Pixel::schema_fields_EVENT, $event)
                ->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=')
                ->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
            $eventStats[$event] = (int)$eventModel->count();
        }

        \arsort($eventStats);
        return $this->success('Report loaded.', [
            'summary' => Pixel::getWebsiteSummary($websiteId),
            'daily_stats' => Pixel::getBusinessValueByPeriod($websiteId, 'daily', $startDate, $endDate),
            'event_stats' => $eventStats,
            'top_events' => \array_slice($eventStats, 0, 10, true),
            'time_range_stats' => Pixel::getWebsiteStatsByDateRange($websiteId, $startDate, $endDate),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function export(array $params): array
    {
        $websiteId = $this->websiteId($params);
        $startDate = $this->nullableStringParam($params, 'startDate');
        $endDate = $this->nullableStringParam($params, 'endDate');

        $query = \w_obj(Pixel::class)->reset();
        if ($websiteId > 0) {
            $query->where(Pixel::schema_fields_WEBSITE_ID, $websiteId);
        }
        if ($startDate) {
            $query->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
        }
        if ($endDate) {
            $query->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
        }

        $rows = $query->limit(10000)->select()->fetchArray();
        if (!$rows) {
            return $this->success('Export loaded.', [
                'filename' => 'visitor-analytics.csv',
                'content' => '',
            ]);
        }

        $handle = \fopen('php://temp', 'r+');
        $headers = \array_keys($rows[0]);
        \fputcsv($handle, $headers);
        foreach ($rows as $row) {
            \fputcsv($handle, \array_map(static function ($value): string {
                if (\is_array($value) || \is_object($value)) {
                    return \json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
                }
                return (string)$value;
            }, \array_values($row)));
        }
        \rewind($handle);
        $content = (string)\stream_get_contents($handle);
        \fclose($handle);

        return $this->success('Export loaded.', [
            'filename' => 'visitor-analytics-' . \date('Ymd-His') . '.csv',
            'content' => $content,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function websiteId(array $params): int
    {
        $value = $params['websiteId'] ?? null;
        if ($value !== null && $value !== '') {
            return (int)$value;
        }
        return (int)(WelineEnv::getWebsiteId() ?? 0);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function intParam(array $params, string $key, int $default): int
    {
        $value = $params[$key] ?? $default;
        return \is_numeric($value) ? (int)$value : $default;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function boundedIntParam(array $params, string $key, int $default, int $min, int $max): int
    {
        return \max($min, \min($max, $this->intParam($params, $key, $default)));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stringParam(array $params, string $key, string $default): string
    {
        $value = $params[$key] ?? $default;
        return \is_scalar($value) ? (string)$value : $default;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function nullableStringParam(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        return \is_scalar($value) ? (string)$value : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function mapParam(array $params, string $key): array
    {
        $value = $params[$key] ?? [];
        return \is_array($value) && !\array_is_list($value) ? $value : [];
    }

    /**
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function success(string $message, mixed $data): array
    {
        return [
            'code' => 200,
            'msg' => $message,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $message, int $code): array
    {
        return [
            'code' => $code,
            'msg' => $message,
            'message' => $message,
            'data' => null,
        ];
    }
}
