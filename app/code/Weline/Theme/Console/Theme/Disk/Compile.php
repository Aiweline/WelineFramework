<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme\Disk;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Disk\ThemeDiskCompileService;
use Weline\Theme\Service\Disk\ThemeDiskKeys;

class Compile extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        $themeId = isset($args['t']) ? (int)$args['t'] : (isset($args['theme_id']) ? (int)$args['theme_id'] : 0);
        $areaArg = (string)($args['a'] ?? $args['area'] ?? '');
        $scope = ThemeDiskKeys::normalizeScope((string)($args['scope'] ?? 'default'));

        /** @var ThemeDiskCompileService $compile */
        $compile = ObjectManager::getInstance(ThemeDiskCompileService::class);
        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);

        $themes = [];
        if ($themeId > 0) {
            $theme = $themeModel->clear()->load($themeId);
            if ($theme && (int)$theme->getId()) {
                $themes[] = $theme;
            }
        } else {
            $themes = $themeModel->clear()->select()->fetch()->getItems();
        }

        $areas = $areaArg !== ''
            ? [ThemeDiskKeys::normalizeArea($areaArg)]
            : ['frontend', 'backend'];

        $ok = 0;
        foreach ($themes as $theme) {
            if (!$theme instanceof WelineTheme) {
                continue;
            }
            foreach ($areas as $area) {
                ThemeData::setCurrentTheme($theme);
                ThemeData::setCurrentArea($area);
                $result = $compile->compileBundle($theme, $area, $scope);
                $ok++;
                $this->printer->success((string)__(
                    '已合编 theme=%{id} area=%{area} hash=%{hash} empty=%{empty}',
                    [
                        'id' => (int)$theme->getId(),
                        'area' => $area,
                        'hash' => $result['hash'] ?: '-',
                        'empty' => !empty($result['empty']) ? '1' : '0',
                    ]
                ));
            }
        }

        $this->printer->note((string)__('主题整盘合编结束，共 %{n} 次', ['n' => $ok]));
    }

    public function tip(): string
    {
        return '从 Meta 合编主题整盘 override CSS 到 generated/theme/disks';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:disk:compile',
            $this->tip(),
            [
                't|theme_id' => '主题 ID，可选',
                'a|area' => 'frontend|backend，可选，默认两边',
                'scope' => '作用域，默认 default',
            ],
            [],
            []
        );
    }
}
