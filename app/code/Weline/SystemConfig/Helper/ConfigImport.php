<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Helper;

/**
 * 历史明文导入入口已废止（TASK-P1D-003）。
 * 跨实例导入请使用 {@see \Weline\SystemConfig\Service\ConfigEnvelopeService}。
 */
final class ConfigImport
{
    /**
     * @param array<string, mixed> $configData
     */
    public function import(array $configData): never
    {
        throw new \RuntimeException('config_envelope_plaintext_import_forbidden');
    }
}
