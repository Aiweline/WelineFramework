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
        self::assertStringContainsString('visibility hidden ≠ leave page', $js);
        self::assertStringContainsString('hardSilenceMedia', $js);
        self::assertStringContainsString("addEventListener('pagehide'", $js);
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
        self::assertStringContainsString('dropped from origin_tabs', $js);
        self::assertStringContainsString('must not overwrite the snap with currentTime=0', $js);
        self::assertStringContainsString('Metadata not ready yet', $js);
        self::assertStringContainsString('_lastGoodProgressTime', $js);
        self::assertStringContainsString('keep the MediaElement', $js);
        self::assertStringContainsString('bfcache restore', $js);
        self::assertStringContainsString('poisoning want_play/user_stopped', $js);
        self::assertStringContainsString('Do NOT broadcastForceStop here', $js);
        self::assertStringContainsString('_tearingDown', $js);
        // Close / volume must not steal leadership (multipage browse ≠ takeover).
        self::assertStringContainsString('Close panel only — must NOT steal leadership', $js);
        self::assertStringContainsString('Never acquireLeaderLock here', $js);
        self::assertStringContainsString('origin_tabs.', $js);
        self::assertStringContainsString('domain_empty', $js);
        self::assertStringContainsString('killRivalAudioElements', $js);
        self::assertStringContainsString('discardAudioElement', $js);
        self::assertStringContainsString('__welineMediaGraphBound', $js);
        self::assertStringContainsString('tearDownForLeave', $js);
        self::assertStringContainsString('startOrphanWatch', $js);
        self::assertStringContainsString('startLeaveHammer', $js);
        self::assertStringContainsString('clearMediaSession', $js);
        self::assertStringContainsString('clearStaleLeaderForSoloResume', $js);
        self::assertStringContainsString('countVisiblePeerTabs', $js);
        self::assertStringContainsString('Try unmuted first', $js);
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
            $this->moduleFile('view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml')
        );
        self::assertStringContainsString('data-store-music-close', $phtml);
        self::assertStringNotContainsString('data-store-music-dismiss', $phtml);
        self::assertStringContainsString('w-store-music--simple', $phtml);
        self::assertStringContainsString('guofeng-cameo.png', $phtml);
        self::assertStringContainsString('data-store-music-playlist', $phtml);
        self::assertStringContainsString('data-store-music-i18n-playlist-count', $phtml);
        self::assertStringContainsString('共 {n} 首', $phtml);
        self::assertStringContainsString('data-store-music-intro', $phtml);
        self::assertStringContainsString('data-store-music-volume-pct', $phtml);
        self::assertStringContainsString('data-store-music-wave', $phtml);
        self::assertStringNotContainsString('music-box-closed.png', $phtml);
        self::assertStringNotContainsString('data-store-music-stage', $phtml);
        self::assertFileExists($this->moduleFile('view/statics/images/guofeng-cameo.png'));
    }
}
