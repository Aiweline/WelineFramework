/**
 * StoreMusic: delayed entrance BGM + full-page waveform + resume/dismiss + single-tab leader.
 * Soft Consent via Weline.Api.resource('consent'); never blocks UI shell.
 */
(function (global) {
    'use strict';

    var ROOT_SEL = '[data-store-music]';
    var CHANNEL_NAME = 'weline.storeMusic.audio';
    var TAB_ID = 't' + String(Date.now()) + '-' + String(Math.random()).slice(2, 8);

    function parseConfig(root) {
        try {
            return JSON.parse(root.getAttribute('data-store-music-config') || '{}') || {};
        } catch (e) {
            return {};
        }
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
     * Prefer IndexedDB blob; otherwise fetch once, cache, then play via object URL.
     * Falls back to the original URL if fetch/IDB fails.
     */
    function resolveMediaSrc(url) {
        return idbGetAudio(url).then(function (cached) {
            if (cached) {
                return {
                    src: URL.createObjectURL(cached),
                    cached: true
                };
            }
            return global.fetch(url, {
                credentials: 'same-origin',
                cache: 'force-cache'
            }).then(function (res) {
                if (!res.ok) {
                    throw new Error('audio fetch ' + res.status);
                }
                return res.blob();
            }).then(function (blob) {
                idbPutAudio(url, blob);
                return {
                    src: URL.createObjectURL(blob),
                    cached: false
                };
            }).catch(function () {
                return { src: url, cached: false };
            });
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

    function whenWindowLoaded() {
        if (document.readyState === 'complete') {
            return Promise.resolve();
        }
        return new Promise(function (resolve) {
            global.addEventListener('load', function () {
                resolve();
            }, { once: true });
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
        this.panelOpen = false;
        this.needGesture = false;
        this.isLeader = false;
        this.channel = null;
        this._progressTimer = 0;
        this._pendingSeek = null;
        this._blobUrl = null;
        this._loadToken = 0;
        this._leaderBeat = 0;
        this._leaderClaimedAt = 0;
        this._lastUserActionAt = 0;
        // Document lifecycle: leave-page sets false so residual webviews cannot keep decoding.
        this._docAlive = true;
        // Only continue while hidden if we already started audibly while this document was visible.
        // Prevents prerender/zombie/hidden restores from autoplaying with no open tab UI.
        this._heardWhileVisible = false;
        this._ghostWatch = 0;

        this.waveCanvas = document.querySelector('[data-store-music-wave]');
        this.mascot = root.querySelector('[data-testid="store-music-mascot"]')
            || root.querySelector('[data-store-music-toggle]');
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
        this.playlistEl = root.querySelector('[data-store-music-playlist]');
        this.compartmentEl = root.querySelector('[data-store-music-compartment]');

        // Soft ambiance default; one-time migrate away from stale loud local prefs.
        var volDefault = Number(this.cfg.default_volume);
        if (!Number.isFinite(volDefault) || volDefault < 0) {
            volDefault = 12;
        }
        if (!readPref(root, 'vol_soft_migrated', false)) {
            writePref(root, 'volume', volDefault);
            writePref(root, 'vol_soft_migrated', '1');
        }
        var volPref = readPref(root, 'volume', volDefault);
        if (!Number.isFinite(Number(volPref))) {
            volPref = volDefault;
        }
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
        this.syncHintVisibility();
        this.warmAudioCache();
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

    StoreMusic.prototype.wantsPlay = function () {
        return this.playIntent() === 'play';
    };

    StoreMusic.prototype.setWantPlay = function (on) {
        writePref(this.root, 'want_play', on ? '1' : '0');
        if (on) {
            this.setDismissed(false);
        }
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
        // Resume unfinished position (not only >1s) so page switches feel continuous.
        if (Number.isFinite(t) && t >= 0.25) {
            this._pendingSeek = t;
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

    StoreMusic.prototype.saveProgress = function () {
        var track = this.currentTrack();
        if (!track) {
            return;
        }
        var t = 0;
        if (this.audio) {
            t = Number(this.audio.currentTime) || 0;
        } else if (this._pendingSeek != null) {
            t = Number(this._pendingSeek) || 0;
        }
        this.persistSelection(t);
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
                    if (msg.type === 'playing') {
                        self.yieldToOtherTab(msg);
                        return;
                    }
                    if (msg.type === 'released') {
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
                        setStatus(
                            self.root,
                            msg.clearWantPlay
                                ? ''
                                : textOf(self.root, '[data-store-music-i18n-other-tab]', '已在其他页面播放')
                        );
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
                var opRaw = String(ev.newValue || '');
                if (!opRaw || opRaw.indexOf(TAB_ID + '|') === 0) {
                    return;
                }
                var opParts = opRaw.split('|');
                self.yieldToOtherTab({
                    tabId: opParts[0] || '',
                    at: Number(opParts[1] || '') || 0
                });
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
                // We are still the latest operator — reclaim if focused.
                if (self.canRefuseYield({ at: Number(String(opNow.split('|')[1] || '')) || 0 })) {
                    self.claimLeadership(true);
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
            // Flush resume intent + position BEFORE killing audio so the next page can continue.
            if (self.audio && !self.audio.paused) {
                self.setWantPlay(true);
                self.saveProgress();
            } else {
                self.saveProgress();
            }
            self._docAlive = false;
            self._heardWhileVisible = false;
            self.stopGhostWatch();
            // Release lock only — do NOT force_stop peers (would mute a still-open tab until re-entry).
            self.releaseLeadership();
            // Always tear down MediaElement on leave. Pause-only is not enough: Cursor/Chrome
            // may keep a detached webview decoding after the tab UI disappears.
            self.hardSilenceMedia(true);
            self.killAllPageAudio();
            self.syncPlayingUi(false);
            // If this is a real tab close (not bfcache), also clear leader lock leftovers.
            if (ev && ev.type === 'pagehide' && ev.persisted) {
                // bfcache: keep want_play for pageshow resume; audio already stopped.
                return;
            }
        };
        global.addEventListener('pagehide', onLeave);
        global.addEventListener('beforeunload', onLeave);
        global.addEventListener('unload', onLeave);
        if (typeof document.addEventListener === 'function') {
            document.addEventListener('freeze', function () {
                self._docAlive = false;
                self._heardWhileVisible = false;
                self.hardSilenceMedia(true);
                self.killAllPageAudio();
            });
        }
        global.addEventListener('pageshow', function (ev) {
            self._docAlive = true;
            // bfcache back-navigation only: never fight a newer page that already claimed the lock.
            if (!(ev && ev.persisted) || self.playIntent() !== 'play') {
                return;
            }
            if (document.visibilityState === 'hidden') {
                return;
            }
            if (!self.acquireLeaderLock()) {
                self.yieldToOtherTab();
                return;
            }
            global.setTimeout(function () {
                if (!self._docAlive || self.playIntent() !== 'play') {
                    return;
                }
                if (document.visibilityState === 'hidden') {
                    return;
                }
                if (!self.acquireLeaderLock()) {
                    self.yieldToOtherTab();
                    return;
                }
                self.restoreProgressIndex();
                self.tryPlay({ force: false });
            }, 30);
        });
    };

    /**
     * Belt-and-suspenders: pause/clear every MediaElement in this document + shared singleton.
     * Used on leave and hidden cold-boot so detached Cursor webviews cannot keep singing.
     */
    StoreMusic.prototype.killAllPageAudio = function () {
        try {
            var nodes = document.querySelectorAll('audio, video');
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                try {
                    el.pause();
                    el.removeAttribute('src');
                    el.src = '';
                    el.load();
                } catch (e) {
                    // ignore
                }
            }
        } catch (e2) {
            // ignore
        }
        var shared = global.__WelineStoreMusicSharedAudio;
        if (shared) {
            try {
                shared.pause();
                shared.removeAttribute('src');
                shared.src = '';
                shared.load();
            } catch (e3) {
                // ignore
            }
            global.__WelineStoreMusicSharedAudio = null;
        }
        try {
            if (global.navigator && global.navigator.mediaSession) {
                global.navigator.mediaSession.playbackState = 'none';
            }
        } catch (e4) {
            // ignore
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
                    self._docAlive = false;
                    self.hardSilenceMedia(true);
                    self.killAllPageAudio();
                    self.stopGhostWatch();
                }
            } catch (e) {
                // ignore
            }
        }, 1500);
    };

    StoreMusic.prototype.stopGhostWatch = function () {
        if (this._ghostWatch) {
            global.clearInterval(this._ghostWatch);
            this._ghostWatch = 0;
        }
    };

    /**
     * Soft autoplay/resume is allowed only when this document is visible, or we already
     * started audibly while visible (user switched away but tab still exists).
     */
    StoreMusic.prototype.canAudiblyStart = function (forceUser) {
        if (!this._docAlive) {
            return false;
        }
        if (forceUser) {
            return document.visibilityState !== 'hidden';
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
            var cur = this.readLeaderLock();
            // Soft autoplay/resume: another tab is actively leading → stay quiet here.
            if (cur && cur.tabId && cur.tabId !== TAB_ID && (now - cur.at) < 2500) {
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
            if (!self.audio || self.audio.paused || !self.isLeader) {
                return;
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
            self.broadcastLeaderState('playing');
        }, 1000);
    };

    StoreMusic.prototype.stopLeaderHeartbeat = function () {
        if (this._leaderBeat) {
            global.clearInterval(this._leaderBeat);
            this._leaderBeat = 0;
        }
    };

    StoreMusic.prototype.broadcastLeaderState = function (type) {
        if (!this.channel) {
            return;
        }
        var track = this.currentTrack();
        // Heartbeat must NOT bump `at` with Date.now() — that would outrank a newer user op on another page.
        var at = this.localOperatorAt() || Date.now();
        try {
            this.channel.postMessage({
                type: type || 'playing',
                tabId: TAB_ID,
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
        this.broadcastLeaderState('playing');
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
        try {
            this.channel.postMessage({
                type: 'force_stop',
                tabId: TAB_ID,
                at: at,
                clearWantPlay: !!clearWantPlay
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
            return;
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
    };

    StoreMusic.prototype.yieldToOtherTab = function (msg) {
        // Latest user operation wins. Only refuse if WE are still newer than the remote claim.
        if (this.canRefuseYield(msg)) {
            this.claimLeadership(true);
            setStatus(this.root, '');
            if (this.playIntent() === 'play' && this.audio && this.audio.paused) {
                var self = this;
                try {
                    var p = this.audio.play();
                    if (p && typeof p.then === 'function') {
                        p.then(function () {
                            self.syncPlayingUi(true);
                        }).catch(function () {
                            // Gesture may be required; UI stays operable.
                        });
                    }
                } catch (e) {
                    // ignore
                }
            }
            return;
        }
        // Remote is authoritative (or we are background): hard-drop residual MediaElement.
        this.silenceForOtherTab(false, true);
        if (msg) {
            this.applyRemoteSelection(msg);
        }
        setStatus(this.root, textOf(this.root, '[data-store-music-i18n-other-tab]', '已在其他页面播放'));
    };

    /**
     * After another tab releases leadership, resume here without stealing if still wanted.
     * Fixes "must enter the tab to hear" after the previous leader closed.
     */
    StoreMusic.prototype.maybeResumeAfterPeerRelease = function () {
        var self = this;
        if (document.visibilityState === 'hidden') {
            return;
        }
        if (this.playIntent() !== 'play') {
            return;
        }
        if (this.audio && !this.audio.paused) {
            return;
        }
        global.setTimeout(function () {
            if (document.visibilityState === 'hidden' || self.playIntent() !== 'play') {
                return;
            }
            if (self.audio && !self.audio.paused) {
                return;
            }
            if (!self.acquireLeaderLock(false)) {
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
        if (this.playlistCountEl) {
            this.playlistCountEl.textContent = multi ? ('共 ' + this.tracks.length + ' 首') : '';
        }
        this.playlistEl.textContent = '';
        if (!multi) {
            return;
        }
        try {
            this.tracks.forEach(function (track, index) {
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
    };

    StoreMusic.prototype.syncPlaylistActive = function () {
        if (!this.playlistEl) {
            return;
        }
        var buttons = this.playlistEl.querySelectorAll('[data-store-music-track]');
        for (var i = 0; i < buttons.length; i++) {
            var btn = buttons[i];
            var active = Number(btn.getAttribute('data-store-music-track')) === this.trackIndex;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
            var mark = btn.querySelector('[data-store-music-track-mark]');
            if (mark) {
                if (active) {
                    mark.textContent = '✓';
                } else {
                    var n = i + 1;
                    mark.textContent = n < 10 ? '0' + n : String(n);
                }
            }        }
    };

    StoreMusic.prototype.selectTrack = function (index) {
        var next = Number(index);
        if (!Number.isFinite(next) || next < 0 || next >= this.tracks.length) {
            return;
        }
        this.markUserOperating();
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
        if (!this._docAlive || !this.canAudiblyStart(true)) {
            return Promise.resolve(null);
        }
        this.markUserOperating();
        this.claimLeadership(true);
        setStatus(this.root, '');
        var audio = this.mountAudioElement();
        audio.__welineStoreMusicOwner = this;
        var multi = this.tracks.length > 1;
        var loopOne = !multi && !(this.cfg.loop === false || this.cfg.loop === 0 || this.cfg.loop === '0');
        audio.loop = !!loopOne;
        var vol = this.volume ? Number(this.volume.value) : Number(this.cfg.default_volume || 12);
        audio.volume = Math.max(0, Math.min(1, vol / 100));
        // Hard-stop previous decode path before swapping src (prevents 高山流水+孤城叠播).
        try {
            audio.pause();
        } catch (e) {
            // ignore
        }
        if (this._blobUrl) {
            try {
                URL.revokeObjectURL(this._blobUrl);
            } catch (e2) {
                // ignore
            }
            this._blobUrl = null;
        }
        this.waveConnected = false;
        this.source = null;
        this.analyser = null;
        if (this.ctx && typeof this.ctx.close === 'function') {
            try {
                this.ctx.close();
            } catch (eCtx) {
                // ignore
            }
            this.ctx = null;
        }
        // Direct URL — do not wait for IDB/fetch (keeps user activation).
        audio.setAttribute('data-store-music-src', url);
        audio.src = url;
        this.audioReady = true;
        this.syncTrackMeta(true);
        // Ensure only this element is audible in this browsing context.
        var shared = global.__WelineStoreMusicSharedAudio;
        if (shared && shared !== audio) {
            try {
                shared.pause();
                shared.removeAttribute('src');
                shared.src = '';
                shared.load();
            } catch (e3) {
                // ignore
            }
            global.__WelineStoreMusicSharedAudio = audio;
        }
        var playResult = null;
        try {
            playResult = audio.play();
        } catch (err) {
            this.setNeedGesture(true);
            return Promise.reject(err);
        }
        // Warm IDB cache without interrupting playback.
        this.cacheTrackInBackground(url);
        if (!playResult || typeof playResult.then !== 'function') {
            this.setWantPlay(true);
            this.markHeardWhileVisible();
            this.syncPlayingUi(true);
            this.broadcastLeaderState('playing');
            return Promise.resolve(audio);
        }
        return playResult.then(function () {
            self.setWantPlay(true);
            self.needGesture = false;
            self.markHeardWhileVisible();
            self.syncPlayingUi(true);
            self.broadcastLeaderState('playing');
            setStatus(self.root, '');
            return audio;
        }).catch(function (err) {
            var name = err && err.name ? String(err.name) : '';
            setStatus(self.root, '');
            self.setNeedGesture(true);
            if (name !== 'NotAllowedError' && name !== 'NotSupportedError') {
                setStatus(self.root, textOf(self.root, '[data-store-music-i18n-play-error]', '音频加载失败，请换一首曲目或格式'));
            }
            return audio;
        });
    };

    StoreMusic.prototype.cacheTrackInBackground = function (url) {
        if (!url || !global.fetch) {
            return;
        }
        idbGetAudio(url).then(function (hit) {
            if (hit) {
                return;
            }
            return global.fetch(url, {
                credentials: 'same-origin',
                cache: 'force-cache'
            }).then(function (res) {
                if (!res.ok) {
                    return null;
                }
                return res.blob();
            }).then(function (blob) {
                if (blob) {
                    return idbPutAudio(url, blob);
                }
            });
        }).catch(function () {
            // Soft.
        });
    };

    StoreMusic.prototype.mountAudioElement = function () {
        var audio = this.audio;
        if (!audio) {
            if (global.__WelineStoreMusicSharedAudio) {
                audio = global.__WelineStoreMusicSharedAudio;
            } else {
                audio = new Audio();
                global.__WelineStoreMusicSharedAudio = audio;
            }
            audio.preload = 'metadata';
            this.audio = audio;
        }
        audio.__welineStoreMusicOwner = this;
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
                try {
                    audio.pause();
                } catch (e) {
                    // ignore
                }
                owner.hardSilenceMedia(false);
                owner.syncPlayingUi(false);
                setStatus(
                    owner.root,
                    textOf(owner.root, '[data-store-music-i18n-other-tab]', '已在其他页面播放')
                );
                return;
            }
            owner.markHeardWhileVisible();
            owner.setWantPlay(true);
            owner.syncPlayingUi(true);
            setStatus(owner.root, '');
            owner.ensureAnalyser();
            owner.updateWaveLoop();
            owner.startProgressWatch();
        });
        audio.addEventListener('pause', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            owner.saveProgress();
            owner.syncPlayingUi(false);
            owner.stopWaveLoop();
            owner.stopProgressWatch();
        });
        audio.addEventListener('timeupdate', function () {
            var owner = audio.__welineStoreMusicOwner || self;
            if (!owner._progressTimer) {
                owner._progressTimer = global.setTimeout(function () {
                    owner._progressTimer = 0;
                    owner.saveProgress();
                }, 2000);
            }
        });
        audio.addEventListener('ended', function () {
            var owner = audio.__welineStoreMusicOwner || self;
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
        }
        this.syncPlaylistActive();
    };

    StoreMusic.prototype.bind = function () {
        var self = this;
        // Any pointer on the widget = this page is the operator; keep UI clickable.
        if (this.root) {
            this.root.addEventListener('pointerdown', function () {
                self.markUserOperating();
                // Prime Audio element during the gesture so later play() is allowed.
                try {
                    self.mountAudioElement();
                } catch (e) {
                    // ignore
                }
            }, true);
        }
        if (this.toggleBtns && this.toggleBtns.length) {
            Array.prototype.forEach.call(this.toggleBtns, function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    self.markUserOperating();
                    self.togglePanel();
                    // Opening the panel must always work; play is separate unless gesture needed.
                    if (self.needGesture || self.playIntent() === 'play') {
                        self.userPlay();
                    }
                });
            });
        } else if (this.mascot) {
            this.mascot.addEventListener('click', function (ev) {
                ev.preventDefault();
                self.markUserOperating();
                self.togglePanel();
                if (self.needGesture || self.playIntent() === 'play') {
                    self.userPlay();
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
                self.markUserOperating();
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
            this.volume.addEventListener('input', function () {
                self.markUserOperating();
                var v = Number(self.volume.value) || 0;
                writePref(self.root, 'volume', v);
                self.syncVolumePct(v);
                if (self.audio) {
                    self.audio.volume = Math.max(0, Math.min(1, v / 100));
                }
            });
        }
        if (this.waveToggle) {
            this.waveToggle.addEventListener('change', function () {
                self.markUserOperating();
                writePref(self.root, 'wave', self.waveToggle.checked ? '1' : '0');
                self.syncWaveLabel();
                self.updateWaveLoop();
            });
        }
        document.addEventListener('visibilitychange', function () {
            // visibility hidden ≠ leave page. Keep BGM only if we already started while visible.
            // Multi-tab handoff stays on pagehide hardStop + force-steal / yieldToOtherTab.
            if (document.hidden) {
                // Drop "recent operator" so a hidden page cannot refuse yield and leave a residual track.
                self._lastUserActionAt = 0;
                self.saveProgress();
                self.stopWaveLoop();
                // Never-started hidden docs (prerender/zombie) must stay silent.
                if (!self._heardWhileVisible) {
                    self.hardSilenceMedia(false);
                    self.killAllPageAudio();
                    self.syncPlayingUi(false);
                }
                return;
            }
            self._docAlive = true;
            self.updateWaveLoop();
            if (self.audio && !self.audio.paused) {
                self.markHeardWhileVisible();
                setStatus(self.root, '');
                self.syncPlayingUi(true);
                return;
            }
            // Soft resume / delayed schedule only when actually visible.
            if (self.playIntent() === 'play' || self.playIntent() === 'unset') {
                global.setTimeout(function () {
                    if (document.visibilityState !== 'visible' || !self._docAlive) {
                        return;
                    }
                    if (self.audio && !self.audio.paused) {
                        return;
                    }
                    if (self.playIntent() === 'stop') {
                        return;
                    }
                    if (!self.acquireLeaderLock(false)) {
                        self.yieldToOtherTab();
                        return;
                    }
                    if (self.playIntent() === 'play') {
                        self.tryPlay({ force: false });
                    } else {
                        self.scheduleStart();
                    }
                }, 40);
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
     * Equal timestamps prefer silence (no residual dual-play). Missing at → remote wins.
     */
    StoreMusic.prototype.isRemoteNewer = function (msg) {
        var remote = this.remoteActionAt(msg);
        if (!remote) {
            return true;
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
        if (this.audio) {
            try {
                this.audio.pause();
            } catch (e) {
                // ignore
            }
            try {
                this.audio.removeAttribute('src');
                this.audio.removeAttribute('data-store-music-src');
                this.audio.src = '';
                this.audio.load();
            } catch (e2) {
                // ignore
            }
        }
        if (this._blobUrl) {
            try {
                URL.revokeObjectURL(this._blobUrl);
            } catch (e3) {
                // ignore
            }
            this._blobUrl = null;
        }
        if (this.ctx && typeof this.ctx.close === 'function') {
            try {
                this.ctx.close();
            } catch (e4) {
                // ignore
            }
            this.ctx = null;
        }
        this.waveConnected = false;
        this.source = null;
        this.analyser = null;
        this.audioReady = false;
        var shared = global.__WelineStoreMusicSharedAudio;
        if (shared && (shared === this.audio || dropShared)) {
            try {
                shared.pause();
                shared.removeAttribute('src');
                shared.src = '';
                shared.load();
            } catch (e5) {
                // ignore
            }
            if (dropShared) {
                global.__WelineStoreMusicSharedAudio = null;
                this.audio = null;
            }
        } else if (shared && shared !== this.audio) {
            try {
                shared.pause();
                shared.removeAttribute('src');
                shared.src = '';
                shared.load();
            } catch (e6) {
                // ignore
            }
        }
    };

    /**
     * Drop leadership and silence residual audio (keep want_play unless clearWantPlay).
     * @param {boolean} [clearWantPlay]
     * @param {boolean} [hard] clear src / AudioContext (default true — last op must not leave residuals)
     */
    StoreMusic.prototype.silenceForOtherTab = function (clearWantPlay, hard) {
        this.isLeader = false;
        this._lastUserActionAt = 0;
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
            return;
        }
        this.hardSilenceMedia(false);
        this.syncPlayingUi(false);
    };

    /**
     * Mark this document as the operator page (latest user action wins).
     */
    StoreMusic.prototype.markUserOperating = function () {
        var at = Date.now();
        this._lastUserActionAt = at;
        this._leaderClaimedAt = at;
        this.setDismissed(false);
        setStatus(this.root, '');
        this.acquireLeaderLock(true);
        try {
            global.localStorage.setItem(storageKey(this.root, 'operator'), TAB_ID + '|' + at);
        } catch (e) {
            // ignore
        }
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
        if (this.panel) {
            this.panel.hidden = !this.panelOpen;
            // Clear leftover inline hide styles (e.g. tooling that forces
            // opacity:0.001 / pointer-events:none on [hidden] nodes).
            if (this.panelOpen) {
                this.panel.style.opacity = '';
                this.panel.style.pointerEvents = '';
                this.panel.removeAttribute('aria-hidden');
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
        this.syncHintVisibility();
    };

    StoreMusic.prototype.scheduleStart = function () {
        var self = this;
        if (!this._docAlive) {
            return;
        }
        if (document.visibilityState === 'hidden' && !this._heardWhileVisible) {
            // Wait until this document is actually shown (no ghost autoplay).
            return;
        }
        var intent = this.playIntent();
        if (this.isDismissed() && intent !== 'play') {
            this.setNeedGesture(false);
            this.syncHintVisibility();
            setStatus(this.root, textOf(this.root, '[data-store-music-i18n-dismissed]', '已关闭自动播放，点击播放可重新开启'));
            return;
        }
        if (intent === 'stop') {
            // User explicitly stopped — do not honor try_autoplay until they press play again.
            this.setNeedGesture(true);
            this.syncHintVisibility();
            return;
        }
        // Re-apply snap in case boot raced; ensures we never fall back to track 0 while
        // progress still points at 孤城 (or any unfinished selection).
        this.restoreProgressIndex();
        var hasSnap = this.hasResumeSnap();
        var resume = intent === 'play' || (intent === 'unset' && hasSnap);
        var delaySec = Math.max(1, Math.min(15, Number(this.cfg.delay_seconds) || 3));
        // Cross-page / refresh resume: start ASAP on the saved track. Cold first visit keeps delay.
        var waitMs = resume ? 0 : delaySec * 1000;
        whenWindowLoaded().then(function () {
            return delay(waitMs);
        }).then(function () {
            if (self.playIntent() === 'stop') {
                return;
            }
            if (self.isDismissed() && self.playIntent() !== 'play') {
                return;
            }
            return marketingAllowed();
        }).then(function (allowed) {
            if (allowed === undefined) {
                return;
            }
            if (!allowed) {
                setStatus(self.root, textOf(self.root, '[data-store-music-i18n-need-consent]', '请先同意营销 Cookie 后再播放'));
                self.setNeedGesture(true);
                return;
            }
            var latest = self.playIntent();
            if (latest === 'stop') {
                self.setNeedGesture(true);
                return;
            }
            self.restoreProgressIndex();
            var snapAgain = self.hasResumeSnap();
            // 1) want_play → resume unfinished selection
            // 2) progress snap after page switch → resume that track (never force first item)
            // 3) cold visit only → try_autoplay first/current playlist head
            var shouldPlay = latest === 'play'
                || (latest === 'unset' && snapAgain)
                || (latest === 'unset'
                    && !snapAgain
                    && self.cfg.try_autoplay !== false
                    && self.cfg.try_autoplay !== 0
                    && self.cfg.try_autoplay !== '0');
            if (!shouldPlay) {
                self.setNeedGesture(true);
                return;
            }
            if (!self.canAudiblyStart(false)) {
                self.setNeedGesture(true);
                return;
            }
            return self.ensureAudio().then(function () {
                return self.tryPlay();
            });
        }).catch(function () {
            self.setNeedGesture(true);
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
                idbGetAudio(url).then(function (hit) {
                    if (hit || self.isDismissed()) {
                        return;
                    }
                    return global.fetch(url, {
                        credentials: 'same-origin',
                        cache: 'force-cache'
                    }).then(function (res) {
                        if (!res.ok) {
                            return null;
                        }
                        return res.blob();
                    }).then(function (blob) {
                        if (blob) {
                            return idbPutAudio(url, blob);
                        }
                    });
                }).catch(function () {
                    // Soft.
                });
            }, 350 + index * 280);
        });
    };

    StoreMusic.prototype.ensureAudio = function () {
        var self = this;
        var track = this.currentTrack();
        var url = track ? String(track.url || '').trim() : '';
        if (!url) {
            return Promise.reject(new Error('no track'));
        }
        if (this.audioReady && this.audio && this.audio.getAttribute('data-store-music-src') === url) {
            this.syncTrackMeta(!!(this.audio && !this.audio.paused));
            return Promise.resolve(this.audio);
        }
        // Switching track: pause first so the previous src cannot keep audible while the next loads.
        if (this.audio && this.audio.getAttribute('data-store-music-src') !== url) {
            try {
                this.audio.pause();
            } catch (e) {
                // ignore
            }
            this.waveConnected = false;
            this.source = null;
            this.analyser = null;
            this.audioReady = false;
        }
        var audio = this.audio;
        if (!audio) {
            // Process-wide singleton: prevents accidental double Audio if the module boots twice.
            if (global.__WelineStoreMusicSharedAudio) {
                audio = global.__WelineStoreMusicSharedAudio;
                try {
                    audio.pause();
                } catch (e) {
                    // ignore
                }
            } else {
                audio = new Audio();
                global.__WelineStoreMusicSharedAudio = audio;
            }
            // Prefer metadata (not auto) so resume seek works without full preload bandwidth.
            audio.preload = 'metadata';
            this.audio = audio;
        }
        audio.__welineStoreMusicOwner = this;
        this.bindAudioElementEvents(audio);
        var multi = this.tracks.length > 1;
        var loopOne = !multi && !(this.cfg.loop === false || this.cfg.loop === 0 || this.cfg.loop === '0');
        audio.loop = !!loopOne;
        var vol = this.volume ? Number(this.volume.value) : Number(this.cfg.default_volume || 12);
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
        return resolveMediaSrc(url).then(function (resolved) {
            if (token !== self._loadToken) {
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
            }
            audio.setAttribute('data-store-music-src', url);
            audio.src = resolved.src;
            self.audioReady = true;
            self.syncTrackMeta(false);
            return audio;
        });
    };

    StoreMusic.prototype.applyPendingSeek = function () {
        if (!this.audio || this._pendingSeek === null || this._pendingSeek === undefined) {
            return;
        }
        var t = Number(this._pendingSeek);
        this._pendingSeek = null;
        if (!Number.isFinite(t) || t < 1) {
            return;
        }
        try {
            var dur = Number(this.audio.duration);
            if (Number.isFinite(dur) && dur > 2) {
                t = Math.min(t, Math.max(0, dur - 1));
            }
            this.audio.currentTime = t;
        } catch (e) {
            // ignore seek errors
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

    StoreMusic.prototype.onTrackEnded = function () {
        this.syncPlayingUi(false);
        this.stopWaveLoop();
        if (this.tracks.length > 1) {
            var loopPlaylist = !(this.cfg.loop === false || this.cfg.loop === 0 || this.cfg.loop === '0');
            var next = this.trackIndex + 1;
            if (next >= this.tracks.length) {
                if (!loopPlaylist) {
                    this.persistSelection(0);
                    return;
                }
                next = 0;
            }
            this.trackIndex = next;
            this._pendingSeek = 0;
            this.persistSelection(0);
            this.syncPlaylistActive();
            var self = this;
            this.ensureAudio().then(function () {
                return self.tryPlay();
            }).catch(function () {
                self.setNeedGesture(true);
            });
        } else {
            this.persistSelection(0);
        }
    };

    StoreMusic.prototype.skipTrack = function (delta) {
        if (this.tracks.length < 2) {
            return;
        }
        var next = (this.trackIndex + delta + this.tracks.length) % this.tracks.length;
        this.selectTrack(next);
    };

    StoreMusic.prototype.syncPlayingUi = function (playing) {
        if (this.playBtn) {
            this.playBtn.hidden = !!playing;
        }
        if (this.pauseBtn) {
            this.pauseBtn.hidden = !playing;
        }
        if (this.root) {
            this.root.classList.toggle('is-playing', !!playing);
        }
        this.syncTrackMeta(!!playing);
        if (playing) {
            this.needGesture = false;
            if (this.mascot) {
                this.mascot.classList.remove('is-need-gesture');
            }
            setStatus(this.root, '');
        }
        this.syncHintVisibility();
    };

    StoreMusic.prototype.tryPlay = function (opts) {
        var self = this;
        var force = !!(opts && opts.force);
        if (!this._docAlive) {
            return Promise.resolve();
        }
        if (force) {
            return this.playCurrentTrackNow();
        }
        if (!this.canAudiblyStart(false)) {
            return Promise.resolve();
        }
        if (!this.acquireLeaderLock(false)) {
            this.yieldToOtherTab();
            return Promise.resolve();
        }
        setStatus(this.root, '');
        return this.ensureAudio().then(function (audio) {
            if (!self._docAlive || !self.canAudiblyStart(false)) {
                return;
            }
            audio.__welineStoreMusicOwner = self;
            if (!self.acquireLeaderLock(false)) {
                self.yieldToOtherTab();
                return;
            }
            self.applyPendingSeek();
            if (!self.claimLeadership(false)) {
                return;
            }
            var p = audio.play();
            if (!p || typeof p.then !== 'function') {
                self.setWantPlay(true);
                self.markHeardWhileVisible();
                self.syncPlayingUi(true);
                return;
            }
            return p.then(function () {
                if (!self._docAlive || !self.acquireLeaderLock(false)) {
                    try {
                        audio.pause();
                    } catch (e) {
                        // ignore
                    }
                    self.yieldToOtherTab();
                    return;
                }
                self.setWantPlay(true);
                self.markHeardWhileVisible();
                self.syncPlayingUi(true);
                self.broadcastLeaderState('playing');
            }).catch(function (err) {
                var name = err && err.name ? String(err.name) : '';
                if (name === 'NotAllowedError' || name === 'NotSupportedError') {
                    self.setNeedGesture(true);
                    return;
                }
                self.setNeedGesture(true);
            });
        });
    };

    StoreMusic.prototype.userPlay = function () {
        var self = this;
        this.markUserOperating();
        this.setDismissed(false);
        this.setWantPlay(true);
        setStatus(this.root, '');
        // Play inside this click first; consent is soft and must not delay gesture play.
        var playing = this.playCurrentTrackNow();
        marketingAllowed().then(function (allowed) {
            if (!allowed) {
                try {
                    if (self.audio) {
                        self.audio.pause();
                    }
                } catch (e) {
                    // ignore
                }
                setStatus(self.root, textOf(self.root, '[data-store-music-i18n-need-consent]', '请先同意营销 Cookie 后再播放'));
                self.setNeedGesture(true);
                self.syncPlayingUi(false);
            }
        });
        return playing;
    };

    /** Explicit stop: pause + clear resume-across-refresh intent. Closing the panel must NOT call this. */
    StoreMusic.prototype.stopPlayback = function () {
        this.markUserOperating();
        this.setWantPlay(false);
        this.pause();
        this.broadcastForceStop(true);
        this.broadcastLeaderState('stop');
        this.releaseLeadership();
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

    StoreMusic.prototype.ensureAnalyser = function () {
        if (this.waveConnected || !this.audio) {
            return;
        }
        if (prefersReducedMotion()) {
            this.waveMode = 'soft';
            return;
        }
        var AC = global.AudioContext || global.webkitAudioContext;
        if (!AC) {
            this.waveMode = 'soft';
            return;
        }
        try {
            this.ctx = this.ctx || new AC();
            if (this.ctx.state === 'suspended') {
                this.ctx.resume();
            }
            this.source = this.ctx.createMediaElementSource(this.audio);
            this.analyser = this.ctx.createAnalyser();
            this.analyser.fftSize = 256;
            this.source.connect(this.analyser);
            this.analyser.connect(this.ctx.destination);
            this.waveConnected = true;
            this.waveMode = 'analyser';
            this.waveFailed = false;
        } catch (e) {
            // Fall back to soft ink-wave so body atmosphere still shows.
            this.waveMode = 'soft';
            this.waveFailed = false;
            this.syncWaveLabel();
        }
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

    StoreMusic.prototype.updateWaveLoop = function () {
        if (!this.waveToggle || !this.waveToggle.checked || prefersReducedMotion()) {
            this.stopWaveLoop();
            if (this.waveCanvas) {
                this.waveCanvas.hidden = true;
            }
            return;
        }
        if (!this.audio || this.audio.paused || document.hidden) {
            this.stopWaveLoop();
            return;
        }
        this.ensureAnalyser();
        if (!this.waveCanvas) {
            return;
        }
        this.waveCanvas.hidden = false;
        this.waveCanvas.setAttribute('aria-hidden', 'false');
        this.startWaveLoop();
    };

    StoreMusic.prototype.startWaveLoop = function () {
        var self = this;
        if (this.raf) {
            return;
        }
        var canvas = this.waveCanvas;
        var ctx2d = canvas.getContext('2d');
        if (!ctx2d) {
            return;
        }
        var draw = function () {
            if (!self.waveToggle || !self.waveToggle.checked || !self.audio || self.audio.paused || document.hidden) {
                self.raf = 0;
                return;
            }
            var w = canvas.clientWidth || global.innerWidth || 1;
            var h = canvas.clientHeight || global.innerHeight || 1;
            var dpr = Math.min(global.devicePixelRatio || 1, 1.5);
            if (canvas.width !== Math.floor(w * dpr) || canvas.height !== Math.floor(h * dpr)) {
                canvas.width = Math.floor(w * dpr);
                canvas.height = Math.floor(h * dpr);
            }
            ctx2d.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx2d.clearRect(0, 0, w, h);
            var fill = accentRgba(0.28);
            ctx2d.fillStyle = fill;
            ctx2d.strokeStyle = accentRgba(0.22);
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
                    var bh = Math.max(2, v * h * 0.42);
                    ctx2d.fillRect(i * barW, h - bh, Math.max(1, barW * 0.65), bh);
                }
            } else {
                // Soft ink-wash undulation driven by playback clock (no WebAudio required).
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
            // Keep canvas in DOM; hide only when wave toggle off.
            if (!this.waveToggle || !this.waveToggle.checked) {
                this.waveCanvas.hidden = true;
            }
        }
    };

    function boot(root) {
        if (!root || root.dataset.storeMusicBooted === '1') {
            return;
        }
        root.dataset.storeMusicBooted = '1';
        return new StoreMusic(root);
    }

    function autoBoot() {
        var nodes = document.querySelectorAll(ROOT_SEL);
        for (var i = 0; i < nodes.length; i++) {
            boot(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoBoot);
    } else {
        autoBoot();
    }

    global.WelineStoreMusic = {
        boot: boot,
        autoBoot: autoBoot,
        TAB_ID: TAB_ID
    };
})(typeof window !== 'undefined' ? window : globalThis);
