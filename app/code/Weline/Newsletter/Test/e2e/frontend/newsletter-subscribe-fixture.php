<?php

declare(strict_types=1);

/**
 * Newsletter subscribe e2e fixture (stdin JSON → stdout JSON).
 *
 * Actions: ensure_campaign | lookup_subscriber | count_subscribers | sync_campaign
 *
 * Note: SystemConfig::setConfig can hang under cache-namespace lock contention;
 * ensure_campaign prefers Marketing upsert + direct SQL for marketing_rule_id.
 */

require dirname(__DIR__, 7) . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignRequest;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Newsletter\Model\Subscriber;
use Weline\Newsletter\Service\SubscribeGiftCampaignSyncService;
use Weline\Newsletter\Service\SubscribeGiftConfig;
use Weline\SystemConfig\Model\SystemConfig;

$raw = stream_get_contents(STDIN);
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$action = (string)($payload['action'] ?? '');

/**
 * @return array{rule_id:int,enabled:bool}
 */
function newsletter_fixture_upsert_campaign(array $payload): array
{
    $enabled = array_key_exists('enabled', $payload) ? (bool)$payload['enabled'] : true;
    $type = (string)($payload['discount_type'] ?? 'percentage');
    $value = (float)($payload['discount_value'] ?? 10);
    $validDays = max(1, (int)($payload['valid_days'] ?? 14));
    $existing = 0;
    try {
        /** @var SubscribeGiftConfig $config */
        $config = ObjectManager::getInstance(SubscribeGiftConfig::class);
        $existing = max(0, (int)($config->read()['marketing_rule_id'] ?? 0));
    } catch (Throwable) {
        $existing = 0;
    }
    if (isset($payload['marketing_rule_id'])) {
        $existing = max(0, (int)$payload['marketing_rule_id']);
    }

    $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
        ->resolve(RandomCouponCampaignProviderInterface::class);
    if (!$provider instanceof RandomCouponCampaignProviderInterface) {
        return ['rule_id' => $existing, 'enabled' => false];
    }

    $discountType = in_array($type, ['fixed_amount', 'fixed', 'amount'], true)
        ? RandomCouponCampaignRequest::DISCOUNT_FIXED
        : RandomCouponCampaignRequest::DISCOUNT_PERCENTAGE;

    $result = $provider->upsert(new RandomCouponCampaignRequest(
        sourceModule: SubscribeGiftCampaignSyncService::SOURCE_MODULE,
        sourceType: SubscribeGiftCampaignSyncService::SOURCE_TYPE,
        sourceId: SubscribeGiftCampaignSyncService::SOURCE_ID,
        sourceKey: SubscribeGiftCampaignSyncService::SOURCE_KEY,
        displayName: '邮件订阅欢迎礼',
        discountType: $discountType,
        discountValue: $value,
        active: $enabled && $value > 0,
        existingRuleId: $existing,
        priority: 92,
        metadata: ['valid_days' => $validDays],
    ));

    $ruleId = max(0, (int)$result->ruleId);
    newsletter_fixture_sql_set_rule_id($ruleId);

    return ['rule_id' => $ruleId, 'enabled' => (bool)$result->enabled];
}

function newsletter_fixture_sql_set_rule_id(int $ruleId): void
{
    $env = include BP . 'app/etc/env.php';
    $db = $env['db']['master'] ?? [];
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $db['hostname'] ?? '127.0.0.1',
        $db['hostport'] ?? '5432',
        $db['database'] ?? ''
    );
    $pdo = new PDO($dsn, (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $key = SubscribeGiftConfig::KEY_MARKETING_RULE_ID;
    $module = SubscribeGiftConfig::MODULE;
    $sel = $pdo->prepare('SELECT key FROM w_system_config WHERE key = ? AND module = ? LIMIT 1');
    $sel->execute([$key, $module]);
    if ($sel->fetchColumn()) {
        $upd = $pdo->prepare('UPDATE w_system_config SET v = ?, update_time = NOW(), updated_at = NOW() WHERE key = ? AND module = ?');
        $upd->execute([(string)$ruleId, $key, $module]);
        return;
    }
    $sib = $pdo->query(
        "SELECT area, scope, locale FROM w_system_config WHERE module = 'Weline_Newsletter' AND key LIKE 'newsletter/subscribe_gift/%' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $ins = $pdo->prepare(
        'INSERT INTO w_system_config (key, module, area, scope, locale, v, value_type, is_active, create_time, update_time, updated_at)
         VALUES (?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())'
    );
    $ins->execute([
        $key,
        $module,
        (string)($sib['area'] ?? SystemConfig::area_BACKEND),
        (string)($sib['scope'] ?? 'default.default.default'),
        (string)($sib['locale'] ?? 'default'),
        (string)$ruleId,
        'string',
        1,
    ]);
}

try {
    $result = match ($action) {
        'ensure_campaign' => (static function () use ($payload): array {
            $synced = newsletter_fixture_upsert_campaign($payload);
            /** @var SubscribeGiftConfig $config */
            $config = ObjectManager::getInstance(SubscribeGiftConfig::class);

            return [
                'ok' => true,
                'config' => $config->read(),
                'sync' => $synced,
                'rule_id' => (int)($synced['rule_id'] ?? 0),
                'source_module' => SubscribeGiftCampaignSyncService::SOURCE_MODULE,
                'source_key' => SubscribeGiftCampaignSyncService::SOURCE_KEY,
                'source_type' => SubscribeGiftCampaignSyncService::SOURCE_TYPE,
            ];
        })(),
        'sync_campaign' => (static function () use ($payload): array {
            $synced = newsletter_fixture_upsert_campaign($payload);
            /** @var SubscribeGiftConfig $config */
            $config = ObjectManager::getInstance(SubscribeGiftConfig::class);

            return [
                'ok' => true,
                'sync' => $synced,
                'config' => $config->read(),
                'rule_id' => (int)($synced['rule_id'] ?? 0),
            ];
        })(),
        'lookup_subscriber' => (static function () use ($payload): array {
            $email = strtolower(trim((string)($payload['email'] ?? '')));
            if ($email === '') {
                return ['ok' => false, 'error' => 'email_required'];
            }
            /** @var Subscriber $model */
            $model = ObjectManager::getInstance(Subscriber::class);
            $row = $model->clear()
                ->where(Subscriber::schema_fields_EMAIL, $email)
                ->order(Subscriber::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();
            if (!($row instanceof Subscriber) || !(int)$row->getId()) {
                return ['ok' => true, 'found' => false, 'email' => $email];
            }

            return [
                'ok' => true,
                'found' => true,
                'email' => $email,
                'subscriber_id' => (int)$row->getId(),
                'coupon_code' => strtoupper(trim((string)$row->getData(Subscriber::schema_fields_COUPON_CODE))),
                'gift_status' => (string)$row->getData(Subscriber::schema_fields_GIFT_STATUS),
                'status' => (string)$row->getData(Subscriber::schema_fields_STATUS),
                'source_surface' => (string)$row->getData(Subscriber::schema_fields_SOURCE_SURFACE),
            ];
        })(),
        'count_subscribers' => (static function (): array {
            /** @var Subscriber $model */
            $model = ObjectManager::getInstance(Subscriber::class);
            $total = (int)$model->clear()->total();

            return ['ok' => true, 'total' => $total];
        })(),
        default => ['ok' => false, 'error' => 'unknown_action', 'action' => $action],
    };
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
