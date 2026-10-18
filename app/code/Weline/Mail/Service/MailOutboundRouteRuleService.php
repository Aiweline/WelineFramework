<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailOutboundRouteRule;

/**
 * 收件域导流规则 CRUD + 保存后 sync。
 */
final class MailOutboundRouteRuleService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listRules(): array
    {
        /** @var MailOutboundRouteRule $model */
        $model = ObjectManager::getInstance(MailOutboundRouteRule::class);
        $rows = $model->clear()
            ->order(MailOutboundRouteRule::schema_fields_PRIORITY, 'ASC')
            ->order(MailOutboundRouteRule::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->publicRow($row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, item?: array<string, mixed>, sync?: array<string, mixed>}
     */
    public function saveRule(array $params): array
    {
        $ruleId = (int)($params['rule_id'] ?? $params['id'] ?? 0);
        /** @var MailOutboundRelaySyncService $sync */
        $sync = ObjectManager::getInstance(MailOutboundRelaySyncService::class);
        $matchDomain = $sync->normalizeMatchDomain((string)($params['match_domain'] ?? ''));
        if ($matchDomain === '') {
            return ['success' => false, 'message' => (string)__('请填写有效的收件域（如 qq.com）')];
        }

        $enabled = (int)($params['enabled'] ?? 1) === 1 ? 1 : 0;
        $priority = (int)($params['priority'] ?? 100);
        if ($priority < 0) {
            $priority = 0;
        }
        if ($priority > 999999) {
            $priority = 999999;
        }

        $host = strtolower(trim((string)($params['relay_host'] ?? '')));
        $port = (int)($params['relay_port'] ?? 587);
        $user = trim((string)($params['relay_username'] ?? ''));
        $password = (string)($params['relay_password'] ?? '');
        $tls = strtolower(trim((string)($params['relay_tls'] ?? 'starttls')));
        $pickKey = trim((string)($params['outbound_smtp_pick'] ?? $params['smtp_pick'] ?? ''));

        if ($pickKey !== '') {
            /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
            $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
            $picked = $catalog->resolveOutboundPick($pickKey);
            if (empty($picked['success'])) {
                return ['success' => false, 'message' => (string)($picked['message'] ?? __('Smtp 选择无效'))];
            }
            $host = strtolower(trim((string)($picked['host'] ?? '')));
            $port = (int)($picked['port'] ?? 587);
            $user = trim((string)($picked['username'] ?? ''));
            if (trim((string)($picked['password'] ?? '')) !== '') {
                $password = (string)$picked['password'];
            }
            $tls = strtolower(trim((string)($picked['tls'] ?? 'starttls')));
            $enabled = 1;
        }

        if (!in_array($tls, ['starttls', 'smtps', 'none'], true)) {
            $tls = 'starttls';
        }
        if ($port < 1 || $port > 65535 || $port === 25) {
            $port = 587;
        }
        if ($host === '') {
            return ['success' => false, 'message' => (string)__('请选择 Smtp 或填写中继主机')];
        }

        /** @var MailOutboundRouteRule $model */
        $model = ObjectManager::getInstance(MailOutboundRouteRule::class);
        if ($ruleId > 0) {
            $model->clear()->load($ruleId);
            if (!(int)$model->getId()) {
                return ['success' => false, 'message' => (string)__('规则不存在')];
            }
        }

        // 启用域唯一（排除自身）
        if ($enabled === 1) {
            $dup = ObjectManager::getInstance(MailOutboundRouteRule::class)->clear()
                ->where(MailOutboundRouteRule::schema_fields_MATCH_DOMAIN, $matchDomain)
                ->where(MailOutboundRouteRule::schema_fields_ENABLED, 1)
                ->select()
                ->fetchArray();
            foreach ($dup as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $existingId = (int)($row[MailOutboundRouteRule::schema_fields_ID] ?? $row['rule_id'] ?? 0);
                if ($existingId > 0 && $existingId !== $ruleId) {
                    return [
                        'success' => false,
                        'message' => (string)__('已存在启用中的同域规则：%{1}', [$matchDomain]),
                    ];
                }
            }
        }

        $now = date('Y-m-d H:i:s');
        $model->setData(MailOutboundRouteRule::schema_fields_MATCH_DOMAIN, $matchDomain)
            ->setData(MailOutboundRouteRule::schema_fields_PRIORITY, $priority)
            ->setData(MailOutboundRouteRule::schema_fields_ENABLED, $enabled)
            ->setData(MailOutboundRouteRule::schema_fields_RELAY_HOST, $host)
            ->setData(MailOutboundRouteRule::schema_fields_RELAY_PORT, $port)
            ->setData(MailOutboundRouteRule::schema_fields_RELAY_USERNAME, $user !== '' ? $user : null)
            ->setData(MailOutboundRouteRule::schema_fields_RELAY_TLS, $tls)
            ->setData(MailOutboundRouteRule::schema_fields_UPDATED_AT, $now);
        if (!(int)$model->getId()) {
            $model->setData(MailOutboundRouteRule::schema_fields_CREATED_AT, $now);
        }

        if (trim($password) !== '') {
            try {
                /** @var MailOutboundRelaySecretService $secrets */
                $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                $model->setData(
                    MailOutboundRouteRule::schema_fields_RELAY_PASSWORD_SEALED,
                    $secrets->seal($password)
                );
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => (string)__('中继密码密封失败：%{1}', [$e->getMessage()])];
            }
        }

        $model->save();
        $savedId = (int)$model->getId();
        if ($savedId <= 0) {
            // 部分 runtime save 后 getId 为空：按 match_domain 回查。
            $found = ObjectManager::getInstance(MailOutboundRouteRule::class)->clear()
                ->where(MailOutboundRouteRule::schema_fields_MATCH_DOMAIN, $matchDomain)
                ->order(MailOutboundRouteRule::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();
            $savedId = (int)$found->getId();
        }
        $fresh = ObjectManager::getInstance(MailOutboundRouteRule::class)->clear()->load($savedId);
        $syncResult = $sync->syncAll();
        $item = [
            'rule_id' => $savedId,
            'match_domain' => $matchDomain,
            'priority' => $priority,
            'enabled' => $enabled,
            'relay_host' => $host,
            'relay_port' => $port,
            'relay_username' => $user,
            'relay_tls' => $tls,
            'has_password' => trim((string)$fresh->getData(
                MailOutboundRouteRule::schema_fields_RELAY_PASSWORD_SEALED
            )) !== ''
                || trim((string)$model->getData(MailOutboundRouteRule::schema_fields_RELAY_PASSWORD_SEALED)) !== '',
            'updated_at' => $now,
        ];

        return [
            'success' => !empty($syncResult['success']),
            'message' => !empty($syncResult['success'])
                ? (string)__('已保存导流规则并下发路由')
                : (string)($syncResult['message'] ?? __('已保存规则，但路由下发失败')),
            'item' => $item,
            'sync' => $this->publicSyncSummary($syncResult),
        ];
    }

    /**
     * @param list<int>|int $ids
     * @return array{success: bool, message: string, deleted: int, sync?: array<string, mixed>}
     */
    public function deleteRules(array|int $ids): array
    {
        $idList = is_array($ids) ? $ids : [$ids];
        $idList = array_values(array_unique(array_filter(array_map('intval', $idList), static fn(int $id): bool => $id > 0)));
        if ($idList === []) {
            return ['success' => false, 'message' => (string)__('请选择要删除的规则'), 'deleted' => 0];
        }
        $deleted = 0;
        foreach ($idList as $id) {
            /** @var MailOutboundRouteRule $model */
            $model = ObjectManager::getInstance(MailOutboundRouteRule::class)->clear()->load($id);
            if (!(int)$model->getId()) {
                continue;
            }
            $model->delete();
            $deleted++;
        }
        if ($deleted === 0) {
            return ['success' => false, 'message' => (string)__('未删除任何规则'), 'deleted' => 0];
        }
        $syncResult = ObjectManager::getInstance(MailOutboundRelaySyncService::class)->syncAll();

        return [
            'success' => !empty($syncResult['success']),
            'message' => !empty($syncResult['success'])
                ? (string)__('已删除 %{1} 条规则并下发路由', [$deleted])
                : (string)($syncResult['message'] ?? __('已删除规则，但路由下发失败')),
            'deleted' => $deleted,
            'sync' => $this->publicSyncSummary($syncResult),
        ];
    }

    /**
     * API 回传不得带 authSecret 明文。
     *
     * @param array<string, mixed> $sync
     * @return array<string, mixed>
     */
    private function publicSyncSummary(array $sync): array
    {
        $routeNames = [];
        foreach ($sync['routes'] ?? [] as $route) {
            if (is_array($route) && isset($route['name'])) {
                $routeNames[] = (string)$route['name'];
            }
        }
        $matchSummary = [];
        $rawMatch = $sync['strategy_route']['match'] ?? null;
        if ($rawMatch instanceof \stdClass) {
            $rawMatch = (array)$rawMatch;
        }
        if (is_array($rawMatch)) {
            foreach ($rawMatch as $i => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $matchSummary[(string)$i] = [
                    'if' => (string)($rule['if'] ?? ''),
                    'then' => (string)($rule['then'] ?? ''),
                ];
            }
        }

        return [
            'success' => !empty($sync['success']),
            'applied' => !empty($sync['applied']),
            'message' => (string)($sync['message'] ?? ''),
            'self_ref_warning' => !empty($sync['self_ref_warning']),
            'route_names' => $routeNames,
            'else' => (string)(($sync['strategy_route']['else'] ?? '')),
            'match' => $matchSummary,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        $id = (int)($row[MailOutboundRouteRule::schema_fields_ID] ?? $row['rule_id'] ?? 0);
        $sealed = trim((string)($row[MailOutboundRouteRule::schema_fields_RELAY_PASSWORD_SEALED]
            ?? $row['relay_password_sealed']
            ?? ''));

        return [
            'rule_id' => $id,
            'match_domain' => (string)($row[MailOutboundRouteRule::schema_fields_MATCH_DOMAIN]
                ?? $row['match_domain']
                ?? ''),
            'priority' => (int)($row[MailOutboundRouteRule::schema_fields_PRIORITY] ?? $row['priority'] ?? 100),
            'enabled' => (int)($row[MailOutboundRouteRule::schema_fields_ENABLED] ?? $row['enabled'] ?? 0) === 1 ? 1 : 0,
            'relay_host' => (string)($row[MailOutboundRouteRule::schema_fields_RELAY_HOST] ?? $row['relay_host'] ?? ''),
            'relay_port' => (int)($row[MailOutboundRouteRule::schema_fields_RELAY_PORT] ?? $row['relay_port'] ?? 587),
            'relay_username' => (string)($row[MailOutboundRouteRule::schema_fields_RELAY_USERNAME]
                ?? $row['relay_username']
                ?? ''),
            'relay_tls' => (string)($row[MailOutboundRouteRule::schema_fields_RELAY_TLS] ?? $row['relay_tls'] ?? 'starttls'),
            'has_password' => $sealed !== '',
            'updated_at' => (string)($row[MailOutboundRouteRule::schema_fields_UPDATED_AT] ?? $row['updated_at'] ?? ''),
        ];
    }
}
