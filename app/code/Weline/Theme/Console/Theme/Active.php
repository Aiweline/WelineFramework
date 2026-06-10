<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

class Active extends AbstractConsole
{
    public function __construct(
        WelineTheme $welineTheme,
        Printing $printing,
        private readonly ThemeContextService $themeContext,
    ) {
        parent::__construct($welineTheme, $printing);
    }

    public function execute(array $args = [], array $data = [])
    {
        $theme_name = $args[1] ?? '';
        if ($theme_name !== '') {
            $theme = $this->welineTheme->load('name', $theme_name);
            if (!$theme->getId()) {
                $this->printing->error(__('当前主题未安装：激活失败！'), __('主题'));

                return;
            }
            $areaRaw = isset($args[2]) ? strtolower(trim((string)$args[2])) : '';
            if ($areaRaw !== '' && !$this->themeContext->isSupportedActivationArea($areaRaw)) {
                $this->printing->error(__('theme:active 区域参数无效，可选 frontend、backend、global'), __('主题'));

                return;
            }
            $activationArea = $this->themeContext->normalizeActivationArea($areaRaw === '' ? 'global' : $areaRaw);
            $field = $this->themeContext->getActivationField($activationArea);
            $alreadyActive = (int)$theme->getData($field) === 1;
            $status = $alreadyActive ? __('已激活') : __('未激活');
            $this->printing->note(__('当前主题:') . $theme_name);
            $this->printing->note(__('安装状态:已安装！'));
            $this->printing->note(__('激活状态:') . $status);
            if ($alreadyActive) {
                $this->printing->error(__('无需再次激活'));
            } else {
                $this->printing->note(__('正在激活...'));
            }
            if (!$alreadyActive) {
                if ($activationArea === null) {
                    $theme->setIsActive(true);
                } else {
                    $theme->setData($field, 1);
                }
                try {
                    $theme->save();
                    $this->clearActivationRuntimeCaches((int)$theme->getId(), $activationArea);
                    $this->printing->success(__('已成功激活主题：') . $theme_name);
                } catch (\ReflectionException $e) {
                    throw $e;
                } catch (Core $e) {
                    throw $e;
                }
            }
        } else {
            $fe = $this->themeContext->resolveTheme(ThemeContextService::AREA_FRONTEND, null, false);
            $be = $this->themeContext->resolveTheme(ThemeContextService::AREA_BACKEND, null, false);
            $this->printing->success(__('theme:active 前台：%{1}', [$fe && $fe->getId() ? $fe->getName() : '-']));
            $this->printing->success(__('theme:active 后台：%{1}', [$be && $be->getId() ? $be->getName() : '-']));
            $legacy = $this->welineTheme->getActiveTheme();
            if ($legacy->getId()) {
                $this->printing->note(__('theme:active 全局 is_active：%{1}', [$legacy->getName()]));
            }
        }
    }

    public function tip(): string
    {
        return '查看当前主题或者激活特定主题';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:active',
            '查看当前激活的主题或激活指定主题',
            [
                '-h, --help' => '显示帮助信息',
            ],
            [
                '<主题名>' => '要激活的主题名称（可选，例如：Weline_Default）',
                '<区域>' => '可选：frontend | backend | global（默认 global，对应 is_active）',
            ],
            [
                '查看前台/后台激活主题' => 'php bin/w theme:active',
                '激活指定主题（全局）' => 'php bin/w theme:active Weline_Default',
                '仅激活前台' => 'php bin/w theme:active Weline_Default frontend',
            ]
        );
    }

    private function clearActivationRuntimeCaches(int $themeId, ?string $area): void
    {
        try {
            ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class)->clearNonGlobalCaches(
                $themeId,
                'theme_cli_active_' . ($area ?? 'global')
            );
        } catch (\Throwable) {
        }
    }
}
