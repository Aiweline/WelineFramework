const CONTENT_TYPE = 'application/x-weline-query-bin';
const PROTOCOL = 'binquery-v1';
const CACHE_PARAM = '__wq_cache';

class BinaryCodec {
  static MAGIC = [0x57, 0x51, 0x42, 0x31];
  static VERSION = 1;

  encodePacket(payload) {
    return concatBytes([new Uint8Array([...BinaryCodec.MAGIC, BinaryCodec.VERSION]), this.encodeValue(payload)]);
  }

  decodePacket(packet) {
    const bytes = packet instanceof Uint8Array ? packet : new Uint8Array(packet);
    if (bytes.length < 5 || BinaryCodec.MAGIC.some((value, index) => bytes[index] !== value)) {
      throw new Error('Invalid Weline binary packet magic.');
    }
    if (bytes[4] !== BinaryCodec.VERSION) {
      throw new Error('Unsupported Weline binary packet version.');
    }
    const cursor = { offset: 5 };
    const value = this.decodeValue(bytes, cursor);
    if (cursor.offset !== bytes.length) {
      throw new Error('Trailing bytes in Weline binary packet.');
    }
    return value;
  }

  encodeValue(value) {
    if (value === null || value === undefined) return new Uint8Array([0x00]);
    if (value === false) return new Uint8Array([0x01]);
    if (value === true) return new Uint8Array([0x02]);
    if (Number.isInteger(value)) {
      if (!Number.isSafeInteger(value)) throw new Error('Integer is outside JavaScript safe integer range.');
      return concatBytes([new Uint8Array([0x03, value < 0 ? 1 : 0]), encodeVarUint(Math.abs(value))]);
    }
    if (typeof value === 'number') {
      const bytes = new Uint8Array(9);
      bytes[0] = 0x04;
      new DataView(bytes.buffer).setFloat64(1, value, false);
      return bytes;
    }
    if (typeof value === 'string') {
      const encoded = new TextEncoder().encode(value);
      return concatBytes([new Uint8Array([0x05]), encodeVarUint(encoded.length), encoded]);
    }
    if (Array.isArray(value)) {
      return concatBytes([new Uint8Array([0x07]), encodeVarUint(value.length), ...value.map((item) => this.encodeValue(item))]);
    }
    if (typeof value === 'object') {
      const entries = Object.entries(value);
      const parts = [new Uint8Array([0x08]), encodeVarUint(entries.length)];
      for (const [key, item] of entries) {
        const encodedKey = new TextEncoder().encode(key);
        parts.push(encodeVarUint(encodedKey.length), encodedKey, this.encodeValue(item));
      }
      return concatBytes(parts);
    }
    throw new Error(`Unsupported Weline binary value type: ${typeof value}`);
  }

  decodeValue(bytes, cursor) {
    const type = bytes[cursor.offset++];
    switch (type) {
      case 0x00: return null;
      case 0x01: return false;
      case 0x02: return true;
      case 0x03: return this.decodeInt(bytes, cursor);
      case 0x04: {
        const value = new DataView(bytes.buffer, bytes.byteOffset + cursor.offset, 8).getFloat64(0, false);
        cursor.offset += 8;
        return value;
      }
      case 0x05: return this.decodeString(bytes, cursor);
      case 0x06: return this.decodeBytes(bytes, cursor);
      case 0x07: return this.decodeList(bytes, cursor);
      case 0x08: return this.decodeMap(bytes, cursor);
      default: throw new Error('Unknown Weline binary type tag.');
    }
  }

  decodeInt(bytes, cursor) {
    const sign = bytes[cursor.offset++];
    const magnitude = decodeVarUint(bytes, cursor);
    return sign === 1 ? -magnitude : magnitude;
  }

  decodeString(bytes, cursor) {
    const length = decodeVarUint(bytes, cursor);
    const value = bytes.subarray(cursor.offset, cursor.offset + length);
    cursor.offset += length;
    return new TextDecoder().decode(value);
  }

  decodeBytes(bytes, cursor) {
    const length = decodeVarUint(bytes, cursor);
    const value = bytes.slice(cursor.offset, cursor.offset + length);
    cursor.offset += length;
    return value;
  }

  decodeList(bytes, cursor) {
    const count = decodeVarUint(bytes, cursor);
    const items = [];
    for (let index = 0; index < count; index += 1) {
      items.push(this.decodeValue(bytes, cursor));
    }
    return items;
  }

  decodeMap(bytes, cursor) {
    const count = decodeVarUint(bytes, cursor);
    const map = {};
    for (let index = 0; index < count; index += 1) {
      const keyLength = decodeVarUint(bytes, cursor);
      const key = new TextDecoder().decode(bytes.subarray(cursor.offset, cursor.offset + keyLength));
      cursor.offset += keyLength;
      map[key] = this.decodeValue(bytes, cursor);
    }
    return map;
  }
}

export class BinQueryClient {
  constructor({ endpoint, apiKey, area = 'frontend', cache = 'auto' }) {
    this.endpoint = endpoint;
    this.apiKey = apiKey;
    this.area = area;
    this.cache = cache;
    this.codec = new BinaryCodec();
    this.operationDocs = new Map();
  }

