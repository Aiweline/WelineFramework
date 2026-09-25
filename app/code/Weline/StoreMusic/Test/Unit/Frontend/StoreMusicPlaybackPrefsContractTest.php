<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Contract: resume/stop/single-tab + simple portrait player markers stay wired.
 */
class StoreMusicPlaybackPrefsContractTest extends TestCase
{
    private function moduleFile(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
    }

    public function testJsContainsResumeDismissLeaderAndSoftWave(): void
    {
        $js = (string)\file_get_contents($this->moduleFile('view/statics/js/store-music.js'));
        self::assertStringContainsString("'progress'", $js);
        self::assertStringContainsString("'dismissed'", $js);
        self::assertStringContainsString('normalizeTrackUrl', $js);
        self::assertStringContainsString('hasResumeSnap', $js);
        self::assertStringContainsString('readLastAudibleAt', $js);
        self::assertStringContainsString('last_audible_at', $js);
        self::assertStringContainsString('isAudibleClockStale', $js);
        self::assertStringContainsString('stickyResume', $js);
        self::assertStringContainsString('skipDelay', $js);
        self::assertStringContainsString('armNavResumeLatch', $js);
        self::assertStringContainsString('consumeNavResumeLatch', $js);
        self::assertStringContainsString('_ignorePeerOnce', $js);
        self::assertStringContainsString('persistVolumePref', $js);
        self::assertStringContainsString('volume_user', $js);
        self::assertStringContainsString('vol_remember_v47', $js);
        self::assertStringContainsString('never re-clamp on every boot', $js);
        self::assertStringContainsString('Clears to unset', $js);
        self::assertStringContainsString('Legacy want_play=0', $js);
        self::assertStringContainsString('cached resume', $js);
        self::assertStringContainsString('cachedResume', $js);
        self::assertStringContainsString("'want_play'", $js);
        self::assertStringContainsString('playIntent', $js);
        self::assertStringContainsString('tryPlay({ force: false })', $js);
        self::assertStringContainsString('playCurrentTrackNow', $js);
        self::assertStringContainsString('mountAudioElement', $js);
        self::assertStringContainsString('markUserOperating', $js);
        self::assertStringContainsString('isUserOperating', $js);
        self::assertStringContainsString('broadcastLeaderState', $js);
        self::assertStringContainsString('applyRemoteSelection', $js);
        self::assertStringContainsString('indexedDB', $js);
        self::assertStringContainsString('resolveMediaSrc', $js);
        self::assertStringContainsString('BroadcastChannel', $js);
        self::assertStringContainsString('yieldToOtherTab', $js);
        self::assertStringContainsString("waveMode = 'soft'", $js);
        self::assertStringContainsString('is-avatar-spin', $js);
        self::assertStringContainsString('readPlaybackLevel', $js);
        self::assertStringContainsString('setAvatarLevel', $js);
        self::assertStringContainsString('avatar_spin', $js);
        self::assertStringContainsString('visibility hidden ≠ leave page', $js);
        self::assertStringContainsString('hardSilenceMedia', $js);
        self::assertStringContainsString("addEventListener('pagehide'", $js);
        self::assertStringNotContainsString("addEventListener('unload'", $js);
        self::assertStringContainsString("addEventListener('beforeunload'", $js);
        self::assertStringContainsString("addEventListener('freeze'", $js);
        self::assertStringContainsString('canRefuseYield', $js);
        self::assertStringContainsString('silenceForOtherTab', $js);
        self::assertStringContainsString('force_stop', $js);
        self::assertStringContainsString('isRemoteNewer', $js);
        self::assertStringContainsString('broadcastForceStop', $js);
        self::assertStringContainsString('maybeResumeAfterPeerRelease', $js);
        self::assertStringContainsString('bindAudioElementEvents', $js);
        self::assertStringContainsString('remote >= this.localOperatorAt()', $js);
        self::assertStringContainsString('canAudiblyStart', $js);
        self::assertStringContainsString('killAllPageAudio', $js);
        self::assertStringContainsString('_heardWhileVisible', $js);
        self::assertStringContainsString('_docAlive', $js);
        self::assertStringContainsString('hasActivePeerLeader', $js);
        self::assertStringContainsString('PEER_CENSUS_FRESH_MS', $js);
        self::assertStringContainsString('isFreshPeerCensusRow', $js);
        self::assertStringContainsString('bumpStoreMusicEpoch', $js);
        self::assertStringContainsString('silenceAllStoreMusicAudio', $js);
        self::assertStringContainsString('Holder still heartbeating in census', $js);
        self::assertStringContainsString('even background', $js);
        self::assertStringContainsString('Explicit ❚❚ / dismiss', $js);
        self::assertStringContainsString('shouldShowOtherTabStatus', $js);
        self::assertStringContainsString('heartbeat: heartbeat', $js);
        self::assertStringContainsString('asHeartbeat', $js);
        self::assertStringContainsString('Heartbeat must NEVER use Date.now()', $js);
        self::assertStringContainsString('isLatestOperator', $js);
        self::assertStringContainsString('markUserGesture', $js);
        self::assertStringContainsString('_lastGestureAt', $js);
        self::assertStringContainsString('owningHere', $js);
        self::assertStringContainsString('owningPlay', $js);
        self::assertStringContainsString('reconcileAudibleSelection', $js);
        self::assertStringContainsString('audioMatchesSelection', $js);
        self::assertStringContainsString('selectionDiffersFromMessage', $js);
        self::assertStringContainsString("broadcastLeaderState('playing', false)", $js);
        self::assertStringContainsString("broadcastLeaderState('playing', true)", $js);
        self::assertStringContainsString('bindConsentAutoplayRetry', $js);
        self::assertStringContainsString('armPageGestureUnlock', $js);
        self::assertStringContainsString('_autoplayPending', $js);
        self::assertStringContainsString('data-consent-accept', $js);
        self::assertStringContainsString('[data-store-music-i18n-tap-to-play]', $js);
        self::assertStringContainsString('clearLegacyStopPoison', $js);
        self::assertStringContainsString('user_stopped', $js);
        self::assertStringContainsString('refreshIntroExpandState', $js);
        self::assertStringContainsString('setIntroExpanded', $js);
        self::assertStringContainsString('data-store-music-intro-toggle', $js);
        self::assertStringContainsString('never write want_play=0 here', $js);
        self::assertStringContainsString('poisoning want_play/user_stopped', $js);
        self::assertStringContainsString('Do NOT broadcastForceStop here', $js);
        self::assertStringContainsString('_tearingDown', $js);
        self::assertStringContainsString('unmuteAudible', $js);
        self::assertStringContainsString('_awaitingUnmute', $js);
        self::assertStringContainsString('audio.muted = true', $js);
        self::assertStringContainsString('[data-store-music-i18n-tap-to-unmute]', $js);
        self::assertStringContainsString('storeMusicPlaybackAllowed', $js);
        self::assertStringContainsString('_scheduleInFlight', $js);
        self::assertStringContainsString('Do NOT gate on marketing Cookie', $js);
        self::assertStringContainsString('armPeerLeaderRetry', $js);
        self::assertStringContainsString('Holder still heartbeating in census', $js);
        self::assertStringContainsString('must not overwrite the snap with currentTime=0', $js);
        self::assertStringContainsString('Metadata not ready yet', $js);
        self::assertStringContainsString('_lastGoodProgressTime', $js);
        self::assertStringContainsString('keep the MediaElement shell', $js);
        self::assertStringContainsString('parkForBfcache', $js);
        self::assertStringContainsString('bfcache restore', $js);
        self::assertStringContainsString('beginNavResumeBypass', $js);
        self::assertStringContainsString('navResume: stickyNow || navResume', $js);
        self::assertStringContainsString('bootGate = stickyResume ? Promise.resolve() : whenWindowLoaded()', $js);
        self::assertStringContainsString('Do NOT wait for window `load`', $js);
        self::assertStringContainsString('preferCache: stickyNow', $js);
        self::assertStringContainsString('preferCache (F5 sticky resume)', $js);
        self::assertStringContainsString('flash「点击开启音乐」', $js);
        self::assertStringContainsString('navType === \'reload\'', $js);
        self::assertStringContainsString('20260917-61speccenter2', $js);
        self::assertStringContainsString('syncSpectrumRadius', $js);
        self::assertStringContainsString('armStickyPlayRetry', $js);
        self::assertStringContainsString('AbortError', $js);
        self::assertStringContainsString('_stickyMutedResume', $js);
        self::assertStringContainsString('expireStaleWantPlay({ silent:', $js);
        self::assertStringContainsString('load can fire between the readyState check', $js);
        self::assertStringContainsString('Hard ceiling: never leave', $js);
        self::assertStringContainsString('isEmbeddedBrowserHost', $js);
        self::assertStringContainsString('host-detached', $js);
        self::assertStringContainsString('0×0 always means detached', $js);
        self::assertStringContainsString('poisoning want_play/user_stopped', $js);
        self::assertStringContainsString('Do NOT broadcastForceStop here', $js);
        self::assertStringContainsString('_tearingDown', $js);
        // Close / volume must not steal leadership (multipage browse ≠ takeover).
        self::assertStringContainsString('Close panel only — must NOT steal leadership', $js);
        self::assertStringContainsString('Never acquireLeaderLock here', $js);
        self::assertStringContainsString('origin_tabs.', $js);
        self::assertStringContainsString('domain_empty', $js);
        self::assertStringContainsString('killRivalAudioElements', $js);
        self::assertStringContainsString('enforceSoleAudibleSource', $js);
        self::assertStringContainsString('getSharedAudioSingleton', $js);
        self::assertStringContainsString('nextPlayGeneration', $js);
        self::assertStringContainsString('destroySharedAudioGraph', $js);
        self::assertStringContainsString('purgeAllStoreMusicAudio', $js);
        self::assertStringContainsString('killAll', $js);
        self::assertStringContainsString('SCRIPT_GEN', $js);
        self::assertStringContainsString('__WelineStoreMusicLive', $js);
        self::assertStringContainsString('startSoleSourceWatch', $js);
        self::assertStringContainsString('isStoreMusicMedia', $js);
        self::assertStringContainsString('Only touches StoreMusic-owned media', $js);
        self::assertStringContainsString('canSoftResumeNow', $js);
        self::assertStringContainsString('expireStaleWantPlay', $js);
        self::assertStringContainsString('SOFT_RESUME_IDLE_MS', $js);
        self::assertStringContainsString('Soft start must still force_stop peers', $js);
        self::assertStringContainsString('stallGrace', $js);
        self::assertStringContainsString('another tab holds the leader lock', $js);
        self::assertStringContainsString('warmIdbAudio', $js);
        self::assertStringContainsString('Do NOT await fetch().blob()', $js);
        self::assertStringContainsString('progressive HTTP streaming', $js);
        self::assertStringContainsString('armDeferredAudioCacheWarm', $js);
        self::assertStringContainsString('data-store-music-stream', $js);
        self::assertStringContainsString('discardAudioElement', $js);
        self::assertStringContainsString('__welineMediaGraphBound', $js);
        self::assertStringContainsString('tearDownForLeave', $js);
        self::assertStringContainsString('startOrphanWatch', $js);
        self::assertStringContainsString('startLeaveHammer', $js);
        self::assertStringContainsString('clearMediaSession', $js);
        self::assertStringContainsString('clearStaleLeaderForSoloResume', $js);
        self::assertStringContainsString('countVisiblePeerTabs', $js);
        self::assertStringContainsString('Try unmuted first', $js);
        self::assertStringContainsString('finishNaturalPlayback', $js);
        self::assertStringContainsString('secretly restart playback', $js);
        self::assertStringContainsString('Soft autoplay/resume must honor natural end', $js);
        self::assertStringContainsString('Natural end / ❚❚ / dismiss must not be revived', $js);
        self::assertStringContainsString('continuePlaylistPlayback', $js);
        self::assertStringContainsString('migrateNaturalEndStopPoison', $js);
        self::assertStringContainsString('_naturalEnded', $js);
        self::assertStringContainsString('Do NOT set user_stopped', $js);
        self::assertStringContainsString("addEventListener('pagehide', onLeave, true)", $js);
        self::assertStringContainsString("'operator'", $js);
        self::assertStringNotContainsString("crossOrigin = 'anonymous'", $js);
        // Accidental play must not force-steal leadership (residual dual-track bug).
        self::assertStringNotContainsString('if (!owner.claimLeadership(true))', $js);
        self::assertStringContainsString('renderPlaylist', $js);
        self::assertStringContainsString('[data-store-music-i18n-playlist-count]', $js);
        self::assertStringContainsString("split('{n}')", $js);
        self::assertStringNotContainsString("'共 ' + this.tracks.length + ' 首'", $js);
        self::assertStringContainsString('selectTrack', $js);
        self::assertStringContainsString('toggleBtns', $js);
        self::assertStringContainsString('syncVolumePct', $js);
        self::assertStringNotContainsString('MusicBoxStage', $js);
    }

