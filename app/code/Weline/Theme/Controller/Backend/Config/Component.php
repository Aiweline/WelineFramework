<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend\Config;

use Weline\Framework\App\Controller\BackendController;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeResourceCatalog;

class Component extends BackendController
{
    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeResourceCatalog $themeResourceCatalog,
        private readonly ThemeContextService $themeContextService,
    ) {
    }

    public function postSaveParams()
    {
        $themeId = (int)$this->request->getPost('theme_id', 0);
        $component = \trim((string)$this->request->getPost('component', ''));
        $area = $this->themeContextService->normalizeArea((string)$this->request->getPost('area', 'frontend'));
        $scope = (string)$this->request->getPost('scope', 'default');
        $params = $this->request->getPost('params', []);

        if ($themeId <= 0) {
            return $this->fetchJson($this->error(__('璇烽€夋嫨涓婚')));
        }

        if ($component === '') {
            return $this->fetchJson($this->error(__('璇烽€夋嫨缁勪欢')));
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('涓婚涓嶅瓨鍦?')));
        }

        $availableComponents = $this->themeResourceCatalog->getComponents($area, $theme);
        $componentExists = false;
        foreach ($availableComponents as $componentOption) {
            if ((string)($componentOption['value'] ?? '') === $component) {
                $componentExists = true;
                break;
            }
        }

        if (!$componentExists) {
            return $this->fetchJson($this->error(__('缁勪欢涓嶅瓨鍦')));
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $normalizedParams = [];
        foreach ((array)$params as $paramName => $paramValue) {
            if (\is_array($paramValue)) {
                $normalizedParams[(string)$paramName] = \json_encode($paramValue, \JSON_UNESCAPED_UNICODE);
                continue;
            }

            if ($paramValue === true) {
                $normalizedParams[(string)$paramName] = '1';
                continue;
            }

            if ($paramValue === false) {
                $normalizedParams[(string)$paramName] = '0';
                continue;
            }

            $normalizedParams[(string)$paramName] = (string)$paramValue;
        }

        try {
            ThemeData::setParamValues("components.{$component}", $normalizedParams, $scope);
            ThemeData::clearCache();
            return $this->fetchJson($this->success(__('鍙傛暟淇濆瓨鎴愬姛')));
        } catch (\Throwable $throwable) {
            return $this->fetchJson($this->error(__('淇濆瓨澶辫触锛?{error}', ['error' => $throwable->getMessage()])));
        }
    }
}
