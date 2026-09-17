<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * Storefront classical typography presets for Theme Editor appearance disk.
 *
 * Roles:
 * - base / body: long copy (宋体可读)
 * - display: hero / section / card titles (文楷古风)
 * - ui: nav / controls / dense chrome (偏干净，仍可古风)
 */
final class StorefrontFontPresetCatalog
{
    /**
     * @return list<array{
     *   id:string,
     *   label:string,
     *   role:list<string>,
     *   stack:string,
     *   face_family:string
     * }>
     */
    public static function presets(): array
    {
        return [
            [
                'id' => 'lxgw-wenkai',
                'label' => '霞鹜文楷',
                'role' => ['display', 'base', 'ui'],
                'stack' => '"LXGW WenKai", "Kaiti SC", "STKaiti", "KaiTi", "Noto Serif SC", "Songti SC", serif',
                'face_family' => 'LXGW WenKai',
            ],
            [
                'id' => 'noto-serif-sc',
                'label' => '思源宋体',
                'role' => ['base', 'ui', 'display', 'serif'],
                'stack' => '"Noto Serif SC", "Songti SC", "Source Han Serif SC", "STSong", "SimSun", "Noto Sans SC", "PingFang SC", serif',
                'face_family' => 'Noto Serif SC',
            ],
            [
                'id' => 'zcool-xiaowei',
                'label' => '站酷小薇',
                'role' => ['display'],
                'stack' => '"ZCOOL XiaoWei", "LXGW WenKai", "Kaiti SC", "Noto Serif SC", "Songti SC", serif',
                'face_family' => 'ZCOOL XiaoWei',
            ],
            [
                'id' => 'noto-sans-sc',
                'label' => '思源黑体',
                'role' => ['ui', 'base'],
                'stack' => '"Noto Sans SC", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif',
                'face_family' => 'Noto Sans SC',
            ],
            [
                'id' => 'songti-system',
                'label' => '系统宋体',
                'role' => ['base', 'serif', 'ui'],
                'stack' => '"Songti SC", "STSong", "SimSun", "Noto Serif SC", serif',
                'face_family' => 'Songti SC',
            ],
            [
                'id' => 'kaiti-system',
                'label' => '系统楷体',
                'role' => ['display'],
                'stack' => '"Kaiti SC", "STKaiti", "KaiTi", "LXGW WenKai", "Noto Serif SC", serif',
                'face_family' => 'Kaiti SC',
            ],
        ];
    }

    /**
     * Default classical pairing for ink / Hanfu storefront.
     * Must stay aligned with view/theme/frontend/variables/_typography.css.
     *
     * @return array{base:string,display:string,ui:string,serif:string}
     */
    public static function defaultStacks(): array
    {
        $byId = [];
        foreach (self::presets() as $preset) {
            $byId[$preset['id']] = $preset['stack'];
        }

        return [
            // 正文：宋体稳、好扫读
            'base' => $byId['noto-serif-sc'],
            // 标题/品牌：霞鹜文楷（默认古风主信号）
            'display' => $byId['lxgw-wenkai'],
            // 顶栏/按钮/筛选：略短宋体栈，避免楷书挤在密控件里
            'ui' => '"Noto Serif SC", "Songti SC", "Noto Sans SC", "PingFang SC", serif',
            'serif' => '"Noto Serif SC", "Songti SC", "Source Han Serif SC", "STSong", "SimSun", Georgia, "Times New Roman", serif',
        ];
    }

    /**
     * Token names editable as font selects in appearance typography panel.
     *
     * @return list<string>
     */
    public static function selectableTokenNames(): array
    {
        return [
            '--font-family-base',
            '--font-family-display',
            '--font-family-ui',
            '--font-family-serif',
        ];
    }

    /**
     * @return array<string, list<array{id:string,label:string,stack:string}>>
     */
    public static function optionsByToken(): array
    {
        $map = [
            '--font-family-base' => ['base', 'ui'],
            '--font-family-display' => ['display'],
            '--font-family-ui' => ['ui', 'base'],
            '--font-family-serif' => ['serif', 'base'],
        ];
        $out = [];
        foreach ($map as $token => $roles) {
            $opts = [];
            foreach (self::presets() as $preset) {
                $hit = false;
                foreach ($roles as $role) {
                    if (\in_array($role, $preset['role'], true)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                $opts[] = [
                    'id' => $preset['id'],
                    'label' => $preset['label'],
                    'stack' => $preset['stack'],
                ];
            }
            $out[$token] = $opts;
        }

        return $out;
    }
}