    public function testTemplateHasDesignSkinAndCloseControl(): void
    {
        $phtml = (string)\file_get_contents(
            $this->moduleFile('view/templates/frontend/widgets/store-music.phtml')
        );
        $hook = (string)\file_get_contents(
            $this->moduleFile('view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml')
        );
        self::assertStringContainsString('templates/frontend/widgets/store-music.phtml', $hook);
        self::assertStringContainsString('data-store-music-close', $phtml);
        self::assertStringNotContainsString('data-store-music-dismiss', $phtml);
        self::assertStringContainsString('w-store-music--simple', $phtml);
        self::assertStringContainsString('guofeng-cameo.png', $phtml);
        self::assertStringContainsString('data-store-music-playlist', $phtml);
        self::assertStringContainsString('data-store-music-i18n-playlist-count', $phtml);
        self::assertStringContainsString('共 {n} 首', $phtml);
        self::assertStringContainsString('data-store-music-intro', $phtml);
        self::assertStringContainsString('data-store-music-intro-wrap', $phtml);
        self::assertStringContainsString('data-store-music-intro-toggle', $phtml);
        self::assertStringContainsString("\$t('展开简介')", $phtml);
        self::assertStringContainsString("\$t('收起简介')", $phtml);
        self::assertStringContainsString("\$t('播放列表')", $phtml);
        self::assertStringNotContainsString('<lang>', $phtml);
        self::assertStringNotContainsString('@lang(', $phtml);
        self::assertStringContainsString('data-store-music-volume-pct', $phtml);
        self::assertStringContainsString('data-store-music-wave', $phtml);
        self::assertStringNotContainsString('music-box-closed.png', $phtml);
        self::assertStringNotContainsString('data-store-music-stage', $phtml);
        self::assertStringContainsString('data-store-music-spectrum', $phtml);
        self::assertStringContainsString('w-store-music__spectrum', $phtml);
        // The template is included by a raw Hook as well as the normal view
        // renderer; static URLs must therefore be resolved before HTML output.
        // Hook bypasses @widget.source — must emit <link data-store-music-css>
        // or the float stays position:static while audio still plays.
        // Critical inline style keeps position:fixed even when the stylesheet 403s.
        self::assertStringContainsString('fetchTagSource', $phtml);
        self::assertStringContainsString('DataInterface::dir_type_STATICS', $phtml);
        self::assertStringContainsString('data-store-music-css', $phtml);
        self::assertStringContainsString('data-store-music-host-css', $phtml);
        self::assertStringContainsString('data-store-music-critical', $phtml);
        self::assertStringContainsString('position:fixed!important', $phtml);
        self::assertStringContainsString('css/widgets/widget-store-music.css', $phtml);
        self::assertStringContainsString('css/store-music.css', $phtml);
        self::assertStringContainsString('<link rel="stylesheet"', $phtml);
        self::assertStringNotContainsString('getStaticUrl', $phtml);
        self::assertStringNotContainsString('href="@static(', $phtml);
        self::assertStringNotContainsString('src="@static(', $phtml);
        self::assertFileExists($this->moduleFile('view/statics/images/guofeng-cameo.png'));

        $js = (string)\file_get_contents($this->moduleFile('view/statics/js/store-music.js'));
        self::assertStringContainsString('updateSpectrumBars', $js);
        self::assertStringContainsString('SPECTRUM_BAR_COUNT', $js);
        self::assertStringContainsString('ensureSpectrumBars', $js);
        self::assertStringContainsString('document.body.appendChild(this.waveCanvas)', $js);
        self::assertStringContainsString('20260917-61speccenter2', $js);
        self::assertStringContainsString('syncSpectrumRadius', $js);

        $css = (string)\file_get_contents($this->moduleFile('view/statics/css/store-music.css'));
        self::assertStringContainsString('w-store-music-spin', $css);
        self::assertStringContainsString('w-store-music__spectrum-bar', $css);
        self::assertStringContainsString('.w-store-music.is-playing .w-store-music__spectrum', $css);
        self::assertStringContainsString('.w-store-music.is-playing.is-avatar-spin .w-store-music__face', $css);
        self::assertStringContainsString('--w-store-music-level', $css);
        self::assertStringContainsString('--w-store-music-z-wave: 2147482900', $css);
        self::assertStringNotContainsString('--w-store-music-z-wave: 8;', $css);
        self::assertStringNotContainsString('w-store-music-edge-pulse', $css);
        self::assertStringNotContainsString('w-store-music__ripple', $css);
    }

