<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend\Config;

use Weline\Framework\App\Controller\BackendController;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeResourceCatalog;

class Partials extends BackendController
{
    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeResourceCatalog $themeResourceCatalog,
        private readonly ThemeContextService $themeContextService,
    ) {
    }

    public function getIndex()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $scope = (string)$this->request->getParam('scope', 'default');
        if ($themeId <= 0) {
            return $this->fetchJson($this->error(__('Invalid theme id')));
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('Theme not found')));
        }

        $frontendPartials = $this->toLegacyPartialMap($this->themeResourceCatalog->getPartials('frontend', $theme));
        $backendPartials = $this->toLegacyPartialMap($this->themeResourceCatalog->getPartials('backend', $theme));

        $frontendConfig = $this->getPartialsConfig($theme, 'frontend', $scope);
        $backendConfig = $this->getPartialsConfig($theme, 'backend', $scope);

        $this->assign('theme', $theme);
        $this->assign('frontendPartials', $frontendPartials);
        $this->assign('backendPartials', $backendPartials);
        $this->assign('frontendConfig', $frontendConfig);
        $this->assign('backendConfig', $backendConfig);
        $this->assign('scope', $scope);

        return $this->fetch('Weline_Theme::templates/backend/config/partials.phtml');
    }

    public function getConfigData()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $scope = (string)$this->request->getParam('scope', 'default');
        if ($themeId <= 0) {
            return $this->fetchJson($this->error(__('Invalid theme id')));
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('Theme not found')));
        }

        $frontendPartials = $this->toLegacyPartialMap($this->themeResourceCatalog->getPartials('frontend', $theme));
        $backendPartials = $this->toLegacyPartialMap($this->themeResourceCatalog->getPartials('backend', $theme));

        $frontendConfig = $this->getPartialsConfig($theme, 'frontend', $scope);
        $backendConfig = $this->getPartialsConfig($theme, 'backend', $scope);

        return $this->fetchJson($this->success('', [
            'theme' => [
                'id' => $theme->getId(),
                'name' => $theme->getName(),
            ],
            'frontendPartials' => $frontendPartials,
            'backendPartials' => $backendPartials,
            'frontendConfig' => $frontendConfig,
            'backendConfig' => $backendConfig,
            'scope' => $scope,
        ]));
    }

    public function postSave()
    {
        $themeId = (int)$this->request->getPost('theme_id', 0);
        $area = $this->themeContextService->normalizeArea((string)$this->request->getPost('area', 'frontend'));
        $scope = (string)$this->request->getPost('scope', 'default');
        $partials = (array)$this->request->getPost('partials', []);

        if ($themeId <= 0) {
            return $this->fetchJson($this->error(__('Invalid theme id')));
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('Theme not found')));
        }

        $availablePartials = $this->toLegacyPartialMap($this->themeResourceCatalog->getPartials($area, $theme));
        foreach ($partials as $type => $option) {
            if (!isset($availablePartials[$type]) || !\in_array((string)$option, $availablePartials[$type], true)) {
                return $this->fetchJson($this->error(__('Partial option is not available: {type}/{option}', ['type' => $type, 'option' => $option])));
            }
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        foreach ($partials as $type => $option) {
            ThemeData::set("partials.{$type}.value", (string)$option, $scope);
        }
        ThemeData::clearCache();

        return $this->fetchJson($this->success(__('Partials saved')));
    }

    public function getOptions()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $area = $this->themeContextService->normalizeArea((string)$this->request->getParam('area', 'frontend'));
        $type = \trim((string)$this->request->getParam('type', ''));

        if ($themeId <= 0 || $type === '') {
            return $this->fetchJson($this->error(__('Invalid theme or partial type')));
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('Theme not found')));
        }

        $partials = $this->toLegacyPartialMap($this->themeResourceCatalog->getPartials($area, $theme));
        return $this->fetchJson($this->success('', ['options' => $partials[$type] ?? []]));
    }

    private function getPartialsConfig(WelineTheme $theme, string $area, string $scope = 'default'): array
    {
        $area = $this->themeContextService->normalizeArea($area);
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        return ThemeData::getPartialsConfig($area, $scope);
    }

    private function toLegacyPartialMap(array $partials): array
    {
        $result = [];
        foreach ($partials as $type => $options) {
            $legacyOptions = [];
            foreach ($options as $option) {
                $value = (string)($option['value'] ?? '');
                if ($value !== '') {
                    $legacyOptions[] = $value;
                }
            }
            $result[$type] = array_values(array_unique($legacyOptions));
        }

        return $result;
    }
}
