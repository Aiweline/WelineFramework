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
- Matrix rows are differentiated per engine via `SEARCH_ENGINE_RULE_CATALOG` (`rule_id` → engines → official URL → browser-testable signal → severity). Shared DOM heuristics are not treated as every-engine hard fails.
- Browser mode can verify rendered DOM/head/schema/content signals only; robots.txt, HTTP headers, redirect chains, Core Web Vitals, IndexNow, and webmaster-console states remain `unknown` until a server crawler or external API checks them. HTML `<link rel="sitemap">` and favicon are not Indexability hard fails (Google: robots.txt `Sitemap:` / Search Console; favicon is SERP branding only).
- Panel SEO Tab extras (still lazy after `weline`/token): copy HTML for Google Rich Results Test; optional `seo/gsc/inspect` URL Inspection when a Google account is bound; short-lived LCP/CLS/INP observers start only after `inspector.js` loads.
- The full-site audit uses the server crawler. Local development HTTPS hosts such as `{hash}.test.weline.com`, `.test`, `.localhost`, `.local`, loopback, and private IPs may use self-signed certificates, so the crawler relaxes TLS verification only for those hosts (`LocalDevelopmentHostPolicy`) and records this as `crawl.tlsVerification=relaxed_for_local_development`; public hosts still require a trusted HTTPS certificate chain.
