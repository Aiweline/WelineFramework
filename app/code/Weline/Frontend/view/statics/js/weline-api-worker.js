/* eslint-disable no-restricted-globals */
(function () {
    'use strict';

    const MAGIC = [0x57, 0x51, 0x42, 0x31]; // WQB1
    const VERSION = 1;
    const MAX_DEPTH = 32;
    const MAX_STRING_BYTES = 2097152;
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
        const normalized = {
            endpoint: config.endpoint || '/api/framework/query-bin',
            deployVersion: config.deployVersion || config.deploy_version || 'dev',
            workerBuildId: config.workerBuildId || config.worker_build_id || 'dev',
            locale: normalizeLocale(config.locale || config.currentLang || config.current_lang || ''),
            defaultCurrency: normalizeCurrencyCode(config.defaultCurrency || config.default_currency || 'CNY'),
            availableCurrencies: normalizeCurrencyList(
                config.availableCurrencies || config.supportedCurrencies || config.currencyCodes || config.currencies || []
            ),
        };
        normalized.currency = normalizeCurrency(config.currency || config.currentCurrency || config.current_currency || '', normalized);

        return normalized;
    }

    function normalizeLocale(value) {
        const locale = String(value || '').trim();
        return /^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/.test(locale) ? locale : '';
    }

    function normalizeCurrencyCode(value) {
        return String(value || '').trim().toUpperCase();
    }

    function isCurrencyCodeShape(value) {
        return /^[A-Z]{3}$/.test(normalizeCurrencyCode(value));
    }

    function normalizeCurrencyList(values) {
        const codes = [];
        const seen = {};
        if (!Array.isArray(values)) {
            values = [values];
        }
        values.forEach((value) => {
            if (value && typeof value === 'object') {
                value = value.code || value.currency || value.currency_code || value.value || '';
            }
            const code = normalizeCurrencyCode(value);
            if (!isCurrencyCodeShape(code) || seen[code]) {
                return;
            }
            seen[code] = true;
            codes.push(code);
        });
        return codes;
    }

    function isSupportedCurrencyCode(value, config) {
        const code = normalizeCurrencyCode(value);
        if (!isCurrencyCodeShape(code)) {
            return false;
        }
        const supported = {};
        normalizeCurrencyList(config && config.availableCurrencies).forEach((entry) => {
            supported[entry] = true;
        });
        if (config && config.defaultCurrency) {
            supported[normalizeCurrencyCode(config.defaultCurrency)] = true;
        }
        return supported[code] === true;
    }

    function normalizeCurrency(value, config) {
        const currency = normalizeCurrencyCode(value);
        return isSupportedCurrencyCode(currency, config || {}) ? currency : '';
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
            handshakePromise = (async () => {
                const session = await handshake(config);
                workerSession = session;
                return session;
            })().finally(() => {
                handshakePromise = null;
            });
        }

        return handshakePromise;
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

        const body = decodeResponsePacket(response, new Uint8Array(await response.arrayBuffer()));
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
        const body = responseBytes.length > 0 ? decodeResponsePacket(response, responseBytes) : null;
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

    function decodeResponsePacket(response, responseBytes) {
        try {
            return decodePacket(responseBytes);
        } catch (error) {
            const status = response && response.status ? response.status : 0;
            const contentType = response && response.headers && typeof response.headers.get === 'function'
                ? (response.headers.get('content-type') || '')
                : '';
            const bytes = Array.from(responseBytes.slice(0, 32))
                .map((byte) => byte.toString(16).padStart(2, '0'))
                .join(' ');
            const ascii = Array.from(responseBytes.slice(0, 600))
                .map((byte) => (byte >= 32 && byte <= 126 ? String.fromCharCode(byte) : '.'))
                .join('');
            const message = [
                error instanceof Error ? error.message : String(error),
                `(HTTP ${status}${contentType ? ', ' + contentType : ''})`,
                bytes ? `bytes=${bytes}` : 'bytes=empty',
                ascii ? `preview=${ascii}` : '',
            ].filter(Boolean).join(' ');
            throw Object.assign(new Error(message), {
                code: 'protocol_error',
                status,
            });
        }
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
        if (typeof crypto !== 'undefined' && crypto && typeof crypto.getRandomValues === 'function') {
            crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i += 1) {
                bytes[i] = Math.floor(Math.random() * 256) & 0xff;
            }
        }
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function hasSubtleCrypto() {
        return typeof crypto !== 'undefined'
            && crypto
            && crypto.subtle
            && typeof crypto.subtle.digest === 'function'
            && typeof crypto.subtle.importKey === 'function'
            && typeof crypto.subtle.sign === 'function';
    }

    async function sha256Hex(bytes) {
        if (!hasSubtleCrypto()) {
            return bytesToHex(sha256Bytes(bytes));
        }
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        return bytesToHex(new Uint8Array(digest));
    }

    async function hmacSha256Hex(secret, message) {
        if (!hasSubtleCrypto()) {
            return bytesToHex(hmacSha256Bytes(encoder.encode(secret), encoder.encode(message)));
        }
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

    const SHA256_K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5,
        0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
        0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc,
        0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7,
        0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
        0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3,
        0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5,
        0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
        0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ];

    function rotr32(value, bits) {
        return (value >>> bits) | (value << (32 - bits));
    }

    function add32() {
        let result = 0;
        for (let i = 0; i < arguments.length; i += 1) {
            result = (result + arguments[i]) >>> 0;
        }
        return result;
    }

    function sha256Bytes(inputBytes) {
        const source = inputBytes instanceof Uint8Array ? inputBytes : new Uint8Array(inputBytes);
        const paddedLength = (((source.length + 9 + 63) >> 6) << 6);
        const bytes = new Uint8Array(paddedLength);
        bytes.set(source);
        bytes[source.length] = 0x80;

        const bitLength = source.length * 8;
        const bitLengthHigh = Math.floor(bitLength / 0x100000000);
        const bitLengthLow = bitLength >>> 0;
        bytes[paddedLength - 8] = (bitLengthHigh >>> 24) & 0xff;
        bytes[paddedLength - 7] = (bitLengthHigh >>> 16) & 0xff;
        bytes[paddedLength - 6] = (bitLengthHigh >>> 8) & 0xff;
        bytes[paddedLength - 5] = bitLengthHigh & 0xff;
        bytes[paddedLength - 4] = (bitLengthLow >>> 24) & 0xff;
        bytes[paddedLength - 3] = (bitLengthLow >>> 16) & 0xff;
        bytes[paddedLength - 2] = (bitLengthLow >>> 8) & 0xff;
        bytes[paddedLength - 1] = bitLengthLow & 0xff;

        const hash = [
            0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
            0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
        ];
        const words = new Uint32Array(64);

        for (let offset = 0; offset < bytes.length; offset += 64) {
            for (let i = 0; i < 16; i += 1) {
                const j = offset + (i * 4);
                words[i] = (
                    (bytes[j] << 24)
                    | (bytes[j + 1] << 16)
                    | (bytes[j + 2] << 8)
                    | bytes[j + 3]
                ) >>> 0;
            }
            for (let i = 16; i < 64; i += 1) {
                const s0 = rotr32(words[i - 15], 7) ^ rotr32(words[i - 15], 18) ^ (words[i - 15] >>> 3);
                const s1 = rotr32(words[i - 2], 17) ^ rotr32(words[i - 2], 19) ^ (words[i - 2] >>> 10);
                words[i] = add32(words[i - 16], s0, words[i - 7], s1);
            }

            let a = hash[0];
            let b = hash[1];
            let c = hash[2];
            let d = hash[3];
            let e = hash[4];
            let f = hash[5];
            let g = hash[6];
            let h = hash[7];

            for (let i = 0; i < 64; i += 1) {
                const s1 = rotr32(e, 6) ^ rotr32(e, 11) ^ rotr32(e, 25);
                const ch = (e & f) ^ ((~e) & g);
                const temp1 = add32(h, s1, ch, SHA256_K[i], words[i]);
                const s0 = rotr32(a, 2) ^ rotr32(a, 13) ^ rotr32(a, 22);
                const maj = (a & b) ^ (a & c) ^ (b & c);
                const temp2 = add32(s0, maj);

                h = g;
                g = f;
                f = e;
                e = add32(d, temp1);
                d = c;
                c = b;
                b = a;
                a = add32(temp1, temp2);
            }

            hash[0] = add32(hash[0], a);
            hash[1] = add32(hash[1], b);
            hash[2] = add32(hash[2], c);
            hash[3] = add32(hash[3], d);
            hash[4] = add32(hash[4], e);
            hash[5] = add32(hash[5], f);
            hash[6] = add32(hash[6], g);
            hash[7] = add32(hash[7], h);
        }

        const digest = new Uint8Array(32);
        for (let i = 0; i < hash.length; i += 1) {
            digest[i * 4] = (hash[i] >>> 24) & 0xff;
            digest[i * 4 + 1] = (hash[i] >>> 16) & 0xff;
            digest[i * 4 + 2] = (hash[i] >>> 8) & 0xff;
            digest[i * 4 + 3] = hash[i] & 0xff;
        }
        return digest;
    }

    function concatBytes(first, second) {
        const out = new Uint8Array(first.length + second.length);
        out.set(first, 0);
        out.set(second, first.length);
        return out;
    }

    function hmacSha256Bytes(secretBytes, messageBytes) {
        let key = secretBytes instanceof Uint8Array ? secretBytes : new Uint8Array(secretBytes);
        if (key.length > 64) {
            key = sha256Bytes(key);
        }
        const inner = new Uint8Array(64);
        const outer = new Uint8Array(64);
        inner.fill(0x36);
        outer.fill(0x5c);
        for (let i = 0; i < key.length; i += 1) {
            inner[i] ^= key[i];
            outer[i] ^= key[i];
        }
        return sha256Bytes(concatBytes(outer, sha256Bytes(concatBytes(inner, messageBytes))));
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
        if (depth > MAX_DEPTH) throw new Error('Weline binary value exceeds max depth.');
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
            if (bytes.length > MAX_STRING_BYTES) throw new Error('String exceeds 2MB limit.');
            writer.byte(0x05);
            writer.varuint(bytes.length);
            writer.bytesValue(bytes);
            return;
        }
        if (value instanceof Uint8Array || value instanceof ArrayBuffer) {
            const bytes = value instanceof Uint8Array ? value : new Uint8Array(value);
            if (bytes.length > MAX_STRING_BYTES) throw new Error('Bytes exceed 2MB limit.');
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
                if (keyBytes.length === 0 || keyBytes.length > MAX_STRING_BYTES) {
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
        if (depth > MAX_DEPTH) throw new Error('Weline binary value exceeds max depth.');
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
            if (length > MAX_STRING_BYTES) throw new Error('String exceeds 2MB limit.');
            return decoder.decode(reader.bytesValue(length));
        }
        if (type === 0x06) {
            const length = reader.varuint();
            if (length > MAX_STRING_BYTES) throw new Error('Bytes exceed 2MB limit.');
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
                if (length === 0 || length > MAX_STRING_BYTES) throw new Error('Invalid Weline map key.');
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
