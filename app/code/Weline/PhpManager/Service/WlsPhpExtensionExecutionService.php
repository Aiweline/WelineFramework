<?php
declare(strict_types=1);

namespace Weline\PhpManager\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\PhpManager\Model\WlsPhpProfile;
use Weline\PhpManager\Service\Adapter\WindowsBundledPhpExtensionAdapter;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;

class WlsPhpExtensionExecutionService
{
    private const AUDIT_FILE = 'php-manager-audit.jsonl';

    private readonly WlsPhpProfileService $profileService;
    private readonly WlsPhpExtensionPlanService $planService;
    private readonly WindowsBundledPhpExtensionAdapter $adapter;
    private readonly IpcControlGateway $ipcControlGateway;

    public function __construct(
        ?WlsPhpProfileService $profileService = null,
        ?WlsPhpExtensionPlanService $planService = null,
        ?WindowsBundledPhpExtensionAdapter $adapter = null,
        ?IpcControlGateway $ipcControlGateway = null
    ) {
        $this->profileService = $profileService ?? ObjectManager::getInstance(WlsPhpProfileService::class);
        $this->planService = $planService ?? ObjectManager::getInstance(WlsPhpExtensionPlanService::class);
        $this->adapter = $adapter ?? new WindowsBundledPhpExtensionAdapter();
        $this->ipcControlGateway = $ipcControlGateway ?? ObjectManager::getInstance(IpcControlGateway::class);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function executeFromPanel(array $input): array
    {
        $action = '';
        $extension = '';
        $targetPath = '';
        try {
            if ((string)($input['confirm_extension_execute'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm the PHP extension operation before submitting.'));
            }
            if (\trim((string)($input['confirm_phrase'] ?? '')) !== WindowsBundledPhpExtensionAdapter::CONFIRM_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type RUN_PHP_EXTENSION_ACTION to run the guarded extension operation.'));
            }

            $context = $this->contextFromInput($input);
            $runtime = $this->profileService->getRuntimeInfo();
            $projectProfile = $this->profileService->getFormData($context);
            $plan = $this->planService->buildPlan($input, $runtime, $projectProfile);
            $action = (string)($plan['action'] ?? '');
            $extension = (string)($plan['extension'] ?? '');
            $targetPath = (string)($plan['target_ini_path'] ?? '');
            if (empty($plan['can_execute'])) {
                $message = (string)($plan['execution_label'] ?? __('The guarded extension adapter is not ready.'));
                $reasons = \array_merge(
                    \is_array($plan['errors'] ?? null) ? $plan['errors'] : [],
                    \is_array($plan['adapter_reasons'] ?? null) ? $plan['adapter_reasons'] : []
                );
                if ($reasons !== []) {
                    $message .= ': ' . \mb_substr(\implode(' ', \array_map('strval', $reasons)), 0, 180);
                }
                throw new \InvalidArgumentException($message);
            }

            $applyResult = $this->adapter->applyPlan($plan);
            $runtimeResult = $this->applyRuntimeFromInput($input);
            $event = (int)($applyResult['change_count'] ?? 0) > 0 ? 'extension_action_executed' : 'extension_action_noop';
            $this->appendAudit($event, [
                'success' => true,
                'action' => $action,
                'extension' => $extension,
                'target_path' => $targetPath,
                'backup_path' => (string)($applyResult['backup_path'] ?? ''),
                'change_count' => (int)($applyResult['change_count'] ?? 0),
                'verification_state' => (string)($applyResult['verification_state'] ?? ''),
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ]);

            return [
                'success' => true,
                'message' => (string)($applyResult['message'] ?? __('PHP extension operation completed.')),
                'action' => $action,
                'extension' => $extension,
                'target_path' => $targetPath,
                'backup_path' => (string)($applyResult['backup_path'] ?? ''),
                'change_count' => (int)($applyResult['change_count'] ?? 0),
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ];
        } catch (\Throwable $throwable) {
            $message = \mb_substr($throwable->getMessage(), 0, 220);
            $this->appendAudit('extension_action_failed', [
                'success' => false,
                'action' => $action,
                'extension' => $extension,
                'target_path' => $targetPath,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'action' => $action,
                'extension' => $extension,
                'target_path' => $targetPath,
                'backup_path' => '',
                'change_count' => 0,
                'runtime_action' => WlsPhpProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function contextFromInput(array $input): array
    {
        return [
            'profile_key' => \trim((string)($input['profile_key'] ?? '')),
            'project_id' => \trim((string)($input['project_id'] ?? '')),
            'domain' => \trim((string)($input['domain'] ?? '')),
            'project_type' => \trim((string)($input['project_type'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{action:string,success:bool,message:string}
     */
    private function applyRuntimeFromInput(array $input): array
    {
        $action = \strtolower(\trim((string)($input['runtime_action'] ?? WlsPhpProfile::RUNTIME_ACTION_NONE)));
        if (!\in_array($action, [WlsPhpProfile::RUNTIME_ACTION_NONE, WlsPhpProfile::RUNTIME_ACTION_RELOAD], true)) {
            $action = WlsPhpProfile::RUNTIME_ACTION_NONE;
        }
        if ($action === WlsPhpProfile::RUNTIME_ACTION_NONE) {
            return [
                'action' => $action,
                'success' => true,
                'message' => (string)__('Runtime reload skipped.'),
            ];
        }

        $instance = $this->normalizeInstanceName((string)($input['runtime_instance'] ?? ''));
        if ($instance === '') {
            return [
                'action' => $action,
                'success' => false,
                'message' => (string)__('Select a running WLS instance before requesting reload.'),
            ];
        }

        $result = $this->ipcControlGateway->reloadAsync($instance, ControlMessage::RELOAD_TYPE_FORCE, 8.0);
        return [
            'action' => $action,
            'success' => !empty($result['success']),
            'message' => \mb_substr((string)($result['message'] ?? __('WLS reload request failed.')), 0, 220),
        ];
    }

    private function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        $instanceName = \preg_replace('/[^a-zA-Z0-9_.:-]/', '', $instanceName) ?? '';
        return \substr($instanceName, 0, 120);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendAudit(string $event, array $payload): void
    {
        $dir = \dirname($this->auditPath());
        if (!\is_dir($dir)) {
            \mkdir($dir, 0775, true);
        }
        $record = [
            'time' => \date('c'),
            'event' => $event,
            'payload' => $payload,
        ];
        \file_put_contents(
            $this->auditPath(),
            \json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . \PHP_EOL,
            \FILE_APPEND | \LOCK_EX
        );
    }

    private function auditPath(): string
    {
        return \rtrim((string)BP, '\\/') . \DIRECTORY_SEPARATOR . 'var'
            . \DIRECTORY_SEPARATOR . 'log'
            . \DIRECTORY_SEPARATOR . 'wls'
            . \DIRECTORY_SEPARATOR . self::AUDIT_FILE;
    }
}
