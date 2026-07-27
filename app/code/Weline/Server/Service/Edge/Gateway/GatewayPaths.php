<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Host-scoped WLS 2.0 gateway paths.
 *
 * Nothing below this root is project-owned. The project only seeds an
 * immutable runtime slot and submits desired state through wls-edge/2.
 */
final class GatewayPaths
{
    public const PROTOCOL = 'wls-edge/2';

    public function home(): string
    {
        $override = \getenv('WLS_GATEWAY_HOME');
        if ($override !== false && \trim((string)$override) !== '') {
            return \rtrim($this->normalizeAbsolutePath((string)$override), '/\\');
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $base = (string)(\getenv('LOCALAPPDATA') ?: \getenv('PROGRAMDATA') ?: '');
            if (\trim($base) === '') {
                throw new \RuntimeException('WLS Gateway requires LOCALAPPDATA or PROGRAMDATA on Windows.');
            }
            return \rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'Weline'
                . DIRECTORY_SEPARATOR . 'Gateway' . DIRECTORY_SEPARATOR . 'v2';
        }

        $stateHome = (string)(\getenv('XDG_STATE_HOME') ?: '');
        if (\trim($stateHome) === '') {
            $userHome = (string)(\getenv('HOME') ?: '');
            if (\trim($userHome) === '') {
                throw new \RuntimeException('WLS Gateway requires HOME or XDG_STATE_HOME.');
            }
            $stateHome = \rtrim($userHome, '/\\') . DIRECTORY_SEPARATOR . '.local'
                . DIRECTORY_SEPARATOR . 'state';
        }

        return \rtrim($stateHome, '/\\') . DIRECTORY_SEPARATOR . 'weline'
            . DIRECTORY_SEPARATOR . 'wls-gateway' . DIRECTORY_SEPARATOR . 'v2';
    }

    public function runtimeDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'runtime';
    }

    public function runDir(): string
    {
        return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'run';
    }

    public function logDir(): string
    {
        return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'logs';
    }

    public function stateDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'state';
    }

    public function slotsDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'slots';
    }

    public function slotDir(string $slot): string
    {
        $slot = \strtoupper(\trim($slot));
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \InvalidArgumentException('Gateway slot must be A or B.');
        }
        return $this->slotsDir() . DIRECTORY_SEPARATOR . $slot;
    }

    public function activeSlotFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'active-slot';
    }

    public function tokenFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'control.token';
    }

    public function endpointFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'control-endpoint.json';
    }

    public function controllerPidFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'controller.pid';
    }

    public function controllerLogFile(): string
    {
        return $this->logDir() . DIRECTORY_SEPARATOR . 'controller.log';
    }

    public function unixSocketFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'wls-edge-2.sock';
    }

    public function publicHttpPort(): int
    {
        return $this->portFromEnvironment('WLS_GATEWAY_LISTEN_HTTP', 80);
    }

    public function publicHttpsPort(): int
    {
        return $this->portFromEnvironment('WLS_GATEWAY_LISTEN_HTTPS', 443);
    }

    public function controlTcpPort(): int
    {
        return $this->portFromEnvironment('WLS_GATEWAY_CONTROL_PORT', 27642);
    }

    /**
     * @return array{transport:string,address:string}
     */
    public function desiredEndpoint(): array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return [
                'transport' => 'tcp',
                'address' => 'tcp://127.0.0.1:' . $this->controlTcpPort(),
            ];
        }

        return [
            'transport' => 'unix',
            'address' => 'unix://' . $this->unixSocketFile(),
        ];
    }

    /**
     * @return array{transport:string,address:string}
     */
    public function endpoint(): array
    {
        $raw = @\file_get_contents($this->endpointFile());
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;
        if (\is_array($decoded)
            && \in_array((string)($decoded['transport'] ?? ''), ['unix', 'tcp'], true)
            && \trim((string)($decoded['address'] ?? '')) !== ''
        ) {
            return [
                'transport' => (string)$decoded['transport'],
                'address' => (string)$decoded['address'],
            ];
        }

        return $this->desiredEndpoint();
    }

    public function activeSlot(): string
    {
        $slot = \strtoupper(\trim((string)@\file_get_contents($this->activeSlotFile())));
        return \in_array($slot, ['A', 'B'], true) ? $slot : 'A';
    }

    public function inactiveSlot(): string
    {
        return $this->activeSlot() === 'A' ? 'B' : 'A';
    }

    public function ensureDirectories(): void
    {
        foreach ([
            $this->home(),
            $this->runtimeDir(),
            $this->runDir(),
            $this->logDir(),
            $this->stateDir(),
            $this->slotsDir(),
            $this->slotDir('A'),
            $this->slotDir('B'),
        ] as $directory) {
            if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
                throw new \RuntimeException('Unable to create WLS Gateway directory: ' . $directory);
            }
            @\chmod($directory, 0700);
        }
    }

    private function portFromEnvironment(string $name, int $default): int
    {
        $raw = \getenv($name);
        if ($raw === false || \trim((string)$raw) === '') {
            return $default;
        }
        $normalized = \trim((string)$raw);
        if (!\ctype_digit($normalized)) {
            throw new \RuntimeException($name . ' must be an integer port.');
        }
        $port = (int)$normalized;
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException($name . ' must be in 1..65535.');
        }
        return $port;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = \trim(\str_replace("\0", '', $path));
        $isAbsolute = \str_starts_with($path, '/')
            || \preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || \str_starts_with($path, '\\\\');
        if (!$isAbsolute || \in_array('..', \preg_split('#[\\\\/]+#', $path) ?: [], true)) {
            throw new \RuntimeException('WLS_GATEWAY_HOME must be an absolute path without traversal.');
        }
        return $path;
    }
}
