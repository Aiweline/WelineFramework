<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Http\Fpc\Bake\SitemapFpcBakeEngine;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeExporter;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeOrigin;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\StorefrontWebsiteCodeResolverInterface;
use Weline\Websites\Model\Website;

/**
 * Thin site-ops shell: resolve website + DetectWebsite gate, soft-pull Seo bake source,
 * then Framework connect-only bake + optional offline export.
 */
final class WebsiteFpcBakeOrchestrator
{
    /**
     * @return array{
     *   ok:bool,
     *   reason?:string,
     *   warnings:list<string>,
     *   website_id?:int,
     *   website_code?:string,
     *   origin?:string,
     *   refresh?:array<string,mixed>,
     *   loc_count?:int,
     *   bake?:array<string,mixed>,
     *   export?:array<string,mixed>,
     *   import_hint?:string
     * }
     */
    public function run(array $options): array
    {
        $warnings = [];
        $websiteArg = \trim((string)($options['website'] ?? ''));
        $originRaw = \trim((string)($options['origin'] ?? ''));
        $connect = \trim((string)($options['connect'] ?? ''));
        $dir = \trim((string)($options['dir'] ?? ''));
        $module = \trim((string)($options['module'] ?? ''));
        $loadTarget = isset($options['load_target']) ? (float)$options['load_target'] : 70.0;
        $maxConcurrency = isset($options['max_concurrency']) ? (int)$options['max_concurrency'] : null;
        $fullPower = !empty($options['full']);
        $dryRun = !empty($options['dry_run']);
        $failFast = !empty($options['fail_fast']);
        $skipRefresh = !empty($options['skip_sitemap_refresh']);
        $skipExport = !empty($options['skip_export']);

        $origin = FpcOfflineBakeOrigin::normalize($originRaw);
        if ($websiteArg === '' || $origin === '') {
            return ['ok' => false, 'reason' => 'website_and_origin_required', 'warnings' => $warnings];
        }

        $resolved = $this->resolveWebsite($websiteArg);
        if ($resolved === null) {
            return ['ok' => false, 'reason' => 'website_not_found', 'warnings' => $warnings];
        }
        [$websiteId, $websiteCode] = $resolved;

        $gate = $this->assertOriginBelongsToWebsite($origin, $websiteCode);
        if ($gate !== null) {
            return ['ok' => false, 'reason' => $gate, 'warnings' => $warnings, 'website_id' => $websiteId, 'website_code' => $websiteCode];
        }

        // Soft-pull concrete Api implementation (optional Seo). Prefer class_exists so
        // ObjectManager does not require a fresh provides compile of the Interface.
        $bakeSourceImpl = 'Weline\\Seo\\Api\\Sitemap\\SitemapBakeSource';
        if (!\class_exists($bakeSourceImpl)) {
            return [
                'ok' => false,
                'reason' => 'seo_module_unavailable',
                'warnings' => $warnings,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
            ];
        }

        /** @var object $source */
        $source = ObjectManager::getInstance($bakeSourceImpl);
        if (!\is_object($source)
            || !\method_exists($source, 'refreshForBake')
            || !\method_exists($source, 'listActiveLocs')
        ) {
            return [
                'ok' => false,
                'reason' => 'seo_bake_source_invalid',
                'warnings' => $warnings,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
            ];
        }

        $refreshMeta = null;
        // Dry-run only needs existing table locs; skip potentially long sync.
        if ($dryRun) {
            $skipRefresh = true;
        }
        if (!$skipRefresh) {
            /** @var array{ok:bool,hard_gate:bool,message:string,website_id:int} $refreshMeta */
            $refreshMeta = $source->refreshForBake($websiteId, $module);
            if (empty($refreshMeta['ok'])) {
                return [
                    'ok' => false,
                    'reason' => 'sitemap_refresh_failed:' . (string)($refreshMeta['message'] ?? ''),
                    'warnings' => $warnings,
                    'website_id' => $websiteId,
                    'website_code' => $websiteCode,
                    'refresh' => $refreshMeta,
                ];
            }
            if (!empty($refreshMeta['hard_gate'])) {
                $warnings[] = 'sitemap_hard_gate_files_skipped';
            }
        }

        /** @var list<string> $locs */
        $locs = $source->listActiveLocs($websiteId);
        if ($locs === [] && !$dryRun) {
            return [
                'ok' => false,
                'reason' => 'no_active_locs',
                'warnings' => $warnings,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'refresh' => $refreshMeta,
                'loc_count' => 0,
            ];
        }

        $engine = new SitemapFpcBakeEngine();
        $bake = $engine->bake(
            $locs,
            $origin,
            $connect,
            $loadTarget,
            $maxConcurrency,
            $fullPower,
            $dryRun,
            $failFast,
        );
        if (empty($bake['ok']) && !$dryRun) {
            return [
                'ok' => false,
                'reason' => 'bake_failed:' . (string)($bake['reason'] ?? 'errors'),
                'warnings' => $warnings,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'origin' => $origin,
                'refresh' => $refreshMeta,
                'loc_count' => \count($locs),
                'bake' => $bake,
            ];
        }

        $exportMeta = null;
        $importHint = null;
        if (!$dryRun && !$skipExport) {
            if ($dir === '') {
                $dir = (\defined('BP') ? (string)BP : \sys_get_temp_dir() . '/')
                    . 'var/tmp/fpc-bake-' . $websiteCode . '-' . \date('YmdHis');
            }
            $exporter = new FpcOfflineBakeExporter();
            $exportMeta = $exporter->export($dir, $websiteCode, $origin, $bake['planned'] ?? []);
            if (empty($exportMeta['ok'])) {
                return [
                    'ok' => false,
                    'reason' => 'export_failed:' . (string)($exportMeta['reason'] ?? 'unknown'),
                    'warnings' => $warnings,
                    'website_id' => $websiteId,
                    'website_code' => $websiteCode,
                    'origin' => $origin,
                    'refresh' => $refreshMeta,
                    'loc_count' => \count($locs),
                    'bake' => $bake,
                    'export' => $exportMeta,
                ];
            }
            $pkg = (string)($exportMeta['package_dir'] ?? $dir);
            $importHint = \sprintf(
                'php bin/w fpc:bake:import --website=%s --origin=%s --dir=%s',
                $websiteCode,
                $origin,
                $pkg
            );
        }

        return [
            'ok' => true,
            'warnings' => $warnings,
            'website_id' => $websiteId,
            'website_code' => $websiteCode,
            'origin' => $origin,
            'refresh' => $refreshMeta,
            'loc_count' => \count($locs),
            'bake' => $bake,
            'export' => $exportMeta,
            'import_hint' => $importHint,
        ];
    }

