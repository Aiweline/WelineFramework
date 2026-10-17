<?php

declare(strict_types=1);

namespace Weline\Framework\Registry\Service;

use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Compilation\CompiledPhpRegistry;

/**
 * Atomically publish PHP array registry files under generated/.
 *
 * Never unlinks the live target first. Optional entry-count guard refuses to
 * replace a healthy file with an empty/wiped payload.
 *
 * After a successful publish, the process bag (CompiledPhpRegistry) is updated
 * via put() when the array payload is known — subsequent load() must not
 * re-include the same path in-process.
 */
final class GeneratedPhpArrayPublisher
{
    public function __construct(
        private readonly AtomicCompiledFilePublisher $publisher = new AtomicCompiledFilePublisher(),
    ) {
    }

    /**
     * Publish pre-built PHP source. Live file is replaced only after a complete temp write.
     *
     * @param array<string, mixed>|null $incomingDataForGuard data used by $countEntries and bag put
     * @param (callable(array):int)|null $countEntries when set, refuse old>0 && new===0
     */
    public function publishContent(
        string $target,
        string $content,
        ?array $incomingDataForGuard = null,
        ?callable $countEntries = null,
    ): void {
        if ($content === '') {
            throw new \InvalidArgumentException("Generated registry content must not be empty: {$target}");
        }

        if ($countEntries !== null && $incomingDataForGuard !== null && is_file($target)) {
            $existing = $this->includeArray($target);
            if ($existing !== null) {
                $old = (int)$countEntries($existing);
                $new = (int)$countEntries($incomingDataForGuard);
                if ($old > 0 && $new === 0) {
                    throw new \RuntimeException(
                        "Generated registry persist refused: new payload has 0 entries"
                        . " but existing {$target} has {$old}; keeping existing file"
                    );
                }
            }
        }

        $this->publisher->publish($target, $content);
        if ($incomingDataForGuard !== null) {
            CompiledPhpRegistry::put($target, $incomingDataForGuard);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param (callable(array):int)|null $countEntries
     */
    public function publishArray(
        string $target,
        array $data,
        ?callable $countEntries = null,
        bool $useWVarExport = false,
        string $header = "<?php return ",
    ): void {
        $export = $useWVarExport ? w_var_export($data, true) : var_export($data, true);
        $this->publishContent($target, $header . $export . ";\n", $data, $countEntries);
    }

    /**
     * @return callable(array):int
     */
    public static function countKey(string $key): callable
    {
        return static function (array $data) use ($key): int {
            $section = $data[$key] ?? [];

            return is_array($section) ? count($section) : 0;
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function countTopLevel(array $data): int
    {
        return count($data);
    }

    /**
     * Count nested map leaves (e.g. widgets: type => name => config).
     *
     * @param array<string, mixed> $data
     */
    public static function countNestedLeaves(array $data): int
    {
        $count = 0;
        foreach ($data as $section) {
            if (is_array($section)) {
                $count += count($section);
            }
        }

        return $count;
    }

    /**
     * Read existing registry for wipe-guard: process bag first, else one gated load.
     *
     * @return array<string, mixed>|null
     */
    private function includeArray(string $path): ?array
    {
        if (CompiledPhpRegistry::isLoaded($path)) {
            $peeked = CompiledPhpRegistry::peek($path);

            return \is_array($peeked) ? $peeked : null;
        }

        $data = CompiledPhpRegistry::load($path);

        return \is_array($data) ? $data : null;
    }
}
