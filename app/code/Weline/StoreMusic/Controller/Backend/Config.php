<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\StoreMusic\Service\StoreMusicSettings;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * 进店音乐配置页：embed 开关字段 + 本页 MediaManager 选音频。
 */
#[Acl('Weline_StoreMusic::config', '进店音乐', 'settings', '进店音乐与氛围播放配置', 'Weline_Backend::marketing_group')]
class Config extends BackendController
{
    #[Acl('Weline_StoreMusic::config_index', '查看进店音乐配置', 'settings', '查看进店音乐曲目与播放配置')]
    public function index(): string
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $resolved = $targetScopeService->resolveFromInput([
            'target_scope' => (string)$this->request->getGet('target_scope', ''),
            'scope' => (string)$this->request->getGet('scope', ''),
            'website_code' => (string)$this->request->getGet('website_code', ''),
            'store_code' => (string)$this->request->getGet('store_code', ''),
            'channel_code' => (string)$this->request->getGet('channel_code', ''),
        ], false);

        $storageScope = (string)($resolved['storage_scope'] ?? 'default.default.default');
        $hasExplicit = trim((string)$this->request->getGet('target_scope', '')) !== ''
            || trim((string)$this->request->getGet('scope', '')) !== ''
            || array_key_exists('website_code', $this->request->getGet());

        if (!$hasExplicit) {
            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl(
                '*/backend/config',
                [
                    'target_scope' => $storageScope,
                    'website_code' => (string)($resolved['website_code'] ?? ''),
                    'store_code' => (string)($resolved['store_code'] ?? ''),
                    'channel_code' => (string)($resolved['channel_code'] ?? ''),
                ]
            ));
        }

        /** @var StoreMusicSettings $settings */
        $settings = ObjectManager::getInstance(StoreMusicSettings::class);
        // Backend editor must read the selected SystemConfig scope (not request/default only).
        $tracks = $settings->tracks($storageScope);
        $trackUrl = (string)($tracks[0]['url'] ?? '');
        $introEditLocale = StoreMusicSettings::currentRequestLocale();
        if ($introEditLocale === '' || $introEditLocale === 'default') {
            $introEditLocale = 'zh_Hans_CN';
        }

        $this->assign('page_title', __('进店音乐'));
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($resolved['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($resolved['store_code'] ?? ''));
        $this->assign('scope_channel_code', (string)($resolved['channel_code'] ?? ''));
        $this->assign('tracks', $tracks);
        $this->assign('intro_edit_locale', $introEditLocale);
        $this->assign('playlist_value', StoreMusicSettings::encodePlaylist($tracks));
        $this->assign('media_value', StoreMusicSettings::mediaCsvFromTracks($tracks));
        $this->assign('playlist_key', StoreMusicSettings::KEY_PLAYLIST);
        $this->assign('track_key', StoreMusicSettings::KEY_TRACK);
        $this->assign('track_value', $trackUrl);
        $this->assign('config_module', StoreMusicSettings::MODULE);
        $this->assign('config_area', StoreMusicSettings::AREA);

        return $this->fetch();
    }
}
