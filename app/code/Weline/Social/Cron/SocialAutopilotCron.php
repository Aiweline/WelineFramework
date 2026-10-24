<?php

declare(strict_types=1);

namespace Weline\Social\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Model\SocialAutopilotPlan;
use Weline\Social\Service\SocialAutopilotService;

/**
 * 每 5 分钟扫描已开启站点的日更时段，命中后执行一次（幂等键防重）。
 */
final class SocialAutopilotCron implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('社媒日更自动运营');
    }

    public function execute_name(): string
    {
        return 'social_autopilot';
    }

    public function tip(): string
    {
        return (string)__('按站点计划时段执行日更：取题、生成内容、按通道排队；视频按审片开关停在待审。');
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }

    public function execute(): string
    {
        try {
            $service = ObjectManager::getInstance(SocialAutopilotService::class);
        } catch (\Throwable) {
            return 'social autopilot: service unavailable';
        }
        if (!$service->globalEnabled()) {
            return 'social autopilot: global switch off';
        }

        $slots = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $needsUser = 0;
        foreach ($this->enabledPlans() as $plan) {
            $websiteId = (int)($plan[SocialAutopilotPlan::schema_fields_WEBSITE_ID] ?? 0);
            if ($websiteId < 1) {
                ++$skipped;
                continue;
            }

            foreach ($this->dueSlots($plan) as $slotKey) {
                ++$slots;
                try {
                    $result = $service->runSlot($websiteId, $slotKey);
                    $created += (int)($result['created'] ?? 0);
                    $skipped += (int)($result['skipped'] ?? 0);
                    if (($result['ok'] ?? false) !== true) {
                        ++$failed;
                    }
                } catch (\Throwable) {
                    ++$failed;
                }
            }

            try {
                $status = $service->getRunStatus($websiteId);
                if ((string)($status['status'] ?? '') === 'needs_user') {
                    ++$needsUser;
                }
            } catch (\Throwable) {
                // 状态汇总失败不阻断其它站点。
            }
        }

        return \sprintf(
            'social autopilot: slots=%d created=%d skipped=%d failed=%d needs_user=%d',
            $slots,
            $created,
            $skipped,
            $failed,
            $needsUser
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function enabledPlans(): array
    {
        try {
            $rows = ObjectManager::getInstance(SocialAutopilotPlan::class)
                ->reset()
                ->where(SocialAutopilotPlan::schema_fields_ENABLED, 1)
                ->select()
                ->fetchArray();

            return \is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 命中窗口：时段 + 0~7 分钟抖动，窗口 5 分钟（Cron 周期）。
     *
     * @param array<string, mixed> $plan
     * @return array<int, string>
     */
    private function dueSlots(array $plan): array
    {
        $timezone = \trim((string)($plan[SocialAutopilotPlan::schema_fields_TIMEZONE] ?? 'Asia/Shanghai'));
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('Asia/Shanghai');
        }
        $now = new \DateTimeImmutable('now', $zone);
        $slots = \json_decode((string)($plan[SocialAutopilotPlan::schema_fields_SLOTS_JSON] ?? ''), true);
        if (!\is_array($slots) || $slots === []) {
            $slots = SocialAutopilotPlan::defaults()['slots'];
        }

        $websiteId = (int)($plan[SocialAutopilotPlan::schema_fields_WEBSITE_ID] ?? 0);
        $due = [];
        foreach ($slots as $slot) {
            if (!\is_scalar($slot)) {
                continue;
            }
            $slotKey = \trim((string)$slot);
            if (!\preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $slotKey, $matches)) {
                continue;
            }
            $start = $now->setTime((int)$matches[1], (int)$matches[2], 0);
            if ($start > $now) {
                continue;
            }
            $jitter = \abs((int)\crc32($websiteId . '|' . $now->format('Y-m-d') . '|' . $slotKey)) % 8;
            $start = $start->modify('+' . $jitter . ' minutes');
            $end = $start->modify('+5 minutes');
            if ($now >= $start && $now < $end) {
                $due[] = $slotKey;
            }
        }

        return $due;
    }
}
