<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;

final class SharedSidecarInspector
{
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

        $result['reusable'] = true;
        $result['pid'] = $pid;
        $result['role'] = $role;
        $result['command_line'] = $commandLine;
        $result['token_file_name'] = $this->extractOptionValue($commandLine, 'token-file-name') ?: $defaultTokenFileName;
        $result['process_name'] = $this->extractOptionValue($commandLine, 'name');
        $result['instance_name'] = $this->resolveInstanceName($commandLine);

        return $result;
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
