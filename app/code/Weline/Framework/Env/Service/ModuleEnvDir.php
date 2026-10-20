<?php

declare(strict_types=1);

namespace Weline\Framework\Env\Service;

/**
 * 解析模块环境契约目录（含 requirements.php 与 script/）。
 *
 * 普通模块：{module}/env/
 * Framework PSR 包与契约共存：{module}/Env/env/
 * Linux 上若 env/ 与 Env/env/ 同时存在且不是同一文件，以 Env/env/ 为准。
 */
final class ModuleEnvDir
{
    /**
     * @return string|null 带结尾分隔符的目录；找不到契约则 null
     */
    public static function resolve(string $modulePath): ?string
    {
        $modulePath = rtrim($modulePath, "/\\");
        if ($modulePath === '') {
            return null;
        }

        $lower = $modulePath . DIRECTORY_SEPARATOR . 'env';
        $nested = $modulePath . DIRECTORY_SEPARATOR . 'Env' . DIRECTORY_SEPARATOR . 'env';
        $lowerReq = $lower . DIRECTORY_SEPARATOR . 'requirements.php';
        $nestedReq = $nested . DIRECTORY_SEPARATOR . 'requirements.php';
        $lowerExists = is_file($lowerReq);
        $nestedExists = is_file($nestedReq);

        if ($lowerExists && $nestedExists) {
            $lowerReal = realpath($lowerReq);
            $nestedReal = realpath($nestedReq);
            if ($lowerReal !== false && $nestedReal !== false && $lowerReal !== $nestedReal) {
                return $nested . DIRECTORY_SEPARATOR;
            }
        }
        if ($lowerExists) {
            return $lower . DIRECTORY_SEPARATOR;
        }
        if ($nestedExists) {
            return $nested . DIRECTORY_SEPARATOR;
        }

        return null;
    }
}
