/**
 * Async schema index: enqueue a pending job and subscribe to its SSE progress.
 * Loaded via data-weline-load="databaseSchemaIndex".
 */
(function (global) {
    'use strict';

    var panel = document.getElementById('schema-index-progress');
    var text = panel ? panel.querySelector('[data-schema-index-progress-text]') : null;
    var bar = panel ? panel.querySelector('[data-schema-index-progress-bar]') : null;
    var meta = panel ? panel.querySelector('[data-schema-index-progress-meta]') : null;
    var source = null;

    function api() {
        if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
            return Promise.resolve(global.Weline.Api.resource('database_index'));
        }
        if (global.Weline && typeof global.Weline.load === 'function') {
            return global.Weline.load('api').then(function () {
                return global.Weline.Api.resource('database_index');
            });
        }
        return Promise.reject(new Error('Weline.Api is unavailable.'));
    }

    function showPanel(value) {
        if (panel) {
            panel.hidden = !value;
        }
    }

    function setProgress(snapshot) {
        var percent = Math.max(0, Math.min(100, Number(snapshot.percent || 0)));
        if (text) {
            text.textContent = String(snapshot.phase || snapshot.status || '');
        }
        if (bar) {
            bar.value = percent;
        }
        if (meta) {
            var bits = ['#' + (snapshot.job_id || '')];
            if (snapshot.table) {
                bits.push(String(snapshot.table) + '.' + String(snapshot.index || ''));
            }
            bits.push(percent + '%');
            if (snapshot.blocks_total) {
                bits.push('blocks ' + Number(snapshot.blocks_done || 0) + '/' + Number(snapshot.blocks_total));
            }
            if (snapshot.tuples_total) {
                bits.push('tuples ' + Number(snapshot.tuples_done || 0) + '/' + Number(snapshot.tuples_total));
            }
            if (snapshot.message) {
                bits.push(String(snapshot.message));
            }
            meta.textContent = bits.join(' · ');
        }
    }

    function closeSource() {
        if (source) {
            source.close();
            source = null;
        }
    }

    function subscribe(jobId) {
        closeSource();
        var url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('stream', '1');
        url.searchParams.set('job_id', String(jobId));
        source = new EventSource(url.toString());
        source.addEventListener('state', function (event) {
            try {
                setProgress(JSON.parse(event.data));
            } catch (e) {
            }
        });
        source.addEventListener('progress', function (event) {
            try {
                setProgress(JSON.parse(event.data));
            } catch (e) {
            }
        });
        source.addEventListener('done', function (event) {
            try {
                setProgress(JSON.parse(event.data));
            } catch (e) {
            }
            closeSource();
            window.setTimeout(function () {
                window.location.reload();
            }, 800);
        });
        source.addEventListener('error', function (event) {
            if (event && event.data) {
                try {
                    setProgress(JSON.parse(event.data));
                } catch (e) {
                }
            }
        });
        source.onerror = function () {
            if (meta) {
                meta.textContent = (meta.textContent ? meta.textContent + ' · ' : '') + 'SSE reconnecting…';
            }
        };
    }

    function indexNow(button) {
        var jobId = parseInt(button.getAttribute('data-job-id') || '0', 10);
        if (jobId < 1) {
            return;
        }
        button.disabled = true;
        showPanel(true);
        if (text) {
            text.textContent = 'queued';
        }
        if (bar) {
            bar.value = 0;
        }
        api().then(function (resource) {
            return resource.enqueue({ job_id: jobId });
        }).then(function (result) {
            if (!result || result.success === false) {
                throw new Error((result && result.message) || 'enqueue failed');
            }
            subscribe(jobId);
        }).catch(function (error) {
            button.disabled = false;
            if (text) {
                text.textContent = 'error';
            }
            if (meta) {
                meta.textContent = error && error.message ? error.message : String(error);
            }
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var button = target.closest('[data-schema-index-now]');
        if (!button) {
            return;
        }
        event.preventDefault();
        indexNow(button);
    });

    window.WelineDatabaseSchemaIndex = {
        subscribe: subscribe,
        close: closeSource
    };
})(window);
