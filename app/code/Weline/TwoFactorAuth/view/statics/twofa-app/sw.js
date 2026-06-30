/**
 * Service Worker for Weline 2FA App
 * 提供离线支持和缓存功能
 */

const CACHE_NAME = 'weline-2fa-v2.1.0';
const urlsToCache = [
    '/twofa-app/',
    '/twofa-app/index.html',
    '/twofa-app/style.css',
    '/twofa-app/jsqr',
    '/twofa-app/qr-scanner',
    '/twofa-app/app.js',
    '/twofa-app/manifest.json'
];

// 安装Service Worker
self.addEventListener('install', event => {
    console.log('[SW] Installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('[SW] Caching app shell');
                return cache.addAll(urlsToCache);
            })
            .then(() => self.skipWaiting())
    );
});

// 激活Service Worker
self.addEventListener('activate', event => {
    console.log('[SW] Activating...');
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[SW] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// 拦截网络请求
self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // 缓存命中，返回缓存资源
                if (response) {
                    return response;
                }

                // 缓存未命中，从网络获取
                return fetch(event.request).then(response => {
                    // 检查是否是有效响应
                    if (!response || response.status !== 200 || response.type !== 'basic') {
                        return response;
                    }

                    // 克隆响应
                    const responseToCache = response.clone();

                    // 将新资源添加到缓存
                    caches.open(CACHE_NAME)
                        .then(cache => {
                            cache.put(event.request, responseToCache);
                        });

                    return response;
                });
            })
            .catch(() => {
                // 网络和缓存都失败，返回离线页面
                return new Response(
                    '<html><body><h1>离线模式</h1><p>请检查网络连接</p></body></html>',
                    { headers: { 'Content-Type': 'text/html' } }
                );
            })
    );
});

// 处理消息
self.addEventListener('message', event => {
    if (event.data.action === 'skipWaiting') {
        self.skipWaiting();
    }
});

