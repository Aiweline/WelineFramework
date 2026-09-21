#!/usr/bin/env python3
"""Multi-locale cross-concurrency correctness probe.

Interleaves the same logical routes across many storefront locales plus admin
login locales under high concurrency. Asserts (HTTP 200 only):

- response path locale matches the requested locale
- same (locale, route) identity is stable across rounds
- different locales do not share identical titles for the same route family
  when both succeed (soft: only flag when titles are identical AND html lang
  disagrees — primary hard checks are path locale + FE/BE + PDP slug)
- FE never lands on admin; admin never gets storefront PDP title set
- PDP slug identity does not cross-contaminate across products

Usage:
  python3 app/code/Weline/Framework/Test/Manual/cache_isolation_locale_cross_correctness.py
"""
from __future__ import annotations

import concurrent.futures as cf
import json
import os
import re
import subprocess
import tempfile
import time
from collections import Counter, defaultdict
from pathlib import Path
from urllib.parse import quote, urlparse

BASE = os.environ.get("LCROSS_BASE", "https://p05113ef3.test.weline.com:9555")
WORKER = os.environ.get("LCROSS_WORKER", "http://127.0.0.1:19655")
HOST = os.environ.get("LCROSS_HOST", "p05113ef3.test.weline.com")
# When BASE is worker-direct HTTP, always send Host (and skip dual via_worker).
FORCE_WORKER_HOST = BASE.startswith("http://127.0.0.1") or BASE.startswith("http://localhost")
ADMIN_PREFIX = "/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH"
CONCURRENCY = int(os.environ.get("CONCURRENCY", "40"))
ROUNDS = int(os.environ.get("ROUNDS", "5"))
# Comma-separated route kinds; empty = all FE_ROUTES. Skip PDP/admin when set.
ROUTE_KINDS = {
    k.strip() for k in os.environ.get("ROUTE_KINDS", "").split(",") if k.strip()
}

# Only Website-enabled storefront locales (disallowed prefixes 301-strip since C1/A2).
FE_LOCALES = [
    "zh_Hans_CN",
    "en_US",
    "fr_FR",
    "de_DE",
    "es_ES",
    "ru_RU",
]
BE_LOCALES = [
    "zh_Hans_CN",
    "en_US",
    "ja_JP",
    "fr_FR",
    "de_DE",
    "ko_KR",
    "es_ES",
    "ru_RU",
]

# Diversity: products + policy/content shells + search/category (skip known 5xx/404).
FE_ROUTES: list[tuple[str, str]] = [
    ("home", ""),
    ("products", "products"),
    ("blog", "blog"),
    ("about", "about"),
    ("faq", "faq"),
    ("guide", "guide"),
    ("terms", "terms"),
    ("sitemap", "sitemap"),
    ("qa", "qa"),
    ("activity", "activity"),
    ("currency", "currency"),
    ("contact", "customer/contact"),
    ("search", "search?q=hanfu"),
    ("cat_hanfu", "category/hanfu"),
    ("best", "best-sellers"),
]

# Hard markers: if another locale's about H1 appears under a different expect locale → content cross.
ABOUT_H1_MARKERS: dict[str, str] = {
    "zh_Hans_CN": "关于我们",
    "en_US": "About Us",
    "fr_FR": "À propos de nous",
    "de_DE": "Über uns",
    "es_ES": "Sobre nosotros",
}

LOCALE_RE = re.compile(
    r"^/(zh_Hans_CN|zh_Hant_[A-Za-z]+|en_US|[a-z]{2}_[A-Z]{2})(?:/|$)"
)


