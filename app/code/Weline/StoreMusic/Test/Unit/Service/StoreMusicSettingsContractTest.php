<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\StoreMusic\Service\StoreMusicSettings;

final class StoreMusicSettingsContractTest extends TestCase
{
    public function testModuleUsesSystemConfigHookAndDecoupledMediaPicker(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $settings = (string)file_get_contents($moduleRoot . '/Service/StoreMusicSettings.php');
        $template = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/store-music.phtml'
        );
        $configController = (string)file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
        $configTemplate = (string)file_get_contents($moduleRoot . '/view/templates/Backend/Config/index.phtml');
        $menuXml = (string)file_get_contents($moduleRoot . '/etc/backend/menu.xml');
        $widget = (string)file_get_contents(
            $moduleRoot . '/view/templates/frontend/widgets/store-music.phtml'
        );
        $hook = (string)file_get_contents(
            $moduleRoot . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        $js = (string)file_get_contents($moduleRoot . '/view/statics/js/store-music.js');
        $modulesJs = (string)file_get_contents($moduleRoot . '/view/statics/frontend/weline.modules.js');

        self::assertStringContainsString('store_music/general/enabled', $template);
        self::assertStringContainsString('store_music/music/playlist', $template);
        self::assertStringContainsString('store_music/music/track', $template);
        self::assertStringContainsString('store_music/music/delay_seconds', $template);
        self::assertStringContainsString('store_music/music/try_autoplay', $template);
        self::assertStringContainsString('store_music/music/loop', $template);
        self::assertStringContainsString('store_music/music/default_volume', $template);
        self::assertStringContainsString('store_music/visual/waveform_default', $template);
        self::assertStringContainsString('store_music/visual/avatar_spin', $template);
        self::assertStringContainsString('scope="global,website,store"', $template);
        self::assertStringContainsString('ext="mp3,wav,ogg,oga,m4a,aac,flac,opus,wma,weba"', $template);

        self::assertStringContainsString('ConfigReader', $settings);
        self::assertStringContainsString('isWidgetActive', $settings);
        self::assertStringContainsString('frontendPayload', $settings);
        self::assertStringContainsString('widgetConfigFromTemplate', $settings);
        self::assertStringContainsString('tracksFromWidgetConfig', $settings);
        self::assertStringContainsString('KEY_PLAYLIST', $settings);
        self::assertStringContainsString('KEY_AVATAR_SPIN', $settings);
        self::assertStringContainsString('avatarSpin', $settings);
        self::assertStringContainsString("'avatar_spin'", $settings);
        self::assertStringContainsString('parsePlaylistJson', $settings);
        self::assertStringContainsString('normalizeIntroMap', $settings);
        self::assertStringContainsString('resolveIntroForLocale', $settings);
        self::assertStringContainsString('tracksForFrontend', $settings);

        self::assertStringContainsString('SystemConfigTargetScopeService', $configController);
        self::assertStringContainsString('intro_edit_locale', $configController);
        self::assertStringContainsString('<w:config:embed', $configTemplate);
        self::assertStringContainsString('module="Weline_StoreMusic"', $configTemplate);
        self::assertStringContainsString('WelineMedia', $configTemplate);
        self::assertStringContainsString('Weline_FileManager::js/w-scope.js', $configTemplate);
        self::assertStringContainsString('ConfigMediaReferenceTemplates::config', $configTemplate);
        self::assertStringContainsString("'identity' => \$storeMusicIdentityPath", $configTemplate);
        self::assertStringContainsString("'picker_title'", $configTemplate);
        self::assertStringContainsString("'identity_root' => 'config'", $configTemplate);
        self::assertStringContainsString("'identity_scope' => \$storeMusicIdentityScope", $configTemplate);
        self::assertStringContainsString("'strong_ref' => '1'", $configTemplate);
        self::assertStringContainsString('ext\' => \'mp3,wav,ogg,oga,m4a,aac,flac,opus,wma,weba\'', $configTemplate);
        self::assertStringContainsString("'multi' => '1'", $configTemplate);
        self::assertStringContainsString('20971520', $configTemplate);
        self::assertStringContainsString('system_config', $configTemplate);
        self::assertStringContainsString('setScopedConfig', $configTemplate);
        self::assertStringContainsString('expected_grant_version', $configTemplate);
        self::assertStringContainsString('<w:scope', $configTemplate);
        self::assertStringContainsString('data-store-music-playlist-editor', $configTemplate);
        self::assertStringContainsString('data-w-component="reorder-list"', $configTemplate);
        self::assertStringContainsString('data-w-reorder-handle', $configTemplate);
        self::assertStringContainsString('w:i18n:language:select', $configTemplate);
        self::assertStringContainsString('data-intro-i18n', $configTemplate);
        self::assertStringContainsString('action="*/backend/config"', $menuXml);

        self::assertStringContainsString('isWidgetActive', $widget);
        self::assertStringContainsString('widgetConfigFromTemplate', $widget);
        self::assertStringContainsString('frontendPayload($widgetConfig)', $widget);
        self::assertStringContainsString('data-weline-load="storeMusic"', $widget);
        self::assertStringContainsString('data-testid="store-music-widget"', $widget);
        self::assertStringContainsString('data-store-music-intro', $widget);
        self::assertStringContainsString("\$t = static fn (string \$word): string => \$esc((string)__(\$word))", $widget);
        self::assertStringNotContainsString('CustomerService', $widget);
        self::assertStringContainsString('templates/frontend/widgets/store-music.phtml', $hook);
        self::assertStringContainsString('BP . ', $hook);

        self::assertStringContainsString('storeMusic', $modulesJs);
        self::assertStringContainsString('load: "defer"', $modulesJs);
        self::assertStringContainsString('marketingAllowed', $js);
        self::assertStringContainsString('resource(\'consent\')', $js);
        self::assertStringContainsString('delay_seconds', $js);
        self::assertStringContainsString('createMediaElementSource', $js);
        self::assertStringContainsString('prefers-reduced-motion', $js);
        self::assertStringContainsString('visibilitychange', $js);
        self::assertStringContainsString('normalizeTracks', $js);
        self::assertStringContainsString('skipTrack', $js);
        self::assertStringContainsString('normalizeTrackUrl', $js);
        self::assertStringContainsString('hasResumeSnap', $js);
        self::assertStringContainsString('persistSelection', $js);
        self::assertStringContainsString('want_play', $js);
        self::assertStringContainsString('indexedDB', $js);
        self::assertStringContainsString('resolveMediaSrc', $js);
        self::assertStringContainsString('stopPlayback', $js);
        self::assertStringContainsString('data-store-music-close', $widget);
        self::assertStringNotContainsString('data-store-music-dismiss', $widget);
        // Streaming playback intentionally uses preload=auto (HTTP Range progressive).
        self::assertStringContainsString("audio.preload = 'auto'", $js);

        $parsed = StoreMusicSettings::parsePlaylistJson(
            '[{"url":"/media/a.m4a","title":"甲","intro":"简介甲"},{"url":"store-music/b.mp3","title":"","intro":""}]'
        );
        self::assertCount(2, $parsed);
        self::assertSame('/media/a.m4a', $parsed[0]['url']);
        self::assertSame('甲', $parsed[0]['title']);
        self::assertSame(['default' => '简介甲'], $parsed[0]['intro']);
        self::assertSame('/media/store-music/b.mp3', $parsed[1]['url']);
        self::assertSame('b', $parsed[1]['title']);
        self::assertSame([], $parsed[1]['intro']);
    }

