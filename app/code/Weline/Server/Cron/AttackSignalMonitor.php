<?php
declare(strict_types=1);

/**
 * Weline Server - 攻击信号监控定时任务
 * 
 * 定期检查攻击信号文件，触发 CDN 通知和攻击恢复。
 * 
 * 工作流程：
 * 1. 每分钟执行一次
 * 2. 检查是否有新攻击且达到阈值 → 广播 CDN 事件开启防护
 * 3. 检查攻击是否已恢复 → 广播 CDN 事件关闭防护
 * 4. 清理过期的攻击记录
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\AttackSignalFileService;

/**
 * 攻击信号监控定时任务
 */
class AttackSignalMonitor implements CronTaskInterface
{
    /**
     * Server-owned attack detection event. Optional edge integrations may listen.
     */
    private const ATTACK_DETECTED_EVENT = 'Weline_Server::security::attack_detected';
    
    /**
     * Server-owned attack recovery event. Optional edge integrations may listen.
     */
    private const ATTACK_RECOVERED_EVENT = 'Weline_Server::security::attack_recovered';
    
    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'server_attack_signal_monitor';
    }
    
    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return __('攻击信号监控');
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('监控攻击信号文件，触发 CDN 攻击防护/恢复');
    }
    
    /**
     * @inheritDoc
     */
    public function cron_time(): string
    {
        // 每分钟执行一次
        return '* * * * *';
    }
    
    /**
     * @inheritDoc
     */
    public function locked(): bool
    {
        return false;
    }
    
    /**
     * @inheritDoc
     * 任务锁定后自动解锁时间（分钟）
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 10;
    }
    
    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        $messages = [];
        
        try {
            // 1. 检查是否应该通知 CDN 开启攻击防护
            if (AttackSignalFileService::shouldNotifyCdn()) {
                $result = $this->dispatchAttackDetected();
                $messages[] = $result;
            }
            
            // 2. 检查是否应该恢复（关闭攻击防护）
            if (AttackSignalFileService::shouldRecover()) {
                $result = $this->dispatchAttackRecovered();
                $messages[] = $result;
            }
            
            // 3. 清理过期数据
            AttackSignalFileService::cleanup();
            
            if (empty($messages)) {
                return __('无攻击信号需要处理');
            }
            
            return \implode('; ', $messages);
            
        } catch (\Throwable $e) {
            return __('攻击信号监控异常: %{1}', [$e->getMessage()]);
        }
    }
    
    /**
     * 通知 CDN 开启攻击防护
     * 
     * @return string
     */
    private function dispatchAttackDetected(): string
    {
        // 获取攻击摘要
        $summary = AttackSignalFileService::getAttackSummary();
        $signals = AttackSignalFileService::getActiveSignals(10);
        
        if (empty($signals)) {
            return __('无活跃攻击信号');
        }
        
        // 获取最近的攻击信号作为主信号
        $latestSignal = \end($signals);
        
        // 构建事件数据
        $eventData = new DataObject([
            'signal' => $latestSignal,
            'summary' => $summary,
            'domain' => $latestSignal['domain'] ?? '',
            'attack_type' => $latestSignal['type'] ?? '',
            'attacker_ip' => $latestSignal['ip'] ?? '',
            'timestamp' => $latestSignal['timestamp'] ?? \time(),
            'reason' => $latestSignal['reason'] ?? '',
            'all_signals' => $signals,
        ]);
        
        // Broadcast the Server-owned signal; optional edge providers may listen.
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch(self::ATTACK_DETECTED_EVENT, $eventData);
        
        // 标记已通知并设置攻击模式
        AttackSignalFileService::markCdnNotified();
        AttackSignalFileService::setAttackMode(true, $summary['domains'] ?? []);
        
        // 归档当前攻击记录
        AttackSignalFileService::archiveToHistory();
        
        return __('已通知 CDN 开启攻击防护模式，攻击次数: %{1}', [$summary['total']]);
    }
    
    /**
     * 通知 CDN 关闭攻击防护（恢复）
     * 
     * @return string
     */
    private function dispatchAttackRecovered(): string
    {
        // 获取攻击模式信息
        $modeInfo = AttackSignalFileService::getAttackModeInfo();
        $domains = $modeInfo['domains'] ?? [];
        
        // 构建事件数据
        $eventData = new DataObject([
            'domains' => $domains,
            'started_at' => $modeInfo['started_at'] ?? 0,
            'recovered_at' => \time(),
            'duration' => \time() - ($modeInfo['started_at'] ?? \time()),
        ]);
        
        // Broadcast the Server-owned recovery signal.
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch(self::ATTACK_RECOVERED_EVENT, $eventData);
        
        // 关闭攻击模式
        AttackSignalFileService::setAttackMode(false);
        
        $duration = \time() - ($modeInfo['started_at'] ?? \time());
        $durationMinutes = \round($duration / 60, 1);
        
        return __('已通知 CDN 关闭攻击防护模式，持续时间: %{1} 分钟', [$durationMinutes]);
    }
}