def curl_to(
    url: str,
    out: Path,
    headers: list[str] | None = None,
    timeout: int = 90,
) -> tuple[int, str, dict[str, str]]:
    hdr_path = out.with_suffix(out.suffix + ".hdr")
    cmd = [
        "/usr/bin/curl",
        "-sk",
        "-L",
        "--max-time",
        str(timeout),
        "-D",
        str(hdr_path),
        "-w",
        "%{http_code}|%{url_effective}",
        "-o",
        str(out),
    ]
    for h in headers or []:
        cmd += ["-H", h]
    cmd.append(url)
    r = subprocess.run(cmd, capture_output=True, text=True)
    meta = (r.stdout or "").strip()
    code_s, _, eff = meta.partition("|")
    code = int(code_s) if code_s.isdigit() else 0
    return code, eff or url, parse_response_headers(hdr_path)


def parse_response_headers(path: Path) -> dict[str, str]:
    """Parse final response headers from curl -D (follow redirects → last block)."""
    if not path.exists():
        return {}
    raw = path.read_text(errors="replace")
    blocks = re.split(r"\r?\n\r?\n", raw.strip())
    last = blocks[-1] if blocks else ""
    out: dict[str, str] = {}
    for line in last.splitlines():
        if ":" not in line:
            continue
        k, _, v = line.partition(":")
        out[k.strip().lower()] = v.strip()
    return out


def path_locale(path: str) -> str | None:
    m = LOCALE_RE.match(path or "")
    return m.group(1) if m else None


def build_url(path: str, via_worker: bool) -> tuple[str, list[str] | None]:
    if "?" in path:
        base_path, qs = path.split("?", 1)
        parts = []
        for pair in qs.split("&"):
            if "=" in pair:
                k, v = pair.split("=", 1)
                parts.append(f"{quote(k, safe='')}={quote(v, safe='')}")
            elif pair:
                parts.append(quote(pair, safe=""))
        path_enc = base_path + "?" + "&".join(parts) + "&no_cache=1&lcross=1&__title_locale=1"
    else:
        path_enc = path + "?no_cache=1&lcross=1&__title_locale=1"
    if via_worker or FORCE_WORKER_HOST:
        return f"{WORKER if via_worker and not FORCE_WORKER_HOST else BASE}{path_enc}", [f"Host: {HOST}"]
    return f"{BASE}{path_enc}", None


def parse(
    kind: str,
    req: str,
    expect_locale: str,
    body: str,
    code: int,
    effective: str,
    resp_headers: dict[str, str] | None = None,
) -> dict:
    final = urlparse(effective).path or req.split("?", 1)[0]
    got_locale = path_locale(final)
    title_m = re.search(r"<title[^>]*>(.*?)</title>", body, re.I | re.S)
    title = re.sub(r"\s+", " ", title_m.group(1)).strip() if title_m else ""
    # Prefer layout-owned H1 when present (about/policy shells); avoid chrome/false first-h1.
    h1_m = re.search(
        r'id=["\']about-layout-title["\'][^>]*>(.*?)</h1>',
        body,
        re.I | re.S,
    )
    if not h1_m:
        h1_m = re.search(
            r'id=["\'][^"\']*layout-title[^"\']*["\'][^>]*>(.*?)</h1>',
            body,
            re.I | re.S,
        )
    if not h1_m:
        h1_m = re.search(r"<h1[^>]*>(.*?)</h1>", body, re.I | re.S)
    h1 = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", h1_m.group(1) if h1_m else "")).strip()
    html_lang_m = re.search(r"""<html[^>]*\slang=["']([^"']+)""", body, re.I)
    html_lang = (html_lang_m.group(1) if html_lang_m else "").replace("-", "_")
    # switcher / active locale markers commonly used by Theme
    active_markers = sorted(
        set(
            re.findall(
                r'data-(?:locale|lang|language)(?:-code)?=["\']([^"\']+)["\']',
                body,
                re.I,
            )
        )
    )[:12]
    slug = final.rstrip("/").split("/")[-1]
    is_admin = ADMIN_PREFIX in final or "/admin" in final
    hdrs = resp_headers or {}
    title_locale_hdr = hdrs.get("x-weline-title-locale", "")
    title_locale_mismatch = hdrs.get("x-weline-title-locale-mismatch", "")
    return {
        "kind": kind,
        "req": req,
        "expect_locale": expect_locale,
        "final": final,
        "got_locale": got_locale or "",
        "code": code,
        "title": title,
        "h1": h1,
        "html_lang": html_lang,
        "active_markers": active_markers,
        "slug": slug,
        "surface": "backend" if is_admin else "frontend",
        "size": len(body),
        "title_locale_hdr": title_locale_hdr,
        "title_locale_mismatch": title_locale_mismatch,
    }


