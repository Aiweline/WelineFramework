window.WelineWidgetAssets.register('storemusic-store-music-0', function (widgetScript) {
/* Immediate cold purge: stop orphan StoreMusic decoders before deferred module boots. */
(function () {
    try {
        var reg = window.__WelineStoreMusicAudioRegistry;
        if (reg && reg.length) {
            for (var i = 0; i < reg.length; i++) {
                var a = reg[i];
                if (!a) continue;
                try { a.pause(); } catch (e0) {}
                try { a.muted = true; a.volume = 0; a.removeAttribute('src'); a.src = ''; a.load(); } catch (e1) {}
            }
        }
        window.__WelineStoreMusicAudioRegistry = [];
        var shared = window.__WelineStoreMusicSharedAudio;
        if (shared) {
            try { shared.pause(); shared.muted = true; shared.src = ''; shared.load(); } catch (e2) {}
        }
        window.__WelineStoreMusicSharedAudio = null;
        if (window.__WelineStoreMusicSharedCtx && window.__WelineStoreMusicSharedCtx.close) {
            try { window.__WelineStoreMusicSharedCtx.close(); } catch (e3) {}
        }
        window.__WelineStoreMusicSharedCtx = null;
        window.__WelineStoreMusicSharedAnalyser = null;
        window.__WelineStoreMusicSharedSource = null;
        window.__WelineStoreMusicLive = null;
        var nodes = document.querySelectorAll('audio,video');
        for (var j = 0; j < nodes.length; j++) {
            var el = nodes[j];
            var src = '';
            try {
                src = String(el.currentSrc || el.src || '') + String(el.getAttribute('data-store-music-src') || '');
            } catch (e4) { src = ''; }
            if (src.indexOf('store-music') !== -1 || el.getAttribute('data-store-music-audio') === '1') {
                try { el.pause(); el.muted = true; el.removeAttribute('src'); el.src = ''; el.load(); } catch (e5) {}
            }
        }
        if (navigator.mediaSession) {
            try { navigator.mediaSession.playbackState = 'none'; navigator.mediaSession.metadata = null; } catch (e6) {}
        }
    } catch (e) {}
})();
});
