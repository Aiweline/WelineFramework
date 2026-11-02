<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Deploy\StaticBuildPublisher;
use Weline\Framework\Deploy\StaticIntegrityGuard;
use Weline\Framework\Deploy\WelineCoreBundle;

/**
 * Runtime/static compile for the theme update pipeline.
 *
 * `deploy:upgrade` never only touches theme assets: it also publishes module
 * statics, merges the four frontend boot files into `weline-core.js`, stamps a new
 * static build id and self-heals an incomplete build generation. `theme:update` used
 * to publish theme assets only, so a theme-only update left `pub/static` on the
 * previous generation — the page then loaded an old `weline-core.js` (or none),
 * `data-weline-load` never resolved, and every on-demand widget script (store music,
 * currency, cart …) silently stopped loading even though the theme itself compiled.
 *
 * Everything here is idempotent: unchanged inputs report "skipped" and no bytes move.
 */
final class ThemeStaticRuntimeCompileService
{
    /**
     * @return array{static_root:string,core_bundle:?string,core_changed:bool,build:string,healed:list<string>,errors:list<string>}
     */
    public function compile(): array
    {
        $report = [
            'static_root' => '',
            'core_bundle' => null,
            'core_changed' => false,
            'build' => '',
            'healed' => [],
            'errors' => [],
        ];

        try {
            $report['static_root'] = DeployStagingSession::staticRoot();
        } catch (\Throwable $error) {
            $report['errors'][] = 'static_root: ' . $error->getMessage();

            return $report;
        }
        if ($report['static_root'] === '' || !\is_dir($report['static_root'])) {
            $report['errors'][] = 'static_root_missing: ' . $report['static_root'];

            return $report;
        }

        // 1) Merge the four boot files into weline-core.js (header loads this in PROD).
        try {
            $bundle = WelineCoreBundle::publish($report['static_root']);
            $report['core_bundle'] = \is_string($bundle['path'] ?? null) ? $bundle['path'] : null;
            $report['core_changed'] = (bool)($bundle['changed'] ?? false);
        } catch (\Throwable $error) {
            $report['errors'][] = 'core_bundle: ' . $error->getMessage();
        }

        // 2) Stamp a new static build generation so versioned URLs resolve.
        try {
            $report['build'] = StaticBuildPublisher::publish($report['static_root']);
        } catch (\Throwable $error) {
            $report['errors'][] = 'build: ' . $error->getMessage();
        }

        // 3) Self-heal an incomplete generation (staging publishes only changed files,
        //    which would otherwise 404 the rest of the tree).
        try {
            $integrity = StaticIntegrityGuard::healBuild($report['static_root']);
            if (!empty($integrity['rebuilt'])) {
                $report['healed'] = \is_array($integrity['missing'] ?? null) ? $integrity['missing'] : [];
                $report['build'] = (string)($integrity['build'] ?? $report['build']);
            }
        } catch (\Throwable $error) {
            $report['errors'][] = 'integrity: ' . $error->getMessage();
        }

        return $report;
    }
}
