<?php
declare(strict_types=1);

/**
 * Weline Server - 实例列表命令
 *
 * 显示所有服务器实例及其状态（包括 CLI 和 Weline Server）
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\PrintInterface as OutputPrintInterface;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:listing - 列出所有服务器实例
 */
class Listing extends CommandAbstract
{
    private ServerInstanceManager $instanceManager;
    private CliServerService $cliServerService;

    /** @var array<string,mixed>|null */
    private ?array $managedNginxSnapshot = null;
    private bool $managedNginxSnapshotLoaded = false;

    public function __construct(
        ServerInstanceManager $instanceManager,
        CliServerService $cliServerService
    ) {
        $this->instanceManager = $instanceManager;
        $this->cliServerService = $cliServerService;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $runningOnly = isset($args['r']) || isset($args['running']);
        $detailed = isset($args['d']) || isset($args['detailed']);
        $json = isset($args['json']);
        $typeFilter = $args['type'] ?? $args['t'] ?? null; // weline, cli, or null for all

        $allInstances = $this->collectAllInstances($typeFilter, $runningOnly);

        if ($json) {
            $this->outputJson($allInstances);
            return;
        }

        if ($detailed) {
            $this->showDetailedList($allInstances);
        } else {
            $this->showSimpleList($allInstances, $runningOnly);
        }
    }

    /**
     * 收集所有服务器实例（CLI + Weline Server）
     *
     * @return array<string, array<string, mixed>>
     */
    protected function collectAllInstances(?string $typeFilter, bool $runningOnly): array
    {
        $allInstances = [];

        if ($typeFilter === null || $typeFilter === 'cli') {
            $cliStatus = $this->cliServerService->getCliServerStatus();
            if ($cliStatus) {
                if (!$runningOnly || $cliStatus['is_running']) {
                    $allInstances['cli-server'] = $cliStatus;
                }
            }
        }

        if ($typeFilter === null || $typeFilter === 'weline') {
            $manager = $this->getInstanceManager();
            $allInfo = $manager->getAllPersistedInstanceInfo();
            $processInfoMap = $this->buildProcessInfoMap($allInfo);

            foreach ($allInfo as $name => $info) {
                $isRunning = $this->isInstanceRunning($info, $processInfoMap);

                if ($runningOnly && !$isRunning) {
                    continue;
                }

                $raw = $manager->getRawInstanceData($info->name) ?? [];
                $edgeFace = $this->resolveEdgeFace($raw);
                $topology = $this->resolveTopologyPresentation($info, $raw);
                $ports = $this->resolvePortPresentation(
                    $edgeFace['code'],
                    $info,
                    $raw,
                    $info->workerCount
                );
                $edgeIdentity = $this->resolveEdgeIdentity(
                    $edgeFace['code'],
                    $info->name,
                    $raw
                );
                $status = [
                    'name' => $info->name,
                    'type' => 'weline',
                    'type_name' => __('Weline Server'),
                    'status' => $isRunning ? 'running' : 'stopped',
                    'is_running' => $isRunning,
                    'pid' => $info->masterPid > 0 ? $info->masterPid : null,
                    'host' => $info->host,
                    'port' => $info->port,
                    'public_host' => \trim((string)($raw['public_host'] ?? '')),
                    'public_origin' => \trim((string)($raw['public_origin'] ?? '')),
                    'worker_port' => $ports['worker_port'],
                    'edge_http_port' => $ports['edge_http_port'],
                    'edge_https_port' => $ports['edge_https_port'],
                    'port_summary' => $ports['port_summary'],
                    'port_lines' => $ports['port_lines'],
                    'topology' => $topology['topology'],
                    'topology_label' => $topology['topology_label'],
                    'worker_direct' => $topology['topology'] === EffectiveTopology::Direct->value,
                    'count' => $info->workerCount,
                    'daemon' => true,
                    'started_at' => $info->startedAt,
                    'running_time' => $this->formatRunningTime($info->startedTimestamp),
                    'edge_face' => $edgeFace['code'],
                    'edge_face_label' => $edgeFace['label'],
                    'edge_pid' => $edgeIdentity['edge_pid'],
                    'edge_owner' => $edgeIdentity['edge_owner'],
                    'edge_owner_self' => $edgeIdentity['edge_owner_self'],
                ];

                $allInstances[$name] = $status;
            }
        }

        return $allInstances;
    }