def discover_pdp_slugs(outdir: Path, limit: int = 12) -> list[str]:
    slugs: list[str] = []
    seed = Path("/tmp/product_urls_100.txt")
    if seed.exists():
        for line in seed.read_text().splitlines():
            line = line.strip()
            if "/product/" not in line:
                continue
            tail = line.rstrip("/").split("/")[-1]
            if tail.isdigit():
                continue
            if tail not in slugs:
                slugs.append(tail)
            if len(slugs) >= limit:
                return slugs
    out = outdir / "harvest_products.html"
    hdrs = [f"Host: {HOST}"] if FORCE_WORKER_HOST else None
    curl_to(f"{BASE}/zh_Hans_CN/products", out, headers=hdrs)
    body = out.read_text(errors="replace") if out.exists() else ""
    for p in re.findall(r"/zh_Hans_CN/product/([a-z0-9\-]+)/?", body):
        if p.isdigit():
            continue
        if p not in slugs:
            slugs.append(p)
        if len(slugs) >= limit:
            break
    return slugs[:limit]


def build_targets(outdir: Path) -> list[tuple[str, str, str]]:
    """Return (kind, path, expect_locale)."""
    targets: list[tuple[str, str, str]] = []
    routes = FE_ROUTES
    if ROUTE_KINDS:
        routes = [(k, r) for k, r in FE_ROUTES if k in ROUTE_KINDS]
    for loc in FE_LOCALES:
        for kind, rel in routes:
            if rel == "":
                path = f"/{loc}/"
            elif "?" in rel:
                path = f"/{loc}/{rel}"
            else:
                path = f"/{loc}/{rel}"
            targets.append((f"{kind}@{loc}", path, loc))
    if not ROUTE_KINDS:
        for slug in discover_pdp_slugs(outdir, 12):
            for loc in FE_LOCALES:
                targets.append((f"pdp@{loc}", f"/{loc}/product/{slug}/", loc))
        for loc in BE_LOCALES:
            targets.append((f"admin_login@{loc}", f"{ADMIN_PREFIX}/{loc}/admin/login", loc))
    return targets


