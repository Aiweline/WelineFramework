<?php

declare(strict_types=1);

namespace Weline\Framework\Test;

/**
 * Resolve the repository root for PHPUnit / contract tests.
 *
 * Fragile {@see dirname}(__DIR__, N) counts often land on {@code app/} or
 * {@code app/code/}, which then materializes accidental trees such as
 * {@code app/code/generated} (FPC) and {@code app/code/var/cache/router-fpc-*}.
 * Always walk up to {@code composer.json} that sits beside {@code app/code}.
 */
final class ProjectRoot
{
    public static function path(?string $startDir = null): string
    {
        $dir = $startDir ?? __DIR__;
        $resolved = \realpath($dir);
        if ($resolved !== false) {
            $dir = $resolved;
        }

        for ($i = 0; $i < 24; $i++) {
            if (
                \is_file($dir . \DIRECTORY_SEPARATOR . 'composer.json')
                && \is_dir($dir . \DIRECTORY_SEPARATOR . 'app' . \DIRECTORY_SEPARATOR . 'code')
            ) {
                return $dir . \DIRECTORY_SEPARATOR;
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException(
            'Cannot resolve Weline project root (composer.json + app/code) from '
            . ($startDir ?? __DIR__)
        );
    }

    public static function defineBp(?string $startDir = null): void
    {
        $root = self::path($startDir);
        if (!\defined('BP')) {
            \define('BP', $root);

            return;
        }

        $current = \rtrim((string)\constant('BP'), '\\/');
        $expected = \rtrim($root, '\\/');
        if ($current === $expected) {
            return;
        }

        // Suite pollution: an earlier test defined BP as app/ or app/code/.
        $probeVar = $current . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR . 'probe';
        $probeGen = $current . \DIRECTORY_SEPARATOR . 'generated' . \DIRECTORY_SEPARATOR . 'probe';
        if (
            self::isAccidentalAppCodeVar($probeVar)
            || self::isAccidentalAppCodeGenerated($probeGen)
            || \str_ends_with($current, \DIRECTORY_SEPARATOR . 'app')
            || \basename($current) === 'app'
        ) {
            throw new \RuntimeException(
                'BP already defined to a non-project root (' . $current
                . '); expected ' . $expected
                . '. Fix the earlier test dirname(__DIR__, N) or require process isolation.'
            );
        }
    }

    /**
     * True when a path is under the accidental {@code app/code/var} tree.
     */
    public static function isAccidentalAppCodeVar(string $path): bool
    {
        $needle = \DIRECTORY_SEPARATOR . 'app' . \DIRECTORY_SEPARATOR . 'code'
            . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR;

        return \str_contains($path, $needle)
            || \str_ends_with(
                \rtrim($path, '\\/'),
                \DIRECTORY_SEPARATOR . 'app' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR . 'var'
            );
    }

    /**
     * True when a path is under the accidental {@code app/code/generated} tree
     * (FPC html authority is repo-root {@code generated/html}).
     */
    public static function isAccidentalAppCodeGenerated(string $path): bool
    {
        $needle = \DIRECTORY_SEPARATOR . 'app' . \DIRECTORY_SEPARATOR . 'code'
            . \DIRECTORY_SEPARATOR . 'generated' . \DIRECTORY_SEPARATOR;

        return \str_contains($path, $needle)
            || \str_ends_with(
                \rtrim($path, '\\/'),
                \DIRECTORY_SEPARATOR . 'app' . \DIRECTORY_SEPARATOR . 'code'
                . \DIRECTORY_SEPARATOR . 'generated'
            );
    }
}
