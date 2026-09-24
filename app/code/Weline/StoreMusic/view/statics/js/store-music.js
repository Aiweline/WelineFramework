/**
 * StoreMusic: delayed entrance BGM + full-page waveform + resume/dismiss + single-tab leader.
 * Soft Consent via Weline.Api.resource('consent'); never blocks UI shell.
 */
(function (global) {
    'use strict';

    var ROOT_SEL = '[data-store-music]';
    var PAGE_HOST = String((global.location && global.location.host) || '');
    var CHANNEL_NAME = 'weline.storeMusic.audio.' + PAGE_HOST;
    var TAB_ID = 't' + String(Date.now()) + '-' + String(Math.random()).slice(2, 8);
    var ORIGIN_TAB_TTL_MS = 15000;
    // Peer-leader / solo-resume: census rows older than this are dying-refresh leftovers
    // (origin watch heartbeats every 1s; real background BGM stays fresh).
    var PEER_CENSUS_FRESH_MS = 3500;
    // After this long without audible progress, soft resume (focus/peer) must not surprise-play.
    var SOFT_RESUME_IDLE_MS = 90000;
    // Polar spectrum bars around the avatar (frequency bins → height, not concentric rings).
    var SPECTRUM_BAR_COUNT = 48;

    function currentStoreMusicEpoch() {
        return Number(global.__WelineStoreMusicEpoch) || 0;
    }

    /** Bump on every boot so a dying document's leaveHammer cannot purge the successor. */
    function bumpStoreMusicEpoch() {
        global.__WelineStoreMusicEpoch = currentStoreMusicEpoch() + 1;
        return global.__WelineStoreMusicEpoch;
    }

    function isFreshPeerCensusRow(row, now) {
        var at = row && Number(row.at) ? Number(row.at) : 0;
        return !!(at && ((now || Date.now()) - at) < PEER_CENSUS_FRESH_MS);
    }

    function audioRegistry() {
        if (!global.__WelineStoreMusicAudioRegistry) {
            global.__WelineStoreMusicAudioRegistry = [];
        }
        return global.__WelineStoreMusicAudioRegistry;
    }

    function nextPlayGeneration() {
        global.__WelineStoreMusicPlayGen = (Number(global.__WelineStoreMusicPlayGen) || 0) + 1;
        return global.__WelineStoreMusicPlayGen;
    }

    function currentPlayGeneration() {
        return Number(global.__WelineStoreMusicPlayGen) || 0;
    }

    /**
     * Process-wide MediaElement singleton. Track switches MUST reuse this element
     * (only change src) — creating new Audio() each time left orphan decoders audible.
     */
    function getSharedAudioSingleton() {
        var existing = global.__WelineStoreMusicSharedAudio;
        if (existing && existing.__welineStoreMusicDestroyed) {
            existing = null;
            global.__WelineStoreMusicSharedAudio = null;
        }
        if (!existing) {
            existing = new Audio();
            existing.preload = 'auto';
            try {
                existing.setAttribute('data-store-music-audio', '1');
            } catch (eMark) {
                // ignore
            }
            existing.__welineStoreMusicSingleton = true;
            global.__WelineStoreMusicSharedAudio = existing;
        }
        registerStoreAudio(existing);
        return existing;
    }

    /** Close shared WebAudio graph and brick the old MediaElement (only on hard leave). */
    function destroySharedAudioGraph() {
        var ctx = global.__WelineStoreMusicSharedCtx;
        if (ctx && typeof ctx.close === 'function') {
            try {
                ctx.close();
            } catch (eClose) {
                // ignore
            }
        }
        global.__WelineStoreMusicSharedCtx = null;
        global.__WelineStoreMusicSharedAnalyser = null;
        global.__WelineStoreMusicSharedSource = null;
        var audio = global.__WelineStoreMusicSharedAudio;
        if (audio) {
            silenceMediaElement(audio);
            try {
                audio.__welineMediaGraphBound = false;
                audio.__welineStoreMusicDestroyed = true;
            } catch (eFlag) {
                // ignore
            }
            global.__WelineStoreMusicSharedAudio = null;
            global.__WelineStoreMusicAudioRegistry = audioRegistry().filter(function (a) {
                return a && a !== audio && !a.__welineStoreMusicDestroyed;
            });
        }
    }

    function registerStoreAudio(audio) {
        if (!audio || audio.__welineStoreMusicDestroyed) {
            return;
        }
        try {
            audio.setAttribute('data-store-music-audio', '1');
        } catch (eMark) {
            // ignore
        }
        var list = audioRegistry();
        if (list.indexOf(audio) === -1) {
            list.push(audio);
        }
    }

    function isStoreMusicMedia(el) {
        if (!el) {
            return false;
        }
        if (el.__welineStoreMusicOwner || el.__welineStoreMusicBound) {
            return true;
        }
        try {
            if (el.getAttribute && (el.getAttribute('data-store-music-src') != null
                || el.getAttribute('data-store-music-audio') === '1')) {
                return true;
            }
        } catch (e) {
            // ignore
        }
        return false;
    }

    /**
     * Silence every MediaElement in this browsing context except optional keep.
     * Prevents 高山流水+孤城 (or zombie Audio) dual-play when switching tracks.
     * Only touches StoreMusic-owned media — never product / CMS <video>.
     */
    function silenceMediaElement(el) {
        if (!el) {
            return;
        }
        try {
            el.muted = true;
        } catch (eMute) {
            // ignore
        }
        try {
            el.volume = 0;
        } catch (eVol) {
            // ignore
        }
        try {
            el.pause();
        } catch (e) {
            // ignore
        }
        try {
            el.removeAttribute('src');
            el.removeAttribute('data-store-music-src');
            el.src = '';
            el.load();
        } catch (e2) {
            // ignore
        }
    }

    function killRivalAudioElements(keep) {
        var list = audioRegistry().slice();
        for (var i = 0; i < list.length; i++) {
            if (list[i] && list[i] !== keep) {
                silenceMediaElement(list[i]);
            }
        }
        try {
            var nodes = document.querySelectorAll('audio[data-store-music-audio], audio[data-store-music-src], video[data-store-music-audio]');
            for (var j = 0; j < nodes.length; j++) {
                if (nodes[j] !== keep) {
                    silenceMediaElement(nodes[j]);
                }
            }
            // Also catch owner-tagged nodes missing attributes (legacy mounts).
            var allAv = document.querySelectorAll('audio, video');
            for (var k = 0; k < allAv.length; k++) {
                if (allAv[k] !== keep && isStoreMusicMedia(allAv[k])) {
                    silenceMediaElement(allAv[k]);
                }
            }
        } catch (e3) {
            // ignore
        }
        var shared = global.__WelineStoreMusicSharedAudio;
        if (shared && shared !== keep) {
            silenceMediaElement(shared);
            if (!keep) {
                global.__WelineStoreMusicSharedAudio = null;
            }
        }
        // Drop dead refs from registry.
        global.__WelineStoreMusicAudioRegistry = audioRegistry().filter(function (a) {
            return a && a === keep;
        });
        if (keep) {
            registerStoreAudio(keep);
            global.__WelineStoreMusicSharedAudio = keep;
        }
    }

    /**
     * Sole audible StoreMusic source in THIS document. Call on every play start.
     */
    function enforceSoleAudibleSource(keep) {
        if (!keep) {
            killRivalAudioElements(null);
            return;
        }
        killRivalAudioElements(keep);
        try {
            if (!keep.paused && keep !== global.__WelineStoreMusicSharedAudio) {
                global.__WelineStoreMusicSharedAudio = keep;
            }
        } catch (e) {
            global.__WelineStoreMusicSharedAudio = keep;
        }
    }

    /**
     * Silence StoreMusic MediaElements + shared graph without clearing Live ownership.
     * Dying leaveHammer / orphan ticks must use this so a refresh successor keeps its Live.
     */
    function silenceAllStoreMusicAudio() {
        nextPlayGeneration();
        try {
            if (global.navigator && global.navigator.mediaSession) {
                global.navigator.mediaSession.playbackState = 'none';
                try {
                    global.navigator.mediaSession.metadata = null;
                } catch (eMeta) {
                    // ignore
                }
            }
        } catch (eMs) {
            // ignore
        }
        destroySharedAudioGraph();
        killRivalAudioElements(null);
        try {
            var nodes = document.querySelectorAll('audio, video');
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                var src = '';
                try {
                    src = String(el.currentSrc || el.src || '')
                        + String(el.getAttribute('data-store-music-src') || '')
                        + String(el.getAttribute('src') || '');
                } catch (eSrc) {
                    src = '';
                }
                if (
                    isStoreMusicMedia(el)
                    || src.indexOf('store-music') !== -1
                    || (el.getAttribute && el.getAttribute('data-store-music-audio') === '1')
                ) {
                    silenceMediaElement(el);
                }
            }
        } catch (eDom) {
            // ignore
        }
        global.__WelineStoreMusicAudioRegistry = [];
        global.__WelineStoreMusicSharedAudio = null;
        global.__WelineStoreMusicSharedCtx = null;
        global.__WelineStoreMusicSharedAnalyser = null;
        global.__WelineStoreMusicSharedSource = null;
    }

    /**
     * Nuclear purge: silence everything and drop Live pointer.
     * Only boot / script evaluate / bfcache cold restore — never leaveHammer.
     */
    function purgeAllStoreMusicAudio() {
        silenceAllStoreMusicAudio();
        global.__WelineStoreMusicLive = null;
    }

    function parseConfig(root) {
        try {
            return JSON.parse(root.getAttribute('data-store-music-config') || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    /** Cursor / VS Code / Electron guest — pagehide often skipped; AudioService can outlive the UI. */
    function isEmbeddedBrowserHost() {
        try {
            var ua = String((global.navigator && global.navigator.userAgent) || '');
            if (/Electron|Cursor|VSCode|Code\/\d/i.test(ua)) {
                return true;
            }
            if (global.process && global.process.versions && global.process.versions.electron) {
                return true;
            }
        } catch (e) {
            // ignore
        }
        return false;
    }

    function prefersReducedMotion() {
        return !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function storageKey(root, suffix) {
        var w = root.getAttribute('data-website-id') || '0';
        var s = root.getAttribute('data-store-id') || '0';
        return 'weline.storeMusic.' + w + '.' + s + '.' + suffix;
    }

    function readPref(root, suffix, fallback) {
        try {
            var raw = global.localStorage.getItem(storageKey(root, suffix));
            if (raw === null || raw === undefined) {
                return fallback;
            }
            if (raw === '1' || raw === 'true') {
                return true;
            }
            if (raw === '0' || raw === 'false') {
                return false;
            }
            var n = Number(raw);
            return Number.isFinite(n) ? n : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function writePref(root, suffix, value) {
        try {
            global.localStorage.setItem(storageKey(root, suffix), String(value));
        } catch (e) {
            // ignore quota / private mode
        }
    }

    function readJsonPref(root, suffix, fallback) {
        try {
            var raw = global.localStorage.getItem(storageKey(root, suffix));
            if (!raw) {
                return fallback;
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function writeJsonPref(root, suffix, value) {
        try {
            global.localStorage.setItem(storageKey(root, suffix), JSON.stringify(value));
        } catch (e) {
            // ignore
        }
    }

    var AUDIO_DB_NAME = 'WelineStoreMusic';
    var AUDIO_DB_VERSION = 1;
    var AUDIO_STORE = 'audio';
    var audioDbPromise = null;

    function openAudioDb() {
        if (!global.indexedDB) {
            return Promise.reject(new Error('indexedDB unavailable'));
        }
        if (audioDbPromise) {
            return audioDbPromise;
        }
        audioDbPromise = new Promise(function (resolve, reject) {
            var req = global.indexedDB.open(AUDIO_DB_NAME, AUDIO_DB_VERSION);
            req.onerror = function () {
                audioDbPromise = null;
                reject(req.error || new Error('indexedDB open failed'));
            };
            req.onsuccess = function () {
                resolve(req.result);
            };
            req.onupgradeneeded = function (event) {
                var db = event.target.result;
                if (!db.objectStoreNames.contains(AUDIO_STORE)) {
                    db.createObjectStore(AUDIO_STORE, { keyPath: 'url' });
                }
            };
        });
        return audioDbPromise;
    }

    function idbGetAudio(url) {
        return openAudioDb().then(function (db) {
            return new Promise(function (resolve) {
                try {
                    var tx = db.transaction(AUDIO_STORE, 'readonly');
                    var req = tx.objectStore(AUDIO_STORE).get(url);
                    req.onsuccess = function () {
                        var row = req.result;
                        resolve(row && row.blob instanceof Blob ? row.blob : null);
                    };
                    req.onerror = function () {
                        resolve(null);
                    };
                } catch (e) {
                    resolve(null);
                }
            });
        }).catch(function () {
            return null;
        });
    }

    function idbPutAudio(url, blob) {
        if (!url || !(blob instanceof Blob) || !blob.size) {
            return Promise.resolve();
        }
        return openAudioDb().then(function (db) {
            return new Promise(function (resolve) {
                try {
                    var tx = db.transaction(AUDIO_STORE, 'readwrite');
                    tx.objectStore(AUDIO_STORE).put({
                        url: url,
                        blob: blob,
                        size: blob.size,
                        updated: Date.now()
                    });
                    tx.oncomplete = function () {
                        resolve();
                    };
                    tx.onerror = function () {
                        resolve();
                    };
                } catch (e) {
                    resolve();
                }
            });
        }).catch(function () {
            // Soft: network src still works without cache.
        });
    }

    /**
     * Resolve playable src for progressive HTTP streaming (Accept-Ranges).
     * Online default: network URL so the browser can Range-buffer and start play()
     * before the whole file is downloaded.
     * preferCache (F5 sticky resume): try IndexedDB blob first for instant local play.
     * Offline: fall back to IndexedDB blob when available.
     * Full-file IDB warm happens later in the background — never before first play.
     */
    function resolveMediaSrc(url, preferCache) {
        var key = normalizeTrackUrl(url) || String(url || '');
        var online = typeof navigator === 'undefined' || navigator.onLine !== false;
        var fromIdb = function () {
            return idbGetAudio(key).then(function (cached) {
                if (cached) {
                    return {
                        src: URL.createObjectURL(cached),
                        cached: true,
                        stream: false
                    };
                }
                return null;
            }).catch(function () {
                return null;
            });
        };
        if (preferCache && global.indexedDB) {
            return fromIdb().then(function (hit) {
                if (hit) {
                    return hit;
                }
                return { src: url, cached: false, stream: true };
            });
        }
        if (online) {
            return Promise.resolve({ src: url, cached: false, stream: true });
        }
        return fromIdb().then(function (hit) {
            return hit || { src: url, cached: false, stream: true };
        });
    }

    /**
     * Background full-file cache into IndexedDB (never blocks play()).
     * Must not race the first progressive stream — call only after audible start / idle.
     */
    function warmIdbAudio(key, fetchUrl) {
        var storeKey = key || normalizeTrackUrl(fetchUrl) || String(fetchUrl || '');
        var networkUrl = fetchUrl || storeKey;
        if (!storeKey || !networkUrl || !global.fetch || !global.indexedDB) {
            return;
        }
        // Do NOT await fetch().blob() on the play path — that waited for the whole MP3/M4A.
        idbGetAudio(storeKey).then(function (hit) {
            if (hit) {
                return null;
            }
            return global.fetch(networkUrl, {
                credentials: 'same-origin',
                cache: 'force-cache'
            }).then(function (res) {
                if (!res.ok) {
                    return null;
                }
                return res.blob();
            }).then(function (blob) {
                if (blob) {
                    return idbPutAudio(storeKey, blob);
                }
            });
        }).catch(function () {
            // Soft.
        });
    }

    function normalizeTrackUrl(value) {
        var v = String(value || '').trim();
        if (!v) {
            return '';
        }
        try {
            v = decodeURIComponent(v);
        } catch (e) {
            // keep raw
        }
        if (/^https?:\/\//i.test(v)) {
            try {
                var u = new URL(v, global.location && global.location.href);
                v = u.pathname || v;
            } catch (e2) {
                // keep
            }
        }
        v = v.replace(/\\/g, '/').split('?')[0].split('#')[0];
        if (v.indexOf('/pub/media/') !== -1) {
            v = v.slice(v.indexOf('/pub/media/') + '/pub/media'.length);
        }
        if (v.indexOf('pub/media/') === 0) {
            v = v.slice('pub/media'.length);
        }
        if (v.charAt(0) !== '/') {
            v = '/' + v.replace(/^\/+/, '');
        }
        if (v.indexOf('/media/') !== 0 && v.indexOf('/static/') !== 0) {
            if (v.indexOf('/store-music/') === 0 || v.indexOf('store-music/') >= 0) {
                v = '/media' + (v.charAt(0) === '/' ? v : '/' + v);
            }
        }
        return v.replace(/\/+/g, '/');
    }

    function trackUrlsEqual(a, b) {
        var left = normalizeTrackUrl(a);
        var right = normalizeTrackUrl(b);
        return !!left && left === right;
    }

    function textOf(root, sel, fallback) {
        var el = root.querySelector(sel);
        var t = el ? String(el.textContent || '').trim() : '';
        return t || fallback;
    }

    function setStatus(root, message) {
        var el = root.querySelector('[data-store-music-status]');
        if (!el) {
            return;
        }
        if (!message) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.hidden = false;
        el.textContent = message;
    }

    function resolveApi() {
        if (global.Weline && typeof global.Weline.load === 'function') {
            return global.Weline.load('api');
        }
        if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
            return Promise.resolve(global.Weline.Api);
        }
        return Promise.reject(new Error('Weline.Api unavailable'));
    }

    /**
     * Soft Consent status — kept for diagnostics only.
     * Entrance BGM is storefront UX, NOT a marketing pixel: never block soft autoplay
     * on Cookie banner / marketing grant (that made 无痕「等很久也不播」).
     */
    function marketingAllowed() {
        return resolveApi().then(function (api) {
            return Promise.resolve(api.resource('consent')).then(function (consent) {
                if (!consent || typeof consent.status !== 'function') {
                    return true;
                }
                return Promise.resolve(consent.status({})).then(function (result) {
                    if (!result || result.recording_enabled === false) {
                        return true;
                    }
                    if (result.granted && typeof result.granted.marketing === 'boolean') {
                        return result.granted.marketing === true;
                    }
                    if (result.show_banner === true) {
                        return false;
                    }
                    return true;
                });
            });
        }).catch(function () {
            return true;
        });
    }

    /** Always true for StoreMusic playback gates (see marketingAllowed note). */
    function storeMusicPlaybackAllowed() {
        return Promise.resolve(true);
    }

    function whenWindowLoaded() {
        if (document.readyState === 'complete') {
            return Promise.resolve();
        }
        return new Promise(function (resolve) {
            var done = false;
            var finish = function () {
                if (done) {
                    return;
                }
                done = true;
                resolve();
            };
            global.addEventListener('load', finish, { once: true });
            // Race: load can fire between the readyState check and addEventListener
            // (cached pages / fast assets). Without this re-check, scheduleStart hangs forever
            // → 进店永远不自动播，只剩「点击开启音乐」.
            if (document.readyState === 'complete') {
                finish();
            } else {
                document.addEventListener('readystatechange', function onRs() {
                    if (document.readyState === 'complete') {
                        document.removeEventListener('readystatechange', onRs);
                        finish();
                    }
                });
            }
            // Hard ceiling: never leave 进店 autoplay hung on a missed load forever.
            global.setTimeout(finish, 5000);
        });
    }

    function delay(ms) {
        return new Promise(function (resolve) {
            global.setTimeout(resolve, ms);
        });
    }

    function normalizeTracks(cfg) {
        var list = [];
        var raw = cfg && Array.isArray(cfg.tracks) ? cfg.tracks : [];
        raw.forEach(function (row) {
            if (!row) return;
            var url = String(row.url || row.path || '').trim();
            if (!url) return;
            list.push({
                url: url,
                title: String(row.title || '').trim(),
                intro: String(row.intro || '').trim()
            });
        });
        if (!list.length) {
            var legacy = String((cfg && cfg.track) || '').trim();
            if (legacy) {
                list.push({ url: legacy, title: '', intro: '' });
            }
        }
        return list;
    }

    function titleFromUrl(url) {
        try {
            var path = String(url || '').split('?')[0];
            var base = path.split('/').pop() || '';
            base = decodeURIComponent(base).replace(/\.[a-z0-9]{2,5}$/i, '').replace(/[_+]+/g, ' ').trim();
            return base || '进店音乐';
        } catch (e) {
            return '进店音乐';
        }
    }

    function accentRgba(alpha) {
        try {
            var probe = document.querySelector('.w-store-music') || document.documentElement;
            var raw = global.getComputedStyle(probe).getPropertyValue('--w-store-music-accent').trim()
                || global.getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim()
                || '#b84a3c';
            if (raw.indexOf('rgb') === 0) {
                return raw.replace(/rgba?\(([^)]+)\)/, function (_, inner) {
                    var parts = inner.split(',').map(function (p) { return p.trim(); });
                    return 'rgba(' + parts[0] + ', ' + parts[1] + ', ' + parts[2] + ', ' + alpha + ')';
                });
            }
            if (raw.charAt(0) === '#' && (raw.length === 7 || raw.length === 4)) {
                var hex = raw.length === 4
                    ? '#' + raw[1] + raw[1] + raw[2] + raw[2] + raw[3] + raw[3]
                    : raw;
                var r = parseInt(hex.slice(1, 3), 16);
                var g = parseInt(hex.slice(3, 5), 16);
                var b = parseInt(hex.slice(5, 7), 16);
                return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
            }
        } catch (e) {
            // fall through
        }
        return 'rgba(184, 74, 60, ' + alpha + ')';
    }

    function StoreMusic(root) {
        this.root = root;
        this.cfg = parseConfig(root);
        this.tracks = normalizeTracks(this.cfg);
        this.trackIndex = 0;
        this.audio = null;
        this.audioReady = false;
        this.ctx = null;
        this.analyser = null;
        this.source = null;
        this.waveConnected = false;
        this.waveFailed = false;
        this.waveMode = 'analyser'; // analyser | soft
        this.raf = 0;
        this._avatarLevel = 0;
        this.spectrumEl = null;
        this._spectrumBars = [];
        this._spectrumSmooth = [];
        this.panelOpen = false;
        this.needGesture = false;
        this.isLeader = false;
        // Remote state is UI-only. It must never be treated as local audio ownership.
        this.remotePlaying = false;
        this.remoteOwnerTabId = '';
        this.remoteStateAt = 0;
        this.channel = null;
        this._progressTimer = 0;
        this._pendingSeek = null;
        this._lastGoodProgressTime = 0;
        this._blobUrl = null;
        this._loadToken = 0;
        this._playGen = 0;
        this._leaderBeat = 0;
        this._leaderClaimedAt = 0;
        this._lastUserActionAt = 0;
        this._lastGestureAt = 0;
        // Cold autoplay / resume awaiting browser gesture or Cookie consent accept.
        this._autoplayPending = false;
        this._awaitingConsent = false;
        this._awaitingUnmute = false;
        this._pageGestureUnlock = null;
        this._consentRetryBound = false;
        this._scheduleGen = 0;
        this._scheduleInFlight = false;
        this._peerRetryArmed = false;
        this._ignorePeerOnce = false;
        /** F5 / bfcache restore: ignore dying predecessor peer lock until play succeeds or we give up. */
        this._navResumeActive = false;
        // Same-document natural end (loop off): block soft resume without sticky user_stopped.
        this._naturalEnded = false;
        // Document lifecycle: leave-page sets false so residual webviews cannot keep decoding.
        this._docAlive = true;
        this._tearingDown = false;
        this._bfcacheParked = false;
        // Only continue while hidden if we already started audibly while this document was visible.
        // Prevents prerender/zombie/hidden restores from autoplaying with no open tab UI.
        this._heardWhileVisible = false;
        this._lastAudibleAt = 0;
        this._epoch = currentStoreMusicEpoch();
        this._bootedAt = Date.now();
        this._ghostWatch = 0;
        this._orphanWatch = 0;
        this._leaveHammer = 0;
        this._originTabWatch = 0;
        this._soleSourceWatch = 0;
        this._audioMutating = false;
        this._collapsedHits = 0;

        this.waveCanvas = document.querySelector('[data-store-music-wave]');
        this.mascot = root.querySelector('[data-testid="store-music-mascot"]')
            || root.querySelector('[data-store-music-toggle]');
        // One live controller per browsing context — prior instance must not keep decoding.
        if (global.__WelineStoreMusicLive && global.__WelineStoreMusicLive !== this) {
            try {
                global.__WelineStoreMusicLive.hardSilenceMedia(false);
                global.__WelineStoreMusicLive.syncPlayingUi(false);
            } catch (eLive) {
                // ignore
            }
        }
        global.__WelineStoreMusicLive = this;
        this.toggleBtns = root.querySelectorAll('[data-store-music-toggle]');
        this.hint = root.querySelector('[data-store-music-hint]');
        this.panel = root.querySelector('[data-store-music-panel]');
        this.playBtn = root.querySelector('[data-store-music-play]');
        this.pauseBtn = root.querySelector('[data-store-music-pause]');
        this.prevBtn = root.querySelector('[data-store-music-prev]');
        this.nextBtn = root.querySelector('[data-store-music-next]');
        this.closeBtn = root.querySelector('[data-store-music-close]');
        this.dismissBtn = root.querySelector('[data-store-music-dismiss]');
        this.volume = root.querySelector('[data-store-music-volume]');
        this.volumePct = root.querySelector('[data-store-music-volume-pct]');
        this.playlistCountEl = root.querySelector('[data-store-music-playlist-count]');
        this.waveToggle = root.querySelector('[data-store-music-wave-toggle]');
        this.waveLabel = root.querySelector('[data-store-music-wave-label]');
        this.titleEl = root.querySelector('[data-store-music-title]');
        this.introEl = root.querySelector('[data-store-music-intro]');
        this.introWrap = root.querySelector('[data-store-music-intro-wrap]');
        this.introToggle = root.querySelector('[data-store-music-intro-toggle]');
        this.playlistEl = root.querySelector('[data-store-music-playlist]');
        this.compartmentEl = root.querySelector('[data-store-music-compartment]');
        this.searchInput = root.querySelector('[data-store-music-search]');
        this.searchEmptyEl = root.querySelector('[data-store-music-search-empty]');
        this.playlistFilter = '';
        this.spectrumEl = root.querySelector('[data-store-music-spectrum]');
        this.ensureSpectrumBars();

        // Admin: optional vinyl spin (default off). Playing uses polar spectrum rings.
        var avatarSpin = this.cfg.avatar_spin === true
            || this.cfg.avatar_spin === 1
            || this.cfg.avatar_spin === '1';
        root.classList.toggle('is-avatar-spin', !!avatarSpin);

        // Volume: remember user choice in localStorage. One-shot migrate only loud leftovers
        // from the pre-quiet era — never re-clamp on every boot (that wiped 用户音量).
        var volDefault = Number(this.cfg.default_volume);
        if (!Number.isFinite(volDefault) || volDefault < 0) {
            volDefault = 8;
        }
        volDefault = Math.max(0, Math.min(100, Math.round(volDefault)));
        var volUserSet = !!readPref(root, 'volume_user', false);
        if (!volUserSet && !readPref(root, 'vol_remember_v47', false)) {
            var legacyVol = readPref(root, 'volume', null);
            var legacyNum = legacyVol === null || legacyVol === undefined || legacyVol === ''
                ? NaN
                : Number(legacyVol);
            // Old installs often stuck at 30–100; soften once if the user never touched the slider.
            if (Number.isFinite(legacyNum) && legacyNum > 40) {
                writePref(root, 'volume', volDefault);
            }
            writePref(root, 'vol_remember_v47', '1');
            writePref(root, 'vol_quiet_v42b', '1');
            writePref(root, 'vol_quiet_v42', '1');
            writePref(root, 'vol_soft_migrated', '1');
        }
        var volPref = readPref(root, 'volume', volDefault);
        if (!Number.isFinite(Number(volPref))) {
            volPref = volDefault;
        }
        volPref = Math.max(0, Math.min(100, Math.round(Number(volPref))));
        if (this.volume) {
            this.volume.value = String(volPref);
            this.syncVolumePct(volPref);
        }
        // Default waveform ON for ambient body wave unless user previously turned off.
        var waveDefault = this.cfg.waveform_default !== false && this.cfg.waveform_default !== 0 && this.cfg.waveform_default !== '0';
        var wavePref = readPref(root, 'wave', waveDefault);
        if (this.waveToggle) {
            this.waveToggle.checked = !!wavePref;
        }
        this.restoreProgressIndex();
        this.syncWaveLabel();
        this.syncSkipButtons();
        this.renderPlaylist();
        this.syncTrackMeta(false);
        this.bindChannel();
        this.bind();
        this.bindConsentAutoplayRetry();
        this.syncHintVisibility();
        // Progressive stream first — do not fetch whole playlist into IDB on boot.
        this.armDeferredAudioCacheWarm();
        this.startOriginTabWatch();
        // Hidden/prerender boot must not autoplay (Cursor may keep detached webviews).
        if (document.visibilityState === 'hidden') {
            this.killAllPageAudio();
        } else {
            this.scheduleStart();
        }
        this.startGhostWatch();
    }

    StoreMusic.prototype.playIntent = function () {
        try {
            var raw = global.localStorage.getItem(storageKey(this.root, 'want_play'));
            if (raw === null || raw === undefined || raw === '') {
                return 'unset';
            }
            if (raw === '1' || raw === 'true') {
                return 'play';
            }
            return 'stop';
        } catch (e) {
            return 'unset';
        }
    };

    /** True only after explicit ❚❚ / dismiss — not after navigation / domain-empty races. */
    StoreMusic.prototype.isUserStopped = function () {
        return !!readPref(this.root, 'user_stopped', false);
    };

    StoreMusic.prototype.setUserStopped = function (on) {
        writePref(this.root, 'user_stopped', on ? '1' : '0');
    };

    /**
     * 1.1.28 natural-end wrote user_stopped=1 (same as ❚❚), killing 进店 try_autoplay
     * and playlist advance via soft tryPlay / play-event guards. One-shot clear for
     * non-dismissed sessions; real ❚❚ still works within the same visit after user presses it.
     */
    StoreMusic.prototype.migrateNaturalEndStopPoison = function () {
        if (readPref(this.root, 'stop_semantics', '') === '1.1.29') {
            return false;
        }
        writePref(this.root, 'stop_semantics', '1.1.29');
        if (this.isDismissed()) {
            return false;
        }
        if (!this.isUserStopped()) {
            return false;
        }
        this.setUserStopped(false);
        if (this.playIntent() === 'stop') {
            try {
                global.localStorage.removeItem(storageKey(this.root, 'want_play'));
            } catch (e) {
                writePref(this.root, 'want_play', '');
            }
        }
        return true;
    };

    StoreMusic.prototype.wantsPlay = function () {
        return this.playIntent() === 'play';
    };

    StoreMusic.prototype.setWantPlay = function (on) {
        writePref(this.root, 'want_play', on ? '1' : '0');
        if (on) {
            this.setDismissed(false);
            this.setUserStopped(false);
        }
    };

    /**
     * Legacy leave/domain_empty wrote want_play=0 without a user stop. That permanently
     * disabled try_autoplay. Clear the poison when there is no explicit user_stopped flag.
     */
    StoreMusic.prototype.clearLegacyStopPoison = function () {
        if (this.playIntent() !== 'stop') {
            return false;
        }
        if (this.isUserStopped() || this.isDismissed()) {
            return false;
        }
        try {
            global.localStorage.removeItem(storageKey(this.root, 'want_play'));
        } catch (e) {
            writePref(this.root, 'want_play', '');
        }
        return true;
    };

    StoreMusic.prototype.isDismissed = function () {
        return !!readPref(this.root, 'dismissed', false);
    };

    StoreMusic.prototype.setDismissed = function (on) {
        writePref(this.root, 'dismissed', on ? '1' : '0');
        if (this.root) {
            this.root.classList.toggle('is-dismissed', !!on);
        }
    };

    StoreMusic.prototype.restoreProgressIndex = function () {
        var snap = readJsonPref(this.root, 'progress', null);
        if (!snap) {
            return false;
        }
        var restored = false;
        var url = snap.url ? String(snap.url) : '';
        if (url) {
            for (var i = 0; i < this.tracks.length; i++) {
                if (trackUrlsEqual(this.tracks[i].url, url)) {
                    this.trackIndex = i;
                    restored = true;
                    break;
                }
            }
        }
        if (!restored) {
            var idx = Number(snap.index);
            if (Number.isFinite(idx) && idx >= 0 && idx < this.tracks.length) {
                this.trackIndex = idx;
                restored = true;
            }
        }
        var t = Number(snap.time);
        // Remember position even for short seeks so refresh / 再点 ▶ 能接上。
        if (Number.isFinite(t) && t >= 0) {
            this._pendingSeek = t;
            if (t >= 0.25) {
                this._lastGoodProgressTime = t;
            }
        }
        return restored;
    };

    StoreMusic.prototype.hasResumeSnap = function () {
        var snap = readJsonPref(this.root, 'progress', null);
        return !!(snap && (snap.url || Number.isFinite(Number(snap.index))));
    };

    /**
     * Persist current track selection immediately (URL + index).
     * Time may be 0 so a just-selected track survives refresh before 0.5s playback.
     */
    StoreMusic.prototype.persistSelection = function (time) {
        var track = this.currentTrack();
        if (!track) {
            return;
        }
        var t = time;
        if (t === undefined || t === null) {
            if (this.audio) {
                t = Number(this.audio.currentTime) || 0;
            } else {
                t = Number(this._pendingSeek) || 0;
            }
        }
        t = Number(t);
        if (!Number.isFinite(t) || t < 0) {
            t = 0;
        }
        writeJsonPref(this.root, 'progress', {
            url: normalizeTrackUrl(track.url) || track.url,
            time: Math.floor(t * 10) / 10,
            index: this.trackIndex,
            updated: Date.now()
        });
    };

    /** Persist slider volume and mark it as an explicit user choice (never re-migrate over). */
    StoreMusic.prototype.persistVolumePref = function (value) {
        var v = Math.max(0, Math.min(100, Math.round(Number(value) || 0)));
        writePref(this.root, 'volume', v);
        writePref(this.root, 'volume_user', '1');
        this.syncVolumePct(v);
        if (this.volume && String(this.volume.value) !== String(v)) {
            this.volume.value = String(v);
        }
        if (this.audio && (this.isLeader || !this.audio.paused)) {
            try {
                this.audio.volume = Math.max(0, Math.min(1, v / 100));
            } catch (e) {
                // ignore
            }
        }
        return v;
    };

    StoreMusic.prototype.saveProgress = function () {
        var track = this.currentTrack();
        if (!track) {
            return;
        }
        var t = null;
        if (this.audio) {
            var live = Number(this.audio.currentTime);
            if (Number.isFinite(live) && live >= 0) {
                t = live;
                if (live >= 0.25) {
                    this._lastGoodProgressTime = live;
                }
            }
        } else if (this._pendingSeek != null && Number.isFinite(Number(this._pendingSeek))) {
            t = Number(this._pendingSeek);
        }
        // After tearDown the MediaElement is gone — a second pagehide/unload must NOT
        // persist time=0 over the snap we just saved (YouTube-like refresh resume).
        if (t === null) {
            return;
        }
        if (t < 0.25 && Number(this._lastGoodProgressTime) >= 0.25) {
            t = Number(this._lastGoodProgressTime);
        }
        this.persistSelection(t);
        // Keep cross-page audible clock fresh so F5 sticky resume is not expired.
        if (this.audio && !this.audio.paused) {
            this.touchAudible();
        }
    };

    StoreMusic.prototype.bindChannel = function () {
        var self = this;
        if (typeof global.BroadcastChannel === 'function') {
            try {
                this.channel = new global.BroadcastChannel(CHANNEL_NAME);
                this.channel.onmessage = function (ev) {
                    var msg = ev && ev.data ? ev.data : null;
                    if (!msg || msg.tabId === TAB_ID) {
                        return;
                    }
                    if (msg.host && msg.host !== PAGE_HOST) {
                        return;
                    }
                    if (msg.type === 'domain_empty') {
                        // Race: a leaving tab may broadcast empty while peers still live.
                        if (self.isRealOriginTab()) {
                            self.touchOriginTab();
                            return;
                        }
                        if (self.hasLiveOriginTab()) {
                            return;
                        }
                        // Silence zombies only — never write want_play=0 here.
                        // Navigation has a census gap; poisoning stop would kill try_autoplay
                        // and page-gesture unlock on the next page (无痕进店「秒数到了不播」).
                        self.silenceForOtherTab(false, true);
                        self.hardSilenceMedia(true);
                        self.killAllPageAudio();
                        self.syncPlayingUi(false);
                        self.syncRemotePlayingUi(false);
                        return;
                    }
                    if (msg.type === 'tab_dead') {
                        global.setTimeout(function () {
                            if (!self.hasLiveOriginTab()) {
                                self.hardSilenceMedia(true);
                                self.killAllPageAudio();
                                self.syncPlayingUi(false);
                                self.syncRemotePlayingUi(false);
                            }
                        }, 80);
                    }
                    if (msg.type === 'playing') {
                        // Real claims (track switch / takeover) must update selection AND
                        // stop stale audio. Heartbeats only keep soft tabs quiet / UI in sync.
                        var isHeartbeat = !!msg.heartbeat;
                        var selectionChanged = self.selectionDiffersFromMessage(msg);
                        if (isHeartbeat) {
                            if (selectionChanged) {
                                // Peer leader advanced the playlist — mirror UI; do not dual-play.
                                self.applyRemoteSelection(msg);
                                if (self.isLeader || (self.audio && !self.audio.paused)) {
                                    if (self.isRemoteNewer(msg) || !self.isLatestOperator()) {
                                        self.silenceForOtherTab(false, true);
                                    } else {
                                        // We still own playback but UI drifted — force audible follow.
                                        self.reconcileAudibleSelection(true);
                                    }
                                }
                            }
                            if (self.isUserOperating() || self.isLatestOperator()) {
                                var owningPlay = !!(self.isLeader || (self.audio && !self.audio.paused));
                                if (owningPlay && self.canAudiblyStart(true) && self.playIntent() === 'play') {
                                    self.claimLeadership(true);
                                    self.reconcileAudibleSelection(false);
                                } else if (!owningPlay) {
                                    self.syncRemotePlayingUi(true, msg);
                                }
                                setStatus(self.root, '');
                                return;
                            }
                            self.yieldToOtherTab(msg);
                            return;
                        }
                        // Non-heartbeat = authoritative play/claim (last user op).
                        if (self.canRefuseYield(msg) && !self.isRemoteNewer(msg)) {
                            self.claimLeadership(true);
                            self.reconcileAudibleSelection(true);
                            setStatus(self.root, '');
                            return;
                        }
                        self.yieldToOtherTab(msg);
                        // After yield, if we are still the latest operator (race), take over with the new track.
                        if (self.isLatestOperator() && self.playIntent() === 'play' && self.canAudiblyStart(true)) {
                            self.reconcileAudibleSelection(true);
                        }
                        return;
                    }
                    if (msg.type === 'released') {
                        self.syncRemotePlayingUi(false, msg);
                        self.maybeResumeAfterPeerRelease();
                        return;
                    }
                    if (msg.type === 'stop' || msg.type === 'force_stop') {
                        // Last user op wins: equal/newer remote must silence us; never keep residual audio.
                        if (self.canRefuseYield(msg)) {
                            return;
                        }
                        self.silenceForOtherTab(!!msg.clearWantPlay, true);
                        if (msg.url || msg.index != null) {
                            self.applyRemoteSelection(msg);
                        }
                        self.syncRemotePlayingUi(msg.type === 'force_stop' && msg.playing === true, msg);
                        if (msg.clearWantPlay || !self.shouldShowOtherTabStatus()) {
                            setStatus(self.root, '');
                        } else {
                            setStatus(
                                self.root,
                                textOf(self.root, '[data-store-music-i18n-other-tab]', '已在其他页面播放')
                            );
                        }
                        return;
                    }
                };
            } catch (e) {
                this.channel = null;
            }
        }

        // localStorage lock complements BroadcastChannel (and works when BC is missing).
        global.addEventListener('storage', function (ev) {
            if (!ev || !ev.key) {
                return;
            }
            var lockKey = storageKey(self.root, 'leader_lock');
            var opKey = storageKey(self.root, 'operator');
            if (ev.key === opKey) {
                // Operator stamp alone must NOT silence peers — opening a panel on another
                // page only updates who last interacted. Playback handoff is lock / force_stop.
                return;
            }
            var progressKey = storageKey(self.root, 'progress');
            if (ev.key === progressKey) {
                // Cross-tab playlist row sync from persistSelection — UI must move with the
                // shared progress snap; audible follow only when we still own playback.
                try {
                    var snap = ev.newValue ? JSON.parse(String(ev.newValue)) : null;
                    if (snap && (snap.url || snap.index != null)) {
                        var changed = self.applyRemoteSelection(snap);
                        if (changed && (self.isLeader || (self.audio && !self.audio.paused))) {
                            if (self.isLatestOperator()) {
                                self.reconcileAudibleSelection(true);
                            } else {
                                self.silenceForOtherTab(false, true);
                            }
                        }
                    }
                } catch (eProg) {
                    // ignore
                }
                return;
            }
            if (ev.key !== lockKey) {
                return;
            }
            var next = String(ev.newValue || '');
            if (!next) {
                // Peer released the lock (tab closed) — soft resume if we still want play.
                self.maybeResumeAfterPeerRelease();
                return;
            }
            if (next.indexOf(TAB_ID + '|') === 0) {
                return;
            }
            // Prefer the durable operator stamp over lock heartbeat time (heartbeat uses Date.now()).
            var opNow = '';
            try {
                opNow = String(global.localStorage.getItem(opKey) || '');
            } catch (e) {
                opNow = '';
            }
            if (opNow && opNow.indexOf(TAB_ID + '|') === 0) {
                // Latest *playback* operator may reclaim — but browsing (open panel /
                // volume UI) must never steal a peer's lock just because we stamped operator.
                var owningHere = !!(self.isLeader || (self.audio && !self.audio.paused));
                if (owningHere && self.canRefuseYield({ at: Number(String(opNow.split('|')[1] || '')) || 0 })) {
                    self.claimLeadership(true);
                    self.reconcileAudibleSelection(true);
                }
                return;
            }
            var opAt = 0;
            var opTab = '';
            if (opNow) {
                var p = opNow.split('|');
                opTab = p[0] || '';
                opAt = Number(p[1] || '') || 0;
            }
            self.yieldToOtherTab({
                tabId: opTab || String(next.split('|')[0] || ''),
                at: opAt || 1
            });
        });

        var onLeave = function (ev) {
            // Snapshot + latch BEFORE any silence so F5 successor can sticky-resume.
            self.saveProgress();
            if (self.isAudibleOwner()) {
                self.armNavResumeLatch();
            }
            // bfcache (back/forward): keep the MediaElement shell, but NEVER keep decoding.
            // Old behavior left audio running after the last domain tab was gone / navigated away.
            if (ev && ev.type === 'pagehide' && ev.persisted) {
                self.parkForBfcache();
                return;
            }
            // Hard leave / full refresh: mark dying in census BEFORE drop so a successor
            // that boots mid-teardown does not treat this tab as a live peer leader.
            try {
                var dyingMap = self.readOriginTabs();
                if (dyingMap[TAB_ID]) {
                    dyingMap[TAB_ID] = {
                        at: 0,
                        host: PAGE_HOST,
                        visible: false,
                        dying: true
                    };
                    self.writeOriginTabs(dyingMap);
                }
            } catch (eDying) {
                // ignore
            }
            self.dropOriginTab();
            // Do NOT broadcastForceStop here. A refresh successor often boots within ~200ms;
            // a late force_stop from the dying document would silence the new page mid-resume.
            // Local tearDown + releaseLeadership('released') is enough; live peers reclaim.
            self.tearDownForLeave(ev && ev.type ? String(ev.type) : 'leave');
        };
        // Capture phase: Cursor/Electron guest teardown may skip bubble listeners.
        // Prefer pagehide; beforeunload + freeze + orphanWatch cover hosts that skip pagehide.
        // Do NOT register `unload` — Permissions-Policy / bfcache forbid it (console violation).
        global.addEventListener('pagehide', onLeave, true);
        global.addEventListener('beforeunload', function (ev) {
            // beforeunload is not bfcache-safe — snapshot + latch; pagehide does teardown.
            self.saveProgress();
            if (self.isAudibleOwner()) {
                self.armNavResumeLatch();
            }
            // Last-tab close may skip pagehide — emergency pause only then. F5 reload always
            // gets pagehide tearDown; pausing here added a race that blocked sticky resume.
            var navType = '';
            try {
                var navEntries = performance.getEntriesByType('navigation');
                navType = navEntries && navEntries[0] ? String(navEntries[0].type || '') : '';
            } catch (eNavType) {
                navType = '';
            }
            if (navType === 'reload') {
                return;
            }
            try {
                self.clearMediaSession();
                if (self.audio && !self.audio.paused) {
                    self.audio.pause();
                }
            } catch (eBefore) {
                // ignore
            }
        }, true);
        if (typeof document.addEventListener === 'function') {
            document.addEventListener('freeze', function () {
                self.tearDownForLeave('freeze');
            }, true);
        }
        self.bindMediaSessionStopHandlers();
        // Cursor/Electron: panel close may skip pagehide+visibilitychange — blur + size check.
        if (isEmbeddedBrowserHost()) {
            global.addEventListener('blur', function () {
                global.setTimeout(function () {
                    if (!self._docAlive || !self.audio || self.audio.paused) {
                        return;
                    }
                    if (self.isCollapsedDetachedView() || (self.root && !self.root.isConnected)) {
                        self.tearDownForLeave('host-detached');
                    }
                }, 50);
            }, true);
        }
        global.addEventListener('pageshow', function (ev) {
            self.stopLeaveHammer();
            self.stopOrphanWatch();
            self._docAlive = true;
            self._tearingDown = false;
            self._collapsedHits = 0;
            self.startGhostWatch();
            self.startOriginTabWatch();
            self.touchOriginTab();
            // bfcache restore: we parked (silenced) on pagehide.persisted — reclaim + resume,
            // do not remount@0 when the element somehow still holds the same track.
            if (ev && ev.persisted) {
                self._bfcacheParked = false;
                self.touchOriginTab();
                self.beginNavResumeBypass();
                if (self.audio && !self.audio.paused) {
                    self.markHeardWhileVisible();
                    self.setWantPlay(true);
                    self.claimLeadership(false);
                    self.syncPlayingUi(true);
                    self.startLeaderHeartbeat();
                    setStatus(self.root, '');
                    return;
                }
                if (self.playIntent() !== 'play' && !self.hasResumeSnap()) {
                    return;
                }
                if (document.visibilityState === 'hidden') {
                    return;
                }
                global.setTimeout(function () {
                    if (!self._docAlive) {
                        return;
                    }
                    if (self.audio && !self.audio.paused) {
                        self.syncPlayingUi(true);
                        return;
                    }
                    self.restoreProgressIndex();
                    self.scheduleStart({ force: true });
                }, 30);
                return;
            }
        });
    };

    /**
     * Entering bfcache: stop all audible output and leave the domain census.
     * Keeping decode alive here caused music to survive after every domain tab was closed
     * or navigated away (frozen page still singing).
     * Keep MediaElement shell via soft silence so pageshow can sticky-resume.
     */
    StoreMusic.prototype.parkForBfcache = function () {
        this._bfcacheParked = true;
        this._autoplayPending = false;
        this._awaitingUnmute = false;
        this.disarmPageGestureUnlock();
        this.clearMediaSession();
        this.stopLeaderHeartbeat();
        this.stopWaveLoop();
        this.stopProgressWatch();
        this.stopOrphanWatch();
        this.stopGhostWatch();
        this.stopOriginTabWatch();
        this.stopSoleSourceWatch();
        this.releaseLeadership();
        this.dropOriginTab();
        try {
            if (typeof silenceAllStoreMusicAudio === 'function') {
                silenceAllStoreMusicAudio();
            }
        } catch (ePark) {
            // ignore
        }
        // Soft silence keeps the singleton shell; never leave a decoding MediaElement in bfcache.
        this.hardSilenceMedia(false);
        this.syncPlayingUi(false);
        if (!this.hasLiveOriginTab()) {
            // Last domain surface is frozen/gone — wake any zombie peer contexts.
            this.broadcastForceStop(false);
            if (this.channel) {
                try {
                    this.channel.postMessage({
                        type: 'domain_empty',
                        tabId: TAB_ID,
                        host: PAGE_HOST,
                        at: Date.now()
                    });
                } catch (eEmpty) {
                    // ignore
                }
            }
        }
    };

    /**
     * Belt-and-suspenders: pause/clear every MediaElement in this document + shared singleton.
     * Used on leave and hidden cold-boot so detached Cursor webviews cannot keep singing.
     */
    StoreMusic.prototype.killAllPageAudio = function () {
        this.stopWaveLoop();
        destroySharedAudioGraph();
        killRivalAudioElements(null);
        this.audio = null;
        this.audioReady = false;
        this.waveConnected = false;
        this.ctx = null;
        this.source = null;
        this.analyser = null;
        this.clearMediaSession();
    };

    StoreMusic.prototype.clearMediaSession = function () {
        try {
            if (!global.navigator || !global.navigator.mediaSession) {
                return;
            }
            // Drop action handlers BEFORE pausing/tearing down — some engines invoke
            // pause/stop handlers on programmatic pause and would call stopPlayback(),
            // poisoning want_play/user_stopped so refresh never resumes.
            if (typeof global.navigator.mediaSession.setActionHandler === 'function') {
                try {
                    global.navigator.mediaSession.setActionHandler('pause', null);
                } catch (ePause) {
                    // ignore
                }
                try {
                    global.navigator.mediaSession.setActionHandler('stop', null);
                } catch (eStop) {
                    // ignore
                }
            }
            global.navigator.mediaSession.playbackState = 'none';
            try {
                global.navigator.mediaSession.metadata = null;
            } catch (eMeta) {
                // ignore
            }
        } catch (e) {
            // ignore
        }
    };

    /**
     * OS media controls (and some embedded browsers) can keep decoding after the tab UI is gone.
     * Wire stop/pause to explicit user stop — never during document teardown/refresh.
     */
    StoreMusic.prototype.bindMediaSessionStopHandlers = function () {
        var self = this;
        try {
            if (!global.navigator || !global.navigator.mediaSession || typeof global.navigator.mediaSession.setActionHandler !== 'function') {
                return;
            }
            var stop = function () {
                if (self._audioMutating || self._tearingDown || !self._docAlive) {
                    return;
                }
                self.stopPlayback();
            };
            global.navigator.mediaSession.setActionHandler('pause', stop);
            global.navigator.mediaSession.setActionHandler('stop', stop);
        } catch (e) {
            // ignore unsupported actions
        }
    };

    /**
     * Hard leave teardown: mark dead, drop leadership, destroy MediaElement.
     * Covers Cursor/Electron closing the Browser panel without a reliable pagehide.
     * Must NOT write user_stopped / want_play=0 — refresh must still resume.
     */
    StoreMusic.prototype.tearDownForLeave = function (reason) {
        this._tearingDown = true;
        this._docAlive = false;
        this._heardWhileVisible = false;
        this._autoplayPending = false;
        this._awaitingUnmute = false;
        this.disarmPageGestureUnlock();
        this.clearMediaSession();
        this.stopOrphanWatch();
        this.stopGhostWatch();
        this.stopOriginTabWatch();
        this.stopSoleSourceWatch();
        this.dropOriginTab();
        this.releaseLeadership();
        try {
            // Prefer silence-without-null-Live: a same-window refresh successor may already
            // own __WelineStoreMusicLive; leaveHammer must not wipe that pointer.
            if (typeof silenceAllStoreMusicAudio === 'function') {
                silenceAllStoreMusicAudio();
            } else if (typeof purgeAllStoreMusicAudio === 'function') {
                purgeAllStoreMusicAudio();
            }
        } catch (ePurge) {
            // ignore
        }
        this.hardSilenceMedia(true);
        this.killAllPageAudio();
        this.syncPlayingUi(false);
        // pagehide/unload/freeze: document is dying — do NOT hammer for 2s.
        // Same-window refresh (Electron/Cursor) would purge the successor mid-resume.
        // Hammer only when the JS context may survive (orphan / detached webview).
        var r = String(reason || '');
        if (
            r.indexOf('orphan') === 0
            || r === 'visibility-detached'
            || r === 'host-detached'
            || r === 'domain-empty'
            || r === 'origin-tabs-empty'
            || r === 'ghost-disconnected'
        ) {
            this.startLeaveHammer(r);
        }
    };

    /**
     * After leave, keep silencing briefly: some hosts re-kick decode right after pause/src clear.
     * Epoch-gated: must not purge a refresh successor that already bumped __WelineStoreMusicEpoch.
     */
    StoreMusic.prototype.startLeaveHammer = function () {
        var self = this;
        this.stopLeaveHammer();
        var epoch = currentStoreMusicEpoch();
        var n = 0;
        this._leaveHammer = global.setInterval(function () {
            if (currentStoreMusicEpoch() !== epoch || global.__WelineStoreMusicLive !== self) {
                self.stopLeaveHammer();
                return;
            }
            try {
                if (typeof silenceAllStoreMusicAudio === 'function') {
                    silenceAllStoreMusicAudio();
                }
            } catch (eH) {
                // ignore
            }
            self.hardSilenceMedia(true);
            self.killAllPageAudio();
            n += 1;
            if (n >= 20) {
                self.stopLeaveHammer();
            }
        }, 100);
    };

    StoreMusic.prototype.stopLeaveHammer = function () {
        if (this._leaveHammer) {
            global.clearInterval(this._leaveHammer);
            this._leaveHammer = 0;
        }
    };

    StoreMusic.prototype.startGhostWatch = function () {
        var self = this;
        this.stopGhostWatch();
        this._ghostWatch = global.setInterval(function () {
            if (!self._docAlive) {
                self.hardSilenceMedia(true);
                self.killAllPageAudio();
                self.stopGhostWatch();
                return;
            }
            try {
                if (!document.documentElement || !document.documentElement.isConnected) {
                    self.tearDownForLeave('ghost-disconnected');
                    return;
                }
                // Cursor can leave a connected DOM in a 0×0 singing webview — catch it here too.
                if (self.audio && !self.audio.paused && self.isCollapsedDetachedView()) {
                    self.tearDownForLeave('host-detached');
                }
            } catch (e) {
                // ignore
            }
        }, 800);
    };

    StoreMusic.prototype.stopGhostWatch = function () {
        if (this._ghostWatch) {
            global.clearInterval(this._ghostWatch);
            this._ghostWatch = 0;
        }
    };

    /**
     * While audible, periodically kill sibling StoreMusic MediaElements in this document
     * and drop local decode if another tab holds the leader lock (cross-tab dual-play).
     */
    StoreMusic.prototype.startSoleSourceWatch = function () {
        var self = this;
        this.stopSoleSourceWatch();
        this._soleSourceWatch = global.setInterval(function () {
            if (!self._docAlive) {
                self.stopSoleSourceWatch();
                return;
            }
            // Cross-tab: we are playing but someone else holds the lock → stop locally
            // (unless the user just operated here — they are taking over).
            var lock = self.readLeaderLock();
            if (lock && lock.tabId && lock.tabId !== TAB_ID
                && self.audio && !self.audio.paused
                && !self.isUserOperating()) {
                self.silenceForOtherTab(false, true);
                return;
            }
            var keep = self.audio;
            if (keep && !keep.paused) {
                enforceSoleAudibleSource(keep);
                self.touchAudible();
                return;
            }
            // Only scrub OTHER playing StoreMusic nodes — never mass-kill when !isLeader.
            var registry = audioRegistry().slice();
            for (var i = 0; i < registry.length; i++) {
                var el = registry[i];
                if (el && el !== keep && !el.paused) {
                    silenceMediaElement(el);
                }
            }
        }, 700);
    };

    StoreMusic.prototype.stopSoleSourceWatch = function () {
        if (this._soleSourceWatch) {
            global.clearInterval(this._soleSourceWatch);
            this._soleSourceWatch = 0;
        }
    };

    /**
     * Background BGM is allowed while the tab still exists. When the host tears down the
     * webview without pagehide (Cursor Browser close), DOM often disconnects or the window
     * collapses to 0×0 — then we must hard-stop so audio cannot outlive the UI.
     * Also run while *visible* playing: Cursor can close Simple Browser without visibilitychange,
     * leaving AudioService decoding with a zombie "visible" 0×0 webview.
     */
    StoreMusic.prototype.startOrphanWatch = function () {
        var self = this;
        this.stopOrphanWatch();
        this._collapsedHits = 0;
        var needHits = isEmbeddedBrowserHost() ? 1 : 2;
        this._orphanWatch = global.setInterval(function () {
            if (!self._docAlive) {
                if (currentStoreMusicEpoch() === self._epoch
                    && global.__WelineStoreMusicLive === self) {
                    try {
                        if (typeof silenceAllStoreMusicAudio === 'function') {
                            silenceAllStoreMusicAudio();
                        }
                    } catch (eOrphan) {
                        // ignore
                    }
                    self.hardSilenceMedia(true);
                    self.killAllPageAudio();
                }
                self.stopOrphanWatch();
                return;
            }
            try {
                var disconnected = !document.documentElement || !document.documentElement.isConnected
                    || (self.root && !self.root.isConnected);
                var collapsed = self.isCollapsedDetachedView();
                if (disconnected || collapsed) {
                    self._collapsedHits = (Number(self._collapsedHits) || 0) + 1;
                    // Fast path: Cursor Browser close must not keep singing for seconds.
                    if (self._collapsedHits < needHits) {
                        return;
                    }
                    self.tearDownForLeave(
                        disconnected ? 'orphan-disconnected' : 'host-detached'
                    );
                    return;
                }
                self._collapsedHits = 0;
                // Only heartbeat census when this is still a real surface.
                if (self.isRealOriginTab()) {
                    self.touchOriginTab();
                }
            } catch (e) {
                // ignore
            }
        }, 180);
    };

    StoreMusic.prototype.stopOrphanWatch = function () {
        if (this._orphanWatch) {
            global.clearInterval(this._orphanWatch);
            this._orphanWatch = 0;
        }
    };

    StoreMusic.prototype.pageHost = function () {
        return PAGE_HOST;
    };

    StoreMusic.prototype.originTabsKey = function () {
        return storageKey(this.root, 'origin_tabs.' + PAGE_HOST);
    };

    /** Detached/closed host webview — not a real browser tab of this domain.
     * Hidden (switched to another tab) is NOT collapsed: outer/inner size often stays normal.
     * Cursor/Electron Simple Browser close often skips pagehide; zombie webviews may stay
     * visibility=visible while chrome is 0×0 — still treat as detached.
     */
    StoreMusic.prototype.isCollapsedDetachedView = function () {
        try {
            if (!document.documentElement || !document.documentElement.isConnected) {
                return true;
            }
            if (this.root && !this.root.isConnected) {
                return true;
            }
            var ow = Number(global.outerWidth) || 0;
            var oh = Number(global.outerHeight) || 0;
            var iw = Number(global.innerWidth) || 0;
            var ih = Number(global.innerHeight) || 0;
            // 0×0 always means detached — even if visibility still reports "visible"
            // (Cursor/Electron closed the panel but left a singing webview).
            if ((ow === 0 && oh === 0) || (iw === 0 && ih === 0)) {
                return true;
            }
            if (document.body) {
                var bw = Number(document.body.clientWidth) || 0;
                var bh = Number(document.body.clientHeight) || 0;
                if (bw === 0 && bh === 0) {
                    return true;
                }
            }
            // Visible + nonzero chrome = real tab (or focused Simple Browser).
            if (document.visibilityState === 'visible') {
                return false;
            }
            // Hidden + nonzero: normal background tab — keep BGM.
            return false;
        } catch (e) {
            return true;
        }
    };

    /**
     * A live tab of THIS domain (location.host). Hidden is OK (user switched tab/app);
     * collapsed/disconnected/dead documents do not count.
     */
    StoreMusic.prototype.isRealOriginTab = function () {
        if (!this._docAlive) {
            return false;
        }
        return !this.isCollapsedDetachedView();
    };

    StoreMusic.prototype.readOriginTabs = function () {
        var map = {};
        try {
            var raw = global.localStorage.getItem(this.originTabsKey());
            map = raw ? JSON.parse(raw) : {};
        } catch (e) {
            map = {};
        }
        if (!map || typeof map !== 'object') {
            map = {};
        }
        var now = Date.now();
        var kept = {};
        Object.keys(map).forEach(function (id) {
            var row = map[id];
            var at = row && Number(row.at) ? Number(row.at) : 0;
            if (id && at && (now - at) < ORIGIN_TAB_TTL_MS) {
                kept[id] = {
                    at: at,
                    host: row.host || PAGE_HOST,
                    visible: row.visible === true,
                    dying: row.dying === true
                };
            }
        });
        return kept;
    };

    StoreMusic.prototype.writeOriginTabs = function (map) {
        try {
            global.localStorage.setItem(this.originTabsKey(), JSON.stringify(map || {}));
        } catch (e) {
            // ignore
        }
    };

    StoreMusic.prototype.touchOriginTab = function () {
        if (!this.isRealOriginTab()) {
            this.dropOriginTab();
            return;
        }
        var map = this.readOriginTabs();
        map[TAB_ID] = { at: Date.now(), host: PAGE_HOST, visible: document.visibilityState === 'visible' };
        this.writeOriginTabs(map);
        if (this.channel) {
            try {
                this.channel.postMessage({ type: 'tab_alive', tabId: TAB_ID, host: PAGE_HOST, at: Date.now() });
            } catch (e2) {
                // ignore
            }
        }
    };

    StoreMusic.prototype.dropOriginTab = function () {
        var map = this.readOriginTabs();
        if (map[TAB_ID]) {
            delete map[TAB_ID];
            this.writeOriginTabs(map);
        }
        if (this.channel) {
            try {
                this.channel.postMessage({ type: 'tab_dead', tabId: TAB_ID, host: PAGE_HOST, at: Date.now() });
            } catch (e) {
                // ignore
            }
        }
    };

    StoreMusic.prototype.countLiveOriginTabs = function () {
        return Object.keys(this.readOriginTabs()).length;
    };

    StoreMusic.prototype.hasLiveOriginTab = function () {
        return this.countLiveOriginTabs() > 0;
    };

    /**
     * Last tab of this domain is gone → every leftover context (zombie webview) must silence.
     */
    StoreMusic.prototype.silenceIfOriginEmpty = function () {
        if (this.isRealOriginTab()) {
            this.touchOriginTab();
        }
        if (this.hasLiveOriginTab()) {
            return false;
        }
        // Hard-silence leftovers only. Clearing want_play here races with full navigation
        // and permanently disables try_autoplay (intent becomes stop).
        this.broadcastForceStop(false);
        if (this.channel) {
            try {
                this.channel.postMessage({
                    type: 'domain_empty',
                    tabId: TAB_ID,
                    host: PAGE_HOST,
                    at: Date.now()
                });
            } catch (e) {
                // ignore
            }
        }
        this.tearDownForLeave('domain-empty');
        return true;
    };

    StoreMusic.prototype.startOriginTabWatch = function () {
        var self = this;
        this.stopOriginTabWatch();
        this.touchOriginTab();
        this._originTabWatch = global.setInterval(function () {
            if (self.isRealOriginTab()) {
                self._collapsedHits = 0;
                self.touchOriginTab();
                return;
            }
            // Require several consecutive "not a real tab" samples before dropping —
            // a single false 0×0 reading must not wipe the domain census / stop every page.
            self._collapsedHits = (Number(self._collapsedHits) || 0) + 1;
            if (self._collapsedHits < 4) {
                return;
            }
            self.dropOriginTab();
            if (!self.hasLiveOriginTab()) {
                self.hardSilenceMedia(true);
                self.killAllPageAudio();
                self.syncPlayingUi(false);
                self.tearDownForLeave('origin-tabs-empty');
            }
        }, 1000);
    };

    StoreMusic.prototype.stopOriginTabWatch = function () {
        if (this._originTabWatch) {
            global.clearInterval(this._originTabWatch);
            this._originTabWatch = 0;
        }
    };

    /**
     * Soft autoplay/resume: need a live tab of this domain. User gesture always wins.
     */
    StoreMusic.prototype.canAudiblyStart = function (forceUser) {
        if (forceUser) {
            this._docAlive = true;
            this.touchOriginTab();
            return true;
        }
        if (!this._docAlive) {
            return false;
        }
        if (!this.hasLiveOriginTab()) {
            return false;
        }
        if (document.visibilityState === 'hidden') {
            return !!this._heardWhileVisible;
        }
        return true;
    };

    StoreMusic.prototype.markHeardWhileVisible = function () {
        if (document.visibilityState !== 'hidden') {
            this._heardWhileVisible = true;
        }
    };

    /** Mark that this tab is (or was just) audibly owning BGM — drives soft-resume idle gate. */
    StoreMusic.prototype.touchAudible = function () {
        var now = Date.now();
        this._lastAudibleAt = now;
        try {
            writePref(this.root, 'last_audible_at', String(now));
        } catch (e) {
            // ignore
        }
        this.markHeardWhileVisible();
    };

    /**
     * Shared play intent is global, but audible ownership is local. Only the document
     * that owns a currently playing StoreMusic element may arm navigation resume.
     */
    StoreMusic.prototype.isAudibleOwner = function () {
        if (!this._docAlive || !this.audio || this.audio.paused) {
            return false;
        }
        return !!(
            this.isLeader
            || this.audio.__welineStoreMusicOwner === this
        );
    };

    StoreMusic.prototype.navResumeKey = function () {
        return storageKey(this.root, 'nav_resume');
    };

    /**
     * F5 / hard-nav: session latch so the successor page sticky-resumes even if localStorage
     * want_play races with tearDown. Survives same-tab refresh; dies with the tab.
     */
    StoreMusic.prototype.armNavResumeLatch = function () {
        // Shared want_play alone is not enough (quiet peer). Arm when THIS document is
        // actually decoding, including leader-or-owner and plain unmuted/muted play.
        var decoding = !!(this.audio && !this.audio.paused && this.playIntent() === 'play');
        if (!this.isAudibleOwner() && !decoding) {
            return false;
        }
        this.setWantPlay(true);
        this.setUserStopped(false);
        this.touchAudible();
        try {
            if (global.sessionStorage) {
                global.sessionStorage.setItem(this.navResumeKey(), String(Date.now()));
            }
        } catch (e) {
            // ignore
        }
        return true;
    };

    /**
     * @returns {boolean} true when this document should force sticky resume after refresh
     */
    StoreMusic.prototype.consumeNavResumeLatch = function () {
        try {
            if (!global.sessionStorage) {
                return false;
            }
            var key = this.navResumeKey();
            var raw = global.sessionStorage.getItem(key);
            global.sessionStorage.removeItem(key);
            if (!raw) {
                return false;
            }
            var at = Number(raw) || 0;
            // Only honor a latch from the last few seconds (refresh), not a stale tab restore.
            return !at || (Date.now() - at) < 45000;
        } catch (e) {
            return false;
        }
    };

    /** Same-tab refresh / bfcache restore: skip the delay, never bypass a live peer lock. */
    StoreMusic.prototype.beginNavResumeBypass = function () {
        this._navResumeActive = true;
        // Kept for compatibility with older boot state; a resume latch is not a takeover.
        this._ignorePeerOnce = false;
        this._heardWhileVisible = true;
    };

    StoreMusic.prototype.clearNavResumeBypass = function () {
        this._navResumeActive = false;
        this._ignorePeerOnce = false;
    };

    /**
     * Cross-page audible clock: memory first, then persisted stamp, then progress.updated.
     * New documents used to see _lastAudibleAt=0 and treat any sticky want_play as "fresh".
     */
    StoreMusic.prototype.readLastAudibleAt = function () {
        var mem = Number(this._lastAudibleAt) || 0;
        var stored = 0;
        try {
            stored = Number(readPref(this.root, 'last_audible_at', 0)) || 0;
        } catch (e) {
            stored = 0;
        }
        var snapAt = 0;
        try {
            var snap = readJsonPref(this.root, 'progress', null);
            snapAt = snap && Number(snap.updated) ? Number(snap.updated) : 0;
        } catch (e2) {
            snapAt = 0;
        }
        return Math.max(mem, stored, snapAt);
    };

    /**
     * True when a persisted audible clock exists and is older than the soft-resume window.
     * Missing clock must NOT count as stale — otherwise refresh clears want_play and kills续播.
     */
    StoreMusic.prototype.isAudibleClockStale = function () {
        var last = this.readLastAudibleAt();
        if (!last) {
            return false;
        }
        return (Date.now() - last) > SOFT_RESUME_IDLE_MS;
    };

    /**
     * Soft resume (visibility / peer release) only while want_play is fresh enough.
     * No audible clock → still allow (legacy / just-played before last_audible_at existed).
     */
    StoreMusic.prototype.canSoftResumeNow = function () {
        if (this._naturalEnded || this.isUserStopped() || this.isDismissed()) {
            return false;
        }
        if (this.playIntent() !== 'play') {
            return false;
        }
        return !this.isAudibleClockStale();
    };

    /**
     * Drop sticky want_play after a known long silence (clock present + stale).
     * Clears to unset (remove key) — never write want_play=0, or cold try_autoplay
     * reaches tryPlay with playIntent==='stop' and silently no-ops (进店不播).
     * @param {{silent?: boolean}} [opts] silent=true: do not flash「点击开启音乐」(F5 sticky path).
     * @returns {boolean} true when want was expired
     */
    StoreMusic.prototype.expireStaleWantPlay = function (opts) {
        if (this.playIntent() !== 'play') {
            return false;
        }
        if (!this.isAudibleClockStale()) {
            return false;
        }
        try {
            global.localStorage.removeItem(storageKey(this.root, 'want_play'));
        } catch (e) {
            writePref(this.root, 'want_play', '');
        }
        if (!(opts && opts.silent)) {
            this.setNeedGesture(true);
        }
        return true;
    };

    StoreMusic.prototype.leaderLockKey = function () {
        return storageKey(this.root, 'leader_lock');
    };

    StoreMusic.prototype.readLeaderLock = function () {
        try {
            var raw = String(global.localStorage.getItem(this.leaderLockKey()) || '');
            if (!raw) {
                return null;
            }
            var parts = raw.split('|');
            return {
                tabId: parts[0] || '',
                at: Number(parts[1]) || 0
            };
        } catch (e) {
            return null;
        }
    };

    /**
     * @param {boolean} [force] user gesture on this page → always steal leadership
     * @returns {boolean}
     */
    StoreMusic.prototype.acquireLeaderLock = function (force) {
        var now = Date.now();
        if (!force) {
            // Soft autoplay/resume: another *live* tab is actively leading → stay quiet here.
            if (this.hasActivePeerLeader()) {
                return false;
            }
        }
        try {
            global.localStorage.setItem(this.leaderLockKey(), TAB_ID + '|' + now);
        } catch (e) {
            // Soft: still allow play if storage blocked.
        }
        return true;
    };

    StoreMusic.prototype.startLeaderHeartbeat = function () {
        var self = this;
        this.stopLeaderHeartbeat();
        this._leaderBeat = global.setInterval(function () {
            if (!self.isLeader || self.playIntent() !== 'play' || self._naturalEnded) {
                return;
            }
            var playing = !!(self.audio && !self.audio.paused);
            var last = Number(self._lastAudibleAt) || 0;
            var stallGrace = last && (Date.now() - last) < 20000;
            // Keep lock during short stalls/track gaps so soft autoplay on another page
            // cannot steal and force_stop this tab (heard as「播一阵就停」).
            if (!playing && !stallGrace) {
                return;
            }
            if (playing) {
                self.touchAudible();
            }
            // Background BGM is intentional: keep the lock alive while still playing so
            // other same-site soft-autoplay pages stay quiet. Leave-page teardown is pagehide.
            var cur = self.readLeaderLock();
            if (cur && cur.tabId && cur.tabId !== TAB_ID) {
                self.yieldToOtherTab({ tabId: cur.tabId, at: cur.at });
                return;
            }
            try {
                global.localStorage.setItem(self.leaderLockKey(), TAB_ID + '|' + Date.now());
            } catch (e) {
                // ignore
            }
            if (playing) {
                self.broadcastLeaderState('playing', true);
            }
        }, 1000);
    };

    StoreMusic.prototype.stopLeaderHeartbeat = function () {
        if (this._leaderBeat) {
            global.clearInterval(this._leaderBeat);
            this._leaderBeat = 0;
        }
    };

    /**
     * @param {string} [type]
     * @param {boolean} [asHeartbeat] true only for the 1s leader pulse — never for track switch / takeover
     */
    StoreMusic.prototype.broadcastLeaderState = function (type, asHeartbeat) {
        if (!this.channel) {
            return;
        }
        var track = this.currentTrack();
        // Heartbeat must NEVER use Date.now() — a fresh stamp would outrank a newer user op
        // on another page and block takeover (sticky「已在其他页面播放」).
        var at = this.localOperatorAt();
        if (!at) {
            at = Number(this._leaderClaimedAt) || 1;
        }
        var kind = type || 'playing';
        var heartbeat = kind === 'playing' ? !!asHeartbeat : false;
        try {
            this.channel.postMessage({
                type: kind,
                heartbeat: heartbeat,
                tabId: TAB_ID,
                host: PAGE_HOST,
                at: at,
                url: track ? track.url : '',
                index: this.trackIndex,
                time: this.audio ? (Number(this.audio.currentTime) || 0) : (Number(this._pendingSeek) || 0),
                want_play: this.playIntent() === 'play'
            });
        } catch (e) {
            // ignore
        }
    };

    /**
     * Count other tabs that report visibilityState=visible in the origin census.
     * Hidden/dying documents (refresh race) must not count as live peers.
     */
    StoreMusic.prototype.countVisiblePeerTabs = function () {
        var tabs = this.readOriginTabs();
        var n = 0;
        Object.keys(tabs).forEach(function (id) {
            if (id === TAB_ID) {
                return;
            }
            if (tabs[id] && tabs[id].visible === true) {
                n += 1;
            }
        });
        return n;
    };

    /**
     * Refresh / same-tab resume: clear leftover leader_lock only when NO other *fresh*
     * tab remains in the origin census (true solo). Stale dying-refresh rows (no heartbeat
     * within PEER_CENSUS_FRESH_MS) must not block F5 resume. Must not clear a background
     * tab's lock while it still heartbeats (even visibility=hidden BGM).
     */
    StoreMusic.prototype.clearStaleLeaderForSoloResume = function () {
        if (this.playIntent() !== 'play' && !this.hasResumeSnap()) {
            return;
        }
        var now = Date.now();
        var tabs = this.readOriginTabs();
        var otherFresh = Object.keys(tabs).filter(function (id) {
            return id !== TAB_ID && isFreshPeerCensusRow(tabs[id], now);
        });
        if (otherFresh.length > 0) {
            return;
        }
        try {
            global.localStorage.removeItem(this.leaderLockKey());
        } catch (e) {
            // ignore
        }
    };

    /**
     * True when another *live* tab holds a fresh leader lock (soft autoplay stays quiet).
     * Background/hidden tabs that still heartbeat while playing MUST count — otherwise a
     * newly opened page clears the lock and starts a second audible source.
     * Dying F5 leftovers stay in ORIGIN_TAB_TTL but stop heartbeating → not fresh → not peer.
     */
    StoreMusic.prototype.hasActivePeerLeader = function () {
        // A navigation latch only bypasses the normal delay. It must never remove or
        // ignore a live peer lock, otherwise a quiet refreshed tab becomes a new source.
        var cur = this.readLeaderLock();
        if (!cur || !cur.tabId || cur.tabId === TAB_ID) {
            return false;
        }
        if ((Date.now() - (Number(cur.at) || 0)) >= 2500) {
            return false;
        }
        var now = Date.now();
        var tabs = this.readOriginTabs();
        var row = tabs[cur.tabId];
        if (!row || !isFreshPeerCensusRow(row, now) || row.dying) {
            // Lock holder already left / dying refresh (no recent heartbeat) — stale lock.
            try {
                global.localStorage.removeItem(this.leaderLockKey());
            } catch (e) {
                // ignore
            }
            return false;
        }
        // Holder still heartbeating in census (visible or hidden background BGM) → peer is live.
        return true;
    };

    /**
     * Soft/cold autoplay blocked by a live peer — retry once after lock TTL.
     * skipDelay on retry: cold path already waited delay_seconds before the peer block.
     */
    StoreMusic.prototype.armPeerLeaderRetry = function () {
        var self = this;
        if (this._peerRetryArmed) {
            return;
        }
        this._peerRetryArmed = true;
        global.setTimeout(function () {
            self._peerRetryArmed = false;
            if (!self._docAlive) {
                return;
            }
            if (self.audio && !self.audio.paused) {
                return;
            }
            if (self.isUserStopped() || self.isDismissed() || self._naturalEnded) {
                return;
            }
            self.expireStaleWantPlay({ silent: true });
            var intent = self.playIntent();
            var sticky = intent === 'play';
            var cold = intent === 'unset'
                && self.cfg.try_autoplay !== false
                && self.cfg.try_autoplay !== 0
                && self.cfg.try_autoplay !== '0';
            if (!sticky && !cold) {
                return;
            }
            if (self.hasActivePeerLeader()) {
                return;
            }
            self.syncRemotePlayingUi(false);
            self.scheduleStart({ force: true, skipDelay: true });
        }, 2800);
    };

    /**
     * F5 sticky resume: AbortError / dying-lock / census races must retry quietly.
     * Only after retries are exhausted may we show「点击开启音乐」.
     */
    StoreMusic.prototype.armStickyPlayRetry = function () {
        var self = this;
        if (this._stickyRetryArmed) {
            return;
        }
        var left = Number(this._stickyRetryLeft);
        if (!Number.isFinite(left)) {
            left = 3;
            this._stickyRetryLeft = 3;
        }
        if (left <= 0) {
            this._autoplayPending = true;
            this.setNeedGesture(true);
            this.syncHintVisibility();
            return;
        }
        this._stickyRetryArmed = true;
        this._stickyRetryLeft = left - 1;
        global.setTimeout(function () {
            self._stickyRetryArmed = false;
            if (!self._docAlive || self.isUserStopped() || self.isDismissed() || self._naturalEnded) {
                return;
            }
            if (self.audio && !self.audio.paused) {
                self._stickyRetryLeft = 0;
                return;
            }
            if (self.playIntent() !== 'play' && !self.hasResumeSnap()) {
                self.setNeedGesture(true);
                return;
            }
            self._docAlive = true;
            self.touchOriginTab();
            self.beginNavResumeBypass();
            self.clearStaleLeaderForSoloResume();
            if (self.hasActivePeerLeader()) {
                self.armPeerLeaderRetry();
                self.armStickyPlayRetry();
                return;
            }
            self.tryPlay({ force: false, navResume: true }).catch(function () {
                self.armStickyPlayRetry();
            });
        }, 280);
    };

    /** True when localStorage operator stamp still points at this tab. */
    StoreMusic.prototype.isLatestOperator = function () {
        try {
            var raw = String(global.localStorage.getItem(storageKey(this.root, 'operator')) || '');
            return !!raw && raw.indexOf(TAB_ID + '|') === 0;
        } catch (e) {
            return false;
        }
    };

    /** Cross-tab nag only when the panel is closed and the user is not mid-gesture. */
    StoreMusic.prototype.shouldShowOtherTabStatus = function () {
        if (this.panelOpen) {
            return false;
        }
        if (this.isUserOperating()) {
            return false;
        }
        if (document.hasFocus && document.hasFocus()) {
            return false;
        }
        return true;
    };

    /**
     * @param {boolean} [force] true when user operates on this page (latest operator wins)
     */
    StoreMusic.prototype.claimLeadership = function (force) {
        if (!this.acquireLeaderLock(!!force)) {
            if (!force) {
                this.yieldToOtherTab();
            }
            return false;
        }
        this.isLeader = true;
        this._leaderClaimedAt = Date.now();
        this.startLeaderHeartbeat();
        // Claim / takeover is never a heartbeat — peers must treat this as a real track claim.
        this.broadcastLeaderState('playing', false);
        // Explicitly tell other pages to silence residual MediaElements (latest operator wins).
        if (force) {
            this.broadcastForceStop(false);
        }
        writePref(this.root, 'leader', TAB_ID);
        return true;
    };

    /**
     * Broadcast an authoritative stop so every other page drops residual audio.
     * @param {boolean} clearWantPlay also clear cross-page resume intent on peers
     */
    StoreMusic.prototype.broadcastForceStop = function (clearWantPlay) {
        if (!this.channel) {
            return;
        }
        var at = Math.max(Date.now(), Number(this._lastUserActionAt) || 0, Number(this._leaderClaimedAt) || 0);
        var track = this.currentTrack();
        try {
            this.channel.postMessage({
                type: 'force_stop',
                tabId: TAB_ID,
                host: PAGE_HOST,
                at: at,
                clearWantPlay: !!clearWantPlay,
                playing: this.isAudibleOwner(),
                // Carry selection so peers can sync the playlist UI even if the following
                // playing message is dropped or treated as a soft heartbeat.
                url: track ? track.url : '',
                index: this.trackIndex,
                time: this.audio ? (Number(this.audio.currentTime) || 0) : (Number(this._pendingSeek) || 0)
            });
        } catch (e) {
            // ignore
        }
    };

    StoreMusic.prototype.releaseLeadership = function () {
        this.stopLeaderHeartbeat();
        var held = false;
        try {
            var cur = this.readLeaderLock();
            held = !!(cur && cur.tabId === TAB_ID);
            if (held) {
                global.localStorage.removeItem(this.leaderLockKey());
            }
        } catch (e) {
            // ignore
        }
        if (!this.isLeader && !held) {
            return;
        }
        this.isLeader = false;
        if (this.channel) {
            try {
                this.channel.postMessage({ type: 'released', tabId: TAB_ID, at: Date.now() });
            } catch (e) {
                // ignore
            }
        }
        if (readPref(this.root, 'leader', '') === TAB_ID) {
            writePref(this.root, 'leader', '');
        }
    };

    StoreMusic.prototype.applyRemoteSelection = function (msg) {
        if (!msg) {
            return false;
        }
        var before = this.trackIndex;
        var beforeUrl = '';
        var beforeTrack = this.currentTrack();
        if (beforeTrack && beforeTrack.url) {
            beforeUrl = String(beforeTrack.url);
        }
        var idx = Number(msg.index);
        if (Number.isFinite(idx) && idx >= 0 && idx < this.tracks.length) {
            this.trackIndex = idx;
        } else if (msg.url) {
            for (var i = 0; i < this.tracks.length; i++) {
                if (trackUrlsEqual(this.tracks[i].url, msg.url)) {
                    this.trackIndex = i;
                    break;
                }
            }
        }
        var t = Number(msg.time);
        if (Number.isFinite(t) && t >= 0) {
            this._pendingSeek = t;
        }
        this.syncPlaylistActive();
        this.syncTrackMeta(false);
        var afterTrack = this.currentTrack();
        var afterUrl = afterTrack && afterTrack.url ? String(afterTrack.url) : '';
        return before !== this.trackIndex || !trackUrlsEqual(beforeUrl, afterUrl);
    };

    /** True when msg points at a different playlist row than this page currently shows. */
    StoreMusic.prototype.selectionDiffersFromMessage = function (msg) {
        if (!msg) {
            return false;
        }
        var idx = Number(msg.index);
        if (Number.isFinite(idx) && idx >= 0 && idx < this.tracks.length && idx !== this.trackIndex) {
            return true;
        }
        if (msg.url) {
            var cur = this.currentTrack();
            if (!cur || !trackUrlsEqual(cur.url, msg.url)) {
                return true;
            }
        }
        return false;
    };

    /** True when the live MediaElement src matches the selected playlist row. */
    StoreMusic.prototype.audioMatchesSelection = function () {
        var track = this.currentTrack();
        if (!track || !track.url) {
            return true;
        }
        if (!this.audio) {
            return false;
        }
        var src = this.audio.getAttribute('data-store-music-src') || this.audio.currentSrc || this.audio.src || '';
        return trackUrlsEqual(src, track.url);
    };

    /**
     * Playlist UI and audible MediaElement must stay one state.
     * @param {boolean} [forcePlay] switch even when currently paused (user/op takeover)
     */
    StoreMusic.prototype.reconcileAudibleSelection = function (forcePlay) {
        if (!this._docAlive || this.playIntent() !== 'play') {
            return;
        }
        if (!this.canAudiblyStart(!!forcePlay)) {
            return;
        }
        var audible = !!(this.audio && !this.audio.paused);
        if (!forcePlay && !audible && !this.isLeader) {
            return;
        }
        if (this.audioMatchesSelection() && audible) {
            return;
        }
        this.playCurrentTrackNow();
    };

    StoreMusic.prototype.yieldToOtherTab = function (msg) {
        // Latest user operation wins. Only refuse if WE are still newer than the remote claim.
        if (this.canRefuseYield(msg)) {
            this.clearRemotePlayingState();
            this.claimLeadership(true);
            // If the remote carried a newer selection we refused, still follow OUR selection audibly.
            this.reconcileAudibleSelection(true);
            setStatus(this.root, '');
            return;
        }
        // Remote is authoritative (or we are background): hard-drop residual MediaElement.
        this.silenceForOtherTab(false, true);
        if (msg) {
            this.applyRemoteSelection(msg);
        }
        // Mirror the remote state in controls only; this document remains silent.
        this.syncRemotePlayingUi(true, msg || this.readLeaderLock());
        // Soft load / open panel: never sticky-nag — user can press ▶ to take over (last op wins).
        if (this.shouldShowOtherTabStatus()) {
            setStatus(this.root, textOf(this.root, '[data-store-music-i18n-other-tab]', '已在其他页面播放'));
        } else {
            setStatus(this.root, '');
        }
    };

    /**
     * After another tab releases leadership, resume here without stealing if still wanted.
     * Fixes "must enter the tab to hear" after the previous leader closed.
     */
    StoreMusic.prototype.maybeResumeAfterPeerRelease = function () {
        var self = this;
        this.syncRemotePlayingUi(false);
        if (document.visibilityState === 'hidden') {
            return;
        }
        if (this.expireStaleWantPlay() || !this.canSoftResumeNow()) {
            return;
        }
        if (this.playIntent() !== 'play') {
            return;
        }
        if (this._naturalEnded) {
            return;
        }
        if (this.audio && !this.audio.paused) {
            return;
        }
        global.setTimeout(function () {
            if (document.visibilityState === 'hidden' || self.playIntent() !== 'play' || self._naturalEnded) {
                return;
            }
            if (self.expireStaleWantPlay() || !self.canSoftResumeNow()) {
                return;
            }
            if (self.audio && !self.audio.paused) {
                return;
            }
            if (!self.acquireLeaderLock(false) || self.hasActivePeerLeader()) {
                return;
            }
            setStatus(self.root, '');
            self.tryPlay({ force: false });
        }, 60);
    };

    StoreMusic.prototype.currentTrack = function () {
        if (!this.tracks.length) {
            return null;
        }
        var i = this.trackIndex % this.tracks.length;
        if (i < 0) {
            i += this.tracks.length;
        }
        return this.tracks[i] || null;
    };

    StoreMusic.prototype.syncSkipButtons = function () {
        var multi = this.tracks.length > 1;
        if (this.prevBtn) {
            this.prevBtn.hidden = !multi;
        }
        if (this.nextBtn) {
            this.nextBtn.hidden = !multi;
        }
    };

    StoreMusic.prototype.trackLabel = function (track) {
        if (!track) {
            return textOf(this.root, '[data-store-music-i18n-idle]', '进店音乐');
        }
        if (track.title) {
            return track.title;
        }
        return titleFromUrl(track.url);
    };

    StoreMusic.prototype.syncVolumePct = function (value) {
        if (!this.volumePct) {
            return;
        }
        var n = Math.max(0, Math.min(100, Math.round(Number(value) || 0)));
        this.volumePct.textContent = n + '%';
    };

    StoreMusic.prototype.renderPlaylist = function () {
        if (!this.playlistEl) {
            return;
        }
        var self = this;
        var multi = this.tracks.length > 1;
        if (this.compartmentEl) {
            this.compartmentEl.hidden = !multi;
        }
        if (!multi && this.searchInput) {
            this.searchInput.value = '';
            this.playlistFilter = '';
        }
        var query = String(this.playlistFilter || '').trim().toLowerCase();
        var visible = 0;
        if (this.playlistCountEl) {
            if (multi) {
                var countTpl = textOf(this.root, '[data-store-music-i18n-playlist-count]', '共 {n} 首');
                var countN = this.tracks.length;
                if (query) {
                    countN = 0;
                    this.tracks.forEach(function (track) {
                        if (self.trackMatchesFilter(track, query)) {
                            countN += 1;
                        }
                    });
                }
                this.playlistCountEl.textContent = String(countTpl).split('{n}').join(String(countN));
            } else {
                this.playlistCountEl.textContent = '';
            }
        }
        this.playlistEl.textContent = '';
        if (!multi) {
            if (this.searchEmptyEl) {
                this.searchEmptyEl.hidden = true;
            }
            return;
        }
        try {
            this.tracks.forEach(function (track, index) {
                if (query && !self.trackMatchesFilter(track, query)) {
                    return;
                }
                visible += 1;
                var li = document.createElement('li');
                li.setAttribute('role', 'none');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'w-store-music__track';
                btn.setAttribute('role', 'option');
                btn.setAttribute('data-store-music-track', String(index));
                btn.setAttribute('data-testid', 'store-music-track-' + index);
                btn.setAttribute('aria-selected', index === self.trackIndex ? 'true' : 'false');
                if (index === self.trackIndex) {
                    btn.classList.add('is-active');
                }
                var title = document.createElement('span');
                title.className = 'w-store-music__track-title';
                title.textContent = self.trackLabel(track);
                btn.appendChild(title);
                var mark = document.createElement('span');
                mark.className = 'w-store-music__track-mark';
                mark.setAttribute('data-store-music-track-mark', '1');
                if (index === self.trackIndex) {
                    mark.textContent = '✓';
                } else {
                    var n = index + 1;
                    mark.textContent = n < 10 ? '0' + n : String(n);
                }
                btn.appendChild(mark);
                if (track.intro) {
                    var intro = document.createElement('span');
                    intro.className = 'w-store-music__track-intro';
                    intro.textContent = track.intro;
                    btn.appendChild(intro);
                    var introToggle = document.createElement('button');
                    introToggle.type = 'button';
                    introToggle.className = 'w-store-music__track-intro-toggle';
                    introToggle.setAttribute('data-store-music-track-intro-toggle', '1');
                    introToggle.setAttribute('aria-expanded', 'false');
                    // Length heuristic: webkit-line-clamp often reports equal scroll/client heights.
                    introToggle.hidden = String(track.intro).trim().length < 18;
                    introToggle.textContent = textOf(self.root, '[data-store-music-i18n-intro-expand]', '展开简介');
                    introToggle.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        ev.stopPropagation();
                        var open = !btn.classList.contains('is-intro-expanded');
                        btn.classList.toggle('is-intro-expanded', open);
                        introToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                        introToggle.textContent = open
                            ? textOf(self.root, '[data-store-music-i18n-intro-collapse]', '收起简介')
                            : textOf(self.root, '[data-store-music-i18n-intro-expand]', '展开简介');
                    });
                    btn.appendChild(introToggle);
                    global.requestAnimationFrame(function () {
                        if (introToggle.hidden && intro.scrollHeight > intro.clientHeight + 1) {
                            introToggle.hidden = false;
                        }
                    });
                }
                btn.addEventListener('click', function () {
                    self.selectTrack(index);
                });
                li.appendChild(btn);
                self.playlistEl.appendChild(li);
            });
        } catch (e) {
            // Soft: playlist chrome is optional; transport still works.
        }
        if (this.searchEmptyEl) {
            this.searchEmptyEl.hidden = !(query && visible === 0);
        }
        if (this.playlistEl) {
            this.playlistEl.hidden = !!(query && visible === 0);
        }
    };

    StoreMusic.prototype.trackMatchesFilter = function (track, query) {
        var q = String(query || '').trim().toLowerCase();
        if (!q) {
            return true;
        }
        var hay = String(this.trackLabel(track) || '') + ' ' + String((track && track.intro) || '');
        return hay.toLowerCase().indexOf(q) !== -1;
    };

    StoreMusic.prototype.onPlaylistSearchInput = function () {
        if (!this.searchInput) {
            return;
        }
        this.playlistFilter = String(this.searchInput.value || '');
        this.renderPlaylist();
        this.syncPlaylistActive();
    };

    StoreMusic.prototype.syncPlaylistActive = function () {
        if (!this.playlistEl) {
            return;
        }
        var buttons = this.playlistEl.querySelectorAll('[data-store-music-track]');
        for (var i = 0; i < buttons.length; i++) {
            var btn = buttons[i];
            var trackIndex = Number(btn.getAttribute('data-store-music-track'));
            var active = trackIndex === this.trackIndex;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
            var mark = btn.querySelector('[data-store-music-track-mark]');
            if (mark) {
                if (active) {
                    mark.textContent = '✓';
                } else {
                    var n = (Number.isFinite(trackIndex) ? trackIndex : i) + 1;
                    mark.textContent = n < 10 ? '0' + n : String(n);
                }
            }
        }
    };

    StoreMusic.prototype.selectTrack = function (index) {
        var next = Number(index);
        if (!Number.isFinite(next) || next < 0 || next >= this.tracks.length) {
            return;
        }
        this.markUserOperating();
        this._naturalEnded = false;
        if (next === this.trackIndex && this.audio && !this.audio.paused && this.isLeader) {
            this.syncTrackMeta(true);
            setStatus(this.root, '');
            return;
        }
        // Flush previous track position, then lock the newly selected track for refresh resume.
        if (this.audio && (Number(this.audio.currentTime) || 0) >= 0.5) {
            this.saveProgress();
        }
        this.trackIndex = next;
        this._pendingSeek = 0;
        this.persistSelection(0);
        this.setWantPlay(true);
        this.setDismissed(false);
        this.waveConnected = false;
        this.source = null;
        this.analyser = null;
        this.syncPlaylistActive();
        this.syncTrackMeta(true);
        setStatus(this.root, '');
        // CRITICAL: play inside the click stack (no IndexedDB await) so the browser
        // still treats this as a user gesture — otherwise other pages stay silent.
        this.playCurrentTrackNow();
        // Belt-and-suspenders: if a race left UI ahead of MediaElement, force follow.
        if (!this.audioMatchesSelection()) {
            this.reconcileAudibleSelection(true);
        }
    };

    /**
     * Soft: pause singleton for src swap (keep element + WebAudio graph).
     * Hard: destroy shared graph + MediaElement (leave-page / orphan teardown only).
     */
    StoreMusic.prototype.discardAudioElement = function (opts) {
        var hard = !!(opts && opts.hard);
        this.stopWaveLoop();
        if (typeof this.resetAvatarLevel === 'function') {
            this.resetAvatarLevel();
        }
        if (hard) {
            destroySharedAudioGraph();
            this.ctx = null;
            this.source = null;
            this.analyser = null;
            this.waveConnected = false;
            if (this._blobUrl) {
                try {
                    URL.revokeObjectURL(this._blobUrl);
                } catch (e2) {
                    // ignore
                }
                this._blobUrl = null;
            }
            this.audio = null;
            this.audioReady = false;
            return;
        }
        // Soft path — never close AudioContext / never new Audio() on track switch.
        if (this.audio) {
            try {
                this.audio.pause();
            } catch (ePause) {
                // ignore
            }
        }
        this.audioReady = false;
    };

    /**
     * Synchronously mount network src + play. Cache to IDB in background only.
     * Must be called from a user gesture (click/tap) for cross-page takeover.
     */
    StoreMusic.prototype.playCurrentTrackNow = function () {
        var self = this;
        var track = this.currentTrack();
        var url = track ? String(track.url || '').trim() : '';
        if (!url) {
            return Promise.reject(new Error('no track'));
        }
        this._docAlive = true;
        this.stopLeaveHammer();
        this.touchOriginTab();
        if (!this._docAlive || !this.canAudiblyStart(true)) {
            return Promise.resolve(null);
        }
        this._audioMutating = true;
        this.markUserOperating();
        this.claimLeadership(true);
        setStatus(this.root, '');
        // Sole audible source: bump generation, soft-pause singleton, kill orphan Audios only.
        var playGen = nextPlayGeneration();
        this._playGen = playGen;
        this.discardAudioElement({ hard: false });
        var audio = this.mountAudioElement();
        killRivalAudioElements(audio);
        audio.__welineStoreMusicOwner = this;
        registerStoreAudio(audio);
        global.__WelineStoreMusicSharedAudio = audio;
        var multi = this.tracks.length > 1;
        var loopOne = !multi && !(this.cfg.loop === false || this.cfg.loop === 0 || this.cfg.loop === '0');
        audio.loop = !!loopOne;
        var vol = this.volume ? Number(this.volume.value) : Number(this.cfg.default_volume || 8);
        audio.volume = Math.max(0, Math.min(1, vol / 100));
        audio.muted = false;
        this._awaitingUnmute = false;
        // Reuse the same MediaElement — only swap src (no parallel decoders).
        audio.setAttribute('data-store-music-src', url);
        audio.src = url;
        this.audioReady = true;
        this.syncTrackMeta(true);
        this.syncPlaylistActive();
        this.applyPendingSeek();
        var playResult = null;
        try {
            playResult = audio.play();
        } catch (err) {
            this._audioMutating = false;
            this.setNeedGesture(true);
            return Promise.reject(err);
        }
        this.cacheTrackInBackground(url);
        var finishOk = function () {
            if (self._playGen !== playGen || currentPlayGeneration() !== playGen) {
                try {
                    audio.pause();
                } catch (eStale) {
                    // ignore
                }
                return null;
            }
            enforceSoleAudibleSource(audio);
            self.setWantPlay(true);
            self.needGesture = false;
            self.touchAudible();
            self.syncPlayingUi(true);
            self.broadcastLeaderState('playing', false);
            self.broadcastForceStop(false);
            setStatus(self.root, '');
            return audio;
        };
        if (!playResult || typeof playResult.then !== 'function') {
            var outSync = finishOk();
            this._audioMutating = false;
            return Promise.resolve(outSync);
        }
        return playResult.then(function () {
            return finishOk();
        }).catch(function (err) {
            var name = err && err.name ? String(err.name) : '';
            setStatus(self.root, '');
            self.setNeedGesture(true);
            if (name !== 'NotAllowedError' && name !== 'NotSupportedError') {
                setStatus(self.root, textOf(self.root, '[data-store-music-i18n-play-error]', '音频加载失败，请换一首曲目或格式'));
            }
            return audio;
        }).then(function (out) {
            self._audioMutating = false;
            return out;
        });
    };

    StoreMusic.prototype.cacheTrackInBackground = function (url) {
        if (!url) {
            return;
        }
        warmIdbAudio(normalizeTrackUrl(url) || url, url);
    };

    StoreMusic.prototype.mountAudioElement = function () {
        var audio = getSharedAudioSingleton();
        if (this.audio && this.audio !== audio) {
            silenceMediaElement(this.audio);
        }
        this.audio = audio;
        audio.__welineStoreMusicOwner = this;
        registerStoreAudio(audio);
        global.__WelineStoreMusicSharedAudio = audio;
        this.bindAudioElementEvents(audio);
        return audio;
    };

    /**
     * Bind MediaElement lifecycle once. Accidental play must NEVER force-steal leadership
     * (that caused residual dual-track playback after a newer tab already won).
     */
    StoreMusic.prototype.bindAudioElementEvents = function (audio) {
        var self = this;
        if (!audio || audio.__welineStoreMusicBound) {
            return;
        }
        audio.__welineStoreMusicBound = true;
        audio.addEventListener('play', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            if (!owner._docAlive) {
                try {
                    audio.pause();
                } catch (e0) {
                    // ignore
                }
                owner.hardSilenceMedia(true);
                owner.killAllPageAudio();
                return;
            }
            // Natural end / ❚❚ / dismiss must not be revived by a late play() event.
            if (owner._naturalEnded || owner.isUserStopped() || owner.isDismissed()) {
                try {
                    audio.pause();
                } catch (eStop) {
                    // ignore
                }
                owner.hardSilenceMedia(false);
                owner.syncPlayingUi(false);
                return;
            }
            if (document.visibilityState === 'hidden' && !owner._heardWhileVisible) {
                try {
                    audio.pause();
                } catch (eH) {
                    // ignore
                }
                owner.hardSilenceMedia(false);
                owner.killAllPageAudio();
                owner.syncPlayingUi(false);
                return;
            }
            var force = !!owner.isUserOperating();
            if (!owner.claimLeadership(force)) {
                // Mid-gesture / open panel: force-steal so "where the user switches" always wins.
                if (force || owner.panelOpen || owner.isLatestOperator()) {
                    if (owner.claimLeadership(true)) {
                        owner.markHeardWhileVisible();
                        owner.setWantPlay(true);
                        owner.syncPlayingUi(true);
                        enforceSoleAudibleSource(audio);
                        setStatus(owner.root, '');
                        owner.ensureAnalyser();
                        owner.updateWaveLoop();
                        owner.startProgressWatch();
                        return;
                    }
                }
                try {
                    audio.pause();
                } catch (e) {
                    // ignore
                }
                owner.hardSilenceMedia(false);
                owner.syncPlayingUi(false);
                if (owner.shouldShowOtherTabStatus()) {
                    setStatus(
                        owner.root,
                        textOf(owner.root, '[data-store-music-i18n-other-tab]', '已在其他页面播放')
                    );
                } else {
                    setStatus(owner.root, '');
                }
                return;
            }
            owner.markHeardWhileVisible();
            owner.setWantPlay(true);
            owner.touchAudible();
            owner.syncPlayingUi(true);
            // Progressive stream is audible — now safe to warm other tracks into IDB.
            if (typeof owner._warmAfterPlay === 'function') {
                var warmCb = owner._warmAfterPlay;
                owner._warmAfterPlay = null;
                warmCb();
            }
            // Sole source: any sibling StoreMusic Audio from remount races must die now.
            enforceSoleAudibleSource(audio);
            if (owner.isLeader) {
                owner.broadcastForceStop(false);
            }
            setStatus(owner.root, '');
            owner.ensureAnalyser();
            owner.updateWaveLoop();
            owner.startProgressWatch();
        });
        audio.addEventListener('pause', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            // pagehide teardown pauses/clears src after the intentional saveProgress —
            // must not overwrite the snap with currentTime=0 (refresh「从头播」根因).
            if (!owner._docAlive || owner._audioMutating) {
                return;
            }
            owner.saveProgress();
            owner.syncPlayingUi(false);
            owner.stopWaveLoop();
            owner.stopProgressWatch();
        });
        audio.addEventListener('timeupdate', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            if (!owner._docAlive) {
                return;
            }
            if (!owner._progressTimer) {
                // Throttle ~0.5s so refresh keeps a fresher resume point.
                owner._progressTimer = global.setTimeout(function () {
                    owner._progressTimer = 0;
                    if (!owner._docAlive) {
                        return;
                    }
                    if (audio && !audio.paused) {
                        owner.touchAudible();
                    }
                    owner.saveProgress();
                }, 500);
            }
        });
        audio.addEventListener('ended', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            if (!owner._docAlive) {
                return;
            }
            owner.saveProgress();
            owner.onTrackEnded();
        });
        audio.addEventListener('error', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            if (owner.isDismissed()) {
                return;
            }
            setStatus(owner.root, textOf(owner.root, '[data-store-music-i18n-play-error]', '音频加载失败，请换一首曲目或格式'));
            owner.needGesture = true;
            if (owner.mascot) {
                owner.mascot.classList.toggle('is-need-gesture', owner.needGesture && !prefersReducedMotion());
            }
            owner.syncPlayingUi(false);
        });
        audio.addEventListener('loadedmetadata', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            owner.applyPendingSeek();
        });
        audio.addEventListener('canplay', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            if (owner._pendingSeek != null) {
                owner.applyPendingSeek();
            }
        });
    };

    StoreMusic.prototype.syncTrackMeta = function (playing) {
        var track = this.currentTrack();
        var title = this.trackLabel(track);
        if (playing) {
            var playingLabel = textOf(this.root, '[data-store-music-i18n-playing]', '播放中');
            if (this.mascot) {
                this.mascot.setAttribute('title', playingLabel + ' · ' + title);
            }
        }
        if (this.titleEl) {
            this.titleEl.textContent = title;
        }
        if (this.introEl) {
            var intro = track && track.intro ? track.intro : '';
            if (intro) {
                this.introEl.hidden = false;
                this.introEl.textContent = intro;
            } else {
                this.introEl.hidden = false;
                this.introEl.textContent = textOf(this.root, '[data-store-music-i18n-idle]', '开匣雅乐');
            }
            this.setIntroExpanded(false);
            this.refreshIntroExpandState();
        }
        this.syncPlaylistActive();
    };

    StoreMusic.prototype.setIntroExpanded = function (expanded) {
        var open = !!expanded;
        if (this.introWrap) {
            this.introWrap.classList.toggle('is-expanded', open);
        }
        if (this.introToggle) {
            this.introToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            this.introToggle.textContent = open
                ? textOf(this.root, '[data-store-music-i18n-intro-collapse]', '收起简介')
                : textOf(this.root, '[data-store-music-i18n-intro-expand]', '展开简介');
        }
    };

    /** Show “展开简介” when clamped text overflows OR copy is long enough to need expand. */
    StoreMusic.prototype.refreshIntroExpandState = function () {
        var self = this;
        if (!this.introEl || !this.introToggle || !this.introWrap) {
            return;
        }
        var textLen = String(this.introEl.textContent || '').trim().length;
        var likelyLong = textLen >= 18;
        // Optimistic show for long copy; refine after layout.
        this.introToggle.hidden = !likelyLong && !this.introWrap.classList.contains('is-expanded');
        global.requestAnimationFrame(function () {
            global.requestAnimationFrame(function () {
                if (!self.introEl || !self.introToggle || !self.introWrap) {
                    return;
                }
                if (self.introWrap.classList.contains('is-expanded')) {
                    self.introToggle.hidden = false;
                    return;
                }
                var overflow = self.introEl.scrollHeight > self.introEl.clientHeight + 1;
                self.introToggle.hidden = !(overflow || likelyLong);
            });
        });
    };

    /** Re-check playlist row intro toggles after panel becomes visible. */
    StoreMusic.prototype.refreshPlaylistIntroToggles = function () {
        if (!this.playlistEl) {
            return;
        }
        var rows = this.playlistEl.querySelectorAll('[data-store-music-track]');
        Array.prototype.forEach.call(rows, function (btn) {
            var intro = btn.querySelector('.w-store-music__track-intro');
            var toggle = btn.querySelector('[data-store-music-track-intro-toggle]');
            if (!intro || !toggle) {
                return;
            }
            var textLen = String(intro.textContent || '').trim().length;
            if (textLen >= 18 || intro.scrollHeight > intro.clientHeight + 1) {
                toggle.hidden = false;
            }
        });
    };

    StoreMusic.prototype.bind = function () {
        var self = this;
        // Pointer on the widget = local gesture only. Do NOT steal leadership until ▶ / 选曲.
        if (this.root) {
            this.root.addEventListener('pointerdown', function () {
                self.markUserGesture();
                try {
                    self.mountAudioElement();
                } catch (e) {
                    // ignore
                }
            }, true);
        }
        if (this.introToggle) {
            this.introToggle.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var open = !(self.introWrap && self.introWrap.classList.contains('is-expanded'));
                self.setIntroExpanded(open);
                self.refreshIntroExpandState();
            });
        }
        if (this.toggleBtns && this.toggleBtns.length) {
            Array.prototype.forEach.call(this.toggleBtns, function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    // Opening the panel = browse playlist only. Do NOT steal the peer's audio yet —
                    // soft-loaded tabs must stay quiet until ▶ / 选曲 (last explicit op wins).
                    var wasOpen = !!self.panelOpen;
                    self.togglePanel();
                    if (!wasOpen && self.panelOpen) {
                        setStatus(self.root, '');
                        // Gesture unlock only when autoplay was blocked; never auto-takeover on open.
                        if (self.needGesture && !self.hasActivePeerLeader()) {
                            self.userPlay();
                        }
                    }
                });
            });
        } else if (this.mascot) {
            this.mascot.addEventListener('click', function (ev) {
                ev.preventDefault();
                var wasOpen = !!self.panelOpen;
                self.togglePanel();
                if (!wasOpen && self.panelOpen) {
                    setStatus(self.root, '');
                    if (self.needGesture && !self.hasActivePeerLeader()) {
                        self.userPlay();
                    }
                }
            });
        }
        if (this.playBtn) {
            this.playBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                self.markUserOperating();
                self.userPlay();
            });
        }
        if (this.pauseBtn) {
            this.pauseBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                self.markUserOperating();
                self.stopPlayback();
            });
        }
        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                self.markUserOperating();
                self.skipTrack(-1);
            });
        }
        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                self.markUserOperating();
                self.skipTrack(1);
            });
        }
        if (this.closeBtn) {
            this.closeBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                // Close panel only — must NOT steal leadership / force-stop peers.
                self.markUserGesture();
                // Force-close even if panelOpen desynced from DOM (e2e / tooling).
                if (self.panel && !self.panel.hidden) {
                    self.panelOpen = true;
                }
                if (self.panelOpen) {
                    self.togglePanel();
                }
            });
        }
        if (this.dismissBtn) {
            this.dismissBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                self.markUserOperating();
                self.stopPlayback();
            });
        }
        if (this.volume) {
            var onVolume = function () {
                // Volume prefs are local UI; only touch the live element if WE are playing.
                // Never acquireLeaderLock here — that used to silence every other page.
                self.markUserGesture();
                self.persistVolumePref(self.volume.value);
            };
            this.volume.addEventListener('input', onVolume);
            this.volume.addEventListener('change', onVolume);
        }
        if (this.waveToggle) {
            this.waveToggle.addEventListener('change', function () {
                self.markUserGesture();
                writePref(self.root, 'wave', self.waveToggle.checked ? '1' : '0');
                self.syncWaveLabel();
                if (self.isLeader || (self.audio && !self.audio.paused)) {
                    self.updateWaveLoop();
                }
            });
        }
        if (this.searchInput) {
            var onSearch = function () {
                self.onPlaylistSearchInput();
            };
            this.searchInput.addEventListener('input', onSearch);
            this.searchInput.addEventListener('search', onSearch);
        }
        document.addEventListener('visibilitychange', function () {
            // visibility hidden ≠ leave page. Keep BGM only if we already started while visible.
            // Multi-tab handoff stays on pagehide hardStop + force-steal / yieldToOtherTab.
            // Cursor Browser close often skips pagehide — orphanWatch covers disconnected/collapsed hosts.
            if (document.hidden) {
                // Drop "recent operator" so a hidden page cannot refuse yield and leave a residual track.
                self._lastUserActionAt = 0;
                self.saveProgress();
                self.stopWaveLoop();
                // Keep this tab in the domain census while it still exists (background OK).
                self.touchOriginTab();
                // Never-started hidden docs (prerender/zombie) must stay silent.
                if (!self._heardWhileVisible) {
                    try {
                        if (typeof silenceAllStoreMusicAudio === 'function') {
                            silenceAllStoreMusicAudio();
                        }
                    } catch (eQuiet) {
                        // ignore
                    }
                    self.hardSilenceMedia(true);
                    self.killAllPageAudio();
                    self.syncPlayingUi(false);
                    self.stopOrphanWatch();
                    return;
                }
                // Host already detached (Cursor Browser close) — do not wait for orphan ticks.
                if (self.isCollapsedDetachedView() || (self.root && !self.root.isConnected)) {
                    self.tearDownForLeave('visibility-detached');
                    return;
                }
                self.startOrphanWatch();
                return;
            }
            self._collapsedHits = 0;
            self.stopOrphanWatch();
            self.stopLeaveHammer();
            self._docAlive = true;
            self.startGhostWatch();
            self.startOriginTabWatch();
            self.touchOriginTab();
            self.updateWaveLoop();
            // Focused return: drop stale "已在其他页面播放" so the real playlist stays readable.
            setStatus(self.root, '');
            if (self.audio && !self.audio.paused) {
                self.markHeardWhileVisible();
                self.syncPlayingUi(true);
                return;
            }
            // Soft resume only when this page still wants play AND no peer is leading.
            // Never auto-steal just because the user focused this tab.
            if (self._naturalEnded) {
                self.setNeedGesture(true);
            } else if (self.playIntent() === 'play') {
                global.setTimeout(function () {
                    if (document.visibilityState !== 'visible' || !self._docAlive || self._naturalEnded) {
                        return;
                    }
                    if (self.audio && !self.audio.paused) {
                        return;
                    }
                    if (self.expireStaleWantPlay() || !self.canSoftResumeNow()) {
                        self.setNeedGesture(true);
                        return;
                    }
                    if (self.playIntent() !== 'play') {
                        return;
                    }
                    if (!self.acquireLeaderLock(false) || self.hasActivePeerLeader()) {
                        setStatus(self.root, '');
                        self.setNeedGesture(true);
                        return;
                    }
                    self.tryPlay({ force: false });
                }, 40);
            } else if (self.playIntent() === 'stop') {
                self.setNeedGesture(true);
            } else {
                // Cold visit / try_autoplay: boot may have skipped scheduleStart while hidden
                // (prerender / background). Re-run entrance delay when first shown.
                self.scheduleStart();
            }
        });
    };

    StoreMusic.prototype.isUserOperating = function () {
        return (Date.now() - (this._lastUserActionAt || 0)) < 8000;
    };

    StoreMusic.prototype.localOperatorAt = function () {
        return Math.max(Number(this._lastUserActionAt) || 0, Number(this._leaderClaimedAt) || 0);
    };

    StoreMusic.prototype.remoteActionAt = function (msg) {
        return msg && msg.at != null ? Number(msg.at) || 0 : 0;
    };

    /**
     * True when remote is equal-or-newer than this page's last user/leader stamp.
     * Equal timestamps prefer silence (no residual dual-play). Missing at → remote wins
     * only for non-heartbeat claims; heartbeats with missing/stale at never outrank a
     * local user gesture.
     */
    StoreMusic.prototype.isRemoteNewer = function (msg) {
        var remote = this.remoteActionAt(msg);
        if (!remote) {
            return !(msg && msg.heartbeat);
        }
        return remote >= this.localOperatorAt();
    };

    /**
     * Refuse yield only if this focused page is still the latest operator.
     * A newer/equal remote user action always wins — no residual audio.
     */
    StoreMusic.prototype.canRefuseYield = function (msg) {
        if (document.visibilityState === 'hidden') {
            return false;
        }
        if (msg && this.isRemoteNewer(msg)) {
            return false;
        }
        if (!this.isUserOperating()) {
            return false;
        }
        var focused = !(document.hasFocus) || document.hasFocus();
        return !!focused;
    };

    /**
     * Tear down local MediaElement decode path. Pause-only leaves residual tracks audible.
     * @param {boolean} [dropShared] also null the process-wide singleton (leave-page)
     */
    StoreMusic.prototype.hardSilenceMedia = function (dropShared) {
        this.stopLeaderHeartbeat();
        this.stopWaveLoop();
        this.stopProgressWatch();
        this._loadToken += 1;
        this._playGen = nextPlayGeneration();
        if (typeof this.resetAvatarLevel === 'function') {
            this.resetAvatarLevel();
        }
        if (this._blobUrl) {
            try {
                URL.revokeObjectURL(this._blobUrl);
            } catch (e3) {
                // ignore
            }
            this._blobUrl = null;
        }
        this.clearMediaSession();
        this.audioReady = false;

        if (dropShared) {
            // Leave-page / orphan: destroy the only MediaElement + WebAudio graph.
            destroySharedAudioGraph();
            killRivalAudioElements(null);
            this.audio = null;
            this.ctx = null;
            this.source = null;
            this.analyser = null;
            this.waveConnected = false;
            return;
        }

        // Soft stop: pause + clear src on singleton, KEEP element + graph for next play.
        var shared = getSharedAudioSingleton();
        try {
            shared.pause();
            shared.muted = true;
            shared.volume = 0;
            shared.removeAttribute('src');
            shared.removeAttribute('data-store-music-src');
            shared.src = '';
            shared.load();
        } catch (eSoft) {
            // ignore
        }
        this.audio = shared;
        // Re-attach shared graph handles if still alive.
        if (global.__WelineStoreMusicSharedCtx) {
            this.ctx = global.__WelineStoreMusicSharedCtx;
            this.analyser = global.__WelineStoreMusicSharedAnalyser;
            this.source = global.__WelineStoreMusicSharedSource;
            this.waveConnected = !!this.analyser;
        } else {
            this.ctx = null;
            this.source = null;
            this.analyser = null;
            this.waveConnected = false;
        }
        killRivalAudioElements(shared);
    };

    StoreMusic.prototype.ensureAnalyser = function () {
        if (prefersReducedMotion()) {
            this.waveMode = 'soft';
            return;
        }
        var audio = this.audio || getSharedAudioSingleton();
        if (!audio) {
            return;
        }
        this.audio = audio;

        // Reuse process-wide graph — createMediaElementSource may run only once per element.
        if (global.__WelineStoreMusicSharedAnalyser && global.__WelineStoreMusicSharedCtx) {
            this.ctx = global.__WelineStoreMusicSharedCtx;
            this.analyser = global.__WelineStoreMusicSharedAnalyser;
            this.source = global.__WelineStoreMusicSharedSource;
            this.waveConnected = true;
            this.waveMode = 'analyser';
            this.waveFailed = false;
            if (this.ctx.state === 'suspended') {
                try {
                    this.ctx.resume();
                } catch (eResume) {
                    // ignore
                }
            }
            return;
        }
        if (this.waveConnected && this.analyser) {
            return;
        }

        var AC = global.AudioContext || global.webkitAudioContext;
        if (!AC) {
            this.waveMode = 'soft';
            return;
        }
        try {
            var ctx = new AC();
            if (ctx.state === 'suspended') {
                ctx.resume();
            }
            var source = ctx.createMediaElementSource(audio);
            var analyser = ctx.createAnalyser();
            analyser.fftSize = 256;
            source.connect(analyser);
            analyser.connect(ctx.destination);
            audio.__welineMediaGraphBound = true;
            global.__WelineStoreMusicSharedCtx = ctx;
            global.__WelineStoreMusicSharedSource = source;
            global.__WelineStoreMusicSharedAnalyser = analyser;
            this.ctx = ctx;
            this.source = source;
            this.analyser = analyser;
            this.waveConnected = true;
            this.waveMode = 'analyser';
            this.waveFailed = false;
        } catch (e) {
            this.waveMode = 'soft';
            this.waveFailed = false;
            this.syncWaveLabel();
        }
    };

    /**
     * Drop leadership and silence residual audio (keep want_play unless clearWantPlay).
     * @param {boolean} [clearWantPlay]
     * @param {boolean} [hard] clear src / AudioContext (default true — last op must not leave residuals)
     */
    StoreMusic.prototype.silenceForOtherTab = function (clearWantPlay, hard) {
        this.isLeader = false;
        // Keep recent user-gesture stamp so a late peer heartbeat cannot wipe takeover intent.
        if (!this.isUserOperating() && !this.isLatestOperator()) {
            this._lastUserActionAt = 0;
        }
        // Yielding must leave this page operable — never mark the document dead.
        this._docAlive = true;
        if (clearWantPlay) {
            this.setWantPlay(false);
        }
        if (hard === false) {
            this.stopLeaderHeartbeat();
            if (this.audio) {
                try {
                    this.audio.pause();
                } catch (e) {
                    // ignore
                }
            }
            var shared = global.__WelineStoreMusicSharedAudio;
            if (shared && shared !== this.audio) {
                try {
                    shared.pause();
                } catch (e2) {
                    // ignore
                }
            }
            this.syncPlayingUi(false);
            this.stopWaveLoop();
            this.setNeedGesture(true);
            return;
        }
        this.hardSilenceMedia(false);
        // Yield must destroy residual StoreMusic MediaElements in THIS document.
        killRivalAudioElements(null);
        this.audio = null;
        this.audioReady = false;
        this.syncPlayingUi(false);
        this.setNeedGesture(true);
        setStatus(this.root, '');
    };

    /**
     * Local browse gesture only (open panel / pointer / volume UI).
     * Must NOT write the cross-tab operator stamp or steal the lock — that made peer
     * heartbeats treat this tab as playback owner and force-stop the page that was playing.
     */
    StoreMusic.prototype.markUserGesture = function () {
        this._lastGestureAt = Date.now();
        this._docAlive = true;
        this.setDismissed(false);
        setStatus(this.root, '');
        this.touchOriginTab();
    };

    /**
     * Mark this document as the playback operator AND take leadership (▶ / 选曲 / 切歌 / ❚❚).
     */
    StoreMusic.prototype.markUserOperating = function () {
        var at = Date.now();
        this.clearRemotePlayingState();
        this._lastGestureAt = at;
        this._lastUserActionAt = at;
        this._leaderClaimedAt = at;
        this._docAlive = true;
        this.setDismissed(false);
        setStatus(this.root, '');
        this.touchOriginTab();
        try {
            global.localStorage.setItem(storageKey(this.root, 'operator'), TAB_ID + '|' + at);
        } catch (e) {
            // ignore
        }
        this.acquireLeaderLock(true);
    };

    StoreMusic.prototype.syncWaveLabel = function () {
        if (!this.waveToggle) {
            return;
        }
        var label;
        if (this.waveFailed && this.waveMode !== 'soft') {
            label = textOf(this.root, '[data-store-music-i18n-wave-unavailable]', '波形不可用');
        } else {
            var on = textOf(this.root, '[data-store-music-i18n-wave-on]', '开启波形');
            var off = textOf(this.root, '[data-store-music-i18n-wave-off]', '关闭波形');
            label = this.waveToggle.checked ? off : on;
        }
        if (this.waveLabel) {
            this.waveLabel.textContent = label;
        }
        var waveWrap = this.waveToggle.closest ? this.waveToggle.closest('.w-store-music__wave') : null;
        if (waveWrap) {
            waveWrap.setAttribute('title', label);
        }
    };

    StoreMusic.prototype.togglePanel = function () {
        this.panelOpen = !this.panelOpen;
        if (this.panelOpen) {
            // Opening the panel = user is looking here; clear sticky nag, but do NOT steal
            // leadership until ▶ / 选曲 (avoids stopping peer + multi-tab auto-takeover).
            setStatus(this.root, '');
        }
        if (this.panel) {
            this.panel.hidden = !this.panelOpen;
            // Clear leftover inline hide styles (e.g. tooling that forces
            // opacity:0.001 / pointer-events:none on [hidden] nodes).
            if (this.panelOpen) {
                this.panel.style.display = '';
                this.panel.style.visibility = '';
                this.panel.style.opacity = '';
                this.panel.style.pointerEvents = '';
                this.panel.removeAttribute('aria-hidden');
                this.refreshIntroExpandState();
                this.refreshPlaylistIntroToggles();
            }
        }
        if (this.root) {
            this.root.classList.toggle('is-open', !!this.panelOpen);
        }
        if (this.mascot) {
            this.mascot.setAttribute('aria-expanded', this.panelOpen ? 'true' : 'false');
        }
        if (this.toggleBtns && this.toggleBtns.length) {
            var open = !!this.panelOpen;
            Array.prototype.forEach.call(this.toggleBtns, function (btn) {
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
        this.syncHintVisibility();
    };

    StoreMusic.prototype.syncHintVisibility = function () {
        if (!this.hint) {
            return;
        }
        var playing = !!(this.root && this.root.classList.contains('is-playing'));
        if (this.panelOpen || playing) {
            this.hint.hidden = true;
            return;
        }
        // Always show the entry chip when closed so “点开选曲” is the real hit target.
        this.hint.hidden = false;
    };

    StoreMusic.prototype.setNeedGesture = function (need) {
        this.needGesture = !!need;
        if (this.mascot) {
            this.mascot.classList.toggle('is-need-gesture', this.needGesture && !prefersReducedMotion());
        }
        if (this.hint && this.needGesture && (this._autoplayPending || this._awaitingUnmute)) {
            // Quiet sticky F5 retries must not rewrite the chip to「点击开启音乐」.
            if (!(this._stickyMutedResume || this._stickyRetryArmed || (Number(this._stickyRetryLeft) > 0))) {
                this.hint.textContent = textOf(
                    this.root,
                    this._awaitingUnmute
                        ? '[data-store-music-i18n-tap-to-unmute]'
                        : '[data-store-music-i18n-tap-to-play]',
                    this._awaitingUnmute ? '点击开启声音' : '点击开启音乐'
                );
            }
        }
        this.syncHintVisibility();
        if (this.needGesture && (this._autoplayPending || this._awaitingUnmute)) {
            this.armPageGestureUnlock();
        }
    };

    /**
     * Chrome/Safari 无痕禁止「无手势有声自播」，但允许 muted autoplay。
     * Soft path: 先试有声 → NotAllowed 则静音开播，等首次手势开声。
     */
    StoreMusic.prototype.unmuteAudible = function () {
        this._awaitingUnmute = false;
        this._autoplayPending = false;
        if (this.audio) {
            try {
                this.audio.muted = false;
            } catch (e) {
                // ignore
            }
            var vol = this.volume ? Number(this.volume.value) : Number(this.cfg.default_volume || 8);
            try {
                this.audio.volume = Math.max(0, Math.min(1, vol / 100));
            } catch (e2) {
                // ignore
            }
        }
        this.needGesture = false;
        if (this.mascot) {
            this.mascot.classList.remove('is-need-gesture');
        }
        this.disarmPageGestureUnlock();
        setStatus(this.root, '');
        this.syncPlayingUi(true);
    };

    /**
     * Incognito / strict browsers block unmuted autoplay. After delay fails with
     * NotAllowedError we may already be muted-playing (_awaitingUnmute) — first
     * gesture unmutes. Otherwise start via userPlay.
     */
    StoreMusic.prototype.armPageGestureUnlock = function () {
        var self = this;
        if (this._pageGestureUnlock) {
            return;
        }
        var unlock = function (ev) {
            if (!self._docAlive) {
                return;
            }
            var t = ev && ev.target ? ev.target : null;
            if (t && t.closest && t.closest('[data-consent-dismiss]')) {
                return;
            }
            // Muted soft-autoplay already running: first real gesture opens sound.
            if (self._awaitingUnmute) {
                self.unmuteAudible();
                return;
            }
            if (!self.needGesture || !self._autoplayPending) {
                return;
            }
            if (self.isDismissed()) {
                return;
            }
            if (self.playIntent() === 'stop') {
                if (self.isUserStopped()) {
                    return;
                }
                self.clearLegacyStopPoison();
            }
            if (self.hasActivePeerLeader()) {
                return;
            }
            // Cookie accept is handled by bindConsentAutoplayRetry (async grant).
            if (t && t.closest && (
                t.closest('[data-consent-accept]')
                || t.closest('#weline-consent-banner')
                || t.closest('.w-consent-banner')
            )) {
                return;
            }
            self.userPlay();
        };
        this._pageGestureUnlock = unlock;
        document.addEventListener('pointerdown', unlock, true);
        document.addEventListener('keydown', unlock, true);
    };

    StoreMusic.prototype.disarmPageGestureUnlock = function () {
        if (!this._pageGestureUnlock) {
            return;
        }
        document.removeEventListener('pointerdown', this._pageGestureUnlock, true);
        document.removeEventListener('keydown', this._pageGestureUnlock, true);
        this._pageGestureUnlock = null;
    };

    /**
     * Cookie「同意」后强制重跑一次（旧版曾被 marketing 卡住；现主要用于开声/补枪）。
     */
    StoreMusic.prototype.bindConsentAutoplayRetry = function () {
        var self = this;
        if (this._consentRetryBound) {
            return;
        }
        this._consentRetryBound = true;
        document.addEventListener('click', function (ev) {
            var t = ev && ev.target ? ev.target : null;
            if (!t || !t.closest || !t.closest('[data-consent-accept]')) {
                return;
            }
            // Accept is a user activation — unmute if already soft-playing muted.
            global.setTimeout(function () {
                if (!self._docAlive) {
                    return;
                }
                if (self._awaitingUnmute) {
                    self.unmuteAudible();
                    return;
                }
                self.scheduleStart({ force: true });
            }, 0);
        }, false);
    };

    /**
     * @param {{force?: boolean, skipDelay?: boolean}} [opts]
     *   force=true cancels in-flight delay and restarts
     *   skipDelay=true used after peer-lock retry (delay already consumed)
     */
    StoreMusic.prototype.scheduleStart = function (opts) {
        var self = this;
        var force = !!(opts && opts.force);
        var skipDelay = !!(opts && opts.skipDelay);
        if (!this._docAlive) {
            return;
        }
        if (document.visibilityState === 'hidden' && !this._heardWhileVisible) {
            // Wait until this document is actually shown (no ghost autoplay).
            return;
        }
        // Prevent visibility/consent/boot from stacking delays that cancel each other
        // via _scheduleGen — that looked like「等很久也不播」.
        if (this._scheduleInFlight && !force) {
            return;
        }
        if (this._naturalEnded) {
            // Same document already finished the playlist (loop off) — wait for ▶.
            this.setNeedGesture(true);
            this.syncHintVisibility();
            return;
        }
        // Explicit ❚❚ / dismiss — every new page must stay quiet until ▶.
        if (this.isUserStopped()) {
            this.setNeedGesture(true);
            this.syncHintVisibility();
            return;
        }
        // 1.1.28 natural-end misused user_stopped and blocked 进店 autoplay.
        this.migrateNaturalEndStopPoison();
        // If migration cleared a false stop, re-check real ❚❚ (should be rare).
        if (this.isUserStopped()) {
            this.setNeedGesture(true);
            this.syncHintVisibility();
            return;
        }
        // Repair sessions poisoned by old leave/domain_empty want_play=0 writes.
        this.clearLegacyStopPoison();
        // F5 while playing: session latch forces sticky resume (progress/want already cached).
        var navResume = this.consumeNavResumeLatch();
        if (navResume) {
            this.setWantPlay(true);
            this.setUserStopped(false);
            this.touchAudible();
            this.beginNavResumeBypass();
        }
        // F5 latch / sticky want: never flash「点击开启」from idle-clock expire mid-resume.
        this.expireStaleWantPlay({ silent: !!(navResume || this.playIntent() === 'play') });
        var intent = this.playIntent();
        if (navResume && intent !== 'play') {
            this.setWantPlay(true);
            intent = 'play';
        }
        if (this.isDismissed() && intent !== 'play') {
            this.setNeedGesture(false);
            this.syncHintVisibility();
            setStatus(this.root, textOf(this.root, '[data-store-music-i18n-dismissed]', '已关闭自动播放，点击播放可重新开启'));
            return;
        }
        if (intent === 'stop') {
            // Non-user stop residue — treat as unset for entrance autoplay.
            this.clearLegacyStopPoison();
            intent = this.playIntent();
        }
        // A valid local track snapshot is a previous playback session, not a cold visit.
        // It may exist without want_play=1 after an unload/legacy cleanup; once the
        // explicit stop/dismiss guards above have passed, resume it immediately.
        var restoredProgress = this.restoreProgressIndex();
        var cachedResume = !!(restoredProgress && this.hasResumeSnap());
        var delaySec = Math.max(1, Math.min(15, Number(this.cfg.delay_seconds) || 3));
        // Refresh/cross-page/cached resume → ASAP. Only a cold visit waits delay_seconds.
        var stickyResume = intent === 'play' || navResume || cachedResume;
        var waitMs = (stickyResume || skipDelay) ? 0 : delaySec * 1000;
        if (force && this._awaitingConsent) {
            waitMs = 0;
        }
        this._awaitingConsent = false;
        this._scheduleInFlight = true;
        var gen = (this._scheduleGen = (Number(this._scheduleGen) || 0) + 1);
        // Sticky F5 / want_play / cached progress: play as soon as this module boots.
        // Do NOT wait for window `load` (images/third-party can stall for seconds).
        // Cold first visit still waits for load + delay_seconds.
        var bootGate = stickyResume ? Promise.resolve() : whenWindowLoaded();
        bootGate.then(function () {
            return delay(waitMs);
        }).then(function () {
            if (gen !== self._scheduleGen) {
                return;
            }
            if (!self._docAlive) {
                return;
            }
            // Re-check after delay (or ASAP sticky path): user may have paused / peer may lead.
            self.clearLegacyStopPoison();
            self.expireStaleWantPlay({ silent: !!(navResume || stickyResume) });
            if (self.isUserStopped() || self._naturalEnded) {
                return;
            }
            if (self.isDismissed() && self.playIntent() !== 'play') {
                return;
            }
            // Do NOT gate on marketing Cookie — entrance BGM must start after delay
            // even when the consent banner is still visible (无痕首次进店).
            return storeMusicPlaybackAllowed();
        }).then(function (allowed) {
            if (allowed === undefined) {
                return;
            }
            if (gen !== self._scheduleGen || !self._docAlive) {
                return;
            }
            if (!allowed) {
                self._autoplayPending = true;
                self.setNeedGesture(true);
                return;
            }
            self.clearLegacyStopPoison();
            self.expireStaleWantPlay({ silent: !!(navResume || stickyResume) });
            // Expire must leave unset (not '0'); re-clear any legacy stop residue before gates.
            self.clearLegacyStopPoison();
            var latest = self.playIntent();
            if (latest === 'stop' && self.isUserStopped()) {
                self.setNeedGesture(true);
                return;
            }
            if (latest === 'stop') {
                self.clearLegacyStopPoison();
                latest = self.playIntent();
            }
            if (latest === 'stop') {
                latest = 'unset';
            }
            // Snap from a previous session is an autoplay trigger after the explicit
            // stop/dismiss guards above — it is the cached-resume fast path.
            self.restoreProgressIndex();
            // 1) sticky want_play / nav latch / cached progress → immediate resume
            // 2) cold visit → try_autoplay after delay (already waited, or skipDelay peer-retry)
            var stickyNow = latest === 'play' || navResume || cachedResume;
            var coldAutoplay = !stickyNow
                && latest === 'unset'
                && self.cfg.try_autoplay !== false
                && self.cfg.try_autoplay !== 0
                && self.cfg.try_autoplay !== '0';
            var shouldPlay = stickyNow || coldAutoplay;
            if (!shouldPlay) {
                self.setNeedGesture(true);
                return;
            }
            self._autoplayPending = true;
            if (stickyNow) {
                self.beginNavResumeBypass();
                self._stickyRetryLeft = 3;
            }
            // Refresh/resume: drop stale lock only when this is the sole remaining tab.
            self.clearStaleLeaderForSoloResume();
            // New page: if another tab (even background) still holds the lock / is playing,
            // stay quiet — do not open a second audible source. ▶ can still take over.
            if (self.hasActivePeerLeader()) {
                self._autoplayPending = true;
                // Sticky F5: dying-tab lock races for ~100–300ms — retry quietly, do not
                // flash「点击开启音乐」(that is only for cold visit / explicit stop).
                if (!stickyNow) {
                    self.setNeedGesture(true);
                } else {
                    self.armStickyPlayRetry();
                }
                self.syncRemotePlayingUi(true, self.readLeaderLock());
                // Cold and sticky both retry once — otherwise 进店 stays silent behind a dying lock.
                self.armPeerLeaderRetry();
                setStatus(self.root, '');
                return;
            }
            // F5 census race: dying tab dropped itself; register this tab before soft gate.
            self._docAlive = true;
            self.touchOriginTab();
            if (!self.canAudiblyStart(false)) {
                if (stickyNow && document.visibilityState === 'visible') {
                    self.touchOriginTab();
                    if (!self.canAudiblyStart(true)) {
                        self.armStickyPlayRetry();
                        return;
                    }
                } else {
                    self.setNeedGesture(true);
                    return;
                }
            }
            return self.ensureAudio({ preferCache: stickyNow }).then(function () {
                if (gen !== self._scheduleGen || !self._docAlive) {
                    return;
                }
                self.clearStaleLeaderForSoloResume();
                if (self.hasActivePeerLeader()) {
                    self._autoplayPending = true;
                    if (!stickyNow) {
                        self.setNeedGesture(true);
                    } else {
                        self.armStickyPlayRetry();
                    }
                    self.syncRemotePlayingUi(true, self.readLeaderLock());
                    self.armPeerLeaderRetry();
                    setStatus(self.root, '');
                    return;
                }
                // Final gate right before play() — delay path must not skip this.
                if (self.isUserStopped() || self._naturalEnded) {
                    return;
                }
                self.clearLegacyStopPoison();
                if (self.playIntent() === 'stop' && self.isUserStopped()) {
                    return;
                }
                // Sticky want_play / cache / latch all share the refresh-resume path.
                return self.tryPlay({ force: false, navResume: stickyNow || navResume });
            });
        }).catch(function () {
            self._autoplayPending = true;
            self.clearNavResumeBypass();
            if (stickyResume || navResume) {
                self.armStickyPlayRetry();
            } else {
                self.setNeedGesture(true);
            }
        }).then(function () {
            if (gen === self._scheduleGen) {
                self._scheduleInFlight = false;
            }
        });
    };

    StoreMusic.prototype.warmAudioCache = function () {
        var self = this;
        if (!this.tracks.length || !global.fetch || !global.indexedDB) {
            return;
        }
        this.tracks.forEach(function (track, index) {
            var url = track && track.url ? String(track.url) : '';
            if (!url) {
                return;
            }
            global.setTimeout(function () {
                if (self.isDismissed()) {
                    return;
                }
                // Skip warming the currently streaming track — avoid competing Range fetches.
                var current = self.currentTrack();
                var curUrl = current ? String(current.url || '') : '';
                if (curUrl && normalizeTrackUrl(curUrl) === normalizeTrackUrl(url)
                    && self.audio && !self.audio.paused) {
                    return;
                }
                warmIdbAudio(normalizeTrackUrl(url) || url, url);
            }, 350 + index * 280);
        });
    };

    /**
     * Defer full-file IDB warm until after first audible stream (or long idle),
     * so progressive play keeps the connection.
     */
    StoreMusic.prototype.armDeferredAudioCacheWarm = function () {
        var self = this;
        if (this._warmArmed) {
            return;
        }
        this._warmArmed = true;
        var run = function () {
            if (self._warmRan || !self._docAlive) {
                return;
            }
            self._warmRan = true;
            self.warmAudioCache();
        };
        this._warmAfterPlay = function () {
            global.setTimeout(run, 10000);
        };
        global.setTimeout(run, 60000);
    };

    StoreMusic.prototype.ensureAudio = function (opts) {
        var self = this;
        var preferCache = !!(opts && opts.preferCache);
        var track = this.currentTrack();
        var url = track ? String(track.url || '').trim() : '';
        if (!url) {
            return Promise.reject(new Error('no track'));
        }
        if (this.audioReady && this.audio && this.audio.getAttribute('data-store-music-src') === url) {
            this.syncTrackMeta(!!(this.audio && !this.audio.paused));
            return Promise.resolve(this.audio);
        }
        // Soft pause + swap src on the process singleton (never spawn a second MediaElement).
        this.discardAudioElement({ hard: false });
        var audio = this.mountAudioElement();
        killRivalAudioElements(audio);
        registerStoreAudio(audio);
        global.__WelineStoreMusicSharedAudio = audio;
        audio.__welineStoreMusicOwner = this;
        this.bindAudioElementEvents(audio);
        var multi = this.tracks.length > 1;
        var loopOne = !multi && !(this.cfg.loop === false || this.cfg.loop === 0 || this.cfg.loop === '0');
        audio.loop = !!loopOne;
        var vol = this.volume ? Number(this.volume.value) : Number(this.cfg.default_volume || 8);
        audio.volume = Math.max(0, Math.min(1, vol / 100));
        var lower = url.toLowerCase().split('?')[0];
        var isM4a = lower.slice(-4) === '.m4a' || lower.indexOf('.m4a.') !== -1;
        if (isM4a && typeof audio.canPlayType === 'function') {
            var ok = audio.canPlayType('audio/mp4') || audio.canPlayType('audio/x-m4a') || audio.canPlayType('audio/aac');
            if (!ok) {
                setStatus(self.root, textOf(self.root, '[data-store-music-i18n-format-unsupported]', '当前浏览器暂不支持此音频格式'));
            }
        }
        var token = ++this._loadToken;
        var playGen = nextPlayGeneration();
        this._playGen = playGen;
        return resolveMediaSrc(url, preferCache).then(function (resolved) {
            if (token !== self._loadToken || self._playGen !== playGen || currentPlayGeneration() !== playGen) {
                if (resolved.src && String(resolved.src).indexOf('blob:') === 0) {
                    try {
                        URL.revokeObjectURL(resolved.src);
                    } catch (e) {
                        // ignore
                    }
                }
                return self.audio;
            }
            try {
                audio.pause();
            } catch (e) {
                // ignore
            }
            if (self._blobUrl) {
                try {
                    URL.revokeObjectURL(self._blobUrl);
                } catch (e) {
                    // ignore
                }
                self._blobUrl = null;
            }
            if (resolved.src && String(resolved.src).indexOf('blob:') === 0) {
                self._blobUrl = resolved.src;
                audio.preload = 'metadata';
            } else {
                audio.preload = 'auto';
            }
            audio.setAttribute('data-store-music-src', url);
            audio.setAttribute('data-store-music-stream', resolved.stream ? '1' : '0');
            audio.src = resolved.src;
            self.audioReady = true;
            enforceSoleAudibleSource(audio);
            self.syncTrackMeta(false);
            return audio;
        });
    };

    StoreMusic.prototype.applyPendingSeek = function () {
        if (!this.audio || this._pendingSeek === null || this._pendingSeek === undefined) {
            return false;
        }
        var t = Number(this._pendingSeek);
        if (!Number.isFinite(t) || t < 0) {
            this._pendingSeek = null;
            return false;
        }
        // Tiny positions still count as "remembered" — only skip exact 0 cold starts.
        if (t > 0 && t < 0.05) {
            t = 0.05;
        }
        if (t === 0) {
            this._pendingSeek = null;
            return false;
        }
        try {
            var dur = Number(this.audio.duration);
            // Metadata not ready yet — keep pending for loadedmetadata (do NOT clear).
            if (!Number.isFinite(dur) || dur <= 0) {
                return false;
            }
            t = Math.min(t, Math.max(0, dur - 0.25));
            this.audio.currentTime = t;
            this._lastGoodProgressTime = t;
            this._pendingSeek = null;
            return true;
        } catch (e) {
            // Keep pending so loadedmetadata / canplay can retry.
            return false;
        }
    };

    StoreMusic.prototype.startProgressWatch = function () {
        // timeupdate handler already throttles saves
    };

    StoreMusic.prototype.stopProgressWatch = function () {
        if (this._progressTimer) {
            global.clearTimeout(this._progressTimer);
            this._progressTimer = 0;
        }
    };

    /**
     * Playlist / track finished with loop off. Must sticky-stop soft resume:
     * leaving want_play=1 made visibility/peer-release/armPeerLeaderRetry
     * secretly restart playback minutes/hours later.
     * Do NOT set user_stopped — that permanently killed 进店 try_autoplay (1.1.28).
     */
    StoreMusic.prototype.finishNaturalPlayback = function () {
        this._naturalEnded = true;
        this._awaitingUnmute = false;
        this._autoplayPending = false;
        this.disarmPageGestureUnlock();
        this.setWantPlay(false);
        try {
            if (this.audio) {
                this.audio.pause();
            }
        } catch (ePause) {
            // ignore
        }
        this.hardSilenceMedia(false);
        this.releaseLeadership();
        this.broadcastLeaderState('stop');
        this.persistSelection(0);
        this.syncPlayingUi(false);
        this.setNeedGesture(true);
        setStatus(this.root, '');
    };

    /**
     * Advance / wrap playlist after `ended`. Remount + muted fallback — ended is not a gesture.
     */
    StoreMusic.prototype.continuePlaylistPlayback = function () {
        var self = this;
        var track = this.currentTrack();
        var url = track ? String(track.url || '').trim() : '';
        if (!url) {
            return Promise.reject(new Error('no track'));
        }
        this._naturalEnded = false;
        this.setDismissed(false);
        this.setUserStopped(false);
        this.setWantPlay(true);
        this._pendingSeek = 0;
        this._docAlive = true;
        this.stopLeaveHammer();
        this.touchOriginTab();
        this._audioMutating = true;
        this.stopWaveLoop();
        var playGen = nextPlayGeneration();
        this._playGen = playGen;
        this.discardAudioElement({ hard: false });
        var audio = this.mountAudioElement();
        killRivalAudioElements(audio);
        audio.__welineStoreMusicOwner = this;
        registerStoreAudio(audio);
        global.__WelineStoreMusicSharedAudio = audio;
        var multi = this.tracks.length > 1;
        var loopOne = !multi && !(this.cfg.loop === false || this.cfg.loop === 0 || this.cfg.loop === '0');
        audio.loop = !!loopOne;
        var vol = this.volume ? Number(this.volume.value) : Number(this.cfg.default_volume || 8);
        audio.volume = Math.max(0, Math.min(1, vol / 100));
        this.acquireLeaderLock(true);
        // Playlist advance is authoritative — force peers to drop residual decode.
        this.claimLeadership(true);
        setStatus(this.root, '');
        audio.setAttribute('data-store-music-src', url);
        audio.src = url;
        this.audioReady = true;
        this.syncTrackMeta(true);
        this.syncPlaylistActive();
        this.cacheTrackInBackground(url);
        var markPlaying = function (mutedSoft) {
            if (self._playGen !== playGen || currentPlayGeneration() !== playGen) {
                try {
                    audio.pause();
                } catch (eStale) {
                    // ignore
                }
                self._audioMutating = false;
                return;
            }
            self._awaitingUnmute = !!mutedSoft;
            self.setWantPlay(true);
            self.touchAudible();
            enforceSoleAudibleSource(audio);
            self.syncPlayingUi(true);
            self.broadcastLeaderState('playing', false);
            self._audioMutating = false;
        };
        try {
            audio.muted = false;
        } catch (eMute) {
            // ignore
        }
        var playResult = null;
        try {
            playResult = audio.play();
        } catch (err) {
            this._audioMutating = false;
            this.setNeedGesture(true);
            return Promise.reject(err);
        }
        if (!playResult || typeof playResult.then !== 'function') {
            markPlaying(!!audio.muted);
            return Promise.resolve(audio);
        }
        return playResult.then(function () {
            enforceSoleAudibleSource(audio);
            markPlaying(!!audio.muted);
            return audio;
        }).catch(function (err) {
            var name = err && err.name ? String(err.name) : '';
            if (name === 'NotAllowedError' && !audio.muted) {
                try {
                    audio.muted = true;
                } catch (e2) {
                    // ignore
                }
                var mutedPlay = audio.play();
                if (!mutedPlay || typeof mutedPlay.then !== 'function') {
                    markPlaying(true);
                    return audio;
                }
                return mutedPlay.then(function () {
                    markPlaying(true);
                    return audio;
                }).catch(function () {
                    self._audioMutating = false;
                    self._awaitingUnmute = false;
                    self.setNeedGesture(true);
                    return audio;
                });
            }
            self._audioMutating = false;
            self.setNeedGesture(true);
            return audio;
        });
    };

    StoreMusic.prototype.onTrackEnded = function () {
        this.syncPlayingUi(false);
        this.stopWaveLoop();
        var loopOn = !(this.cfg.loop === false || this.cfg.loop === 0 || this.cfg.loop === '0');
        if (this.tracks.length > 1) {
            var next = this.trackIndex + 1;
            if (next >= this.tracks.length) {
                if (!loopOn) {
                    this.finishNaturalPlayback();
                    return;
                }
                next = 0;
            }
            this.trackIndex = next;
            this._pendingSeek = 0;
            this.persistSelection(0);
            this.syncPlaylistActive();
            var self = this;
            this.continuePlaylistPlayback().catch(function () {
                self.setNeedGesture(true);
            });
            return;
        }
        // Single track: MediaElement.loop handles repeat when loopOn.
        if (!loopOn) {
            this.finishNaturalPlayback();
            return;
        }
        this.persistSelection(0);
        var selfOne = this;
        this.continuePlaylistPlayback().catch(function () {
            selfOne.setNeedGesture(true);
        });
    };

    StoreMusic.prototype.skipTrack = function (delta) {
        if (this.tracks.length < 2) {
            return;
        }
        var next = (this.trackIndex + delta + this.tracks.length) % this.tracks.length;
        this.selectTrack(next);
    };

    StoreMusic.prototype.syncTransportButtons = function (playing) {
        if (this.playBtn) {
            this.playBtn.hidden = !!playing;
        }
        if (this.pauseBtn) {
            this.pauseBtn.hidden = !playing;
        }
    };

    StoreMusic.prototype.clearRemotePlayingState = function () {
        this.remotePlaying = false;
        this.remoteOwnerTabId = '';
        this.remoteStateAt = 0;
        if (this.root) {
            this.root.classList.remove('is-remote-playing');
        }
    };

    /**
     * Mirror a peer's playback state without mounting, seeking, or playing local audio.
     * A local user operation clears this mirror and can then claim the lock normally.
     */
    StoreMusic.prototype.syncRemotePlayingUi = function (playing, msg) {
        if (this.isAudibleOwner()) {
            return;
        }
        this.remotePlaying = !!playing;
        this.remoteOwnerTabId = playing && msg ? String(msg.tabId || '') : '';
        this.remoteStateAt = playing && msg ? (Number(msg.at) || 0) : 0;
        this.syncPlayingUi(false);
    };

    StoreMusic.prototype.syncPlayingUi = function (playing) {
        var localPlaying = !!playing;
        if (localPlaying) {
            this.clearRemotePlayingState();
        }
        var mirroredPlaying = !localPlaying && !!this.remotePlaying;
        this.syncTransportButtons(localPlaying || mirroredPlaying);
        if (this.root) {
            this.root.classList.toggle('is-playing', localPlaying);
            this.root.classList.toggle('is-remote-playing', mirroredPlaying);
            this.root.classList.toggle('is-awaiting-unmute', !!(localPlaying && this._awaitingUnmute));
        }
        if (!localPlaying) {
            this.resetSpectrumBars();
        }
        this.syncTrackMeta(localPlaying || mirroredPlaying);
        if (localPlaying && this._awaitingUnmute) {
            // Soft muted autoplay succeeded — keep gesture arm for first unmute click.
            this.needGesture = true;
            this._autoplayPending = true;
            if (this.mascot) {
                this.mascot.classList.add('is-need-gesture');
            }
            // Sticky F5 muted resume: already「续播」decoding — do not flash cold「点击开启音乐」.
            // First pointer/keydown unmutes via armPageGestureUnlock.
            if (this._stickyMutedResume) {
                setStatus(this.root, '');
                if (this.hint) {
                    this.hint.textContent = '';
                }
                this.syncHintVisibility();
            } else {
                setStatus(
                    this.root,
                    textOf(this.root, '[data-store-music-i18n-tap-to-unmute]', '点击开启声音')
                );
                if (this.hint) {
                    this.hint.textContent = textOf(
                        this.root,
                        '[data-store-music-i18n-tap-to-unmute]',
                        '点击开启声音'
                    );
                }
            }
            this.armPageGestureUnlock();
            this.syncHintVisibility();
            this.startSoleSourceWatch();
            // Playing must poll host liveness even while "visible" (Cursor closes without hide).
            this.startOrphanWatch();
            return;
        }
        if (playing) {
            this.needGesture = false;
            this._autoplayPending = false;
            this._awaitingConsent = false;
            this._awaitingUnmute = false;
            this.disarmPageGestureUnlock();
            this.startSoleSourceWatch();
            this.startOrphanWatch();
            if (this.mascot) {
                this.mascot.classList.remove('is-need-gesture');
            }
            setStatus(this.root, '');
        } else {
            this.stopSoleSourceWatch();
            if (!document.hidden) {
                this.stopOrphanWatch();
            }
        }
        this.syncHintVisibility();
        this.updateWaveLoop();
    };

    StoreMusic.prototype.tryPlay = function (opts) {
        var self = this;
        var force = !!(opts && opts.force);
        var navResume = !!(opts && opts.navResume);
        if (!this._docAlive) {
            return Promise.resolve();
        }
        if (navResume) {
            this.beginNavResumeBypass();
        }
        if (force) {
            return this.playCurrentTrackNow();
        }
        // Soft autoplay/resume must honor natural end / ❚❚ / dismiss.
        // Legacy want_play=0 (not ❚❚) must not block cold try_autoplay.
        if (!force) {
            this.clearLegacyStopPoison();
        }
        if (this._naturalEnded || this.isUserStopped() || this.isDismissed()) {
            return Promise.resolve();
        }
        if (this.playIntent() === 'stop') {
            if (this.isUserStopped()) {
                return Promise.resolve();
            }
            this.clearLegacyStopPoison();
        }
        if (this.playIntent() === 'stop') {
            return Promise.resolve();
        }
        if (!this.canAudiblyStart(false)) {
            return Promise.resolve();
        }
        this.clearStaleLeaderForSoloResume();
        if (this.hasActivePeerLeader() || !this.acquireLeaderLock(false)) {
            // Soft path: peer already playing — stay silent without sticky nag.
            if (this.shouldShowOtherTabStatus()) {
                this.yieldToOtherTab();
            } else {
                this.silenceForOtherTab(false, true);
                setStatus(this.root, '');
            }
            return Promise.resolve();
        }
        setStatus(this.root, '');
        return this.ensureAudio({ preferCache: navResume }).then(function (audio) {
            if (!self._docAlive || !self.canAudiblyStart(false)) {
                return;
            }
            if (self.hasActivePeerLeader()) {
                self.silenceForOtherTab(false, true);
                setStatus(self.root, '');
                return;
            }
            audio.__welineStoreMusicOwner = self;
            if (!self.acquireLeaderLock(false)) {
                if (self.shouldShowOtherTabStatus()) {
                    self.yieldToOtherTab();
                } else {
                    self.silenceForOtherTab(false, true);
                    setStatus(self.root, '');
                }
                return;
            }
            self.applyPendingSeek();
            if (!self.claimLeadership(false)) {
                return;
            }
            var markPlaying = function (mutedSoft) {
                self._awaitingUnmute = !!mutedSoft;
                self._stickyMutedResume = !!(mutedSoft && navResume);
                self.clearNavResumeBypass();
                self.setWantPlay(true);
                self.touchAudible();
                self._stickyRetryLeft = 0;
                enforceSoleAudibleSource(audio);
                self.syncPlayingUi(true);
                // Soft start must still force_stop peers — otherwise two tabs both decode
                // (heard as 两首歌一起播). Stall-grace heartbeat keeps our lock so peers
                // cannot soft-steal mid-track just because of a short pause.
                self.broadcastForceStop(false);
                self.broadcastLeaderState('playing', false);
            };
            var playMutedFallback = function () {
                try {
                    audio.muted = true;
                } catch (e2) {
                    // ignore
                }
                var mutedPlay = null;
                try {
                    mutedPlay = audio.play();
                } catch (mutedPlayError) {
                    recoverSoftAutoplayFailure();
                    return null;
                }
                if (!mutedPlay || typeof mutedPlay.then !== 'function') {
                    markPlaying(true);
                    return null;
                }
                return mutedPlay.then(function () {
                    if (!self._docAlive) {
                        return;
                    }
                    markPlaying(true);
                }).catch(function () {
                    recoverSoftAutoplayFailure();
                });
            };
            var recoverSoftAutoplayFailure = function () {
                self._awaitingUnmute = false;
                self._autoplayPending = true;
                self.clearNavResumeBypass();
                // A rejected play() is not playback ownership. Release the lock
                // before showing the gesture fallback so quiet peers are not
                // stranded behind a silent refresh tab.
                self.releaseLeadership();
                self.silenceForOtherTab(false, true);
                // Sticky F5: AbortError / policy races — retry quietly, do not flash tip yet.
                var sticky = navResume || self.playIntent() === 'play' || self.hasResumeSnap();
                if (sticky && !self.isUserStopped() && !self._naturalEnded) {
                    self.armStickyPlayRetry();
                    return;
                }
                self.setNeedGesture(true);
            };
            // Try unmuted first (refresh/MEI often allows sound). Only mute on NotAllowedError.
            // Do NOT pre-mute when !userActivation — that made F5 look like「不播放了」(silent).
            self._playAbortRetried = false;
            try {
                audio.muted = false;
            } catch (eMute) {
                // ignore
            }
            var p = null;
            try {
                p = audio.play();
            } catch (playError) {
                recoverSoftAutoplayFailure();
                return;
            }
            if (!p || typeof p.then !== 'function') {
                markPlaying(!!audio.muted);
                return;
            }
            return p.then(function () {
                if (!self._docAlive || self.hasActivePeerLeader() || !self.acquireLeaderLock(false)) {
                    try {
                        audio.pause();
                    } catch (e) {
                        // ignore
                    }
                    self.silenceForOtherTab(false, true);
                    setStatus(self.root, '');
                    return;
                }
                markPlaying(!!audio.muted);
            }).catch(function (err) {
                var name = err && err.name ? String(err.name) : '';
                // Src swap / double scheduleStart often rejects with AbortError — retry once.
                if (name === 'AbortError' && !self._playAbortRetried) {
                    self._playAbortRetried = true;
                    return delay(50).then(function () {
                        if (!self._docAlive) {
                            return;
                        }
                        try {
                            audio.muted = false;
                        } catch (eRetryMute) {
                            // ignore
                        }
                        var retry = null;
                        try {
                            retry = audio.play();
                        } catch (retryErr) {
                            return playMutedFallback();
                        }
                        if (!retry || typeof retry.then !== 'function') {
                            markPlaying(!!audio.muted);
                            return;
                        }
                        return retry.then(function () {
                            markPlaying(!!audio.muted);
                        }).catch(function (err2) {
                            var n2 = err2 && err2.name ? String(err2.name) : '';
                            if ((n2 === 'NotAllowedError' || n2 === 'AbortError') && !audio.muted) {
                                return playMutedFallback();
                            }
                            recoverSoftAutoplayFailure();
                        });
                    });
                }
                if ((name === 'NotAllowedError' || name === 'AbortError') && !audio.muted) {
                    return playMutedFallback();
                }
                recoverSoftAutoplayFailure();
            });
        });
    };

    StoreMusic.prototype.userPlay = function () {
        this.markUserOperating();
        this._naturalEnded = false;
        this.setDismissed(false);
        this.setUserStopped(false);
        this.clearNavResumeBypass();
        this.setWantPlay(true);
        this.touchAudible();
        this._awaitingUnmute = false;
        this._awaitingConsent = false;
        setStatus(this.root, '');
        // Play inside this click — do not gate on marketing Cookie (entrance BGM ≠ tracking).
        return this.playCurrentTrackNow();
    };

    /** Explicit stop on THIS page. Closing the panel must NOT call this. */
    StoreMusic.prototype.stopPlayback = function () {
        // Teardown/refresh programmatic pause must never look like a user stop.
        if (this._tearingDown || !this._docAlive) {
            try {
                if (this.audio) {
                    this.audio.pause();
                }
            } catch (e) {
                // ignore
            }
            return;
        }
        this.markUserOperating();
        this.setUserStopped(true);
        this.setWantPlay(false);
        this.pause();
        // Tell peers to silence residual audio, but do not clear their want_play /
        // destroy their UI — they stay operable and can ▶ to take over.
        this.broadcastForceStop(false);
        this.broadcastLeaderState('stop');
        this.releaseLeadership();
        this.setNeedGesture(true);
        setStatus(this.root, '');
    };

    StoreMusic.prototype.pause = function () {
        if (this.audio && !this.audio.paused) {
            this.audio.pause();
        }
        this.saveProgress();
        this.syncPlayingUi(false);
    };

    StoreMusic.prototype.dismiss = function () {
        this.setUserStopped(true);
        this.setWantPlay(false);
        this.setDismissed(true);
        this.setNeedGesture(false);
        this.releaseLeadership();
        if (this.audio) {
            try {
                this.audio.pause();
            } catch (_e) {
                // ignore
            }
            // Drop src so late network/decode errors cannot overwrite dismiss status.
            try {
                this.audio.removeAttribute('src');
                this.audio.removeAttribute('data-store-music-src');
                this.audio.load();
            } catch (_e2) {
                // ignore
            }
            this.audioReady = false;
        }
        if (this._blobUrl) {
            try {
                URL.revokeObjectURL(this._blobUrl);
            } catch (_e3) {
                // ignore
            }
            this._blobUrl = null;
        }
        this.saveProgress();
        this.syncPlayingUi(false);
        this.stopWaveLoop();
        this.syncHintVisibility();
        if (this.panelOpen) {
            this.togglePanel();
        }
        setStatus(this.root, textOf(this.root, '[data-store-music-i18n-dismissed]', '已关闭自动播放，点击播放可重新开启'));
    };

    StoreMusic.prototype.disableWaveToggle = function () {
        if (this.waveToggle) {
            this.waveToggle.checked = false;
            this.waveToggle.disabled = true;
        }
        this.syncWaveLabel();
        if (this.waveCanvas) {
            this.waveCanvas.hidden = true;
        }
    };

    StoreMusic.prototype.setAvatarLevel = function (level) {
        var n = Number(level);
        if (!Number.isFinite(n)) {
            n = 0;
        }
        n = Math.max(0, Math.min(1, n));
        // Light smoothing so rings do not jitter frame-to-frame.
        this._avatarLevel = (this._avatarLevel * 0.62) + (n * 0.38);
        var out = this._avatarLevel;
        if (this.root) {
            this.root.style.setProperty('--w-store-music-level', out.toFixed(3));
        }
        if (this.mascot) {
            this.mascot.style.setProperty('--w-store-music-level', out.toFixed(3));
        }
    };

    StoreMusic.prototype.syncSpectrumRadius = function () {
        var host = this.mascot || this.root;
        if (!host || !host.style) {
            return;
        }
        var face = this.root ? this.root.querySelector('.w-store-music__face') : null;
        var box = (face || host).getBoundingClientRect();
        var radius = Math.max(8, Math.min(box.width, box.height) / 2);
        // Start just outside the visible circular face (border sits on the img edge).
        host.style.setProperty('--w-spectrum-r', (radius + 1).toFixed(1) + 'px');
    };

    StoreMusic.prototype.ensureSpectrumBars = function () {
        if (!this.spectrumEl || prefersReducedMotion()) {
            return;
        }
        this.syncSpectrumRadius();
        if (this._spectrumBars && this._spectrumBars.length === SPECTRUM_BAR_COUNT) {
            return;
        }
        this.spectrumEl.textContent = '';
        this._spectrumBars = [];
        this._spectrumSmooth = [];
        for (var i = 0; i < SPECTRUM_BAR_COUNT; i++) {
            var bar = document.createElement('span');
            bar.className = 'w-store-music__spectrum-bar';
            bar.style.setProperty('--w-ang', ((360 / SPECTRUM_BAR_COUNT) * i) + 'deg');
            bar.style.setProperty('--w-bar', '0');
            this.spectrumEl.appendChild(bar);
            this._spectrumBars.push(bar);
            this._spectrumSmooth.push(0);
        }
    };

    /**
     * Drive polar spectrum bars from Analyser frequency bins (or soft fake spectrum).
     * Hidden via CSS when !is-playing — never show idle concentric rings.
     */
    StoreMusic.prototype.updateSpectrumBars = function () {
        this.ensureSpectrumBars();
        this.syncSpectrumRadius();
        if (!this._spectrumBars || !this._spectrumBars.length) {
            return;
        }
        var playing = !!(this.root && this.root.classList.contains('is-playing')
            && this.audio && !this.audio.paused);
        if (!playing) {
            this.resetSpectrumBars();
            return;
        }
        var levels = [];
        var i;
        if (this.waveMode === 'analyser' && this.analyser) {
            var bins = this.analyser.frequencyBinCount;
            var data = new Uint8Array(bins);
            this.analyser.getByteFrequencyData(data);
            // Map thin bars across the low–mid spectrum and remove the analyser noise floor.
            var usable = Math.max(SPECTRUM_BAR_COUNT, Math.floor(bins * 0.5));
            var rawLevels = [];
            var framePeak = 0.0001;
            for (i = 0; i < SPECTRUM_BAR_COUNT; i++) {
                var idx = Math.min(usable - 1, Math.floor((i / SPECTRUM_BAR_COUNT) * usable));
                // Mirror left/right for a fuller ring (bass at bottom-ish via angle 0).
                var mirrored = i < SPECTRUM_BAR_COUNT / 2
                    ? idx
                    : Math.min(usable - 1, Math.floor(((SPECTRUM_BAR_COUNT - 1 - i) / SPECTRUM_BAR_COUNT) * usable));
                var raw = data[mirrored] / 255;
                var aboveFloor = Math.max(0, (raw - 0.015) / 0.985);
                var shaped = Math.pow(aboveFloor, 0.7);
                rawLevels[i] = shaped;
                if (shaped > framePeak) {
                    framePeak = shaped;
                }
            }
            // Per-frame normalize so quiet tracks still fill the visual range.
            for (i = 0; i < SPECTRUM_BAR_COUNT; i++) {
                levels[i] = Math.min(1, Math.max(0.12, (rawLevels[i] / framePeak) * 0.95));
            }
        } else {
            var t = (this.audio && this.audio.currentTime) || 0;
            for (i = 0; i < SPECTRUM_BAR_COUNT; i++) {
                var phase = (i / SPECTRUM_BAR_COUNT) * Math.PI * 2;
                var contour = 0.72 + (0.28 * Math.sin(phase * 2 + t * 0.45));
                var pulse = 0.28
                    + 0.32 * Math.abs(Math.sin(t * 2.4 + phase * 1.7))
                    + 0.24 * Math.abs(Math.sin(t * 5.7 + phase * 0.8))
                    + 0.18 * Math.abs(Math.sin(t * 1.05 + phase * 3.1));
                levels[i] = Math.min(1, Math.max(0.22, pulse * contour));
            }
        }
        for (i = 0; i < SPECTRUM_BAR_COUNT; i++) {
            var prev = this._spectrumSmooth[i] || 0;
            var target = Math.max(0, Math.min(1, Number(levels[i]) || 0));
            // Quick attack, slower decay: a small envelope feels more like music than
            // 48 synchronised columns jumping at the same speed.
            var rate = target > prev ? 0.34 : 0.12;
            var next = prev + ((target - prev) * rate);
            this._spectrumSmooth[i] = next;
            this._spectrumBars[i].style.setProperty('--w-bar', next.toFixed(3));
        }
    };

    StoreMusic.prototype.resetSpectrumBars = function () {
        if (!this._spectrumBars || !this._spectrumBars.length) {
            return;
        }
        for (var i = 0; i < this._spectrumBars.length; i++) {
            this._spectrumSmooth[i] = 0;
            this._spectrumBars[i].style.setProperty('--w-bar', '0');
        }
    };

    StoreMusic.prototype.resetAvatarLevel = function () {
        this._avatarLevel = 0;
        this.resetSpectrumBars();
        if (this.root) {
            this.root.style.setProperty('--w-store-music-level', '0');
        }
        if (this.mascot) {
            this.mascot.style.setProperty('--w-store-music-level', '0');
        }
    };

    StoreMusic.prototype.readPlaybackLevel = function () {
        if (this.waveMode === 'analyser' && this.analyser) {
            var bins = this.analyser.frequencyBinCount;
            var data = new Uint8Array(bins);
            this.analyser.getByteFrequencyData(data);
            var sum = 0;
            var peak = 0;
            // Bias toward mid/low energy — more musical than raw average of high hiss.
            var use = Math.max(8, Math.floor(bins * 0.55));
            for (var i = 0; i < use; i++) {
                var v = data[i] / 255;
                sum += v;
                if (v > peak) {
                    peak = v;
                }
            }
            var avg = sum / use;
            return Math.max(0, Math.min(1, (avg * 0.72) + (peak * 0.38)));
        }
        var t = (this.audio && this.audio.currentTime) || 0;
        return Math.max(
            0.12,
            Math.min(
                0.85,
                0.28
                    + 0.22 * Math.abs(Math.sin(t * 2.15))
                    + 0.18 * Math.abs(Math.sin(t * 5.4))
                    + 0.12 * Math.abs(Math.sin(t * 0.9))
            )
        );
    };

    StoreMusic.prototype.updateWaveLoop = function () {
        if (prefersReducedMotion()) {
            this.stopWaveLoop();
            this.resetAvatarLevel();
            if (this.waveCanvas) {
                this.waveCanvas.hidden = true;
            }
            return;
        }
        if (!this.audio || this.audio.paused || document.hidden) {
            this.stopWaveLoop();
            this.resetAvatarLevel();
            if (this.waveCanvas && (!this.waveToggle || !this.waveToggle.checked)) {
                this.waveCanvas.hidden = true;
            }
            return;
        }
        // Avatar edge waves need analyser/soft clock even when full-page waveform is off.
        this.ensureAnalyser();
        var waveOn = !!(this.waveToggle && this.waveToggle.checked);
        if (this.waveCanvas) {
            // Escape zero-size host / transform ancestors so fixed + z-index paint above chrome.
            if (waveOn && this.waveCanvas.parentElement !== document.body) {
                try {
                    document.body.appendChild(this.waveCanvas);
                } catch (eMount) {
                    // ignore
                }
            }
            this.waveCanvas.hidden = !waveOn;
            if (waveOn) {
                this.waveCanvas.setAttribute('aria-hidden', 'false');
            }
        }
        this.startWaveLoop();
    };

    StoreMusic.prototype.startWaveLoop = function () {
        var self = this;
        if (this.raf) {
            return;
        }
        var draw = function () {
            if (!self.audio || self.audio.paused || document.hidden || prefersReducedMotion()) {
                self.raf = 0;
                self.resetAvatarLevel();
                return;
            }
            var level = self.readPlaybackLevel();
            self.setAvatarLevel(level);
            self.updateSpectrumBars();

            var waveOn = !!(self.waveToggle && self.waveToggle.checked);
            var canvas = self.waveCanvas;
            if (waveOn && canvas) {
                var ctx2d = canvas.getContext('2d');
                if (ctx2d) {
                    var w = canvas.clientWidth || global.innerWidth || 1;
                    var h = canvas.clientHeight || global.innerHeight || 1;
                    var dpr = Math.min(global.devicePixelRatio || 1, 1.5);
                    if (canvas.width !== Math.floor(w * dpr) || canvas.height !== Math.floor(h * dpr)) {
                        canvas.width = Math.floor(w * dpr);
                        canvas.height = Math.floor(h * dpr);
                    }
                    ctx2d.setTransform(dpr, 0, 0, dpr, 0, 0);
                    ctx2d.clearRect(0, 0, w, h);
                    ctx2d.fillStyle = accentRgba(0.42);
                    ctx2d.strokeStyle = accentRgba(0.32);
                    ctx2d.lineWidth = 1.25;

                    if (self.waveMode === 'analyser' && self.analyser) {
                        var bins = self.analyser.frequencyBinCount;
                        var step = w < 720 ? 4 : 2;
                        var data = new Uint8Array(bins);
                        self.analyser.getByteFrequencyData(data);
                        var barCount = Math.floor(bins / step);
                        var barW = w / barCount;
                        for (var i = 0; i < barCount; i++) {
                            var v = data[i * step] / 255;
                            var bh = Math.max(4, v * h * 0.55);
                            ctx2d.fillRect(i * barW, h - bh, Math.max(1, barW * 0.7), bh);
                        }
                    } else {
                        var t = (self.audio.currentTime || 0) * 1.35;
                        ctx2d.beginPath();
                        for (var x = 0; x <= w; x += 6) {
                            var y = h * 0.72
                                + Math.sin(x * 0.012 + t) * h * 0.06
                                + Math.sin(x * 0.004 + t * 0.7) * h * 0.04;
                            if (x === 0) {
                                ctx2d.moveTo(x, y);
                            } else {
                                ctx2d.lineTo(x, y);
                            }
                        }
                        ctx2d.lineTo(w, h);
                        ctx2d.lineTo(0, h);
                        ctx2d.closePath();
                        ctx2d.fill();
                        ctx2d.beginPath();
                        for (var x2 = 0; x2 <= w; x2 += 8) {
                            var y2 = h * 0.62
                                + Math.sin(x2 * 0.018 + t * 1.2) * h * 0.035;
                            if (x2 === 0) {
                                ctx2d.moveTo(x2, y2);
                            } else {
                                ctx2d.lineTo(x2, y2);
                            }
                        }
                        ctx2d.stroke();
                    }
                }
            }

            self.raf = global.requestAnimationFrame(draw);
        };
        this.raf = global.requestAnimationFrame(draw);
    };

    StoreMusic.prototype.stopWaveLoop = function () {
        if (this.raf) {
            global.cancelAnimationFrame(this.raf);
            this.raf = 0;
        }
        if (this.waveCanvas) {
            var ctx2d = this.waveCanvas.getContext('2d');
            if (ctx2d) {
                ctx2d.clearRect(0, 0, this.waveCanvas.width, this.waveCanvas.height);
            }
            if (!this.waveToggle || !this.waveToggle.checked) {
                this.waveCanvas.hidden = true;
            }
        }
    };

    var SCRIPT_GEN = '20260917-61speccenter2';

    function boot(root) {
        if (!root) {
            return null;
        }
        // Allow re-boot when a newer script generation lands (stale booted flag blocked fixes).
        if (root.dataset.storeMusicBooted === SCRIPT_GEN && global.__WelineStoreMusicLive) {
            return global.__WelineStoreMusicLive;
        }
        bumpStoreMusicEpoch();
        try {
            if (global.__WelineStoreMusicLive && typeof global.__WelineStoreMusicLive.hardSilenceMedia === 'function') {
                global.__WelineStoreMusicLive.stopLeaveHammer();
                global.__WelineStoreMusicLive.stopOrphanWatch();
                global.__WelineStoreMusicLive.hardSilenceMedia(true);
            }
        } catch (ePrev) {
            // ignore
        }
        purgeAllStoreMusicAudio();
        root.dataset.storeMusicBooted = SCRIPT_GEN;
        return new StoreMusic(root);
    }

    function autoBoot() {
        var nodes = document.querySelectorAll(ROOT_SEL);
        for (var i = 0; i < nodes.length; i++) {
            boot(nodes[i]);
        }
    }

    // Kill leftovers immediately when this script evaluates (covers cached-tab re-inject).
    try {
        purgeAllStoreMusicAudio();
    } catch (eBootPurge) {
        // ignore
    }

    global.addEventListener('pageshow', function (ev) {
        if (ev && ev.persisted) {
            purgeAllStoreMusicAudio();
            autoBoot();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoBoot);
    } else {
        autoBoot();
    }

    global.WelineStoreMusic = {
        boot: boot,
        autoBoot: autoBoot,
        killAll: purgeAllStoreMusicAudio,
        SCRIPT_GEN: SCRIPT_GEN,
        TAB_ID: TAB_ID
    };
})(typeof window !== 'undefined' ? window : globalThis);
