/* eslint-disable no-restricted-globals */
(function () {
    'use strict';

    const MAGIC = [0x57, 0x51, 0x42, 0x31]; // WQB1
    const VERSION = 1;
    const CONTENT_TYPE = 'application/x-weline-query-bin';
    const PROTOCOL = 'worker-query-bin-v1';
    const WORKER_PROTOCOL = 'weline-worker-request-v1';
    const SIGNED_PATH = '/api/framework/query-bin';
    const MAX_SAFE_INTEGER = Number.MAX_SAFE_INTEGER;
    const encoder = new TextEncoder();
    const decoder = new TextDecoder('utf-8', { fatal: true });

    let workerSession = null;
    let handshakePromise = null;

    self.addEventListener('message', async (event) => {
        const message = event.data || {};
        const id = message.id;
        if (!id) return;

        try {
            const config = normalizeConfig(message.config || {});
            const packetPayload = buildPayload(message, config);
            const capability = resolveCapability(packetPayload);
            await ensureSession(config);
            const result = await postSigned(config, packetPayload, capability);
            self.postMessage({
                id,
                ok: result.responseOk && result.body && result.body.ok === true,
                status: result.status,
                statusText: result.statusText,
                headers: result.headers,
                body: result.body,
                maintenance: detectMaintenance(result.status, result.body),
            });
        } catch (error) {
            self.postMessage({
                id,
                ok: false,
                status: error && error.status ? error.status : 0,
                statusText: '',
                headers: {},
                body: {
                    ok: false,
                    data: null,
                    error: {
                        code: error && error.code ? error.code : 'protocol_error',
                        message: error instanceof Error ? error.message : String(error),
                    },
                    request_id: '',
                },
                maintenance: false,
                error: error instanceof Error ? error.message : String(error),
            });
        }
    });

    function normalizeConfig(config) {
        return {
            endpoint: config.endpoint || '/api/framework/query-bin',
            deployVersion: config.deployVersion || config.deploy_version || 'dev',
            workerBuildId: config.workerBuildId || config.worker_build_id || 'dev',
            locale: normalizeLocale(config.locale || config.currentLang || config.current_lang || ''),
            currency: normalizeCurrency(config.currency || config.currentCurrency || config.current_currency || ''),
        };
    }

    function normalizeLocale(value) {
        const locale = String(value || '').trim();
        return /^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/.test(locale) ? locale : '';
    }

    function normalizeCurrency(value) {
        const currency = String(value || '').trim().toUpperCase();
        return /^[A-Z]{3}$/.test(currency) ? currency : '';
    }

    function withContext(payload, config) {
        const context = {};
        if (config.locale) {
            context.locale = config.locale;
        }
        if (config.currency) {
            context.currency = config.currency;
        }
        if (Object.keys(context).length > 0) {
            payload.context = context;
        }
        return payload;
    }

    function buildPayload(message, config) {
        if (message.type === 'call') {
            return withContext({
                type: 'call',
                provider: String(message.provider || ''),
                operation: String(message.operation || ''),
                params: normalizeMap(message.params),
            }, config);
        }
        if (message.type === 'graph') {
            return withContext({
                type: 'graph',
                graph: message.graph || message.operations || {},
            }, config);
        }
        if (message.type === 'stream-ticket') {
            return withContext({
                type: 'stream-ticket',
                channel: String(message.channel || ''),
                params: normalizeMap(message.params),
            }, config);
        }
        throw Object.assign(new Error('Unsupported Weline worker request type.'), { code: 'protocol_error' });
    }

    function resolveCapability(payload) {
        if (payload.type === 'call') {
            return `${payload.provider}.${payload.operation}`;
        }
        if (payload.type === 'graph') {
            return 'graph';
        }
        if (payload.type === 'stream-ticket') {
            return 'stream-ticket';
        }
        throw Object.assign(new Error('Unsupported Weline worker capability.'), { code: 'protocol_error' });
    }

    function normalizeMap(value) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            return {};
        }
        return value;
    }

    async function ensureSession(config) {
        const now = Math.floor(Date.now() / 1000);
        if (
            workerSession &&
            workerSession.expires_at > now + 5 &&
            workerSession.deploy_version === config.deployVersion &&
            workerSession.worker_build_id === config.workerBuildId
        ) {
            return workerSession;
        }

        if (!handshakePromise) {
            handshakePromise = handshake(config).finally(() => {
                handshakePromise = null;
            });
        }

        workerSession = await handshakePromise;
        return workerSession;
    }

    async function handshake(config) {
        const rawBody = encodePacket({
            type: 'handshake',
            deploy_version: config.deployVersion,
            worker_build_id: config.workerBuildId,
        });

        const response = await fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': CONTENT_TYPE,
                'X-Weline-Protocol': PROTOCOL,
                'X-Weline-Worker-Protocol': WORKER_PROTOCOL,
                'X-Weline-Deploy-Version': config.deployVersion,
                'X-Weline-Worker-Build-Id': config.workerBuildId,
            },
            body: rawBody,
        });

        const body = decodePacket(new Uint8Array(await response.arrayBuffer()));
        if (!response.ok || !body || body.ok !== true || !body.data) {
            const message = body && body.error ? body.error.message : 'Weline worker handshake failed.';
            throw Object.assign(new Error(message), { code: 'auth_error', status: response.status });
        }

        return body.data;
    }

    async function postSigned(config, payload, capability) {
        const rawBody = encodePacket(payload);
        const timestamp = String(Math.floor(Date.now() / 1000));
        const nonce = randomHex(16);
        const bodyHash = await sha256Hex(rawBody);
        const signatureBase = [
            'POST',
            SIGNED_PATH,
            config.deployVersion,
            config.workerBuildId,
            capability,
            nonce,
            timestamp,
            bodyHash,
        ].join('\n');
        const signature = await hmacSha256Hex(workerSession.signing_secret, signatureBase);

        const response = await fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': CONTENT_TYPE,
                'X-Weline-Protocol': PROTOCOL,
                'X-Weline-Worker-Protocol': WORKER_PROTOCOL,
                'X-Weline-Deploy-Version': config.deployVersion,
                'X-Weline-Worker-Build-Id': config.workerBuildId,
                'X-Weline-Worker-Session': workerSession.worker_session_token,
                'X-Weline-Worker-Capability': capability,
                'X-Weline-Worker-Nonce': nonce,
                'X-Weline-Worker-Timestamp': timestamp,
                'X-Weline-Worker-Body-Hash': bodyHash,
                'X-Weline-Worker-Signature': signature,
            },
            body: rawBody,
        });

        const responseBytes = new Uint8Array(await response.arrayBuffer());
        const body = responseBytes.length > 0 ? decodePacket(responseBytes) : null;
        return {
            responseOk: response.ok,
            status: response.status,
            statusText: response.statusText || '',
            headers: collectHeaders(response.headers),
            body,
        };
    }

    function detectMaintenance(status, body) {
        if (status === 503) return true;
        const code = body && body.error && typeof body.error.code === 'string' ? body.error.code : '';
        return code.toLowerCase() === 'maintenance';
    }

    function collectHeaders(responseHeaders) {
        const headers = {};
        responseHeaders.forEach((value, key) => {
            headers[key] = value;
        });
        return headers;
    }

    function randomHex(length) {
        const bytes = new Uint8Array(length);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    async function sha256Hex(bytes) {
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        return bytesToHex(new Uint8Array(digest));
    }

    async function hmacSha256Hex(secret, message) {
        const key = await crypto.subtle.importKey(
            'raw',
            encoder.encode(secret),
            { name: 'HMAC', hash: 'SHA-256' },
            false,
            ['sign']
        );
        const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(message));
        return bytesToHex(new Uint8Array(signature));
    }

    function bytesToHex(bytes) {
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    class Writer {
        constructor() {
            this.bytes = [];
        }

        byte(value) {
            this.bytes.push(value & 0xff);
        }

        bytesValue(bytes) {
            for (const byte of bytes) {
                this.byte(byte);
            }
        }

        varuint(value) {
            if (!Number.isSafeInteger(value) || value < 0) {
                throw new Error('Invalid Weline varuint.');
            }
            do {
                let byte = value & 0x7f;
                value = Math.floor(value / 128);
                if (value > 0) byte |= 0x80;
                this.byte(byte);
            } while (value > 0);
        }

        finish() {
            return new Uint8Array(this.bytes);
        }
    }

    class Reader {
        constructor(bytes) {
            this.bytes = bytes;
            this.offset = 0;
        }

        byte() {
            if (this.offset >= this.bytes.length) {
                throw new Error('Unexpected end of Weline binary packet.');
            }
            return this.bytes[this.offset++];
        }

        bytesValue(length) {
            if (this.offset + length > this.bytes.length) {
                throw new Error('Unexpected end of Weline binary packet.');
            }
            const value = this.bytes.slice(this.offset, this.offset + length);
            this.offset += length;
            return value;
        }

        varuint() {
            let result = 0;
            let shift = 0;
            while (true) {
                if (shift > 56) {
                    throw new Error('Weline varuint is too large.');
                }
                const byte = this.byte();
                result += (byte & 0x7f) * Math.pow(2, shift);
                if ((byte & 0x80) === 0) {
                    return result;
                }
                shift += 7;
            }
        }
    }

    function encodePacket(value) {
        const writer = new Writer();
        MAGIC.forEach((byte) => writer.byte(byte));
        writer.byte(VERSION);
        encodeValue(writer, value, 0);
        return writer.finish();
    }

    function encodeValue(writer, value, depth) {
        if (depth > 8) throw new Error('Weline binary value exceeds max depth.');
        if (value === null || typeof value === 'undefined') {
            writer.byte(0x00);
            return;
        }
        if (value === false) {
            writer.byte(0x01);
            return;
        }
        if (value === true) {
            writer.byte(0x02);
            return;
        }
        if (typeof value === 'number') {
            if (!Number.isFinite(value)) throw new Error('Non-finite number is not allowed.');
            if (Number.isInteger(value)) {
                if (!Number.isSafeInteger(value)) throw new Error('Integer exceeds safe range.');
                writer.byte(0x03);
                writer.byte(value < 0 ? 1 : 0);
                writer.varuint(Math.abs(value));
            } else {
                writer.byte(0x04);
                const buffer = new ArrayBuffer(8);
                new DataView(buffer).setFloat64(0, value, false);
                writer.bytesValue(new Uint8Array(buffer));
            }
            return;
        }
        if (typeof value === 'string') {
            const bytes = encoder.encode(value);
            if (bytes.length > 16384) throw new Error('String exceeds 16KB limit.');
            writer.byte(0x05);
            writer.varuint(bytes.length);
            writer.bytesValue(bytes);
            return;
        }
        if (value instanceof Uint8Array || value instanceof ArrayBuffer) {
            const bytes = value instanceof Uint8Array ? value : new Uint8Array(value);
            if (bytes.length > 16384) throw new Error('Bytes exceed 16KB limit.');
            writer.byte(0x06);
            writer.varuint(bytes.length);
            writer.bytesValue(bytes);
            return;
        }
        if (Array.isArray(value)) {
            if (value.length > 200) throw new Error('List exceeds 200 item limit.');
            writer.byte(0x07);
            writer.varuint(value.length);
            value.forEach((item) => encodeValue(writer, item, depth + 1));
            return;
        }
        if (typeof value === 'object') {
            const keys = Object.keys(value);
            if (keys.length > 100) throw new Error('Map exceeds 100 key limit.');
            writer.byte(0x08);
            writer.varuint(keys.length);
            keys.forEach((key) => {
                const keyBytes = encoder.encode(key);
                if (keyBytes.length === 0 || keyBytes.length > 16384) {
                    throw new Error('Invalid Weline map key.');
                }
                writer.varuint(keyBytes.length);
                writer.bytesValue(keyBytes);
                encodeValue(writer, value[key], depth + 1);
            });
            return;
        }
        throw new Error('Unsupported Weline binary value type.');
    }

    function decodePacket(bytes) {
        const reader = new Reader(bytes);
        for (const byte of MAGIC) {
            if (reader.byte() !== byte) {
                throw new Error('Invalid Weline binary magic.');
            }
        }
        if (reader.byte() !== VERSION) {
            throw new Error('Unsupported Weline binary version.');
        }
        const value = decodeValue(reader, 0);
        if (reader.offset !== reader.bytes.length) {
            throw new Error('Trailing bytes in Weline binary packet.');
        }
        return value;
    }

    function decodeValue(reader, depth) {
        if (depth > 8) throw new Error('Weline binary value exceeds max depth.');
        const type = reader.byte();
        if (type === 0x00) return null;
        if (type === 0x01) return false;
        if (type === 0x02) return true;
        if (type === 0x03) {
            const sign = reader.byte();
            const magnitude = reader.varuint();
            if (magnitude > MAX_SAFE_INTEGER) throw new Error('Integer exceeds safe range.');
            return sign === 1 ? -magnitude : magnitude;
        }
        if (type === 0x04) {
            const bytes = reader.bytesValue(8);
            const value = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength).getFloat64(0, false);
            if (!Number.isFinite(value)) throw new Error('Non-finite float is not allowed.');
            return value;
        }
        if (type === 0x05) {
            const length = reader.varuint();
            if (length > 16384) throw new Error('String exceeds 16KB limit.');
            return decoder.decode(reader.bytesValue(length));
        }
        if (type === 0x06) {
            const length = reader.varuint();
            if (length > 16384) throw new Error('Bytes exceed 16KB limit.');
            return reader.bytesValue(length);
        }
        if (type === 0x07) {
            const count = reader.varuint();
            if (count > 200) throw new Error('List exceeds 200 item limit.');
            const list = [];
            for (let i = 0; i < count; i += 1) {
                list.push(decodeValue(reader, depth + 1));
            }
            return list;
        }
        if (type === 0x08) {
            const count = reader.varuint();
            if (count > 100) throw new Error('Map exceeds 100 key limit.');
            const map = {};
            for (let i = 0; i < count; i += 1) {
                const length = reader.varuint();
                if (length === 0 || length > 16384) throw new Error('Invalid Weline map key.');
                const key = decoder.decode(reader.bytesValue(length));
                if (Object.prototype.hasOwnProperty.call(map, key)) {
                    throw new Error('Duplicate Weline map key.');
                }
                map[key] = decodeValue(reader, depth + 1);
            }
            return map;
        }
        throw new Error('Unknown Weline binary type tag.');
    }
})();
