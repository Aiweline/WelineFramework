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
        self::assertStringContainsString("'operator'", $js);
        self::assertStringNotContainsString("crossOrigin = 'anonymous'", $js);
        // Accidental play must not force-steal leadership (residual dual-track bug).
        self::assertStringNotContainsString('if (!owner.claimLeadership(true))', $js);
        self::assertStringContainsString('renderPlaylist', $js);
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
        self::assertStringContainsString('data-store-music-intro', $phtml);
        self::assertStringContainsString('data-store-music-volume-pct', $phtml);
        self::assertStringContainsString('data-store-music-wave', $phtml);
        self::assertStringNotContainsString('music-box-closed.png', $phtml);
        self::assertStringNotContainsString('data-store-music-stage', $phtml);
        self::assertFileExists($this->moduleFile('view/statics/images/guofeng-cameo.png'));
    }
}
