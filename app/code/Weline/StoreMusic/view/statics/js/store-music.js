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

    function audioRegistry() {
        if (!global.__WelineStoreMusicAudioRegistry) {
            global.__WelineStoreMusicAudioRegistry = [];
        }
        return global.__WelineStoreMusicAudioRegistry;
    }

    function registerStoreAudio(audio) {
        if (!audio) {
            return;
        }
        var list = audioRegistry();
        if (list.indexOf(audio) === -1) {
            list.push(audio);
        }
    }

    /**
     * Silence every MediaElement in this browsing context except optional keep.
     * Prevents 高山流水+孤城 (or zombie Audio) dual-play when switching tracks.
     */
    function silenceMediaElement(el) {
        if (!el) {
            return;
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
            var nodes = document.querySelectorAll('audio, video');
            for (var j = 0; j < nodes.length; j++) {
                if (nodes[j] !== keep) {
                    silenceMediaElement(nodes[j]);
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
        this._lastGoodProgressTime = 0;
        this._blobUrl = null;
        this._loadToken = 0;
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
        // Document lifecycle: leave-page sets false so residual webviews cannot keep decoding.
        this._docAlive = true;
        this._tearingDown = false;
        // Only continue while hidden if we already started audibly while this document was visible.
        // Prevents prerender/zombie/hidden restores from autoplaying with no open tab UI.
        this._heardWhileVisible = false;
        this._ghostWatch = 0;
        this._orphanWatch = 0;
        this._leaveHammer = 0;
        this._originTabWatch = 0;
        this._audioMutating = false;
        this._collapsedHits = 0;

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
        this.bindConsentAutoplayRetry();
        this.syncHintVisibility();
        this.warmAudioCache();
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
        // Resume unfinished position (not only >1s) so page switches feel continuous.
        if (Number.isFinite(t) && t >= 0.25) {
            this._pendingSeek = t;
            this._lastGoodProgressTime = t;
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
                        return;
                    }
                    if (msg.type === 'tab_dead') {
                        global.setTimeout(function () {
                            if (!self.hasLiveOriginTab()) {
                                self.hardSilenceMedia(true);
                                self.killAllPageAudio();
                                self.syncPlayingUi(false);
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
            self.saveProgress();
            // bfcache (Chrome back/forward, many in-site navigations): keep the MediaElement
            // alive like YouTube — hard tearDown would stop audio and force a cold restart.
            if (ev && ev.type === 'pagehide' && ev.persisted) {
                self.stopLeaderHeartbeat();
                self.stopOriginTabWatch();
                self.stopGhostWatch();
                self.stopOrphanWatch();
                // Stay in census as hidden so a soft-loaded peer does not think the domain is empty.
                self.touchOriginTab();
                return;
            }
            // Hard leave / full refresh: document will be destroyed — drop census + silence.
            self.dropOriginTab();
            // Do NOT broadcastForceStop here. A refresh successor often boots within ~200ms;
            // a late force_stop from the dying document would silence the new page mid-resume.
            // Local tearDown + releaseLeadership('released') is enough; live peers reclaim.
            self.tearDownForLeave(ev && ev.type ? String(ev.type) : 'leave');
        };
        // Capture phase: Cursor/Electron guest teardown may skip bubble listeners.
        // Prefer pagehide; beforeunload/unload are backup for hosts that skip pagehide.
        global.addEventListener('pagehide', onLeave, true);
        global.addEventListener('beforeunload', function (ev) {
            // beforeunload is not bfcache-safe — only snapshot progress; pagehide does teardown.
            self.saveProgress();
        }, true);
        global.addEventListener('unload', function (ev) {
            self.saveProgress();
            if (self._docAlive) {
                self.tearDownForLeave('unload');
            }
        }, true);
        if (typeof document.addEventListener === 'function') {
            document.addEventListener('freeze', function () {
                self.tearDownForLeave('freeze');
            }, true);
        }
        self.bindMediaSessionStopHandlers();
        global.addEventListener('pageshow', function (ev) {
            self.stopLeaveHammer();
            self.stopOrphanWatch();
            self._docAlive = true;
            self._tearingDown = false;
            self._collapsedHits = 0;
            self.startGhostWatch();
            self.startOriginTabWatch();
            self.touchOriginTab();
            // bfcache restore: MediaElement often still playing — reclaim lock, do not remount@0.
            if (ev && ev.persisted) {
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
     * Belt-and-suspenders: pause/clear every MediaElement in this document + shared singleton.
     * Used on leave and hidden cold-boot so detached Cursor webviews cannot keep singing.
     */
    StoreMusic.prototype.killAllPageAudio = function () {
        killRivalAudioElements(null);
        this.audio = null;
        this.audioReady = false;
        this.waveConnected = false;
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
     * Hard leave teardown: mark dead, drop leadership, destroy MediaElement, hammer residuals.
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
        // Clear MediaSession handlers before pause/src clear (see clearMediaSession).
        this.clearMediaSession();
        this.stopOrphanWatch();
        this.stopGhostWatch();
        this.stopOriginTabWatch();
        this.dropOriginTab();
        // Release lock only — do NOT force_stop peers (would mute a still-open / successor tab).
        this.releaseLeadership();
        this.hardSilenceMedia(true);
        this.killAllPageAudio();
        this.syncPlayingUi(false);
        this.startLeaveHammer(reason || 'leave');
    };

    /**
     * After leave, keep silencing briefly: some hosts re-kick decode right after pause/src clear.
     */
    StoreMusic.prototype.startLeaveHammer = function () {
        var self = this;
        this.stopLeaveHammer();
        var n = 0;
        this._leaveHammer = global.setInterval(function () {
            self.hardSilenceMedia(true);
            self.killAllPageAudio();
            n += 1;
            if (n >= 12) {
                self.stopLeaveHammer();
            }
        }, 120);
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
     * Background BGM is allowed while the tab still exists. When the host tears down the
     * webview without pagehide (Cursor Browser close), DOM often disconnects or the window
     * collapses to 0×0 — then we must hard-stop so audio cannot outlive the UI.
     */
    StoreMusic.prototype.startOrphanWatch = function () {
        var self = this;
        this.stopOrphanWatch();
        this._collapsedHits = 0;
        this._orphanWatch = global.setInterval(function () {
            if (!self._docAlive) {
                self.hardSilenceMedia(true);
                self.killAllPageAudio();
                self.stopOrphanWatch();
                return;
            }
            try {
                var disconnected = !document.documentElement || !document.documentElement.isConnected;
                var collapsed = self.isCollapsedDetachedView();
                if (disconnected || collapsed) {
                    self._collapsedHits = (Number(self._collapsedHits) || 0) + 1;
                    if (self._collapsedHits < 4) {
                        return;
                    }
                    self.tearDownForLeave(disconnected ? 'orphan-disconnected' : 'orphan-collapsed');
                    return;
                }
                self._collapsedHits = 0;
                // Still a real hidden tab of this domain — keep census alive.
                self.touchOriginTab();
            } catch (e) {
                // ignore
            }
        }, 500);
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
     * Only treat as detached when the document is gone or the window is truly 0×0 while hidden.
     */
    StoreMusic.prototype.isCollapsedDetachedView = function () {
        try {
            if (!document.documentElement || !document.documentElement.isConnected) {
                return true;
            }
            // Visible tabs are always "real" — never drop them from the domain census.
            if (document.visibilityState === 'visible') {
                return false;
            }
            var ow = Number(global.outerWidth) || 0;
            var oh = Number(global.outerHeight) || 0;
            // Hidden but still a real tab: keep counting. Only 0×0 chrome (zombie webview) drops.
            return ow === 0 && oh === 0;
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
                kept[id] = { at: at, host: row.host || PAGE_HOST };
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
            self.broadcastLeaderState('playing', true);
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
     * Refresh / same-tab resume: clear leftover leader_lock when no other VISIBLE tab exists.
     * Hard F5 races leave a lock + census entry for ~1s; that must not block soft tryPlay.
     */
    StoreMusic.prototype.clearStaleLeaderForSoloResume = function () {
        if (this.playIntent() !== 'play' && !this.hasResumeSnap()) {
            return;
        }
        if (this.countVisiblePeerTabs() > 0) {
            return;
        }
        try {
            global.localStorage.removeItem(this.leaderLockKey());
        } catch (e) {
            // ignore
        }
    };

    /** True when another *live* tab refreshed the leader lock recently (soft autoplay stays quiet). */
    StoreMusic.prototype.hasActivePeerLeader = function () {
        var cur = this.readLeaderLock();
        if (!cur || !cur.tabId || cur.tabId === TAB_ID) {
            return false;
        }
        if ((Date.now() - (Number(cur.at) || 0)) >= 2500) {
            return false;
        }
        // Full navigation leaves a lock for up to 2.5s while the old TAB_ID is already
        // dropped from origin_tabs. Treating that as a peer made cold 无痕 autoplay bail
        // out forever (status「已在其他页面播放」) even though this is the only tab.
        var tabs = this.readOriginTabs();
        if (!tabs[cur.tabId]) {
            try {
                global.localStorage.removeItem(this.leaderLockKey());
            } catch (e) {
                // ignore
            }
            return false;
        }
        // Refresh successor: lock holder still in census but not visible → not a live peer.
        if (tabs[cur.tabId].visible !== true && this.countVisiblePeerTabs() === 0) {
            try {
                global.localStorage.removeItem(this.leaderLockKey());
            } catch (e2) {
                // ignore
            }
            return false;
        }
        return true;
    };

    /** Cold autoplay blocked by a live peer — retry once after lock TTL. */
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
            if (self.playIntent() === 'stop' && self.isUserStopped()) {
                return;
            }
            if (self.hasActivePeerLeader()) {
                return;
            }
            self.scheduleStart({ force: true });
        }, 2800);
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
        if (this.playlistCountEl) {
            if (multi) {
                var countTpl = textOf(this.root, '[data-store-music-i18n-playlist-count]', '共 {n} 首');
                this.playlistCountEl.textContent = String(countTpl).split('{n}').join(String(this.tracks.length));
            } else {
                this.playlistCountEl.textContent = '';
            }
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
        // Belt-and-suspenders: if a race left UI ahead of MediaElement, force follow.
        if (!this.audioMatchesSelection()) {
            this.reconcileAudibleSelection(true);
        }
    };

    /**
     * Discard current MediaElement + AudioContext.
     * After createMediaElementSource(), closing the context bricks that element — reuse
     * makes “换歌不生效” while an older decode path can keep audible.
     */
    StoreMusic.prototype.discardAudioElement = function () {
        if (this.ctx && typeof this.ctx.close === 'function') {
            try {
                this.ctx.close();
            } catch (e) {
                // ignore
            }
        }
        this.ctx = null;
        this.source = null;
        this.analyser = null;
        this.waveConnected = false;
        if (this.audio) {
            silenceMediaElement(this.audio);
        }
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
        // Hard reset: kill every rival Audio (multi-source overlay) then use a fresh element.
        // Reusing an element that was wired to createMediaElementSource after ctx.close()
        // is what made track switches appear to do nothing while the previous song kept playing.
        this.stopWaveLoop();
        this.discardAudioElement();
        killRivalAudioElements(null);
        // Never reuse a silenced singleton for track switches — stale decode buffers
        // can keep the previous song audible while the playlist UI already moved on.
        global.__WelineStoreMusicSharedAudio = null;
        var audio = this.mountAudioElement();
        audio.__welineStoreMusicOwner = this;
        registerStoreAudio(audio);
        global.__WelineStoreMusicSharedAudio = audio;
        var multi = this.tracks.length > 1;
        var loopOne = !multi && !(this.cfg.loop === false || this.cfg.loop === 0 || this.cfg.loop === '0');
        audio.loop = !!loopOne;
        var vol = this.volume ? Number(this.volume.value) : Number(this.cfg.default_volume || 12);
        audio.volume = Math.max(0, Math.min(1, vol / 100));
        audio.muted = false;
        this._awaitingUnmute = false;
        // Direct URL — do not wait for IDB/fetch (keeps user activation).
        audio.setAttribute('data-store-music-src', url);
        audio.src = url;
        this.audioReady = true;
        this.syncTrackMeta(true);
        this.syncPlaylistActive();
        // Gesture play must still honor resume snap (refresh / cross-page).
        this.applyPendingSeek();
        var playResult = null;
        try {
            playResult = audio.play();
        } catch (err) {
            this._audioMutating = false;
            this.setNeedGesture(true);
            return Promise.reject(err);
        }
        // Warm IDB cache without interrupting playback.
        this.cacheTrackInBackground(url);
        if (!playResult || typeof playResult.then !== 'function') {
            this.setWantPlay(true);
            this.markHeardWhileVisible();
            this.syncPlayingUi(true);
            this.broadcastLeaderState('playing', false);
            this.broadcastForceStop(false);
            this._audioMutating = false;
            return Promise.resolve(audio);
        }
        return playResult.then(function () {
            // Re-assert single audible source after async play (host may resurrect orphans).
            killRivalAudioElements(audio);
            self.setWantPlay(true);
            self.needGesture = false;
            self.markHeardWhileVisible();
            self.syncPlayingUi(true);
            self.broadcastLeaderState('playing', false);
            self.broadcastForceStop(false);
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
        }).then(function (out) {
            self._audioMutating = false;
            return out;
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
            if (global.__WelineStoreMusicSharedAudio
                && !global.__WelineStoreMusicSharedAudio.__welineMediaGraphBound) {
                audio = global.__WelineStoreMusicSharedAudio;
            } else {
                // Never reuse an element that already had createMediaElementSource().
                if (global.__WelineStoreMusicSharedAudio) {
                    silenceMediaElement(global.__WelineStoreMusicSharedAudio);
                }
                audio = new Audio();
                global.__WelineStoreMusicSharedAudio = audio;
            }
            audio.preload = 'metadata';
            this.audio = audio;
        }
        audio.__welineStoreMusicOwner = this;
        registerStoreAudio(audio);
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
                // Mid-gesture / open panel: force-steal so "where the user switches" always wins.
                if (force || owner.panelOpen || owner.isLatestOperator()) {
                    if (owner.claimLeadership(true)) {
                        owner.markHeardWhileVisible();
                        owner.setWantPlay(true);
                        owner.syncPlayingUi(true);
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
            owner.syncPlayingUi(true);
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
                owner._progressTimer = global.setTimeout(function () {
                    owner._progressTimer = 0;
                    if (!owner._docAlive) {
                        return;
                    }
                    owner.saveProgress();
                }, 1000);
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
        }
        this.syncPlaylistActive();
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
            this.volume.addEventListener('input', function () {
                // Volume prefs are local UI; only touch the live element if WE are playing.
                // Never acquireLeaderLock here — that used to silence every other page.
                self.markUserGesture();
                var v = Number(self.volume.value) || 0;
                writePref(self.root, 'volume', v);
                self.syncVolumePct(v);
                if (self.audio && (self.isLeader || !self.audio.paused)) {
                    self.audio.volume = Math.max(0, Math.min(1, v / 100));
                }
            });
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
                    self.hardSilenceMedia(false);
                    self.killAllPageAudio();
                    self.syncPlayingUi(false);
                    self.stopOrphanWatch();
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
            if (self.playIntent() === 'play') {
                global.setTimeout(function () {
                    if (document.visibilityState !== 'visible' || !self._docAlive) {
                        return;
                    }
                    if (self.audio && !self.audio.paused) {
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
        this.clearMediaSession();
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
            this.hint.textContent = textOf(
                this.root,
                this._awaitingUnmute
                    ? '[data-store-music-i18n-tap-to-unmute]'
                    : '[data-store-music-i18n-tap-to-play]',
                this._awaitingUnmute ? '点击开启声音' : '点击开启音乐'
            );
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
            var vol = this.volume ? Number(this.volume.value) : Number(this.cfg.default_volume || 12);
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
     * @param {{force?: boolean}} [opts] force=true cancels in-flight delay and restarts
     */
    StoreMusic.prototype.scheduleStart = function (opts) {
        var self = this;
        var force = !!(opts && opts.force);
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
        // Repair sessions poisoned by old leave/domain_empty want_play=0 writes.
        this.clearLegacyStopPoison();
        var intent = this.playIntent();
        if (this.isDismissed() && intent !== 'play') {
            this.setNeedGesture(false);
            this.syncHintVisibility();
            setStatus(this.root, textOf(this.root, '[data-store-music-i18n-dismissed]', '已关闭自动播放，点击播放可重新开启'));
            return;
        }
        if (intent === 'stop' && this.isUserStopped()) {
            // User explicitly stopped — do not honor try_autoplay until they press play again.
            this.setNeedGesture(true);
            this.syncHintVisibility();
            return;
        }
        if (intent === 'stop') {
            // Non-user stop residue — treat as unset for entrance autoplay.
            this.clearLegacyStopPoison();
            intent = this.playIntent();
        }
        // Re-apply snap in case boot raced; ensures we never fall back to track 0 while
        // progress still points at 孤城 (or any unfinished selection).
        this.restoreProgressIndex();
        var hasSnap = this.hasResumeSnap();
        var resume = intent === 'play' || (intent === 'unset' && hasSnap);
        var delaySec = Math.max(1, Math.min(15, Number(this.cfg.delay_seconds) || 3));
        // Cross-page / refresh resume: start ASAP on the saved track. Cold first visit keeps delay.
        var waitMs = resume ? 0 : delaySec * 1000;
        if (force && this._awaitingConsent) {
            waitMs = 0;
        }
        this._awaitingConsent = false;
        this._scheduleInFlight = true;
        var gen = (this._scheduleGen = (Number(this._scheduleGen) || 0) + 1);
        whenWindowLoaded().then(function () {
            return delay(waitMs);
        }).then(function () {
            if (gen !== self._scheduleGen) {
                return;
            }
            self.clearLegacyStopPoison();
            if (self.playIntent() === 'stop' && self.isUserStopped()) {
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
            if (gen !== self._scheduleGen) {
                return;
            }
            if (!allowed) {
                self._autoplayPending = true;
                self.setNeedGesture(true);
                return;
            }
            self.clearLegacyStopPoison();
            var latest = self.playIntent();
            if (latest === 'stop' && self.isUserStopped()) {
                self.setNeedGesture(true);
                return;
            }
            if (latest === 'stop') {
                latest = 'unset';
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
            self._autoplayPending = true;
            // Refresh/resume: drop stale lock from the document we just unloaded (solo tab).
            self.clearStaleLeaderForSoloResume();
            // New / soft-loaded tab: if another page is already leading, stay quiet.
            // Do not sticky-nag; user can ▶ / 选曲 here to take over (last op wins).
            if (self.hasActivePeerLeader()) {
                self._autoplayPending = true;
                self.setNeedGesture(true);
                self.armPeerLeaderRetry();
                setStatus(self.root, '');
                return;
            }
            if (!self.canAudiblyStart(false)) {
                self.setNeedGesture(true);
                return;
            }
            return self.ensureAudio().then(function () {
                if (gen !== self._scheduleGen) {
                    return;
                }
                self.clearStaleLeaderForSoloResume();
                if (self.hasActivePeerLeader()) {
                    self._autoplayPending = true;
                    self.setNeedGesture(true);
                    self.armPeerLeaderRetry();
                    setStatus(self.root, '');
                    return;
                }
                return self.tryPlay({ force: false });
            });
        }).catch(function () {
            self._autoplayPending = true;
            self.setNeedGesture(true);
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
        if (this.audioReady && this.audio && this.audio.getAttribute('data-store-music-src') === url
            && !this.audio.__welineMediaGraphBound) {
            this.syncTrackMeta(!!(this.audio && !this.audio.paused));
            return Promise.resolve(this.audio);
        }
        // Switching track or graph-bound element: discard and remount fresh (no dual-decode).
        if (this.audio && (this.audio.getAttribute('data-store-music-src') !== url || this.audio.__welineMediaGraphBound)) {
            this.discardAudioElement();
            killRivalAudioElements(null);
        }
        var audio = this.mountAudioElement();
        registerStoreAudio(audio);
        global.__WelineStoreMusicSharedAudio = audio;
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
            return false;
        }
        var t = Number(this._pendingSeek);
        if (!Number.isFinite(t) || t < 0.25) {
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
            this.root.classList.toggle('is-awaiting-unmute', !!(playing && this._awaitingUnmute));
        }
        this.syncTrackMeta(!!playing);
        if (playing && this._awaitingUnmute) {
            // Soft muted autoplay succeeded — keep gesture arm for first unmute click.
            this.needGesture = true;
            this._autoplayPending = true;
            if (this.mascot) {
                this.mascot.classList.add('is-need-gesture');
            }
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
            this.armPageGestureUnlock();
            this.syncHintVisibility();
            return;
        }
        if (playing) {
            this.needGesture = false;
            this._autoplayPending = false;
            this._awaitingConsent = false;
            this._awaitingUnmute = false;
            this.disarmPageGestureUnlock();
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
        return this.ensureAudio().then(function (audio) {
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
                self.setWantPlay(true);
                self.markHeardWhileVisible();
                self.syncPlayingUi(true);
                self.broadcastLeaderState('playing', false);
            };
            // Try unmuted first (refresh/MEI often allows sound). Only mute on NotAllowedError.
            // Do NOT pre-mute when !userActivation — that made F5 look like「不播放了」(silent).
            try {
                audio.muted = false;
            } catch (eMute) {
                // ignore
            }
            var p = audio.play();
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
                if (name === 'NotAllowedError' && !audio.muted) {
                    try {
                        audio.muted = true;
                    } catch (e2) {
                        // ignore
                    }
                    var mutedPlay = audio.play();
                    if (!mutedPlay || typeof mutedPlay.then !== 'function') {
                        markPlaying(true);
                        return;
                    }
                    return mutedPlay.then(function () {
                        if (!self._docAlive) {
                            return;
                        }
                        markPlaying(true);
                    }).catch(function () {
                        self._awaitingUnmute = false;
                        self._autoplayPending = true;
                        self.setNeedGesture(true);
                    });
                }
                self._awaitingUnmute = false;
                self._autoplayPending = true;
                self.setNeedGesture(true);
            });
        });
    };

    StoreMusic.prototype.userPlay = function () {
        this.markUserOperating();
        this.setDismissed(false);
        this.setUserStopped(false);
        this.setWantPlay(true);
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
            this.audio.__welineMediaGraphBound = true;
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