    /**
     * @return null|array{0:int,1:string}
     */
    private function resolveWebsite(string $arg): ?array
    {
        /** @var Website $model */
        $model = ObjectManager::getInstance(Website::class);
        if (\ctype_digit($arg)) {
            $id = (int)$arg;
            if ($id < 0) {
                return null;
            }
            $row = $model->reset()->load($id);
            $code = \trim((string)$row->getCode());
            if ($code === '') {
                return $id === 0 ? [0, 'default'] : null;
            }

            return [$id, $code];
        }
        $code = \trim($arg);
        $row = $model->reset()->where(Website::schema_fields_CODE, $code)->find()->fetch();
        $loadedCode = \trim((string)$row->getCode());
        // website_id=0 is valid; do not use truthiness on getId().
        if ($loadedCode === '') {
            return null;
        }

        return [(int)$row->getId(), $loadedCode];
    }

    private function assertOriginBelongsToWebsite(string $origin, string $websiteCode): ?string
    {
        $probeUrl = \rtrim($origin, '/') . '/';
        $resolverClass = StorefrontWebsiteCodeResolverInterface::class;
        if (!\interface_exists($resolverClass) && !\class_exists($resolverClass)) {
            return 'website_resolver_unavailable';
        }
        try {
            /** @var StorefrontWebsiteCodeResolverInterface $resolver */
            $resolver = ObjectManager::getInstance($resolverClass);
            $resolved = $resolver->resolveWebsiteCode($probeUrl);
        } catch (\Throwable $e) {
            return 'origin_resolve_failed:' . $e->getMessage();
        }
        if ($resolved === null || $resolved === '') {
            return 'origin_not_bound_to_any_website';
        }
        if (\strcasecmp($resolved, $websiteCode) !== 0) {
            return 'origin_website_mismatch:' . $resolved;
        }

        return null;
    }
}
