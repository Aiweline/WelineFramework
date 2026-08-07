/**
 * Contract for url-frontend.js path-mount inject_path ordering.
 * Run: node app/code/Weline/Framework/Test/Unit/View/url-frontend-path-mount-contract.mjs
 */

function normalizeWebsiteMountPath(url, config = {}) {
    const rawUrl = String(url || '').trim();
    if (!rawUrl) {
        return '';
    }
    try {
        let pathname = '';
        if (/^https?:\/\//i.test(rawUrl)) {
            pathname = new URL(rawUrl).pathname || '';
        } else if (rawUrl.charAt(0) === '/') {
            pathname = rawUrl.split('?')[0];
        } else {
            pathname = '/' + rawUrl.replace(/^\/+|\/+$/g, '');
        }
        const segments = String(pathname || '').split('/').filter(Boolean);
        const firstSegment = segments[0] || '';
        const apiArea = String(config.apiArea || 'api').toLowerCase();
        if (firstSegment && (firstSegment.toLowerCase() === apiArea || firstSegment.toLowerCase() === 'api')) {
            return '';
        }
        const mount = '/' + segments.join('/');
        return mount === '/' ? '' : mount.replace(/\/+$/, '');
    } catch (e) {
        return '';
    }
}

function peelWebsiteMountPrefix(pathname, mountPath) {
    let path = String(pathname || '/').split('?')[0] || '/';
    if (path.charAt(0) !== '/') {
        path = '/' + path;
    }
    const mount = String(mountPath || '').replace(/\/+$/, '');
    if (!mount || mount === '/') {
        return path;
    }
    const lower = path.toLowerCase();
    const mountLower = mount.toLowerCase();
    if (lower === mountLower) {
        return '/';
    }
    if (lower.indexOf(mountLower + '/') === 0) {
        const peeled = path.slice(mount.length) || '/';
        return peeled.charAt(0) === '/' ? peeled : ('/' + peeled);
    }
    return path;
}

function injectLang(path, lang, websiteUrl, defaultLang = 'en_US') {
    let prePath = normalizeWebsiteMountPath(websiteUrl);
    path = peelWebsiteMountPrefix(path, prePath);
    const parts = String(path || '/').split('/').filter(Boolean).filter((p) => !/^[a-z]{2}_/i.test(p));
    const relative = parts.length ? '/' + parts.join('/') : '/';
    if (lang && lang !== defaultLang) {
        prePath += '/' + lang;
    }
    return (prePath + (relative === '/' ? '' : relative)) || '/';
}

function assertEq(actual, expected, label) {
    if (actual !== expected) {
        throw new Error(`${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}

const site = 'https://pre.example.test/aisite_accept_ok';
assertEq(normalizeWebsiteMountPath(site), '/aisite_accept_ok', 'mount from full url');
assertEq(normalizeWebsiteMountPath('aisite_accept_ok'), '/aisite_accept_ok', 'mount from bare');
assertEq(injectLang('/aisite_accept_ok/about', 'hi_IN', site), '/aisite_accept_ok/hi_IN/about', 'lang after mount');
assertEq(injectLang('/about', 'hi_IN', site), '/aisite_accept_ok/hi_IN/about', 'relative after mount');
assertEq(injectLang('/aisite_accept_ok/hi_IN/about', 'en_US', site), '/aisite_accept_ok/about', 'default lang omits locale');
assertEq(injectLang('/aisite_accept_ok/about', 'hi_IN', site) !== '/hi_IN/aisite_accept_ok/about', true, 'never locale-before-mount');

console.log('url-frontend-path-mount-contract: OK');
