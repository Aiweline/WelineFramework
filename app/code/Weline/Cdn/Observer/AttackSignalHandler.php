<?php
declare(strict_types=1);

/**
 * Weline CDN - 攻击信号处理观察者
 *
 * 接收来自 WLS Dispatcher 的攻击检测信号，
 * 向各 CDN 服务商广播开启攻击防护模式请求。
 *
 * 工作流程：
 * 1. Server 模块检测到攻击 → 发送 Weline_Cdn::security::attack_detected 事件
 * 2. 本观察者接收信号 → 判断攻击严重程度
 * 3. 获取域名关联的 CDN 账户 → 向各服务商 API 发送防护请求
 * 4. 记录攻击日志
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

class AttackSignalHandler implements ObserverInterface
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
    
    /**
     * 执行观察者逻辑（事件负载在 $event->getData('data')，与 EventsManager::dispatch 约定一致）
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        $signal = $data->getData('signal');
        $signal = is_array($signal) ? $signal : [];
        $summary = $data->getData('summary');
        $summary = is_array($summary) ? $summary : [];
        $targetDomain = $data->getData('domain');
        $attackType = $data->getData('attack_type');
        $attackerIp = $data->getData('attacker_ip');
        $reason = $data->getData('reason');
        if ($data->hasData('site_id')) {
            $siteId = $this->normalizeSiteId($data->getData('site_id'), true);
        } elseif (array_key_exists('site_id', $signal)) {
            $siteId = $this->normalizeSiteId($signal['site_id'], true);
        } else {
            $siteId = $this->normalizeSiteId(w_env_website_id(), false);
        }

        if ($siteId === null) {
            $this->log->warning(__('CDN 攻击信号缺少站点上下文，已拒绝处理'));
            return;
        }
        
        $totalAttacks = $summary['total'] ?? 0;
        
        // 记录攻击日志到系统日志
        $this->log->warning(__('CDN 攻击信号接收: 域名=%{1}, 类型=%{2}, IP=%{3}, 攻击次数=%{4}', [
            $targetDomain,
            $attackType,
            $attackerIp,
            $totalAttacks,
        ]));
        
        // 获取所有受影响的域名（从 summary 中获取）
        $domains = $summary['domains'] ?? [];
        if (empty($domains) && $targetDomain) {
            $domains = [$targetDomain];
        }
        
        // 遍历所有受影响域名，开启防护模式并记录日志
        foreach ($domains as $domainName) {
            // 记录攻击日志到数据库
            $attackLog = AttackLog::log(
                $domainName,
                $attackType ?: AttackLog::TYPE_UNKNOWN,
                $attackerIp ?: '',
                $totalAttacks,
                $reason ?: __('攻击信号由 Cron 监控触发'),
                AttackLog::ACTION_DETECTED
            );
            
            // 开启防护模式
            $this->enableAttackModeForDomain($domainName, $signal, $summary, $attackLog, $siteId);
        }
    }
    
    /**
     * 为指定域名开启攻击防护模式
     *
     * @param string $targetDomain 域名
     * @param array $signal 攻击信号
     * @param array $summary 攻击摘要
     * @param AttackLog|null $attackLog 攻击日志记录
     * @param int $siteId 网站 ID（0 是合法默认站）
     */
    private function enableAttackModeForDomain(
        string $targetDomain,
        array $signal,
        array $summary,
        ?AttackLog $attackLog,
        int $siteId
    ): void
    {
        $domainModel = $this->domain->reset()
            ->where(Domain::schema_fields_DOMAIN_NAME, $targetDomain)
            ->where(Domain::schema_fields_ENABLED, 1)
            ->where(Domain::schema_fields_SITE_ID, $siteId);
        $domainModel->find()->fetch();
        
        if (!$domainModel->getId()) {
            // 尝试匹配通配符域名
            $domainModel = $this->findWildcardDomain($targetDomain, $siteId);
        }
        
        if (!$domainModel || !$domainModel->getId()) {
            $this->log->warning(__('CDN 攻击信号: 未找到域名 %{1} 的 CDN 配置', [$targetDomain]));
            return;
        }
        
        // 获取关联的账户
        $accountId = $domainModel->getData(Domain::schema_fields_ACCOUNT_ID);
        if (!$accountId) {
            $this->log->warning(__('CDN 攻击信号: 域名 %{1} 未关联 CDN 账户', [$targetDomain]));
            return;
        }
        
        $accountModel = $this->account->reset()->load($accountId);
        if (!$accountModel->getId()) {
            $this->log->warning(__('CDN 攻击信号: CDN 账户 ID=%{1} 不存在', [$accountId]));
            return;
        }
        
        // 向 CDN 服务商发送攻击防护请求
        $this->enableAttackMode($accountModel, $domainModel, $signal, $summary, $attackLog);
    }
    
    /**
     * 尝试匹配通配符域名
     *
     * @param string $domain 域名
     * @return Domain|null
     */
    private function findWildcardDomain(string $domain, int $siteId): ?Domain
    {
        // 提取主域名部分尝试匹配 *.example.com
        $parts = \explode('.', $domain);
        if (\count($parts) < 2) {
            return null;
        }
        
        // 尝试 *.example.com 格式
        $wildcardDomain = '*.' . \implode('.', \array_slice($parts, -2));
        $model = $this->domain->reset()
            ->where(Domain::schema_fields_DOMAIN_NAME, $wildcardDomain)
            ->where(Domain::schema_fields_ENABLED, 1)
            ->where(Domain::schema_fields_SITE_ID, $siteId);
        $model->find()->fetch();
        
        if ($model->getId()) {
            return $model;
        }
        
        return null;
    }
    
    /**
     * 向 CDN 服务商发送开启攻击防护模式请求
     *
     * @param Account $account CDN 账户
     * @param Domain $domain 域名
     * @param array $signal 攻击信号
     * @param array $summary 攻击摘要
     * @param AttackLog|null $attackLog 攻击日志记录
     */
    private function enableAttackMode(Account $account, Domain $domain, array $signal, array $summary, ?AttackLog $attackLog = null): void
    {
        try {
            $adapterCode = $account->getData('adapter') ?: $account->getData('type');
            $adapter = $this->adapterResolver->getAdapter($adapterCode);
            
            if ($adapter === null) {
                $this->log->error(__('CDN 攻击信号: 无法解析 CDN 适配器，账户类型=%{1}', [
                    $adapterCode,
                ]));
                // 更新日志状态为失败
                if ($attackLog) {
                    $attackLog->setData(AttackLog::schema_fields_STATUS, AttackLog::STATUS_FAILED);
                    $attackLog->setData(AttackLog::schema_fields_CDN_RESPONSE, \json_encode(['error' => 'Adapter not found']));
                    $attackLog->save();
                }
                return;
            }
            
            // 检查适配器是否支持攻击模式
            if (!$adapter->supportsAttackMode()) {
                $this->log->warning(__('CDN 攻击信号: CDN 适配器 %{1} 不支持攻击防护模式', [
                    \get_class($adapter),
                ]));
                if ($attackLog) {
                    $attackLog->setData(AttackLog::schema_fields_CDN_RESPONSE, \json_encode(['error' => 'Adapter does not support attack mode']));
                    $attackLog->save();
                }
                return;
            }
            
            // 获取账户凭据（支持 secret_ref 密封；禁止直接 json_decode）
            $credentials = $account->getCredentialsArray();
            
            // 调用适配器开启攻击防护模式
            $result = $adapter->enableAttackMode(
                $domain->getData('zone_id'),
                $credentials,
                [
                    'signal' => $signal,
                    'summary' => $summary,
                    'attacker_ips' => $summary['recent_ips'] ?? [],
                ]
            );
            
            if ($result['success'] ?? false) {
                $this->log->info(__('CDN 攻击防护模式已开启: 域名=%{1}, CDN=%{2}', [
                    $domain->getData(Domain::schema_fields_DOMAIN_NAME),
                    $account->getData('type'),
                ]));
                // 更新攻击日志
                if ($attackLog) {
                    $attackLog->setData(AttackLog::schema_fields_ACTION, AttackLog::ACTION_CDN_NOTIFIED);
                    $attackLog->setData(AttackLog::schema_fields_CDN_RESPONSE, \json_encode($result, JSON_UNESCAPED_UNICODE));
                    $attackLog->save();
                }
            } else {
                $this->log->error(__('CDN 攻击防护模式开启失败: 域名=%{1}, 错误=%{2}', [
                    $domain->getData(Domain::schema_fields_DOMAIN_NAME),
                    $result['message'] ?? 'Unknown error',
                ]));
                // 更新日志状态为失败
                if ($attackLog) {
                    $attackLog->setData(AttackLog::schema_fields_STATUS, AttackLog::STATUS_FAILED);
                    $attackLog->setData(AttackLog::schema_fields_CDN_RESPONSE, \json_encode($result, JSON_UNESCAPED_UNICODE));
                    $attackLog->save();
                }
            }
        } catch (\Throwable $e) {
            $this->log->error(__('CDN 攻击信号处理异常: %{1}', [$e->getMessage()]));
            // 更新日志状态为失败
            if ($attackLog) {
                $attackLog->setData(AttackLog::schema_fields_STATUS, AttackLog::STATUS_FAILED);
                $attackLog->setData(AttackLog::schema_fields_CDN_RESPONSE, \json_encode(['exception' => $e->getMessage()]));
                $attackLog->save();
            }
        }
    }

    private function normalizeSiteId(mixed $value, bool $explicit): ?int
    {
        if ($value === null || (!$explicit && ($value === '' || $value === false))) {
            return null;
        }
        if (is_int($value)) {
            $siteId = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $siteId = (int)$value;
        } else {
            throw new \InvalidArgumentException(__('site_id 必须是非负整数'));
        }
        if ($siteId < 0) {
            throw new \InvalidArgumentException(__('site_id 不能为负数'));
        }
        return $siteId;
    }
}
