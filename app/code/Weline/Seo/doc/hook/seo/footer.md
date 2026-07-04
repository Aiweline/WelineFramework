# seo::footer

## Purpose

Renders SEO footer-level markup before the document body closes.

## Implementation

- `view/hooks/seo/footer.phtml`

## Contract

- Use this hook only in frontend footer or body-end context.
- Implementations should render footer-safe markup through `Weline_Seo`.
- The default implementation renders `<w:seo slot="footer"/>`.
- The footer slot includes the SEO inspector bootstrap by default; any layout with this hook can open it with the `weline` key command.
- The inspector includes a browser-mode search platform matrix for Google, Bing, Yahoo, Yandex, Baidu, DuckDuckGo, Naver, Seznam, Sogou, and Ecosia/Qwant.
- Browser mode can verify rendered DOM/head/schema/content signals only; robots.txt, HTTP headers, redirect chains, Core Web Vitals, IndexNow, and webmaster-console states remain `unknown` until a server crawler or external API checks them.
- The full-site audit uses the server crawler. Local development HTTPS hosts such as `.test`, `.localhost`, `.local`, loopback, and private IPs may use self-signed certificates, so the crawler relaxes TLS verification only for those hosts and records this as `crawl.tlsVerification=relaxed_for_local_development`; public hosts still require a trusted HTTPS certificate chain.
