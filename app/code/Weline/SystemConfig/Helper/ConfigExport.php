<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Helper;

/**
 * 历史明文导出入口已废止（TASK-P1D-003）。
 * 跨实例导出请使用 {@see \Weline\SystemConfig\Service\ConfigEnvelopeService}。
 */
final class ConfigExport
{
    public function export(string $module = ''): never
    {
        throw new \RuntimeException('config_envelope_plaintext_export_forbidden');
    }
}
