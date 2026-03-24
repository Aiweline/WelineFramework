<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\ConfigLoader;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

class PartialsFetchFileBefore implements ObserverInterface
{
    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeContextService $themeContext,
    ) {
    }

    public function execute(Event &$event): void
    {
        /** @var DataObject|null $fileData */
        $fileData = $event->getData('data');
        if (!$fileData instanceof DataObject) {
            return;
        }

        $modulePath = (string)$fileData->getData('filename');
        if ($modulePath === '' || !str_contains($modulePath, '/partials/')) {
            return;
        }

        $pathInfo = $this->parsePartialsPath($modulePath);
        if ($pathInfo === null) {
            return;
        }

        try {
            $area = $pathInfo['area'];
            $partialType = $pathInfo['type'];
            $currentOption = $pathInfo['option'];
            $theme = $this->resolveTheme($area);
            if (!$theme || !$theme->getId()) {
                return;
            }

            $scope = $this->themeContext->resolveCurrentScope($area);
            $configOption = ConfigLoader::getPartialConfig($theme, $area, $partialType, $scope);

            $finalOption = $currentOption;
            if ($configOption !== '' && $configOption !== $currentOption) {
                $fileData->setData('filename', $this->replacePartialsOption($modulePath, $currentOption, $configOption));
                $finalOption = $configOption;
            }

            $metaIdentify = 'partials.' . $partialType . '.' . ($finalOption !== '' ? $finalOption : 'default');
            $partialParams = ThemeData::getFileParams($metaIdentify, $scope);

            if (empty($partialParams)) {
                $finalPath = (string)$fileData->getData('filename');
                $partialFilePath = $this->getPartialFilePath($finalPath, $theme, $area, $partialType, $finalOption);
                if ($partialFilePath && is_file($partialFilePath)) {
                    $parsedMeta = ComponentMetaParser::parse($partialFilePath);
                    if (!empty($parsedMeta['params']) && is_array($parsedMeta['params'])) {
                        $formattedParams = LayoutPathResolver::formatParsedParams($parsedMeta['params']);
                        foreach ($formattedParams as $paramName => $paramDef) {
                            $defaultValue = $paramDef['default'] ?? null;
                            if ($defaultValue === 'true' || $defaultValue === true) {
                                $defaultValue = true;
                            } elseif ($defaultValue === 'false' || $defaultValue === false) {
                                $defaultValue = false;
                            } elseif ($defaultValue === '') {
                                $defaultValue = '';
                            }
                            $partialParams[$paramName] = $defaultValue;
                        }
                    }
                }
            }

            $partialParams = is_array($partialParams) ? $partialParams : [];

            $template = Template::getInstance();
            $existingMeta = $template->getData('meta');
            if (!is_array($existingMeta)) {
                $existingMeta = [];
            }

            $metaData = array_merge($existingMeta, $partialParams);
            ThemeData::performanceLoad();
            $themeMetaDataObj = ThemeData::getMeta("theme.{$area}.partials.{$partialType}");
            if ($themeMetaDataObj && !empty($themeMetaDataObj['meta_data']) && is_array($themeMetaDataObj['meta_data'])) {
                $metaData = array_merge($metaData, $themeMetaDataObj['meta_data']);
            }

            $template->setData('meta', $metaData);
        } catch (\Throwable) {
            return;
        }
    }

    private function resolveTheme(string $area): ?WelineTheme
    {
        $theme = $this->themeContext->resolveTheme($area);
        if ($theme && $theme->getId()) {
            return $theme;
        }

        $fallback = clone $this->welineTheme;
        $fallback->clearData()->clearQuery()->getActiveTheme($area);

        return $fallback->getId() ? $fallback : null;
    }

    private function parsePartialsPath(string $path): ?array
    {
        $partialsPos = strpos($path, '/partials/');
        if ($partialsPos === false) {
            return null;
        }

        $afterPartials = substr($path, $partialsPos + 10);
        if ($afterPartials === false || $afterPartials === '') {
            return null;
        }

        $parts = explode('/', $afterPartials, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $type = $parts[0];
        $file = $parts[1];
        if (!str_ends_with($file, '.phtml')) {
            return null;
        }

        $option = substr($file, 0, -6);
        $themePos = strpos($path, 'theme/');
        if ($themePos === false) {
            $viewThemePos = strpos($path, 'view/theme/');
            if ($viewThemePos === false) {
                return null;
            }
            $themePos = $viewThemePos + 5;
        }

        $afterTheme = substr($path, $themePos + 6);
        if ($afterTheme === false) {
            return null;
        }

        $areaParts = explode('/', $afterTheme, 2);
        $area = $areaParts[0] ?? '';
        if (!in_array($area, ['frontend', 'backend'], true)) {
            return null;
        }

        return [
            'area' => $area,
            'type' => $type,
            'option' => $option,
        ];
    }

    private function replacePartialsOption(string $path, string $oldOption, string $newOption): string
    {
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false) {
            return $path;
        }

        return substr($path, 0, $lastSlash + 1) . $newOption . '.phtml';
    }

    private function getPartialFilePath(
        string $modulePath,
        WelineTheme $theme,
        string $area,
        string $partialType,
        string $partialOption
    ): ?string {
        if (is_file($modulePath)) {
            return $modulePath;
        }

        $themePath = (string)$theme->getPath();
        if ($themePath !== '') {
            $partialPath = rtrim($themePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'view'
                . DIRECTORY_SEPARATOR . 'theme'
                . DIRECTORY_SEPARATOR . $area
                . DIRECTORY_SEPARATOR . 'partials'
                . DIRECTORY_SEPARATOR . $partialType
                . DIRECTORY_SEPARATOR . $partialOption
                . '.phtml';
            if (is_file($partialPath)) {
                return $partialPath;
            }
        }

        $themePos = strpos($modulePath, DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR);
        if ($themePos !== false) {
            $relativePath = substr($modulePath, $themePos + strlen(DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR));
            $fullPath = BP . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($fullPath)) {
                return $fullPath;
            }
        }

        if (strpos($modulePath, '::') !== false) {
            [$moduleName, $relativePath] = explode('::', $modulePath, 2);
            $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
            if (isset($modules[$moduleName]['base_path'])) {
                $fullPath = rtrim((string)$modules[$moduleName]['base_path'], DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
                    . $relativePath;
                if (is_file($fullPath)) {
                    return $fullPath;
                }
            }
        }

        return null;
    }
}
