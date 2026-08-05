<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Campaign\Campaign;
use Weline\Marketing\Model\Coupon\Coupon;
use Weline\Marketing\Model\Rule\Rule;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

function marketing_r43_require_isolated_clone(): string
{
    if ((string)getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('R4.3 marketing fixture requires WELINE_E2E_ISOLATED_DB=1');
    }
    $env = require BP . 'app/etc/env.php';
    $database = (string)($env['db']['master']['database'] ?? '');
    $type = strtolower((string)($env['db']['master']['type'] ?? ''));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1 || !str_contains($type, 'pgsql')) {
        throw new RuntimeException('R4.3 marketing fixture refuses non-PostgreSQL clone');
    }
    return $database;
}

/** @return array<string, mixed> */
function marketing_r43_input(): array
{
    $decoded = json_decode((string)stream_get_contents(STDIN), true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function marketing_r43_output(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
}

function marketing_r43_token(array $input): string
{
    $token = strtolower((string)($input['token'] ?? ''));
    $token = preg_replace('/[^a-z0-9]/', '', $token) ?: '';

    return $token !== '' ? substr($token, 0, 24) : substr(bin2hex(random_bytes(8)), 0, 12);
}

/** @return array{rule_name:string,fixture_rule_name:string,coupon_code:string,campaign_name:string} */
function marketing_r43_names(string $token): array
{
    return [
        'rule_name' => 'R43 UI Rule ' . $token,
        'fixture_rule_name' => 'R43 Fixture Rule ' . $token,
        'coupon_code' => 'R43' . strtoupper($token),
        'campaign_name' => 'R43 UI Campaign ' . $token,
    ];
}

function marketing_r43_rule(string $name): ?Rule
{
    /** @var Rule $model */
    $model = clone ObjectManager::getInstance()->get(Rule::class);
    $model->clearData()->clearQuery()->load(Rule::schema_fields_NAME, $name);

    return $model->getId() ? $model : null;
}

function marketing_r43_coupon(string $code): ?Coupon
{
    /** @var Coupon $model */
    $model = clone ObjectManager::getInstance()->get(Coupon::class);
    $model->clearData()->clearQuery()->load(Coupon::schema_fields_CODE, $code);

    return $model->getId() ? $model : null;
}

function marketing_r43_campaign(string $name): ?Campaign
{
    /** @var Campaign $model */
    $model = clone ObjectManager::getInstance()->get(Campaign::class);
    $model->clearData()->clearQuery()->load(Campaign::schema_fields_NAME, $name);

    return $model->getId() ? $model : null;
}

function marketing_r43_cleanup(array $names): int
{
    $deleted = 0;
    $campaign = marketing_r43_campaign($names['campaign_name']);
    if ($campaign !== null) {
        $campaign->delete()->fetch();
        $deleted++;
    }
    $coupon = marketing_r43_coupon($names['coupon_code']);
    if ($coupon !== null) {
        $coupon->delete()->fetch();
        $deleted++;
    }
    foreach ([$names['rule_name'], $names['fixture_rule_name']] as $ruleName) {
        if (!str_starts_with($ruleName, 'R43 ')) {
            throw new RuntimeException('refusing cleanup outside the R43 marketing namespace');
        }
        $rule = marketing_r43_rule($ruleName);
        if ($rule !== null) {
            $rule->delete()->fetch();
            $deleted++;
        }
    }

    return $deleted;
}

function marketing_r43_prepare_rule(string $name, string $ruleType): Rule
{
    /** @var Rule $rule */
    $rule = clone ObjectManager::getInstance()->get(Rule::class);
    $now = date('Y-m-d H:i:s');
    $rule->clearData()->clearQuery()->setData([
        Rule::schema_fields_NAME => $name,
        Rule::schema_fields_DESCRIPTION => 'R43 fixture prerequisite only',
        Rule::schema_fields_RULE_TYPE => $ruleType,
        Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE,
        Rule::schema_fields_PRIORITY => 0,
        Rule::schema_fields_CREATED_AT => $now,
        Rule::schema_fields_UPDATED_AT => $now,
    ])->save();
    if (!$rule->getId()) {
        throw new RuntimeException('marketing prerequisite rule creation failed');
    }

    return $rule;
}

try {
    marketing_r43_require_isolated_clone();
    $input = marketing_r43_input();
    $action = trim((string)($input['action'] ?? ''));
    $entity = trim((string)($input['entity'] ?? ''));
    if (!in_array($entity, ['rule', 'coupon', 'campaign'], true)) {
        throw new InvalidArgumentException('entity must be rule, coupon, or campaign');
    }
    $token = marketing_r43_token($input);
    $names = marketing_r43_names($token);

    if ($action === 'prepare') {
        marketing_r43_cleanup($names);
        $ruleId = 0;
        if ($entity === 'coupon' || $entity === 'campaign') {
            $rule = marketing_r43_prepare_rule(
                $names['fixture_rule_name'],
                $entity === 'coupon' ? Rule::RULE_TYPE_COUPON : Rule::RULE_TYPE_CAMPAIGN,
            );
            $ruleId = (int)$rule->getId();
        }
        marketing_r43_output(['ok' => true, 'token' => $token, 'rule_id' => $ruleId, ...$names]);
    }
    if ($action === 'inspect') {
        $rows = [];
        if ($entity === 'rule' && ($rule = marketing_r43_rule($names['rule_name'])) !== null) {
            $rows[] = [
                'id' => (int)$rule->getId(),
                'name' => (string)$rule->getData(Rule::schema_fields_NAME),
                'rule_type' => (string)$rule->getData(Rule::schema_fields_RULE_TYPE),
                'status' => (string)$rule->getData(Rule::schema_fields_STATUS),
            ];
        }
        if ($entity === 'coupon' && ($coupon = marketing_r43_coupon($names['coupon_code'])) !== null) {
            $rows[] = [
                'id' => (int)$coupon->getId(),
                'rule_id' => (int)$coupon->getData(Coupon::schema_fields_RULE_ID),
                'code' => (string)$coupon->getData(Coupon::schema_fields_CODE),
                'type' => (string)$coupon->getData(Coupon::schema_fields_TYPE),
                'discount_value' => (float)$coupon->getData(Coupon::schema_fields_DISCOUNT_VALUE),
                'status' => (string)$coupon->getData(Coupon::schema_fields_STATUS),
            ];
        }
        if ($entity === 'campaign' && ($campaign = marketing_r43_campaign($names['campaign_name'])) !== null) {
            $rows[] = [
                'id' => (int)$campaign->getId(),
                'rule_id' => (int)$campaign->getData(Campaign::schema_fields_RULE_ID),
                'name' => (string)$campaign->getData(Campaign::schema_fields_NAME),
                'status' => (string)$campaign->getData(Campaign::schema_fields_STATUS),
                'budget' => (float)$campaign->getData(Campaign::schema_fields_BUDGET),
            ];
        }
        marketing_r43_output(['ok' => true, 'rows' => $rows]);
    }
    if ($action === 'cleanup') {
        marketing_r43_output(['ok' => true, 'deleted' => marketing_r43_cleanup($names)]);
    }
    throw new InvalidArgumentException('unsupported action: ' . $action);
} catch (Throwable $throwable) {
    marketing_r43_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
