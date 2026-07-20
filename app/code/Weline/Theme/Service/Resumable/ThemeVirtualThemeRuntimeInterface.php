<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Resumable;

/**
 * Data-only boundary between the Theme query adapter and the detached
 * virtual-theme runner.  None of these methods accepts a Request, Session,
 * ORM instance, callback, or an executable PHP object.
 */
interface ThemeVirtualThemeRuntimeInterface
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function catalog(array $input, int $actorId): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function freezeTaskInput(array $input, int $actorId): array;

    /** @param array<string,mixed> $input @param array<string,mixed> $target @return array<string,mixed> */
    public function planTarget(array $input, array $target): array;

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function generateSource(array $plan, string $idempotencyKey, int $actorId): array;

    /** @param array<string,mixed> $plan @param array<string,mixed> $generated @return array<string,mixed> */
    public function persistGenerated(
        array $plan,
        array $generated,
        string $taskId,
        string $targetKey,
        int $actorId,
    ): array;

    /** @param array<string,mixed> $plan @return array<string,mixed>|null */
    public function findPersisted(array $plan, string $taskId, string $targetKey): ?array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveManualDraft(array $input, int $actorId): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function loadSource(array $input, int $actorId): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function publishVersion(array $input, int $actorId): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function rollbackVersion(array $input, int $actorId): array;
}
