<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

/** Existing ThemeData writes record a new immutable configuration revision. */
final class ThemeLayoutConfigurationWriter
{
    private static array $active = [];

    public function write(int $themeId, string $scope, string $area, callable $mutation): mixed
    {
        $context = ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class)->buildContext($themeId, 'homepage', $area, ['scope' => $scope]);
        $owner = new ThemeVersionIdentity($themeId, $context->scope->storageScope, $context->scope->storeMode, $area);
        $fiber = \Fiber::getCurrent();
        $execution = getmypid() . ':' . ($fiber ? spl_object_id($fiber) : 'main') . ':' . (RequestContext::getId() ?? '') . ':' . $owner->ownerHash();
        if (isset(self::$active[$execution])) { return $mutation(); }
        return ThemeLayoutEntityOwnerLock::write($owner, function () use ($context, $mutation, $execution): mixed {
            self::$active[$execution] = true;
            try {
                $snapshots = ObjectManager::getInstance(ThemeVersionResourceSnapshotService::class);
                $model = ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeVersion::class);
                $saved = ObjectManager::getInstance(\Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface::class)->runWrite($model->getConnection(), function () use ($context, $mutation, $snapshots): array {
                    $version = $snapshots->beginWrite($context);
                    $result = $mutation();
                    if ($result === false) { return ['result' => false]; }
                    $identity = $snapshots->advanceConfiguration($version, (new ThemeLayoutConfigurationSnapshot())->capture($context));
                    return ['result' => $result, 'version_identity' => $identity->toArray(), 'theme_version_id' => $identity->themeVersionId, 'content_revision' => $identity->contentRevision];
                });
                if (isset($saved['version_identity'])) {
                    try { ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class)->afterResourceWrite($context, $saved); }
                    catch (\Throwable $error) { throw new ThemeLayoutEntitySaveException($saved, $error); }
                }
                return $saved['result'];
            } finally { unset(self::$active[$execution]); }
        });
    }
}
