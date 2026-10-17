<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

final class CompiledPhpArrayWriter
{
    public function __construct(
        private readonly AtomicCompiledFilePublisher $publisher = new AtomicCompiledFilePublisher(),
    ) {
    }

    /**
     * Publish return-array PHP to disk and write-through into CompiledPhpRegistry
     * so runtime consumers never re-include this path in-process.
     *
     * @param array<string, mixed> $data
     */
    public function write(string $target, array $data): void
    {
        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($data, true) . ";\n";
        $this->publisher->publish($target, $payload);
        CompiledPhpRegistry::put($target, $data);
    }
}
