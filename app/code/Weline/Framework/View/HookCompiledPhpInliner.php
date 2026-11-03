<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;

/**
 * Production compile: inline fixed include() of each hook contributor's compiled
 * com_*.phtml (ordered, solo-aware). Development keeps <?= $this->getHook() ?>.
 * Never bakes rendered HTML snapshots.
 */
final class HookCompiledPhpInliner
{
    /** 烘焙产物形态版本：变更烘焙输出时必须改这个值，让带缓存的 compiled 重编。 */
    public const COMPILE_SIGNATURE = 'baked-hook-guard-v1';

    /** @var array<string, int> */
    private static array $depth = [];

    public static function isProductionInlineEnabled(): bool
    {
        if (!(\defined('DEV') && DEV)) {
            return true;
        }

        // 发布/固化编译：dev 也按生产口径把 hook 烘成 include，
        // 避免产物里留 getHook() 让每次请求都走事件派发。
        return \getenv('WELINE_BAKE_TEMPLATE_TAGS') === '1';
    }

    /**
     * @param array{runtime?:bool|string|int} $attributes
     * @return string|null PHP snippet to embed, or null to keep getHook()
     */
    public static function tryEmit(string $hookName, array $attributes = []): ?string
    {
        $hookName = \trim($hookName);
        if ($hookName === '' || !self::isProductionInlineEnabled()) {
            return null;
        }
        if (self::wantsRuntime($attributes)) {
            return null;
        }
        if (self::isEditorOrPreviewContext()) {
            return null;
        }

        $depth = self::$depth[$hookName] ?? 0;
        if ($depth >= 3) {
            return null;
        }
        self::$depth[$hookName] = $depth + 1;
        try {
            return self::buildInlinePhp($hookName);
        } catch (\Throwable) {
            return null;
        } finally {
            self::$depth[$hookName] = \max(0, (self::$depth[$hookName] ?? 1) - 1);
            if (self::$depth[$hookName] === 0) {
                unset(self::$depth[$hookName]);
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    private static function wantsRuntime(array $attributes): bool
    {
        $raw = $attributes['runtime'] ?? $attributes['hook-runtime'] ?? null;
        if ($raw === null || $raw === '') {
            return false;
        }
        if (\is_bool($raw)) {
            return $raw;
        }
        $v = \strtolower(\trim((string)$raw));

        return \in_array($v, ['1', 'true', 'yes', 'on', 'runtime'], true);
    }

    private static function isEditorOrPreviewContext(): bool
    {
        if (\function_exists('w_env_get')) {
            if ((string)\w_env_get('editor_mode', '') !== '') {
                return true;
            }
            if ((string)\w_env_get('preview', '') === '1') {
                return true;
            }
        }
        try {
            $req = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            if ((string)$req->getGet('editor_mode', '') !== ''
                || (string)$req->getGet('preview', '') === '1'
                || (string)$req->getGet('shell', '') === 'theme-editor') {
                return true;
            }
            $path = (string)$req->getUriPath();
            if (\str_contains($path, 'workspace-preview') || \str_contains($path, '/~preview/')) {
                return true;
            }
        } catch (\Throwable) {
            // ignore
        }

        return false;
    }

    private static function buildInlinePhp(string $hookName): ?string
    {
        /** @var HookReader $hookReader */
        $hookReader = ObjectManager::make(HookReader::class);
        $hookReader->setPath($hookName);
        $withMeta = $hookReader->getFileListWithMeta();
        $files = [];
        if ($withMeta !== []) {
            foreach ($withMeta as $module => $meta) {
                $filePath = (string)($meta['file'] ?? $meta['path'] ?? '');
                if ($filePath === '') {
                    continue;
                }
                $files[$module] = \strpos($filePath, '::') !== false
                    ? $filePath
                    : $module . '::hooks/' . $filePath;
            }
        }
        if ($files === []) {
            $files = $hookReader->getFileList();
        }
        if ($files === []) {
            return null;
        }

        // solo 语义与 Template 共用 HookReader::applySolo()。
        $files = HookReader::applySolo($files, $withMeta);

        $template = Template::getInstance();
        $includes = [];
        foreach ($files as $module => $hookFile) {
            $comPath = $template->fetchTagSource('hooks', (string)$hookFile);
            if (!\is_string($comPath) || $comPath === '' || !\is_file($comPath)) {
                return null;
            }
            $real = \realpath($comPath) ?: $comPath;
            $includes[] = [
                'module' => (string)$module,
                'path' => $real,
            ];
        }
        if ($includes === []) {
            return null;
        }

        $paths = [];
        $parts = [];
        foreach ($includes as $row) {
            $paths[] = $row['path'];
            $export = \var_export($row['path'], true);
            $mod = \addcslashes($row['module'], "\\'");
            $parts[] = '/* baked-hook:' . $hookName . ' @' . $mod . ' */'
                . ' include ' . $export . ';';
        }

        // 烘焙用的是绝对路径，而 compiled 目录会被 theme:update -f / gc 重建（换 hash 或清空）。
        // 目标文件缺失时不能静默丢内容：回落运行期 getHook()，保证重建后 hook 依然完整。
        $pathsExport = \var_export($paths, true);
        $hookExport = \var_export($hookName, true);

        return '<?php $__w_baked=' . $pathsExport . '; $__w_ok=true;'
            . ' foreach($__w_baked as $__w_p){ if(!\is_file($__w_p)){ $__w_ok=false; break; } }'
            . ' if($__w_ok){ ' . \implode(' ', $parts) . ' }'
            . ' else { echo $this->getHook(' . $hookExport . '); } ?>';
    }
}
