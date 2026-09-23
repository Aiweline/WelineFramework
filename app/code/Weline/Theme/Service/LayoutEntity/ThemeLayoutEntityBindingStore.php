<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Runtime\RequestContext;

/** 完整产物先落盘，再原子切换实体绑定；旧请求继续使用旧配置快照。 */
final class ThemeLayoutEntityBindingStore
{
    private readonly AtomicCompiledFilePublisher $publisher;

    public function __construct(private readonly ThemeLayoutEntityPaths $paths, ?AtomicCompiledFilePublisher $publisher = null)
    {
        $this->publisher = $publisher ?? new AtomicCompiledFilePublisher();
    }

    public function readPageBinding(int $themeId, string $scope, string $identityKey, string $entityKey): ?EntityRenderBinding
    {
        return $this->read($this->paths->pageBindingJson($themeId, $scope, $identityKey, $entityKey), $themeId, $scope, $identityKey, $entityKey, 'page');
    }

    public function readChromeBinding(int $themeId, string $scope, int $versionId): ?EntityRenderBinding
    {
        return $this->read($this->paths->chromeBindingJson($themeId, $scope, $versionId), $themeId, $scope, '', 'tv' . $versionId, 'chrome');
    }

    public function publishPageBinding(int $themeId, string $scope, string $identityKey, string $entityKey, string $structureKey, array $config, array $assets): EntityRenderBinding
    {
        return $this->publish($this->paths->pageBindingJson($themeId, $scope, $identityKey, $entityKey), $themeId, $scope, $identityKey, $entityKey, $structureKey, 'page', $config, $assets);
    }

    public function publishChromeBinding(int $themeId, string $scope, int $versionId, string $structureKey, array $config, array $assets): EntityRenderBinding
    {
        return $this->publish($this->paths->chromeBindingJson($themeId, $scope, $versionId), $themeId, $scope, '', 'tv' . $versionId, $structureKey, 'chrome', $config, $assets);
    }

    private function publish(string $manifest, int $themeId, string $scope, string $identityKey, string $entityKey, string $structureKey, string $source, array $config, array $assets): EntityRenderBinding
    {
        $configJson = $this->encode($config);
        $assetsJson = $this->encode($assets);
        $configKey = hash('sha256', $configJson . "\n" . $assetsJson);
        $binding = $this->binding($themeId, $scope, $identityKey, $entityKey, $structureKey, $configKey, $source);
        if (!is_file($binding->templatePath) || !is_file($binding->structurePath)) {
            throw new \RuntimeException('theme_layout_binding_structure_missing');
        }
        // 内容寻址文件一旦存在便不重写，确保旧请求的快照稳定。
        foreach ([$binding->configPath => $configJson, $binding->assetsPath => $assetsJson] as $path => $content) {
            if (!is_file($path)) {
                $this->publisher->publish($path, $content);
            }
        }
        $manifestJson = $this->encode([
            'schema_version' => 2,
            'structure_key' => $structureKey,
            'config_key' => $configKey,
        ]);
        if (!is_file($manifest) || file_get_contents($manifest) !== $manifestJson) {
            $this->publisher->publish($manifest, $manifestJson);
        }
        // 同一请求中的写后再读取得新绑定；已传入模板的 DTO 仍保持旧快照。
        if (class_exists(RequestContext::class)) {
            $identity = $source === 'chrome'
                ? [$themeId, $scope, (int)substr($entityKey, 2)]
                : [$themeId, $scope, $identityKey, $entityKey];
            RequestContext::set('theme.layout_entity.' . $source . '_binding.'
                . hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), null);
        }
        return $binding;
    }

    private function read(string $manifest, int $themeId, string $scope, string $identityKey, string $entityKey, string $source): ?EntityRenderBinding
    {
        if (!is_file($manifest)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($manifest), true);
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 2
            || !is_string($data['structure_key'] ?? null) || !preg_match('/^s[a-f0-9]{64}$/D', $data['structure_key'])
            || !is_string($data['config_key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['config_key'])) {
            return null;
        }
        return $this->binding($themeId, $scope, $identityKey, $entityKey, $data['structure_key'], $data['config_key'], $source);
    }

    private function binding(int $themeId, string $scope, string $identityKey, string $entityKey, string $structureKey, string $configKey, string $source): EntityRenderBinding
    {
        if (!preg_match('/^s[a-f0-9]{64}$/D', $structureKey)) {
            throw new \InvalidArgumentException('theme_layout_binding_structure_key_invalid');
        }
        $structureDir = $source === 'chrome'
            ? $this->paths->chromeStructureDir($themeId, $scope, $structureKey)
            : $this->paths->pageDir($themeId, $scope, $identityKey, $structureKey);
        $configDir = $source === 'chrome'
            ? $this->paths->chromeConfigBundleDir($themeId, $scope, $configKey)
            : $this->paths->pageConfigBundleDir($themeId, $scope, $identityKey, $configKey);
        return new EntityRenderBinding($themeId, $scope, $identityKey, $entityKey, $structureKey, $configKey, $source,
            $structureDir . ($source === 'chrome' ? 'chrome.phtml' : 'layout.phtml'),
            $configDir . $source . '-config.json', $configDir . $source . '-assets.json',
            $structureDir . 'structure.json', $structureDir . 'shell.phtml');
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
