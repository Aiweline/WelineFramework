<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 穿章（FPC pierce stamp）轮换 → CDN Cache Rules 重推。
 *
 * 为什么需要它：穿章 Bypass 规则由 {@see RuleManager::getPierceBypassRules()} 按当前 secret 现算，
 * 但 CF 边缘上仍是上一次推送的旧 secret。轮换后不重推，就会出现「新章不打穿 CF、旧章继续可打穿」。
 *
 * 设计边界：本服务只提交同步任务，不直接拼 CF API；凭据/账户/域名全部走 CDN 模块既有配置
 * （w_cdn_account / w_cdn_domain），不读环境变量、不落 secret。
 */
final class PierceRuleSyncService
{
    /**
     * @return array{ok:bool, message:string, domains:list<array<string,mixed>>}
     */
    public function pushAll(bool $enabledOnly = true): array
    {
        $report = ['ok' => true, 'message' => '', 'domains' => []];
        try {
            $targets = ObjectManager::getInstance(FpcPolicyTargetService::class);
            $ruleManager = ObjectManager::getInstance(RuleManager::class);
            $domains = $targets->domains($enabledOnly);
        } catch (\Throwable $error) {
            return ['ok' => false, 'message' => $error->getMessage(), 'domains' => []];
        }

        if ($domains === []) {
            return ['ok' => true, 'message' => 'no_enabled_domains', 'domains' => []];
        }

        $allOk = true;
        foreach ($domains as $domain) {
            $domainId = (int)$domain->getId();
            $domainName = (string)$domain->getData('domain_name');
            try {
                $push = $ruleManager->pushRules($domain);
                $ok = (bool)($push['success'] ?? false);
                $message = (string)($push['message'] ?? '');
            } catch (\Throwable $error) {
                $ok = false;
                $message = $error->getMessage();
            }
            $allOk = $allOk && $ok;
            $report['domains'][] = [
                'domain_id' => $domainId,
                'domain' => $domainName,
                'ok' => $ok,
                'message' => $message,
            ];
        }

        $report['ok'] = $allOk;
        $report['message'] = $allOk ? 'pierce_rule_sync_submitted' : 'pierce_rule_sync_partial_failure';

        return $report;
    }
}
