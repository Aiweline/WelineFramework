<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration;

/**
 * Framework-owned contract for module database migrations.
 */
interface MigrationInterface
{
    public function install(): bool;

    public function uninstall(): bool;

    public function getInfo(): array;

    public function validate(): bool;

    public function getDependencies(): array;

    public function getDescription(): string;

    public function getVersion(): string;

    public function getDate(): string;

}