    public function testIntroLocaleMapNormalizeAndResolve(): void
    {
        $fromString = StoreMusicSettings::normalizeIntroMap('进店氛围');
        self::assertSame(['default' => '进店氛围'], $fromString);

        $map = StoreMusicSettings::normalizeIntroMap([
            'zh_Hans_CN' => '中文简介',
            'en_US' => 'English intro',
            '' => 'skip',
            'fr_FR' => '  ',
        ]);
        self::assertSame([
            'zh_Hans_CN' => '中文简介',
            'en_US' => 'English intro',
        ], $map);

        self::assertSame('English intro', StoreMusicSettings::resolveIntroForLocale($map, 'en_US'));
        self::assertSame('English intro', StoreMusicSettings::resolveIntroForLocale($map, 'en_GB'));
        self::assertSame('中文简介', StoreMusicSettings::resolveIntroForLocale($map, 'zh_Hans_CN'));
        self::assertSame('中文简介', StoreMusicSettings::resolveIntroForLocale($map, 'missing'));

        $encoded = StoreMusicSettings::encodePlaylist([
            ['url' => '/media/a.m4a', 'title' => '甲', 'intro' => $map],
        ]);
        $again = StoreMusicSettings::parsePlaylistJson($encoded);
        self::assertSame($map, $again[0]['intro']);
    }

    public function testTracksFromWidgetConfigNormalizesUrlsAndIntros(): void
    {
        $tracks = StoreMusicSettings::tracksFromWidgetConfig([
            ['url' => 'store-music/a.mp3', 'title' => '甲', 'intro' => '简介甲'],
            ['url' => ['type' => 'file-image', 'path' => '/media/store-music/b.mp3'], 'title' => '', 'intro' => ''],
            ['url' => '', 'title' => 'skip'],
        ]);
        self::assertCount(2, $tracks);
        self::assertSame('/media/store-music/a.mp3', $tracks[0]['url']);
        self::assertSame('甲', $tracks[0]['title']);
        self::assertSame('简介甲', $tracks[0]['intro']);
        self::assertSame('/media/store-music/b.mp3', $tracks[1]['url']);
        self::assertSame('b', $tracks[1]['title']);
    }

    public function testDoesNotPatchSystemConfigCoreTemplate(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        // StoreMusic → Weline → code → app → repo
        $repoRoot = dirname($moduleRoot, 4);
        $indexPath = $repoRoot . '/app/code/Weline/SystemConfig/view/templates/backend/config/index.phtml';
        self::assertFileExists($indexPath);
        $index = (string)file_get_contents($indexPath);
        // StoreMusic must not require core picker size hardcode changes; assert StoreMusic owns picker.
        $storeMusicAdmin = (string)file_get_contents(
            $moduleRoot . '/view/templates/Backend/Config/index.phtml'
        );
        self::assertStringContainsString('WelineMedia', $storeMusicAdmin);
        self::assertStringContainsString("'size' => 2097152", $index);
    }
}
