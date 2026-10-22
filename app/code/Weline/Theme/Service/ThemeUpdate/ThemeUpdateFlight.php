<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

/**
 * 主题更新的航班。
 *
 * 正在处理的版本走完并进入下一步之前，后面的变更全部挤在同一个未处理版本号上。
 * 这一次进入下一步之后，那些积压才变成下一个版本号。没处理完就不会再换号。
 */
final class ThemeUpdateFlight
{
    public function __construct(
        private readonly ?string $root = null,
        private readonly int $pid = 0,
        private readonly ?\Closure $alive = null,
    ) {
    }

    public static function scopeForTheme(int $themeId): string
    {
        return 'theme-' . $themeId;
    }

    public function arrive(string $scope): int
    {
        $scope = $this->scope($scope);

        return (int)$this->update($scope, static function (array $state): array {
            $state['generation'] = (int)($state['generation'] ?? 0) + 1;

            return ['state' => $state, 'value' => $state['generation']];
        });
    }

    public function generation(string $scope): int
    {
        $scope = $this->scope($scope);

        return (int)$this->update($scope, static function (array $state): array {
            return ['value' => (int)($state['generation'] ?? 0)];
        });
    }

    public function isLatest(string $scope, int $generation): bool
    {
        return $generation > 0 && $this->generation($scope) === $generation;
    }

    public function tryHold(string $scope): bool
    {
        $scope = $this->scope($scope);
        $me = $this->pid();

        return (bool)$this->update($scope, function (array $state) use ($me): array {
            $holder = (int)($state['holder_pid'] ?? 0);
            if ($holder > 0 && $holder !== $me && $this->alive($holder)) {
                return ['value' => false];
            }
            $state['holder_pid'] = $me;

            return ['state' => $state, 'value' => true];
        });
    }

    /** 当前正在处理、还没进入下一步的版本号。空表示没有。 */
    public function activeBatchId(string $scope): string
    {
        $scope = $this->scope($scope);

        return (string)$this->update($scope, static function (array $state): array {
            return ['value' => (string)($state['active_batch_id'] ?? '')];
        });
    }

    /** 还没轮到处理的那一个版本号。有则一直是它，直到被取去处理。 */
    public function tailBatchId(string $scope): string
    {
        $scope = $this->scope($scope);

        return (string)$this->update($scope, static function (array $state): array {
            return ['value' => (string)($state['tail_batch_id'] ?? '')];
        });
    }

    /**
     * 当前更新还在进行时，后面的变更合并进这一个未处理版本号。
     * 已经有号就不再换。
     */
    public function ensureTail(string $scope): string
    {
        $scope = $this->scope($scope);

        return (string)$this->update($scope, function (array $state) use ($scope): array {
            $tail = (string)($state['tail_batch_id'] ?? '');
            if ($tail === '') {
                $tail = $this->nextToken($scope);
                $state['tail_batch_id'] = $tail;
            }

            return ['state' => $state, 'value' => $tail];
        });
    }

    /**
     * 开始处理：先做完还没进入下一步的版本；没有的话，再把积压的那一个版本拿上来。
     * 积压被拿走之后，新的变更才会再得到另一个版本号。
     */
    public function takeForProcessing(string $scope): string
    {
        $scope = $this->scope($scope);

        return (string)$this->update($scope, function (array $state) use ($scope): array {
            $active = (string)($state['active_batch_id'] ?? '');
            if ($active === '') {
                $tail = (string)($state['tail_batch_id'] ?? '');
                if ($tail !== '') {
                    $active = $tail;
                    $state['tail_batch_id'] = '';
                } else {
                    $active = $this->nextToken($scope);
                }
                $state['active_batch_id'] = $active;
            }

            return ['state' => $state, 'value' => $active];
        });
    }

    /** 当前版本已经进入下一步。积压的版本号留着，等下一次再处理。 */
    public function finishProcessing(string $scope): void
    {
        $scope = $this->scope($scope);
        $this->update($scope, static function (array $state): array {
            $state['active_batch_id'] = '';

            return ['state' => $state, 'value' => true];
        });
    }

