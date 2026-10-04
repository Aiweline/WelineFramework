<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * @deprecated Sidecar bindings are retired; layout relationships are emitted into PHTML.
 * Retained for legacy API consumers. Remove after their callers migrate to
 * SolidifiedControllerTemplateResolver and the save-time candidate publisher.
 */
final class ThemeLayoutEntityBindingStore
{

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        ?AtomicCompiledFilePublisher $publisher = null,
    ) {  }

    public function readPageBinding(ThemeVersionIdentity $identity, string $layoutIdentityHash): ?EntityRenderBinding
    { return null; }

    public function readChromeBinding(ThemeVersionIdentity $identity): ?EntityRenderBinding
    { return null; }

    public function publishPageBinding(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey,
        array $config,
        array $assets,
        int $baseVersionId = 0,
        string $chromeImmutableBindingKey = '',
        string $sourceFingerprint = '',
        string $injectionFingerprint = '',
    ): EntityRenderBinding { throw new \LogicException('theme_layout_sidecars_retired_use_phtml_candidates'); }

    public function publishChromeBinding(
        ThemeVersionIdentity $identity,
        string $structureKey,
        array $config,
        array $assets,
        int $baseVersionId = 0,
        string $sourceFingerprint = '',
        string $injectionFingerprint = '',
    ): EntityRenderBinding { throw new \LogicException('theme_layout_sidecars_retired_use_phtml_candidates'); }
}
