<?php
declare(strict_types=1);

/**
 * Weline CDN - 攻击恢复处理观察者
 *
 * 接收来自 Server Cron 的攻击恢复信号，
 * 向各 CDN 服务商广播关闭攻击防护模式请求。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Cdn\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Output\Log;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\AttackLog;
use Weline\Cdn\Service\AdapterResolver;

class AttackRecoveryHandler implements ObserverInterface
{
    private Domain $domain;
    private Account $account;
    private AdapterResolver $adapterResolver;
    private Log $log;
    
    public function __construct(
        Domain $domain,
        Account $account,
        AdapterResolver $adapterResolver,
        Log $log
    ) {
        $this->domain = $domain;
        $this->account = $account;
        $this->adapterResolver = $adapterResolver;
        $this->log = $log;
    }
    
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        $domains = $data->getData('domains') ?: [];
        $startedAt = $data->getData('started_at');
        $recoveredAt = $data->getData('recovered_at');
        $duration = $data->getData('duration');
        
        $durationMinutes = \round(($duration ?? 0) / 60, 1);
        
        $this->log->info(__('CDN 攻击恢复信号接收: 域名数=%{1}, 持续时间=%{2}分钟', [
            \count($domains),
            $durationMinutes,
        ]));
        
        if (empty($domains)) {
            $this->log->warning(__('CDN 攻击恢复: 无受影响域名'));
            return;
        }
        
        // 遍历所有受影响域名，关闭防护模式并更新日志
        foreach ($domains as $targetDomain) {
            // 标记攻击日志为已恢复
            AttackLog::markRecovered($targetDomain);
            
            // 关闭 CDN 防护模式
            $this->disableAttackModeForDomain($targetDomain);
        }
    }
    
    /**
     * 为指定域名关闭攻击防护模式
     *
     * @param string $targetDomain 域名
     */
    private function disableAttackModeForDomain(string $targetDomain): void
    {
        // 获取域名配置
        $domainModel = $this->domain->reset()->where('domain', $targetDomain)->find()->fetch();
        
        if (!$domainModel->getId()) {
            // 尝试匹配通配符域名
            $domainModel = $this->findWildcardDomain($targetDomain);
        }
        
        if (!$domainModel || !$domainModel->getId()) {
            $this->log->warning(__('CDN 攻击恢复: 未找到域名 %{1} 的 CDN 配置', [$targetDomain]));
            return;
        }
        
        // 获取关联的账户
        $accountId = $domainModel->getData('account_id');
        if (!$accountId) {
            $this->log->warning(__('CDN 攻击恢复: 域名 %{1} 未关联 CDN 账户', [$targetDomain]));
            return;
        }
        
        $accountModel = $this->account->reset()->load($accountId);
        if (!$accountModel->getId()) {
            return;
        }
        
        // 获取适配器
        $adapterCode = $accountModel->getData('adapter') ?: $accountModel->getData('type');
        $adapter = $this->adapterResolver->getAdapter($adapterCode);
        
        if ($adapter === null || !$adapter->supportsAttackMode()) {
            return;
        }
        
        // 获取账户凭据
        $credentials = $accountModel->getData('credentials');
        if (\is_string($credentials)) {
            $credentials = \json_decode($credentials, true) ?: [];
        }
        
        try {
            // 调用适配器关闭攻击防护模式
            $result = $adapter->disableAttackMode(
                $domainModel->getData('zone_id'),
                $credentials
            );
            
            if ($result['success'] ?? false) {
                $this->log->info(__('CDN 攻击防护模式已关闭: 域名=%{1}, CDN=%{2}', [
                    $targetDomain,
                    $accountModel->getData('type'),
                ]));
            } else {
                $this->log->error(__('CDN 攻击防护模式关闭失败: 域名=%{1}, 错误=%{2}', [
                    $targetDomain,
                    $result['message'] ?? 'Unknown error',
                ]));
            }
        } catch (\Throwable $e) {
            $this->log->error(__('CDN 攻击恢复处理异常: %{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 尝试匹配通配符域名
     *
     * @param string $domain 域名
     * @return Domain|null
     */
    private function findWildcardDomain(string $domain): ?Domain
    {
        $parts = \explode('.', $domain);
        if (\count($parts) < 2) {
            return null;
        }
        
        $wildcardDomain = '*.' . \implode('.', \array_slice($parts, -2));
        $model = $this->domain->reset()->where('domain', $wildcardDomain)->find()->fetch();
        
        if ($model->getId()) {
            return $model;
        }
        
        return null;
    }
}