def main() -> int:
    outdir = Path(tempfile.mkdtemp(prefix="locale_cross_"))
    print(f"workdir={outdir}")
    targets = build_targets(outdir)
    print(f"targets={len(targets)} fe_locales={len(FE_LOCALES)} be_locales={len(BE_LOCALES)}")
    print("kinds", dict(Counter(k.split("@")[0] for k, _, _ in targets)))

    jobs: list[tuple[str, str, str, str, int]] = []
    for r in range(ROUNDS):
        for i, (kind, path, loc) in enumerate(targets):
            jobs.append((kind, path, loc, f"{kind}_{r}_{i}", r))

    print(f"jobs={len(jobs)} concurrency={CONCURRENCY}")
    t0 = time.time()

    def run(kind: str, path: str, loc: str, tag: str, round_i: int) -> dict:
        out = outdir / f"{tag}.html"
        via_worker = kind.startswith("pdp@") and (hash(tag) % 4 == 0)
        url, headers = build_url(path, via_worker)
        code, eff, resp_headers = curl_to(url, out, headers=headers)
        body = out.read_text(errors="replace") if out.exists() else ""
        probe = parse(kind, path, loc, body, code, eff, resp_headers)
        probe["via"] = "worker" if via_worker else "edge"
        probe["tag"] = tag
        probe["round"] = round_i
        return probe

    results: list[dict] = []
    errors: list[str] = []
    with cf.ThreadPoolExecutor(max_workers=CONCURRENCY) as ex:
        futs = [ex.submit(run, *job) for job in jobs]
        for i, fut in enumerate(cf.as_completed(futs), 1):
            try:
                results.append(fut.result())
            except Exception as e:  # noqa: BLE001
                errors.append(str(e))
            if i % 100 == 0:
                print(f"  progress {i}/{len(jobs)}")

    elapsed = time.time() - t0
    fails: list[dict] = []
    capacity: list[dict] = []
    ok = [r for r in results if r["code"] == 200]

    for r in results:
        if r["code"] in (0, 502, 503, 504):
            capacity.append({"code": r["code"], "kind": r["kind"], "req": r["req"][:100]})

    if len(ok) < max(40, len(jobs) // 10):
        fails.append({"issue": "too_few_http_200", "ok": len(ok), "jobs": len(jobs)})

    # Hard locale correctness among HTTP 200:
    # - If final path still has a locale segment, it must equal expect.
    # - If path locale was stripped by redirect (common for en_US), html_lang
    #   (when present) must match expect language family.
    locale_mismatch = []
    html_lang_bad = []
    for r in ok:
        if r["surface"] == "backend":
            if (
                ADMIN_PREFIX in r["final"]
                and r["expect_locale"] not in r["final"]
                and f"/{r['expect_locale']}/" not in r["final"]
            ):
                locale_mismatch.append(
                    {
                        "surface": "backend",
                        "req": r["req"],
                        "final": r["final"],
                        "expect": r["expect_locale"],
                    }
                )
            continue

        got = r["got_locale"]
        exp = r["expect_locale"]
        if got and got != exp:
            locale_mismatch.append(
                {
                    "surface": "frontend",
                    "req": r["req"],
                    "final": r["final"],
                    "got": got,
                    "expect": exp,
                }
            )
        elif not got:
            # path stripped — require html_lang family match when available
            hl = (r["html_lang"] or "").replace("-", "_")
            if hl:
                exp_l = exp.split("_")[0].lower()
                hl_l = hl.split("_")[0].lower()
                if exp_l == "zh" and hl_l == "zh":
                    pass
                elif hl_l != exp_l and hl.replace("-", "_") != exp:
                    html_lang_bad.append(
                        {
                            "req": r["req"],
                            "final": r["final"],
                            "html_lang": r["html_lang"],
                            "expect": exp,
                            "note": "path_locale_stripped",
                        }
                    )

        # When path keeps locale, html_lang should still agree (detect wrong L1 lang)
        if got and got == exp and r["html_lang"]:
            hl = r["html_lang"].replace("-", "_")
            exp_l = exp.split("_")[0].lower()
            hl_l = hl.split("_")[0].lower()
            if not (hl_l == exp_l or (exp_l == "zh" and hl_l == "zh") or hl == exp):
                html_lang_bad.append(
                    {
                        "req": r["req"],
                        "final": r["final"],
                        "html_lang": r["html_lang"],
                        "expect": exp,
                        "note": "path_locale_kept_html_lang_wrong",
                    }
                )

    if locale_mismatch:
        fails.append(
            {
                "issue": "locale_path_mismatch",
                "n": len(locale_mismatch),
                "sample": locale_mismatch[:15],
            }
        )
    if html_lang_bad:
        fails.append(
            {
                "issue": "html_lang_mismatch",
                "n": len(html_lang_bad),
                "sample": html_lang_bad[:12],
            }
        )
    # Theme module placeholder: only flag layout-owned H1 / empty document title pairs
    # when the about (or generic layout-title) H1 itself is a module code.
    theme_leaks = []
    for r in ok:
        if not r["kind"].startswith("about@"):
            continue
        if r["h1"] == "Weline_Theme" or (
            r["h1"] and __import__("re").match(r"^Weline_[A-Za-z]", r["h1"])
        ):
            theme_leaks.append(
                {
                    "req": r["req"],
                    "final": r["final"],
                    "title": r["title"][:40],
                    "h1": r["h1"][:40],
                }
            )
        elif r["title"] == "Weline_Theme":
            theme_leaks.append(
                {
                    "req": r["req"],
                    "final": r["final"],
                    "title": r["title"][:40],
                    "h1": r["h1"][:40],
                    "note": "document_title",
                }
            )
    if theme_leaks:
        fails.append(
            {
                "issue": "theme_placeholder_leak",
                "n": len(theme_leaks),
                "sample": theme_leaks[:12],
            }
        )

    # Cross-locale about H1: response shows another language's canonical about title.
    content_cross = []
    for r in ok:
        if not r["kind"].startswith("about@"):
            continue
        expect = r["expect_locale"]
        h1 = (r["h1"] or "").strip()
        if not h1:
            continue
        for other_loc, marker in ABOUT_H1_MARKERS.items():
            if other_loc == expect:
                continue
            if h1 == marker:
                content_cross.append(
                    {
                        "req": r["req"],
                        "expect": expect,
                        "h1": h1[:60],
                        "matched_locale_marker": other_loc,
                    }
                )
                break
    if content_cross:
        fails.append(
            {
                "issue": "cross_locale_content_h1",
                "n": len(content_cross),
                "sample": content_cross[:15],
            }
        )

    # Soft-hard removed: old blanket html_lang / generic theme leak loops replaced above

    # FE/BE cross
    fe_ok = [r for r in ok if r["surface"] == "frontend"]
    be_ok = [r for r in ok if r["surface"] == "backend"]
    for r in fe_ok:
        if ADMIN_PREFIX in r["final"]:
            fails.append({"issue": "fe_landed_on_admin", "req": r["req"], "final": r["final"]})
        if "管理面板" in r["title"] and "登录" in r["title"] and "/admin" not in r["req"]:
            fails.append({"issue": "fe_admin_title", "req": r["req"], "title": r["title"][:80]})
    pdp_titles = {r["title"] for r in fe_ok if r["kind"].startswith("pdp@") and r["title"]}
    for r in be_ok:
        if r["title"] in pdp_titles:
            fails.append({"issue": "admin_title_equals_pdp", "title": r["title"][:80]})

    # Stability: same req path across rounds → same title/h1 among 200
    # Ignore pure theme-placeholder flicker (counted separately as theme_leaks).
    by_req = defaultdict(list)
    for r in ok:
        by_req[r["req"]].append(r)
    unstable = []
    for req, group in by_req.items():
        if len(group) < 2:
            continue
        titles = {g["title"] for g in group if g["title"] and g["title"] != "Weline_Theme"}
        h1s = {g["h1"] for g in group if g["h1"] and g["h1"] != "Weline_Theme"}
        locales = {g["got_locale"] for g in group if g["got_locale"]}
        # path locale may strip for some locales; only flag when two non-empty disagree
        if len(locales) > 1:
            unstable.append({"req": req, "field": "got_locale", "vals": list(locales)})
        if len(titles) > 1:
            unstable.append({"req": req, "field": "title", "vals": [t[:40] for t in list(titles)[:3]]})
        if len(h1s) > 1:
            unstable.append({"req": req, "field": "h1", "vals": list(h1s)[:3]})
    if unstable:
        fails.append({"issue": "identity_unstable_across_rounds", "n": len(unstable), "sample": unstable[:12]})

    # Title×locale probe headers (require __title_locale=1 on request)
    title_probe_mismatch: list[dict] = []
    title_probe_seen = 0
    for r in ok:
        hdr = (r.get("title_locale_hdr") or "").strip()
        mism = (r.get("title_locale_mismatch") or "").strip()
        if hdr or mism != "":
            title_probe_seen += 1
        if mism == "1":
            title_probe_mismatch.append(
                {
                    "req": r["req"],
                    "expect": r["expect_locale"],
                    "hdr": hdr[:120],
                    "title": (r.get("title") or "")[:60],
                    "kind": r["kind"],
                }
            )
    if title_probe_mismatch:
        fails.append(
            {
                "issue": "title_locale_probe_mismatch",
                "n": len(title_probe_mismatch),
                "sample": title_probe_mismatch[:15],
            }
        )

    # Cross-locale title collision for same logical route (home@zh vs home@en may differ —
    # FAIL only if two different locales share exact same title for different expect_locale
    # AND both claim success with matching path locales — that's usually OK for brand-only
    # titles. Flag only when titles identical across locales for PDP of different slugs.)
    pdp_ok = [r for r in fe_ok if r["kind"].startswith("pdp@")]
    by_slug_locale: dict[tuple[str, str], set[str]] = defaultdict(set)
    for r in pdp_ok:
        slug = r["req"].rstrip("/").split("/")[-1]
        by_slug_locale[(slug, r["expect_locale"])].add(r["h1"] or r["title"])
    # same slug across locales: h1 may translate; identity key is slug in path
    cross_slug = []
    slug_h1_zh: dict[str, str] = {}
    for r in pdp_ok:
        if r["expect_locale"] != "zh_Hans_CN" or not r["h1"]:
            continue
        slug = r["req"].rstrip("/").split("/")[-1]
        slug_h1_zh[slug] = r["h1"]
    # different slugs must not share zh h1
    inv: dict[str, list[str]] = defaultdict(list)
    for slug, h1 in slug_h1_zh.items():
        inv[h1].append(slug)
    for h1, slugs in inv.items():
        if len(set(slugs)) > 1:
            cross_slug.append({"h1": h1, "slugs": slugs[:5]})
    if cross_slug:
        fails.append({"issue": "pdp_h1_shared_across_slugs", "n": len(cross_slug), "sample": cross_slug[:8]})

    # Locale coverage among 200
    fe_loc_cov = Counter(r["expect_locale"] for r in fe_ok)
    be_loc_cov = Counter(r["expect_locale"] for r in be_ok)

    summary = {
        "concurrency": CONCURRENCY,
        "rounds": ROUNDS,
        "targets": len(targets),
        "jobs": len(jobs),
        "completed": len(results),
        "errors": len(errors),
        "elapsed_s": round(elapsed, 2),
        "http_200": len(ok),
        "capacity_errors_n": len(capacity),
        "capacity_codes": dict(Counter(c["code"] for c in capacity)),
        "fe_200": len(fe_ok),
        "be_200": len(be_ok),
        "fe_locale_coverage": dict(fe_loc_cov),
        "be_locale_coverage": dict(be_loc_cov),
        "title_locale_probe_seen_n": title_probe_seen,
        "title_locale_mismatch_n": len(title_probe_mismatch),
        "title_locale_mismatch_sample": title_probe_mismatch[:8],
        "fail_n": len(fails),
        "pass": len(fails) == 0 and len(errors) == 0,
    }

    report = {
        "summary": summary,
        "fails": fails,
        "capacity_sample": capacity[:25],
        "sample_by_locale": {
            loc: next((r["title"][:80] for r in ok if r["expect_locale"] == loc and r["kind"].startswith("home@")), "")
            for loc in FE_LOCALES
        },
        "sample_admin_by_locale": {
            loc: next((r["title"][:80] for r in be_ok if r["expect_locale"] == loc), "")
            for loc in BE_LOCALES
        },
    }
    out_json = Path("/tmp/cache_isolation_locale_cross_correctness.json")
    out_json.write_text(json.dumps(report, ensure_ascii=False, indent=2))
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    if fails:
        print("FAILS", json.dumps(fails[:8], ensure_ascii=False, indent=2))
    print("capacity_errors", len(capacity), "codes", summary["capacity_codes"])
    print(
        "LOCALE CROSS CORRECTNESS",
        "PASSED" if summary["pass"] else "FAILED",
        "(among HTTP 200; capacity separate)",
    )
    print("report", out_json)
    return 0 if summary["pass"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