    public function release(string $scope): void
    {
        $scope = $this->scope($scope);
        $me = $this->pid();
        $this->update($scope, static function (array $state) use ($me): array {
            if ((int)($state['holder_pid'] ?? 0) === $me) {
                $state['holder_pid'] = 0;
            }

            return ['state' => $state, 'value' => true];
        });
    }

    private function scope(string $scope): string
    {
        if (\preg_match('/^(theme-[1-9]\d*|origin-watch)$/', $scope) !== 1) {
            throw new \InvalidArgumentException('theme_update_flight_scope');
        }

        return $scope;
    }

    private function pid(): int
    {
        if ($this->pid > 0) {
            return $this->pid;
        }
        $pid = \getmypid();

        return $pid === false ? 0 : $pid;
    }

    private function alive(int $pid): bool
    {
        if ($this->alive !== null) {
            return ($this->alive)($pid) === true;
        }
        if ($pid <= 0) {
            return false;
        }
        if ($pid === $this->pid()) {
            return true;
        }
        if (\function_exists('posix_kill')) {
            return \posix_kill($pid, 0);
        }

        return true;
    }

    private function nextToken(string $scope): string
    {
        if ($scope === 'origin-watch') {
            return 'queued';
        }

        return ThemeUpdatePointerPlanner::newBatchId(new \DateTimeImmutable(), \bin2hex(\random_bytes(3)));
    }

    /**
     * @param callable(array<string,int|string>): array{state?:array<string,int|string>,value?:mixed} $mutator
     */
    private function update(string $scope, callable $mutator): mixed
    {
        $dir = $this->directory();
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('theme_update_flight_dir_unavailable');
        }
        $lock = \fopen($this->lockFile($scope), 'c+');
        if ($lock === false) {
            throw new \RuntimeException('theme_update_flight_lock_failed');
        }
        try {
            if (!\flock($lock, \LOCK_EX)) {
                throw new \RuntimeException('theme_update_flight_lock_failed');
            }
            $state = $this->read($scope);
            $out = $mutator($state);
            if (isset($out['state']) && \is_array($out['state'])) {
                $this->write($scope, $out['state']);
            }

            return $out['value'] ?? null;
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }

    /** @return array{generation:int,holder_pid:int,active_batch_id:string,tail_batch_id:string} */
    private function read(string $scope): array
    {
        $file = $this->stateFile($scope);
        if (!\is_file($file)) {
            return $this->blankState();
        }
        $raw = \file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('theme_update_flight_state_unreadable');
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('theme_update_flight_state_corrupt');
        }

        return [
            'generation' => (int)($decoded['generation'] ?? 0),
            'holder_pid' => (int)($decoded['holder_pid'] ?? 0),
            'active_batch_id' => (string)($decoded['active_batch_id'] ?? ''),
            'tail_batch_id' => (string)($decoded['tail_batch_id'] ?? ''),
        ];
    }

    /** @return array{generation:int,holder_pid:int,active_batch_id:string,tail_batch_id:string} */
    private function blankState(): array
    {
        return [
            'generation' => 0,
            'holder_pid' => 0,
            'active_batch_id' => '',
            'tail_batch_id' => '',
        ];
    }

    /** @param array<string,int|string> $state */
    private function write(string $scope, array $state): void
    {
        $payload = \json_encode([
            'generation' => (int)($state['generation'] ?? 0),
            'holder_pid' => (int)($state['holder_pid'] ?? 0),
            'active_batch_id' => (string)($state['active_batch_id'] ?? ''),
            'tail_batch_id' => (string)($state['tail_batch_id'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('theme_update_flight_state_encode_failed');
        }
        if (\file_put_contents($this->stateFile($scope), $payload) === false) {
            throw new \RuntimeException('theme_update_flight_state_write_failed');
        }
    }

    private function directory(): string
    {
        if ($this->root !== null && $this->root !== '') {
            return rtrim($this->root, '/\\');
        }

        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
            . 'theme-update' . DIRECTORY_SEPARATOR . 'flight';
    }

    private function stateFile(string $scope): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $scope . '.json';
    }

    private function lockFile(string $scope): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $scope . '.lock';
    }
}