    /**
     * @param array<string, ServerInstanceInfo> $instances
     * @return array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}>
     */
    protected function buildProcessInfoMap(array $instances): array
    {
        $pids = [];
        foreach ($instances as $info) {
            if (!$info instanceof ServerInstanceInfo) {
                continue;
            }
            if ($info->masterPid > 0) {
                $pids[$info->masterPid] = true;
            }
            foreach ($info->services as $service) {
                foreach ($service->getManagedPids() as $pid) {
                    if ($pid > 0) {
                        $pids[$pid] = true;
                    }
                }
                $trackingPid = $service->getTrackingPid();
                if ($trackingPid > 0) {
                    $pids[$trackingPid] = true;
                }
            }
        }

        if ($pids === []) {
            return [];
        }

        return Processer::batchGetProcessInfo(\array_map('intval', \array_keys($pids)));
    }

    /**
     * IPC is the primary health signal. If it times out, fall back to the
     * managed Master PID check so a busy control plane is not listed as
     * stopped while the process is still alive (aligned with server:status).
     *
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     */
    protected function isInstanceRunning(ServerInstanceInfo $info, array $processInfoMap): bool
    {
        return (bool) $this->resolveMasterRuntimeState($info, $processInfoMap)['running'];
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     * @return array{running: bool, ipc_ok: bool, source: string, message: string}
     */
    protected function resolveMasterRuntimeState(ServerInstanceInfo $info, array $processInfoMap): array
    {
        if ($info->masterPid <= 0) {
            return [
                'running' => false,
                'ipc_ok' => false,
                'source' => 'metadata',
                'message' => '',
            ];
        }

        $pidRunning = (bool) ($processInfoMap[$info->masterPid]['exists'] ?? false);
        if (!$pidRunning && $processInfoMap === []) {
            $pidRunning = $info->isMasterRunning();
        }

        if ($info->controlPort > 0) {
            $gateway = new IpcControlGateway();
            $status = $gateway->getStatusBrief($info->name, 0.5);
            if ($status['success'] && (bool)($status['data']['running'] ?? false)) {
                return [
                    'running' => true,
                    'ipc_ok' => true,
                    'source' => 'ipc',
                    'message' => '',
                ];
            }
        }

        if ($pidRunning) {
            return [
                'running' => true,
                'ipc_ok' => false,
                'source' => 'pid',
                'message' => __(
                    'Master PID is running, but IPC status did not respond within 0.5s; the control plane may be busy or in an orchestrator full-restart cycle.'
                ),
            ];
        }

        return [
            'running' => false,
            'ipc_ok' => false,
            'source' => 'pid',
            'message' => '',
        ];
    }

    protected function isSharedDependencyService(ServiceInfo $service): bool
    {
        return $service->role === ControlMessage::ROLE_SESSION_SERVER
            || $service->role === ControlMessage::ROLE_MEMORY_SERVER;
    }

    protected function formatRunningTime(int $startedTimestamp): string
    {
        if ($startedTimestamp <= 0) {
            return '-';
        }

        $seconds = \time() - $startedTimestamp;
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            $minutes = (int) ($seconds / 60);
            return "{$minutes}m";
        }
        if ($seconds < 86400) {
            $hours = (int) ($seconds / 3600);
            $minutes = (int) (($seconds % 3600) / 60);
            return "{$hours}h {$minutes}m";
        }
        $days = (int) ($seconds / 86400);
        $hours = (int) (($seconds % 86400) / 3600);
        return "{$days}d {$hours}h";
    }

    protected function getInstanceManager(): ServerInstanceManager
    {
        return ObjectManager::getInstance(ServerInstanceManager::class);
    }

