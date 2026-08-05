<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment;

use Throwable;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\PaymentObjectScopeService;
use Weline\Payment\Service\PaymentReconciliationService;

/**
 * Persistent Payment reconciliation CLI (MOD-P2F-007).
 *
 * Dry-run is the default operating mode. Repair requires an explicit scope,
 * two independently authorized backend users, current grant versions, an
 * approval reference and a request idempotency key.
 */
class Reconcile extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var PaymentReconciliationService $service */
        $service = ObjectManager::getInstance(PaymentReconciliationService::class);
        /** @var PaymentObjectScopeService $scopeService */
        $scopeService = ObjectManager::getInstance(PaymentObjectScopeService::class);

        $action = $this->action($args);
        if ($action === '' || $action === 'help') {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string)$help;
            $printing->printing($encoded, 'success');

            return $encoded;
        }

        if ($action === 'catalog') {
            return $this->printPayload($printing, 'catalog', [
                'invariants' => $service->invariantCatalog(),
                'correlation_chain' => PaymentReconciliationService::correlationChain(),
                'retention_days' => PaymentReconciliationService::RETENTION_DAYS,
            ], true);
        }

        $scopeCode = trim((string)($this->optionValue($args, 'scope') ?? ''));
        if ($scopeCode === '') {
            return $this->printPayload($printing, $action, [
                'ok' => false,
                'error_code' => PaymentReconciliationService::ERROR_SCOPE_REQUIRED,
            ], false);
        }

        try {
            $scope = $scopeService->fromPersistedScope($scopeCode, $action === 'dry-run');
        } catch (Throwable) {
            return $this->printPayload($printing, $action, [
                'ok' => false,
                'error_code' => PaymentReconciliationService::ERROR_SCOPE_REQUIRED,
            ], false);
        }

        if ($action === 'dry-run') {
            $report = $service->dryRun($scope);

            return $this->printPayload($printing, 'dry-run', $report, !empty($report['ok']));
        }

        $result = $service->repair(
            scope: $scope,
            actorUserId: $this->positiveIntOption($args, 'actor-user-id'),
            actorExpectedGrantVersion: $this->positiveIntOption($args, 'actor-grant-version'),
            approverUserId: $this->positiveIntOption($args, 'approver-user-id'),
            approverExpectedGrantVersion: $this->positiveIntOption($args, 'approver-grant-version'),
            approvalReference: (string)($this->optionValue($args, 'approval-reference') ?? ''),
            idempotencyKey: (string)($this->optionValue($args, 'idempotency-key') ?? ''),
            enabled: $this->hasFlag($args, 'enable-repair'),
        );

        return $this->printPayload($printing, 'repair', $result, !empty($result['ok']));
    }

    public function tip(): string
    {
        return (string)__('Payment 持久化不变量对账：dry-run / catalog / 双人授权 repair');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'payment:reconcile',
            $this->tip(),
            [
                'help' => (string)__('打印帮助'),
                'catalog' => (string)__('列出不变量、可修复标记与关联链'),
                'dry-run' => (string)__('只读扫描 ORM 差异并持久化审计证据'),
                'repair' => (string)__('只补唯一缺失 effect，默认关闭'),
                '--scope=' => (string)__('必填三段 Scope；dry-run 可显式使用 global'),
                '--enable-repair' => (string)__('显式打开本次 repair'),
                '--actor-user-id=' => (string)__('操作者后台用户 ID'),
                '--actor-grant-version=' => (string)__('操作者当前 reconcile grant version'),
                '--approver-user-id=' => (string)__('独立审批者后台用户 ID，必须与操作者不同'),
                '--approver-grant-version=' => (string)__('审批者当前 reconcile grant version'),
                '--approval-reference=' => (string)__('外部审批引用；审计仅保存 SHA-256'),
                '--idempotency-key=' => (string)__('8–128 位 repair 请求幂等键'),
                '-h, --help' => (string)__('显示本帮助'),
            ],
            [],
            [
                'php bin/w payment:reconcile catalog',
                'php bin/w payment:reconcile dry-run --scope=default.default.default',
                'php bin/w payment:reconcile dry-run --scope=global',
                'php bin/w payment:reconcile repair --scope=default.default.default --enable-repair '
                    . '--actor-user-id=10 --actor-grant-version=3 --approver-user-id=11 '
                    . '--approver-grant-version=5 --approval-reference=CHG-2026-001 '
                    . '--idempotency-key=reconcile-2026-001',
            ],
        );
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function action(array $args): string
    {
        foreach ($args as $arg) {
            $candidate = strtolower(trim((string)$arg));
            if (in_array($candidate, ['help', 'dry-run', 'dry_run', 'repair', 'catalog'], true)) {
                return $candidate === 'dry_run' ? 'dry-run' : $candidate;
            }
        }

        return $this->wantsCommandHelp($args) ? 'help' : '';
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function hasFlag(array $args, string $name): bool
    {
        foreach ($args as $arg) {
            if (in_array((string)$arg, ['--' . $name, $name], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function optionValue(array $args, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            $arg = (string)$arg;
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function positiveIntOption(array $args, string $name): int
    {
        $raw = $this->optionValue($args, $name);
        if ($raw === null || preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
            return 0;
        }

        return (int)$raw;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function wantsCommandHelp(array $args): bool
    {
        foreach ($args as $arg) {
            if (in_array((string)$arg, ['-h', '--help', 'help'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function printPayload(
        Printing $printing,
        string $action,
        array $payload,
        bool $success,
    ): string {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        $message = 'PAYMENT_RECONCILE ' . $action . ': ' . $encoded;
        $printing->printing($message, $success ? 'success' : 'error');

        return $message;
    }
}
