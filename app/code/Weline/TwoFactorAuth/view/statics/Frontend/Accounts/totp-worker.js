(function () {
    'use strict';

    const accounts = new Map();
    const pending = new Set();

    function nowSeconds() {
        return Math.floor(Date.now() / 1000);
    }

    function codeFromData(data) {
        if (!data || typeof data.hash_value === 'undefined') {
            return null;
        }
        const digits = Number(data.digits || 6);
        const modulo = Math.pow(10, digits);
        return String(Number(data.hash_value) % modulo).padStart(digits, '0');
    }

    function cached(accountId) {
        const item = accounts.get(String(accountId));
        if (!item) {
            return null;
        }
        const now = nowSeconds();
        if (Number(item.expires_at || 0) > now + 1) {
            return {
                hash_value: item.hash_value,
                digits: item.digits,
                period: item.period,
                remaining: Number(item.expires_at) - now,
                expires_at: item.expires_at
            };
        }
        return null;
    }

    function requestCode(accountId) {
        const id = String(accountId);
        if (pending.has(id)) {
            return;
        }
        pending.add(id);
        self.postMessage({ type: 'fetch_code', accountId: id });
    }

    function storeCode(accountId, data) {
        const id = String(accountId);
        pending.delete(id);
        if (!data || data.success === false) {
            accounts.delete(id);
            return null;
        }
        const period = Number(data.period || 30);
        const remaining = Number(data.remaining || period);
        const stored = {
            hash_value: Number(data.hash_value || 0),
            digits: Number(data.digits || 6),
            period,
            remaining,
            expires_at: nowSeconds() + remaining,
            obtained_at: nowSeconds()
        };
        accounts.set(id, stored);
        return stored;
    }

    function countdowns() {
        const now = nowSeconds();
        const list = [];
        const toRefresh = [];
        accounts.forEach((data, accountId) => {
            const remaining = Math.max(0, Number(data.expires_at || 0) - now);
            if (remaining <= 0) {
                toRefresh.push(accountId);
                requestCode(accountId);
                return;
            }
            list.push({
                accountId,
                remaining,
                period: Number(data.period || 30),
                code: codeFromData(data),
                data
            });
        });
        return { countdowns: list, toRefresh };
    }

    function parseJson(content) {
        const data = JSON.parse(content);
        const result = [];
        const pushUri = item => {
            if (!item || typeof item !== 'object') {
                return;
            }
            const uri = item.uri || item.url;
            if (typeof uri === 'string' && uri.startsWith('otpauth://')) {
                result.push({ uri });
            }
        };
        if (Array.isArray(data)) {
            data.forEach(pushUri);
            return result;
        }
        if (data.exportFormat === 'uri' && Array.isArray(data.uris)) {
            data.uris.forEach(pushUri);
            return result;
        }
        if (Array.isArray(data.rows)) {
            data.rows.forEach(pushUri);
            return result;
        }
        pushUri(data);
        return result;
    }

    function parseLines(content) {
        return String(content)
            .split(/\r?\n/)
            .map(line => line.trim())
            .filter(line => line.startsWith('otpauth://'))
            .map(uri => ({ uri }));
    }

    function parseCsv(content) {
        const rows = String(content).split(/\r?\n/);
        const result = [];
        rows.forEach(row => {
            const value = row.split(',').map(part => part.trim()).find(part => part.startsWith('otpauth://'));
            if (value) {
                result.push({ uri: value.replace(/^"|"$/g, '') });
            }
        });
        return result;
    }

    function parseFile(content, fileName) {
        const name = String(fileName || '').toLowerCase();
        if (name.endsWith('.json')) {
            return parseJson(content);
        }
        if (name.endsWith('.csv')) {
            return parseCsv(content);
        }
        if (name.endsWith('.txt')) {
            return parseLines(content);
        }
        throw new Error('Unsupported file format');
    }

    self.onmessage = function (event) {
        const message = event.data || {};
        switch (message.type) {
            case 'init':
                (message.accountIds || []).forEach(accountId => {
                    const data = cached(accountId);
                    if (data) {
                        self.postMessage({
                            type: 'code_update',
                            accountId: String(accountId),
                            code: codeFromData(data),
                            data
                        });
                    } else {
                        requestCode(accountId);
                    }
                });
                self.postMessage({ type: 'init_complete', accounts: [] });
                break;
            case 'refresh':
                accounts.delete(String(message.accountId));
                requestCode(message.accountId);
                break;
            case 'code_data':
                {
                    const data = storeCode(message.accountId, message.data);
                    if (data) {
                        self.postMessage({
                            type: 'code_update',
                            accountId: String(message.accountId),
                            code: codeFromData(data),
                            data
                        });
                    }
                }
                break;
            case 'update_countdowns':
                {
                    const state = countdowns();
                    self.postMessage({
                        type: 'countdown_update',
                        countdowns: state.countdowns,
                        toRefresh: state.toRefresh
                    });
                }
                break;
            case 'remove':
                accounts.delete(String(message.accountId));
                pending.delete(String(message.accountId));
                break;
            case 'parse_file':
                try {
                    const parsed = parseFile(message.fileContent || '', message.fileName || '');
                    self.postMessage({
                        type: 'parse_result',
                        accounts: parsed,
                        count: parsed.length
                    });
                } catch (error) {
                    self.postMessage({
                        type: 'parse_error',
                        error: error && error.message ? error.message : String(error)
                    });
                }
                break;
            case 'import_accounts':
                self.postMessage({
                    type: 'import_request',
                    accounts: message.accounts || []
                });
                break;
        }
    };
}());