    /**
     * Resolve the public edge face shown next to [WLS].
     *
     * @param array<string,mixed> $raw
     * @return array{code:string,label:string}
     */
    protected function resolveEdgeFace(array $raw): array
    {
        if ($raw === []) {
            return ['code' => 'wls', 'label' => '[--]'];
        }

        $gateway = \is_array($raw['gateway'] ?? null) ? $raw['gateway'] : [];
        $servingMode = \strtolower(\trim((string)($gateway['serving_mode'] ?? '')));
        $mode = \strtolower(\trim((string)($gateway['mode'] ?? '')));
        $adapter = \strtolower(\trim((string)($raw['edge_adapter'] ?? '')));

        if ($servingMode === GatewayRuntimeServingProjection::SERVING_GATEWAY
            || GatewayRuntimeServingProjection::gatewayIsServing($raw)
        ) {
            return ['code' => 'gateway', 'label' => '[GW]'];
        }

        if (\in_array($servingMode, [
            GatewayRuntimeServingProjection::SERVING_FALLBACK_WLS,
            'native_wls',
        ], true)
            || $adapter === 'wls'
            || $mode === GatewayStartupDecision::MODE_WLS
        ) {
            return ['code' => 'wls', 'label' => '[--]'];
        }

        if (GatewayRuntimeServingProjection::isManagedNginxEdge($raw)
            || (
                $adapter === 'nginx'
                && (
                    $mode === GatewayStartupDecision::MODE_LEGACY
                    || $servingMode === GatewayStartupDecision::MODE_LEGACY
                    || $servingMode === ''
                )
            )
        ) {
            return ['code' => 'managed_nginx', 'label' => '[nG]'];
        }

        if ($mode === GatewayStartupDecision::MODE_GATEWAY) {
            return ['code' => 'gateway', 'label' => '[GW]'];
        }

        return ['code' => 'wls', 'label' => '[--]'];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{topology:string,topology_label:string}
     */
    protected function resolveTopologyPresentation(ServerInstanceInfo $info, array $raw): array
    {
        $code = \strtolower(\trim($info->runtimeSelection->effectiveTopology->value));
        if ($code === '') {
            $selection = \is_array($raw['runtime_selection'] ?? null) ? $raw['runtime_selection'] : [];
            $code = \strtolower(\trim((string)($selection['effective_topology'] ?? '')));
        }

        $label = match ($code) {
            EffectiveTopology::Direct->value => (string) __('直连'),
            EffectiveTopology::Dispatcher->value => (string) __('派遣器'),
            default => $code !== '' ? $code : '-',
        };

        return [
            'topology' => $code !== '' ? $code : 'unknown',
            'topology_label' => $label,
        ];
    }

    /**
     * Who owns the public edge, and which PID is listening there.
     *
     * @param array<string,mixed> $raw
     * @return array{edge_pid:?int,edge_owner:string,edge_owner_self:bool}
     */
    protected function resolveEdgeIdentity(string $edgeFace, string $instanceName, array $raw): array
    {
        $owner = '';
        $pid = null;
        if ($edgeFace === 'managed_nginx') {
            $snapshot = $this->managedNginxSnapshot();
            if (\is_array($snapshot)) {
                $owner = \trim((string)($snapshot['owner_instance'] ?? ''));
                $pidValue = (int)($snapshot['pid'] ?? 0);
                $pid = $pidValue > 0 ? $pidValue : null;
            }
        } elseif ($edgeFace === 'gateway') {
            $gateway = \is_array($raw['gateway'] ?? null) ? $raw['gateway'] : [];
            $owner = \trim((string)($gateway['instance_id'] ?? $gateway['owner_instance'] ?? ''));
            $pidValue = (int)($gateway['pid'] ?? $gateway['master_pid'] ?? 0);
            $pid = $pidValue > 0 ? $pidValue : null;
        }

        return [
            'edge_pid' => $pid,
            'edge_owner' => $owner,
            'edge_owner_self' => $owner !== '' && \hash_equals($owner, $instanceName),
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{
     *   worker_port:int,
     *   edge_http_port:int,
     *   edge_https_port:int,
     *   port_summary:string,
     *   port_lines:string[]
     * }
     */
    protected function resolvePortPresentation(
        string $edgeFace,
        ServerInstanceInfo $info,
        array $raw,
        int $workerCount = 0
    ): array {
        $workerPort = $info->port > 0 ? $info->port : 0;
        $edgeHttp = 0;
        $edgeHttps = 0;
        $parts = [];

        if ($edgeFace === 'managed_nginx') {
            if ($workerPort > 0) {
                $parts[] = $this->formatWorkerPortLabel($workerPort, $workerCount);
            }
            $snapshot = $this->managedNginxSnapshot();
            if (\is_array($snapshot)
                && (bool)($snapshot['runtime_owner_active'] ?? false)
                && \hash_equals($info->name, (string)($snapshot['owner_instance'] ?? ''))
            ) {
                $edgeHttp = (int)($snapshot['listen_http'] ?? 0);
                $edgeHttps = (int)($snapshot['listen_https'] ?? 0);
            }
            $pair = $this->formatHttpHttpsPair($edgeHttp, $edgeHttps);
            $parts[] = 'nG:' . ($pair !== '' ? $pair : '-');
        } elseif ($edgeFace === 'gateway') {
            if ($workerPort > 0) {
                $parts[] = $this->formatWorkerPortLabel($workerPort, $workerCount);
            }
            $gateway = \is_array($raw['gateway'] ?? null) ? $raw['gateway'] : [];
            $edgeHttp = (int)($gateway['public_http'] ?? 0);
            $edgeHttps = (int)($gateway['public_https'] ?? 0);
            $pair = $this->formatHttpHttpsPair($edgeHttp, $edgeHttps);
            $parts[] = 'GW:' . ($pair !== '' ? $pair : '-');
        } elseif ($workerPort > 0) {
            // Pure WLS public listen is the worker port; still mark worker count.
            $parts[] = $this->formatWorkerPortLabel($workerPort, $workerCount, 'WLS');
        }

        return [
            'worker_port' => $workerPort,
            'edge_http_port' => $edgeHttp,
            'edge_https_port' => $edgeHttps,
            'port_summary' => $parts !== [] ? \implode(' ', $parts) : '-',
            'port_lines' => $parts,
        ];
    }

    protected function formatWorkerPortLabel(
        int $workerPort,
        int $workerCount,
        string $prefix = 'Worker'
    ): string {
        $label = $prefix . ':' . $workerPort;
        if ($workerCount > 0) {
            // No space before "(N)" so narrow-cell wrap cannot split count onto
            // its own line (would become "Worker:9555" / "(4)").
            $label .= '(' . $workerCount . ')';
        }

        return $label;
    }

    protected function formatHttpHttpsPair(int $httpPort, int $httpsPort): string
    {
        $bits = [];
        if ($httpPort > 0) {
            $bits[] = (string) $httpPort;
        }
        if ($httpsPort > 0) {
            $bits[] = (string) $httpsPort;
        }

        return \implode('/', $bits);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function managedNginxSnapshot(): ?array
    {
        if ($this->managedNginxSnapshotLoaded) {
            return $this->managedNginxSnapshot;
        }

        $this->managedNginxSnapshotLoaded = true;
        try {
            $this->managedNginxSnapshot = ManagedNginxService::fromEnv()->doctorSnapshot();
        } catch (\Throwable) {
            $this->managedNginxSnapshot = null;
        }

        return $this->managedNginxSnapshot;
    }

    /**
     * Merged columns may wrap inside the cell (multi-line), never truncate
     * ports like nG:80/443. Layout: identity | status | runtime | proc
     *
     * @param array<string, array<string, mixed>> $instances
     */
    protected function showSimpleList(array $instances, bool $runningOnly): void
    {
        $typeWidth = 5; // unused alias kept for width math via kindWidth
        $kindWidth = 5;
        $statusWidth = \max(
            $this->displayWidth((string) __('运行中')),
            $this->displayWidth((string) __('已停止'))
        );
        $nameWidth = 12;
        $procWidth = 8;

        $rows = [];
        foreach ($instances as $name => $status) {
            $type = (string) ($status['type'] ?? 'weline');
            $kindLines = $this->formatKindSummaryLines($status, $type);
            $runtimeLines = $this->formatRuntimeSummaryLines($status, $type);
            $procLines = $this->formatProcSummaryLines($status);
            $nameWidth = \max($nameWidth, $this->displayWidth((string) $name));
            foreach ($kindLines as $kindLine) {
                $kindWidth = \max($kindWidth, $this->displayWidth($kindLine));
            }
            foreach ($procLines as $procLine) {
                $procWidth = \max($procWidth, $this->displayWidth($procLine));
            }
            $rows[] = [
                'name' => (string) $name,
                'status' => $status,
                'type' => $type,
                'kind_lines' => $kindLines,
                'runtime_lines' => $runtimeLines,
                'proc_lines' => $procLines,
            ];
        }
        $nameWidth = \min($nameWidth, 24);
        $typeWidth = $kindWidth;

        $maxRuntimeToken = 12;
        foreach ($rows as $row) {
            foreach ($row['runtime_lines'] as $runtimeLine) {
                $maxRuntimeToken = \max($maxRuntimeToken, $this->displayWidth((string) $runtimeLine));
            }
        }

        $titleWidth = $this->displayWidth((string) __('服务器实例列表'));
        $summaryProbe = (string) __('总计: %{total}  |  Weline: ●%{wrun} ○%{wstop}  |  CLI: ●%{clirun}', [
            'total' => \max(\count($instances), 99),
            'wrun' => 99,
            'wstop' => 99,
            'clirun' => 99,
        ]);
        $emptyProbe = $runningOnly
            ? (string) __('没有找到运行中的服务器实例')
            : (string) __('没有找到任何服务器实例');
        $hintProbe = (string) __('使用 php bin/w server:start [name] 启动 Weline Server');

        $termCols = $this->resolveTerminalColumns();
        $termInner = \max(40, $termCols - 2);
        // ● + kind + name + status + runtime + proc (+ gaps). Prefer keeping
        // Worker:9555(4) / nG:80/443 on one line by shrinking name first.
        $fixedExceptNameRuntime = 1 + 1 + $kindWidth + 1 + 2 + $statusWidth + 2 + 2 + $procWidth;
        $runtimeWidth = $maxRuntimeToken;
        $nameBudget = $termInner - $fixedExceptNameRuntime - $runtimeWidth;
        if ($nameBudget < 8) {
            $nameBudget = 8;
            $runtimeWidth = \max($maxRuntimeToken, $termInner - $fixedExceptNameRuntime - $nameBudget);
        }
        $nameWidth = \min($nameWidth, \max(8, $nameBudget));

        $boxInnerWidth = \max(
            56,
            \min(
                $termInner,
                \max(
                    $titleWidth + 4,
                    $this->displayWidth($summaryProbe) + 2,
                    $this->displayWidth($emptyProbe) + 2,
                    $this->displayWidth($hintProbe) + 2,
                    $fixedExceptNameRuntime + $nameWidth + $runtimeWidth
                )
            )
        );

        $fixedBeforeRuntime = 1 + 1 + $kindWidth + 1 + $nameWidth + 2 + $statusWidth + 2;
        $fixedAfterRuntime = 2 + $procWidth;
        $runtimeWidth = \max($maxRuntimeToken, $boxInnerWidth - $fixedBeforeRuntime - $fixedAfterRuntime);

        $this->printer->note(__(''));
        $this->printer->note('╭' . \str_repeat('─', $boxInnerWidth) . '╮');
        $this->printer->note($this->renderBoxContent((string) __('服务器实例列表'), $boxInnerWidth, STR_PAD_BOTH));
        $this->printer->note('├' . \str_repeat('─', $boxInnerWidth) . '┤');

        if ($rows === []) {
            $emptyText = $runningOnly ? __('没有找到运行中的服务器实例') : __('没有找到任何服务器实例');
            $this->printer->note($this->renderBoxContent((string) $emptyText, $boxInnerWidth));
            $this->printer->note($this->renderBoxContent((string) __('使用 php bin/w server:start [name] 启动 Weline Server'), $boxInnerWidth));
            $this->printer->note('╰' . \str_repeat('─', $boxInnerWidth) . '╯');
            return;
        }

        $welineRunning = 0;
        $welineStopped = 0;
        $cliRunning = 0;

        foreach ($rows as $row) {
            $status = $row['status'];
            $type = $row['type'];
            $isRunning = ($status['status'] ?? '') === 'running' || ($status['is_running'] ?? false);

            if ($isRunning) {
                if ($type === 'cli') {
                    $cliRunning++;
                } else {
                    $welineRunning++;
                }
            } elseif ($type !== 'cli') {
                $welineStopped++;
            }

            $statusIcon = $isRunning ? '●' : '○';
            $statusIconColor = $isRunning ? OutputPrintInterface::SUCCESS : OutputPrintInterface::NOTE;
            $statusText = $isRunning ? (string) __('运行中') : (string) __('已停止');
            $statusColor = $isRunning ? OutputPrintInterface::SUCCESS : OutputPrintInterface::NOTE;
            $plainStatusColumn = $this->padDisplayWidth($statusText, $statusWidth);
            $statusColumn = $this->colorizeSegment($plainStatusColumn, $statusColor);
            $nameColumn = $this->padDisplayWidth($row['name'], $nameWidth);
            $blankNameColumn = $this->padDisplayWidth('', $nameWidth);
            $blankStatusColumn = $this->padDisplayWidth('', $statusWidth);

            $kindLines = $this->wrapDisplayLines($row['kind_lines'], $kindWidth);
            $runtimeLines = $this->wrapDisplayLines($row['runtime_lines'], $runtimeWidth);
            $procLines = $this->wrapDisplayLines($row['proc_lines'], $procWidth);
            $lineCount = \max(\count($kindLines), \count($runtimeLines), \count($procLines), 1);

            for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
                $kindColumn = $this->padDisplayWidth(
                    (string) ($kindLines[$lineIndex] ?? ''),
                    $kindWidth
                );
                $runtimeColumn = $this->padDisplayWidth(
                    (string) ($runtimeLines[$lineIndex] ?? ''),
                    $runtimeWidth
                );
                $procColumn = $this->padDisplayWidth(
                    (string) ($procLines[$lineIndex] ?? ''),
                    $procWidth
                );

                if ($lineIndex === 0) {
                    $prefix = " {$kindColumn} {$nameColumn}  ";
                    $mid = $plainStatusColumn;
                    $midColored = $statusColumn;
                    $icon = $statusIcon;
                    $iconColored = $this->colorizeSegment($statusIcon, $statusIconColor);
                } else {
                    // Continuation: kind cell may show [nG]/[GW]/[--]; name/status blank.
                    $prefix = " {$kindColumn} {$blankNameColumn}  ";
                    $mid = $blankStatusColumn;
                    $midColored = $blankStatusColumn;
                    $icon = ' ';
                    $iconColored = ' ';
                }

                $suffix = "  {$runtimeColumn}  {$procColumn}";
                $plainRowContent = $icon . $prefix . $mid . $suffix;
                $rowPadding = \str_repeat(' ', \max(0, $boxInnerWidth - $this->displayWidth($plainRowContent)));

                echo $this->colorizeSegment('│', OutputPrintInterface::NOTE)
                    . $iconColored
                    . $this->colorizeSegment($prefix, OutputPrintInterface::NOTE)
                    . $midColored
                    . $this->colorizeSegment($suffix . $rowPadding . '│', OutputPrintInterface::NOTE)
                    . PHP_EOL;
            }
        }

        $this->printer->note('├' . \str_repeat('─', $boxInnerWidth) . '┤');
        $summaryLine = (string) __('总计: %{total}  |  Weline: ●%{wrun} ○%{wstop}  |  CLI: ●%{clirun}', [
            'total' => \count($instances),
            'wrun' => $welineRunning,
            'wstop' => $welineStopped,
            'clirun' => $cliRunning,
        ]);
        $this->printer->note($this->renderBoxContent($summaryLine, $boxInnerWidth));
        $this->printer->note('╰' . \str_repeat('─', $boxInnerWidth) . '╯');
        $this->printer->note(__(''));
        $this->printer->note(__(
            '[WLS]=高性能  [GW]=共享网关  [nG]=托管Nginx  [--]=纯WLS  [CLI]=PHP内置'
        ));
        $this->printer->note(__(
            '合并列可在 cell 内换行：类型=[WLS]  |  运行面=拓扑/Worker(人数)/nG|GW  |  进程=PID·时长'
        ));
        $this->printer->note(__(''));
    }

    /**
     * Kind tags: only [WLS]/[CLI]. Edge face ([nG]/[GW]/[--]) is already
     * shown under Worker in the runtime cell, so it is not repeated here.
     *
     * @param array<string,mixed> $status
     * @return string[]
     */
    protected function formatKindSummaryLines(array $status, string $type): array
    {
        if ($type === 'cli') {
            return ['[CLI]'];
        }

        return ['[WLS]'];
    }

    /**
     * @param array<string,mixed> $status
     * @return string[]
     */
    protected function formatRuntimeSummaryLines(array $status, string $type): array
    {
        $lines = [];
        if ($type === 'weline') {
            $topology = \trim((string) ($status['topology_label'] ?? ''));
            if ($topology !== '' && $topology !== '-') {
                $lines[] = $topology;
            }
            $owner = \trim((string) ($status['edge_owner'] ?? ''));
            $edgeFace = (string) ($status['edge_face'] ?? '');
            if ($owner !== '') {
                $prefix = $edgeFace === 'gateway' ? 'GW@' : 'nG@';
                $lines[] = $prefix . $owner;
            }
        }

        $ports = (string) ($status['port_summary'] ?? '');
        if ($ports === '') {
            $ports = 'Port:' . (string) ($status['port'] ?? '-');
        }
        // Keep "Worker:9555 (4)" as one token; split only before Worker:/nG:/GW:/Port:.
        $parts = \preg_split('/\s+(?=(?:Worker|nG|GW|Port):)/', \trim($ports)) ?: [];
        foreach ($parts as $part) {
            $part = \trim((string) $part);
            if ($part !== '') {
                $lines[] = $part;
            }
        }

        return $lines !== [] ? $lines : ['-'];
    }

    /**
     * @param array<string,mixed> $status
     */
    protected function formatRuntimeSummaryColumn(array $status, string $type): string
    {
        return \implode(' · ', $this->formatRuntimeSummaryLines($status, $type));
    }

    /**
     * @param array<string,mixed> $status
     * @return string[]
     */
    protected function formatProcSummaryLines(array $status): array
    {
        $pid = (string) ($status['pid'] ?? '-');
        $uptime = (string) ($status['running_time'] ?? '-');
        $lines = ['PID:' . $pid . ' · ' . $uptime];
        $edgePid = (int) ($status['edge_pid'] ?? 0);
        if ($edgePid > 0) {
            $edgeFace = (string) ($status['edge_face'] ?? '');
            $prefix = $edgeFace === 'gateway' ? 'GW' : 'nG';
            $lines[] = $prefix . ':' . $edgePid;
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $status
     */
    protected function formatProcSummaryColumn(array $status): string
    {
        return \implode(' ', $this->formatProcSummaryLines($status));
    }

    /**
     * @param string[] $lines
     * @return string[]
     */
    protected function wrapDisplayLines(array $lines, int $width): array
    {
        if ($width < 1) {
            $width = 1;
        }

        $wrapped = [];
        foreach ($lines as $line) {
            $line = (string) $line;
            if ($line === '') {
                continue;
            }
            if ($this->displayWidth($line) <= $width) {
                $wrapped[] = $line;
                continue;
            }

            // Port / edge tokens must stay intact (Worker:9555(4), nG:80/443).
            if (\preg_match('/^(Worker|WLS|nG|GW):/u', $line) === 1) {
                $wrapped[] = $line;
                continue;
            }

            $chunks = \preg_split('/\s+/u', $line) ?: [$line];
            $current = '';
            foreach ($chunks as $chunk) {
                $chunk = (string) $chunk;
                if ($chunk === '') {
                    continue;
                }
                if ($this->displayWidth($chunk) > $width) {
                    if ($current !== '') {
                        $wrapped[] = $current;
                        $current = '';
                    }
                    $wrapped = \array_merge($wrapped, $this->hardWrapDisplayWidth($chunk, $width));
                    continue;
                }
                $candidate = $current === '' ? $chunk : $current . ' ' . $chunk;
                if ($this->displayWidth($candidate) <= $width) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') {
                    $wrapped[] = $current;
                }
                $current = $chunk;
            }
            if ($current !== '') {
                $wrapped[] = $current;
            }
        }

        return $wrapped !== [] ? $wrapped : [''];
    }

    /**
     * @return string[]
     */
    protected function hardWrapDisplayWidth(string $text, int $width): array
    {
        if ($width < 1) {
            return [$text];
        }
        if ($this->displayWidth($text) <= $width) {
            return [$text];
        }

        $chars = \preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $lines = [];
        $current = '';
        foreach ($chars as $char) {
            $candidate = $current . $char;
            if ($this->displayWidth($candidate) <= $width) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $char;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines !== [] ? $lines : [$text];
    }

    protected function resolveTerminalColumns(): int
    {
        foreach (['COLUMNS', 'TERM_COLUMNS'] as $envKey) {
            $cols = (int) (\getenv($envKey) ?: 0);
            if ($cols >= 40) {
                return $cols;
            }
        }

        if (\function_exists('shell_exec')) {
            $raw = @\shell_exec('tput cols 2>/dev/null');
            $cols = (int) \trim((string) $raw);
            if ($cols >= 40) {
                return $cols;
            }
        }

        return 100;
    }

    protected function renderBoxContent(string $content, int $width, int $padType = STR_PAD_RIGHT): string
    {
        return '│' . $this->padDisplayWidth($content, $width, $padType) . '│';
    }

    protected function padDisplayWidth(string $text, int $width, int $padType = STR_PAD_RIGHT): string
    {
        if ($width <= 0) {
            return '';
        }

        $plainText = $text;
        $displayWidth = $this->displayWidth($plainText);
        if ($displayWidth > $width) {
            if (\function_exists('mb_strimwidth')) {
                $plainText = (string) \mb_strimwidth($plainText, 0, $width, '', 'UTF-8');
            } else {
                $plainText = \substr($plainText, 0, $width);
            }
            $displayWidth = $this->displayWidth($plainText);
        }

        $padding = $width - $displayWidth;
        if ($padding <= 0) {
            return $plainText;
        }

        return match ($padType) {
            STR_PAD_LEFT => \str_repeat(' ', $padding) . $plainText,
            STR_PAD_BOTH => \str_repeat(' ', intdiv($padding, 2)) . $plainText . \str_repeat(' ', $padding - intdiv($padding, 2)),
            default => $plainText . \str_repeat(' ', $padding),
        };
    }

    protected function displayWidth(string $text): int
    {
        $plainText = \preg_replace('/\e\[[\d;]*m/', '', $text) ?? $text;

        if (\function_exists('mb_strwidth')) {
            return \mb_strwidth($plainText, 'UTF-8');
        }

        return \strlen($plainText);
    }

    protected function colorizeSegment(string $text, string $color): string
    {
        if (\method_exists($this->printer, 'colorize')) {
            return $this->printer->colorize($text, $color);
        }

        return $text;
    }

    /**
     * @param array<string, array<string, mixed>> $instances
     */
    protected function showDetailedList(array $instances): void
    {
        if (empty($instances)) {
            $this->printer->warning(__('没有找到任何服务器实例'));
            return;
        }

        $this->printer->note(__(''));
        $this->printer->note(__('═══════════════════════════════════════════════════════════════════════════════'));
        $this->printer->note(__('                         服务器实例详细列表'));
        $this->printer->note(__('═══════════════════════════════════════════════════════════════════════════════'));

        $count = 0;
        foreach ($instances as $name => $status) {
            $count++;
            $isRunning = ($status['status'] ?? '') === 'running' || ($status['is_running'] ?? false);
            $type = $status['type'] ?? 'weline';
            $typeName = $status['type_name'] ?? ($type === 'cli' ? __('PHP 内置服务器') : __('Weline Server'));

            $this->printer->note(__(''));
            $this->printer->note(__('───────────────────────────────────────────────────────────────────────────────'));

            if ($isRunning) {
                $this->printer->success(__('[%{1}] ● 运行中', [$name]));
            } else {
                $this->printer->warning(__('[%{1}] ○ 已停止', [$name]));
            }

            $this->printer->note(__(''));
            $this->printer->note(__('  ├─ 服务类型   : %{1}', [$typeName]));
            if ($type === 'weline') {
                $edgeFace = (string) ($status['edge_face'] ?? '');
                $edgeFaceText = match ($edgeFace) {
                    'gateway' => (string) __('共享网关 [GW]'),
                    'managed_nginx' => (string) __('托管 Nginx [nG]'),
                    'wls' => (string) __('纯 WLS'),
                    default => '-',
                };
                $this->printer->note(__('  ├─ 边缘模式   : %{1}', [$edgeFaceText]));
                $this->printer->note(__('  ├─ 拓扑模式   : %{1}', [
                    (string) ($status['topology_label'] ?? '-'),
                ]));
            }
            $this->printer->note(__('  ├─ PID         : %{1}', [$status['pid'] ?? '-']));
            if ($type === 'weline') {
                $this->printer->note(__('  ├─ 网关归属   : %{1}', [
                    \trim((string)($status['edge_owner'] ?? '')) !== ''
                        ? (string)$status['edge_owner']
                        : '-',
                ]));
                $this->printer->note(__('  ├─ 边缘 PID   : %{1}', [
                    (int)($status['edge_pid'] ?? 0) > 0 ? (int)$status['edge_pid'] : '-',
                ]));
                $this->printer->note(__('  ├─ 端口说明   : %{1}', [
                    (string) ($status['port_summary'] ?? '-'),
                ]));
                $this->printer->note(__('  ├─ Worker 地址 : %{1}:%{2}', [
                    $status['host'] ?? '-',
                    $status['worker_port'] ?? ($status['port'] ?? '-'),
                ]));
            } else {
                $this->printer->note(__('  ├─ 监听地址   : %{1}:%{2}', [$status['host'] ?? '-', $status['port'] ?? '-']));
            }

            if ($type === 'weline') {
                $this->printer->note(__('  ├─ Worker 数  : %{1}', [$status['count'] ?? '-']));
            }

            $this->printer->note(__('  ├─ 运行模式   : %{1}', [($status['daemon'] ?? false) ? __('守护进程') : __('前台模式')]));
            $this->printer->note(__('  ├─ 启动者     : %{1}', [$status['started_by'] ?? '-']));
            $this->printer->note(__('  ├─ 启动时间   : %{1}', [$status['started_at'] ?? '-']));
            $this->printer->note(__('  └─ 运行时长   : %{1}', [$status['running_time'] ?? '-']));
        }

        $this->printer->note(__(''));
        $this->printer->note(__('═══════════════════════════════════════════════════════════════════════════════'));
        $this->printer->note(__('总计：%{1} 个实例', [$count]));
        $this->printer->note(__(''));
    }

    /**
     * @param array<string, array<string, mixed>> $instances
     */
    protected function outputJson(array $instances): void
    {
        echo json_encode(array_values($instances), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    public function tip(): string
    {
        return __('列出所有服务器实例（CLI 和 Weline Server）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:listing',
            __('列出所有服务器实例及其状态，包括 Weline Server 和 PHP CLI 服务器'),
            [
                '-r, --running' => __('仅显示运行中的实例'),
                '-d, --detailed' => __('显示详细信息'),
                '-t, --type <type>' => __('按类型过滤：weline 或 cli'),
                '--json' => __('以 JSON 格式输出'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('显示所有实例') => 'php bin/w server:listing',
                __('仅 Weline Server') => 'php bin/w server:listing -t weline',
                __('仅 CLI 服务器') => 'php bin/w server:listing -t cli',
                __('仅运行中') => 'php bin/w server:listing -r',
                __('详细信息') => 'php bin/w server:listing -d',
            ]
        );
    }

    public function aliases(): array
    {
        return ['server:list', 'server:ls', 'server:ps', 'ser:listing', 'ser:list', 'ser:ls'];
    }
}
