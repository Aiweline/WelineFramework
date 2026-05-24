<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;

final class SharedSidecarInspector
{
    public static function extractTokenFileNameFromCommandLine(string $commandLine): string
    {
        if ($commandLine === '') {
            return '';
        }

        $pattern = '/--token-file-name=(?:"([^"]+)"|\'([^\']+)\'|([^\\s]+))/i';
        if (\preg_match($pattern, $commandLine, $matches) !== 1) {
            return '';
        }

        foreach ([1, 2, 3] as $index) {
            $value = \trim((string) ($matches[$index] ?? ''), " \t\n\r\0\x0B\"'");
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array{
     *   in_use: bool,
     *   reusable: bool,
     *   pid: int,
     *   port: int,
     *   role: string,
     *   instance_name: string,
     *   token_file_name: string,
     *   process_name: string,
     *   command_line: string
     * }
     */
    public function inspect(int $port, string $expectedRole, string $defaultTokenFileName): array
    {
        $result = [
            'in_use' => false,
            'reusable' => false,
            'pid' => 0,
            'port' => $port,
            'role' => '',
            'instance_name' => '',
            'token_file_name' => $defaultTokenFileName,
            'process_name' => '',
            'command_line' => '',
        ];

        if ($port <= 0) {
            return $result;
        }

        $indexedResult = $this->inspectIndexedPortOccupant($port, $expectedRole, $defaultTokenFileName, $result);
        if ($indexedResult['reusable']) {
            return $indexedResult;
        }

        $occupant = Processer::inspectPortOccupantWithHistory($port);
        if (!($occupant['in_use'] ?? false)) {
            return $result;
        }

        $result['in_use'] = true;
        $pid = (int) ($occupant['pid'] ?? 0);
        if ($pid <= 0 || !($occupant['pid_running'] ?? false) || !($occupant['is_weline'] ?? false)) {
            return $result;
        }

        $commandLine = Processer::getProcessCommandLine($pid);
        if ($commandLine === '') {
            return $result;
        }

        $role = $this->resolveRoleFromCommandLine($commandLine);
        if ($role !== $expectedRole) {
            return $result;
        }

        if (!$this->isSharedServiceProcess($commandLine, $role)) {
            return $result;
        }

        $result['reusable'] = true;
        $result['pid'] = $pid;
        $result['role'] = $role;
        $result['command_line'] = $commandLine;
        $result['token_file_name'] = $this->extractOptionValue($commandLine, 'token-file-name') ?: $defaultTokenFileName;
        $result['process_name'] = $this->extractOptionValue($commandLine, 'name');
        $result['instance_name'] = $this->resolveInstanceName($commandLine);

        return $result;
    }

    /**
     * @param array{
     *   in_use: bool,
     *   reusable: bool,
     *   pid: int,
     *   port: int,
     *   role: string,
     *   instance_name: string,
     *   token_file_name: string,
     *   process_name: string,
     *   command_line: string
     * } $baseResult
     * @return array{
     *   in_use: bool,
     *   reusable: bool,
     *   pid: int,
     *   port: int,
     *   role: string,
     *   instance_name: string,
     *   token_file_name: string,
     *   process_name: string,
     *   command_line: string
     * }
     */
    private function inspectIndexedPortOccupant(
        int $port,
        string $expectedRole,
        string $defaultTokenFileName,
        array $baseResult
    ): array {
        $pid = Processer::getProcessIdByPort($port);
        if ($pid <= 0) {
            return $baseResult;
        }

        $baseResult['in_use'] = true;
        $baseResult['pid'] = $pid;

        $pidIndex = Processer::readPidIndex();
        $commandLine = (string) ($pidIndex[$pid]['pname'] ?? '');
        if ($commandLine === '') {
            $portIndex = Processer::readPortIndex();
            $commandLine = $this->buildCommandLineFromIndexedProcessName((string) ($portIndex[(string) $port] ?? ''));
        }
        if ($commandLine === '') {
            return $baseResult;
        }

        return $this->buildReusableResultFromCommandLine(
            $baseResult,
            $pid,
            $commandLine,
            $expectedRole,
            $defaultTokenFileName
        );
    }

    /**
     * @param array{
     *   in_use: bool,
     *   reusable: bool,
     *   pid: int,
     *   port: int,
     *   role: string,
     *   instance_name: string,
     *   token_file_name: string,
     *   process_name: string,
     *   command_line: string
     * } $result
     * @return array{
     *   in_use: bool,
     *   reusable: bool,
     *   pid: int,
     *   port: int,
     *   role: string,
     *   instance_name: string,
     *   token_file_name: string,
     *   process_name: string,
     *   command_line: string
     * }
     */
    private function buildReusableResultFromCommandLine(
        array $result,
        int $pid,
        string $commandLine,
        string $expectedRole,
        string $defaultTokenFileName
    ): array {
        $role = $this->resolveRoleFromCommandLine($commandLine);
        if ($role !== $expectedRole) {
            return $result;
        }

        if (!$this->isSharedServiceProcess($commandLine, $role)) {
            return $result;
        }

        $result['reusable'] = true;
        $result['pid'] = $pid;
        $result['role'] = $role;
        $result['command_line'] = $commandLine;
        $result['token_file_name'] = $this->extractOptionValue($commandLine, 'token-file-name') ?: $defaultTokenFileName;
        $result['process_name'] = $this->extractOptionValue($commandLine, 'name');
        $result['instance_name'] = $this->resolveInstanceName($commandLine);

        return $result;
    }

    private function buildCommandLineFromIndexedProcessName(string $processName): string
    {
        $processName = \trim($processName);
        if ($processName === '') {
            return '';
        }

        if (\str_starts_with($processName, '--name=')) {
            $processName = \substr($processName, 7);
        }
        $processName = $this->normalizeCommandValue($processName);
        if ($processName === '') {
            return '';
        }

        return '--name=' . $processName . ' --shared-service=1';
    }

    private function resolveRoleFromCommandLine(string $commandLine): string
    {
        $role = $this->extractOptionValue($commandLine, 'role');
        if ($role === ControlMessage::ROLE_MEMORY_SERVER) {
            return ControlMessage::ROLE_MEMORY_SERVER;
        }

        if (\str_contains($commandLine, 'weline-wls-memory-')) {
            return ControlMessage::ROLE_MEMORY_SERVER;
        }

        if (\str_contains($commandLine, 'session_server.php')) {
            return ControlMessage::ROLE_SESSION_SERVER;
        }

        if (\str_contains($commandLine, 'weline-wls-session-')) {
            return ControlMessage::ROLE_SESSION_SERVER;
        }

        return '';
    }

    private function isSharedServiceProcess(string $commandLine, string $role): bool
    {
        if (\preg_match('/--shared-service(?:=1)?(?:\\s|$)/i', $commandLine) === 1) {
            return true;
        }

        $instanceName = $this->resolveInstanceName($commandLine);
        if ($instanceName !== '') {
            if ($role === ControlMessage::ROLE_MEMORY_SERVER && \str_starts_with($instanceName, 'shared-memory-')) {
                return true;
            }
            if ($role === ControlMessage::ROLE_SESSION_SERVER && \str_starts_with($instanceName, 'shared-session-')) {
                return true;
            }
        }

        $processName = $this->extractOptionValue($commandLine, 'name');
        if ($processName !== '' && \str_contains($processName, '-shared-')) {
            return true;
        }

        // 兼容历史命名：旧版由 SessionServerProvider/MemoryServerProvider 拉起的
        // 进程名不含 "-shared-"，但若明确带当前项目 scope，允许复用避免启动阶段误判端口占用。
        if ($processName !== '') {
            $scopeToken = MasterProcess::getProjectScopeToken();
            if ($scopeToken !== '' && \str_contains($processName, '-' . $scopeToken)) {
                if ($role === ControlMessage::ROLE_SESSION_SERVER && \str_starts_with($processName, 'weline-wls-session-')) {
                    return true;
                }
                if ($role === ControlMessage::ROLE_MEMORY_SERVER && \str_starts_with($processName, 'weline-wls-memory-')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractOptionValue(string $commandLine, string $option): string
    {
        $pattern = '/--' . \preg_quote($option, '/') . '=(?:"([^"]+)"|\'([^\']+)\'|([^\\s]+))/i';
        if (!\preg_match($pattern, $commandLine, $matches)) {
            return '';
        }

        foreach ([1, 2, 3] as $index) {
            $value = (string) ($matches[$index] ?? '');
            if ($value !== '') {
                return $this->normalizeCommandValue($value);
            }
        }

        return '';
    }

    private function resolveInstanceName(string $commandLine): string
    {
        $instanceName = $this->extractOptionValue($commandLine, 'instance-name');
        if ($instanceName !== '') {
            return $instanceName;
        }

        $tokens = $this->tokenizeCommandLine($commandLine);
        $scriptIndex = $this->findScriptTokenIndex($tokens);
        if ($scriptIndex === null) {
            return '';
        }

        $instanceIndex = $scriptIndex + 3;
        $instanceName = (string) ($tokens[$instanceIndex] ?? '');
        if ($instanceName === '' || \str_starts_with($instanceName, '--')) {
            return '';
        }

        return $this->normalizeCommandValue($instanceName);
    }

    /**
     * @return list<string>
     */
    private function tokenizeCommandLine(string $commandLine): array
    {
        if ($commandLine === '') {
            return [];
        }

        \preg_match_all('/"([^"]*)"|\'([^\']*)\'|([^\\s]+)/', $commandLine, $matches, \PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            foreach ([1, 2, 3] as $index) {
                if (!isset($match[$index]) || $match[$index] === '') {
                    continue;
                }

                $tokens[] = $this->normalizeCommandValue((string) $match[$index]);
                break;
            }
        }

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     */
    private function findScriptTokenIndex(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            $normalized = \str_replace('\\', '/', $token);
            if (\str_ends_with($normalized, '/session_server.php') || \str_ends_with($normalized, 'session_server.php')) {
                return $index;
            }
        }

        return null;
    }

    private function normalizeCommandValue(string $value): string
    {
        return \trim($value, " \t\n\r\0\x0B\"'");
    }
}
