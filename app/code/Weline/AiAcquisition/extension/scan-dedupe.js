'use strict';

/**
 * 页面去重工具（与 PHP 端 Weline\AiAcquisition\Service\Scan\ScanRegistry 归一化口径一致）：
 * - 只认 http/https；
 * - 域名小写并去 www.；
 * - 去掉查询串与锚点（同页不同参数视为同一页）；
 * - 路径去尾部斜杠，空路径记 /。
 *
 * 用法：importScripts('scan-dedupe.js') → self.WelineScanDedupe
 */
(function (root) {
  function normalize(url) {
    const raw = String(url || '').trim();
    if (raw === '') return '';
    let parsed;
    try {
      parsed = new URL(raw);
    } catch (error) {
      return '';
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return '';
    let host = String(parsed.hostname || '').toLowerCase();
    if (host.startsWith('www.')) host = host.slice(4);
    if (host === '') return '';
    let path = String(parsed.pathname || '/').replace(/\/+$/, '');
    if (path === '') path = '/';
    return host + path;
  }

  function domain(url) {
    const normalized = normalize(url);
    if (normalized === '') return '';
    const slash = normalized.indexOf('/');
    return slash === -1 ? normalized : normalized.slice(0, slash);
  }

  function toSet(values) {
    const set = new Set();
    (Array.isArray(values) ? values : []).forEach(function (value) {
      const key = normalize(String(value || ''));
      if (key !== '') set.add(key);
    });
    return set;
  }

  function toDomainSet(values) {
    const set = new Set();
    (Array.isArray(values) ? values : []).forEach(function (value) {
      let key = String(value || '').trim().toLowerCase();
      if (key.startsWith('www.')) key = key.slice(4);
      if (key !== '') set.add(key);
    });
    return set;
  }

  /** 域名命中：精确匹配或子域（shop.example.com 命中 example.com）。 */
  function domainMatches(domainName, domainSet) {
    if (!domainName) return false;
    if (domainSet.has(domainName)) return true;
    const parts = domainName.split('.');
    for (let i = 1; i < parts.length; i += 1) {
      if (domainSet.has(parts.slice(i).join('.'))) return true;
    }
    return false;
  }

  /**
   * 纯函数过滤：urls 里命中已看清单的进 skipped。
   * @returns {{allowed: string[], skipped: string[]}}
   */
  function filterBySeen(urls, seenUrls, seenDomains) {
    const urlSet = seenUrls instanceof Set ? seenUrls : toSet(seenUrls);
    const domainSet = seenDomains instanceof Set ? seenDomains : toDomainSet(seenDomains);
    const allowed = [];
    const skipped = [];
    (Array.isArray(urls) ? urls : []).forEach(function (url) {
      const key = normalize(String(url || ''));
      if (key === '') return;
      const slash = key.indexOf('/');
      const host = slash === -1 ? key : key.slice(0, slash);
      if (urlSet.has(key) || domainMatches(host, domainSet)) {
        skipped.push(url);
        return;
      }
      allowed.push(url);
    });
    return { allowed: allowed, skipped: skipped };
  }

  /**
   * 运行级追踪器：种子来自后端 context.scan，运行时不断累积，并攒批上报。
   */
  function makeTracker(scan) {
    const seed = scan && typeof scan === 'object' ? scan : {};
    const seenUrls = toSet(seed.seen_urls || []);
    const seenDomains = toDomainSet(seed.seen_domains || []);
    const pending = new Set();

    return {
      seenUrls: seenUrls,
      seenDomains: seenDomains,
      isSeen: function (url) {
        const key = normalize(String(url || ''));
        if (key === '') return false;
        const slash = key.indexOf('/');
        const host = slash === -1 ? key : key.slice(0, slash);
        return seenUrls.has(key) || domainMatches(host, seenDomains);
      },
      /** 记录已抓取页面（返回 true 表示新增）。 */
      mark: function (url) {
        const raw = String(url || '').trim();
        const key = normalize(raw);
        if (key === '') return false;
        const fresh = !seenUrls.has(key);
        seenUrls.add(key);
        if (fresh) pending.add(raw);
        return fresh;
      },
      /** 取出待上报的原始地址（后端按原始 URL 记账）。 */
      drain: function () {
        const urls = Array.from(pending);
        pending.clear();
        return urls;
      },
      size: function () {
        return seenUrls.size;
      },
    };
  }

  /**
   * 过滤检索结果数组（元素含 url）：返回保留项、跳过数量与提示语。
   * @returns {{results: Array, skipped: number, hint: string}}
   */
  function filterResults(list, tracker) {
    const items = Array.isArray(list) ? list : [];
    if (!tracker || items.length === 0) {
      return { results: items, skipped: 0, hint: '' };
    }
    const filtered = filterBySeen(
      items.map(function (item) { return String((item && item.url) || ''); }),
      tracker.seenUrls,
      tracker.seenDomains
    );
    const allowed = new Set(filtered.allowed.map(function (url) { return normalize(url); }));
    const kept = items.filter(function (item) {
      return allowed.has(normalize(String((item && item.url) || '')));
    });
    const skipped = items.length - kept.length;
    return {
      results: kept,
      skipped: skipped,
      hint: kept.length === 0
        ? '这些结果都已扫描过或已入库线索，请更换关键词/国家/渠道，不要重复抓同一批页面。'
        : (skipped > 0 ? '已跳过 ' + skipped + ' 个看过的页面' : ''),
    };
  }

  root.WelineScanDedupe = {
    normalize: normalize,
    domain: domain,
    filterBySeen: filterBySeen,
    filterResults: filterResults,
    makeTracker: makeTracker,
  };
})(typeof self !== 'undefined' ? self : this);