    public function testCrossTabPlaybackKeepsOwnershipAndUsesCompactRemoteSpectrum(): void
    {
        $js = (string)\file_get_contents($this->moduleFile('view/statics/js/store-music.js'));
        $css = (string)\file_get_contents($this->moduleFile('view/statics/css/store-music.css'));

        self::assertStringContainsString('StoreMusic.prototype.isAudibleOwner', $js);
        self::assertStringContainsString('if (!this.isAudibleOwner() && !decoding)', $js);
        self::assertStringContainsString('self.releaseLeadership();', $js);
        self::assertStringContainsString('remotePlaying', $js);
        self::assertStringContainsString('is-remote-playing', $js);
        self::assertStringContainsString('visible: row.visible === true', $js);
        self::assertStringContainsString('dying: row.dying === true', $js);
        self::assertStringContainsString('SPECTRUM_BAR_COUNT = 48', $js);
        self::assertStringContainsString('* 2.15rem', $css);
        self::assertStringContainsString('--w-spectrum-r', $css);
        self::assertStringContainsString('bottom: 50%', $css);
        self::assertStringContainsString('transform-origin: center bottom', $css);
        self::assertStringNotContainsString('translateY(-3.05rem)', $css);
        self::assertStringNotContainsString('* 0.42rem', $css);
    }
}