  static async connect(config) {
    const domain = String(config.domain || '').trim();
    const endpoint = String(config.endpoint || '').trim()
      || `https://${domain.replace(/^https?:\/\//, '').replace(/\/+$/, '')}/bin/query`;
    if (!domain && !config.endpoint) {
      throw new Error('BinQuery domain is required.');
    }
    const client = new BinQueryClient({
      endpoint,
      apiKey: config.apiKey,
      area: config.area || 'frontend',
      cache: config.cache ?? 'auto',
    });
    await client.request({ type: 'connect' });
    return client;
  }

  async help(provider = null, operation = null) {
    if (provider && operation) return this.docs(provider, operation);
    if (provider) return this.provider(provider);
    return this.providers();
  }

  async query(what = 'providers', params = {}) {
    return this.request({ type: 'query', what, ...params });
  }

  async providers() {
    return this.query('providers');
  }

  async resources() {
    return this.providers();
  }

  async provider(provider) {
    return this.query('provider', { provider });
  }

  async resource(provider) {
    return this.provider(provider);
  }

  async operations(provider) {
    return this.query('operations', { provider });
  }

  async docs(provider, operation) {
    const key = `${provider}.${operation}`;
    if (!this.operationDocs.has(key)) {
      this.operationDocs.set(key, await this.query('docs', { provider, operation }));
    }
    return this.operationDocs.get(key);
  }

  async exists(provider, operation = '') {
    return this.query('exists', { provider, operation });
  }

  async hasProvider(provider) {
    return Boolean((await this.exists(provider)).provider);
  }

  async hasResource(provider) {
    return this.hasProvider(provider);
  }

  async hasOperation(provider, operation) {
    return Boolean((await this.exists(provider, operation)).operation);
  }

  async call(provider, operation, params = {}) {
    const query = {};
    const marker = await this.buildCacheMarker(provider, operation, params);
    if (marker) query[CACHE_PARAM] = marker;
    return this.request({ type: 'call', provider, operation, params }, query);
  }

  async graph(operations) {
    return this.request({ type: 'graph', graph: operations });
  }

  async request(payload, query = {}) {
    const body = this.codec.encodePacket({ area: this.area, ...payload });
    const url = new URL(this.endpoint);
    for (const [key, value] of Object.entries(query)) {
      url.searchParams.set(key, String(value));
    }
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': CONTENT_TYPE,
        Accept: CONTENT_TYPE,
        Authorization: `Bearer ${this.apiKey}`,
        'X-Weline-BinQuery-Protocol': PROTOCOL,
      },
      body,
    });
    const bytes = new Uint8Array(await response.arrayBuffer());
    const decoded = this.codec.decodePacket(bytes);
    if (!decoded || decoded.ok !== true) {
      throw new Error(decoded?.error?.message || `BinQuery request failed with HTTP ${response.status}.`);
    }
    return decoded.data;
  }

  async buildCacheMarker(provider, operation, params) {
    if (this.cache !== 'auto') return '';
    const docs = await this.docs(provider, operation);
    const cache = docs?.cache || {};
    if (cache.cdn !== true || docs?.mode !== 'read') return '';
    const keyParams = Array.isArray(cache.key_params) ? cache.key_params : [];
    const vary = Array.isArray(cache.vary) ? cache.vary : ['area', 'locale', 'currency'];
    const pickedParams = {};
    const paramKeys = keyParams.length ? keyParams : Object.keys(params);
    for (const key of paramKeys.sort()) {
      if (Object.prototype.hasOwnProperty.call(params, key)) pickedParams[key] = params[key];
    }
    const varyValues = {};
    for (const key of vary.slice().sort()) {
      varyValues[key] = key === 'area' ? this.area : (params[key] ?? null);
    }
    const hash = await sha256(JSON.stringify({
      area: this.area,
      provider,
      operation,
      params: pickedParams,
      vary: varyValues,
    }));
    return `wq1.${this.area}.${provider}.${operation}.${hash.slice(0, 24)}`;
  }
}

function concatBytes(parts) {
  const total = parts.reduce((sum, part) => sum + part.length, 0);
  const output = new Uint8Array(total);
  let offset = 0;
  for (const part of parts) {
    output.set(part, offset);
    offset += part.length;
  }
  return output;
}

function encodeVarUint(value) {
  const bytes = [];
  let next = value;
  do {
    let byte = next & 0x7f;
    next = Math.floor(next / 128);
    if (next > 0) byte |= 0x80;
    bytes.push(byte);
  } while (next > 0);
  return new Uint8Array(bytes);
}

function decodeVarUint(bytes, cursor) {
  let result = 0;
  let shift = 0;
  while (true) {
    const byte = bytes[cursor.offset++];
    result += (byte & 0x7f) * (2 ** shift);
    if ((byte & 0x80) === 0) return result;
    shift += 7;
  }
}

async function sha256(input) {
  if (globalThis.crypto?.subtle) {
    const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
    return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  }
  const { createHash } = await import('node:crypto');
  return createHash('sha256').update(input).digest('hex');
}

export { BinaryCodec };
