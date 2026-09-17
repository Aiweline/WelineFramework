<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

/**
 * 计算挽回下一步：已有 sent 日志 + step_interval。
 */
final class WinbackStepResolver
{
    /**
     * @param callable(int,string,int):bool $hasStepLog
     * @param callable(int,string,int):?string $lastSentAt
     * @return array{step:int,ready:bool}|null null=全部完成
     */
    public function resolveNext(
        int $campaignId,
        string $subjectKey,
        int $maxSteps,
        int $stepIntervalHours,
        int $nowTs,
        callable $hasStepLog,
        callable $lastSentAt,
    ): ?array {
        $maxSteps = \max(1, $maxSteps);
        $stepIntervalHours = \max(1, $stepIntervalHours);
        for ($step = 1; $step <= $maxSteps; $step++) {
            if ($hasStepLog($campaignId, $subjectKey, $step)) {
                continue;
            }
            if ($step === 1) {
                return ['step' => 1, 'ready' => true];
            }
            $prevSent = $lastSentAt($campaignId, $subjectKey, $step - 1);
            if ($prevSent === null || $prevSent === '') {
                return null;
            }
            $prevTs = \strtotime($prevSent . ' UTC');
            if ($prevTs === false) {
                $prevTs = \strtotime($prevSent);
            }
            if ($prevTs === false) {
                return null;
            }
            $ready = $nowTs >= ($prevTs + $stepIntervalHours * 3600);

            return ['step' => $step, 'ready' => $ready];
        }

        return null;
    }
}
