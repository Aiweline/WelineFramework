#!/usr/bin/env python3
"""High-diversity FE+BE concurrent cache-isolation probe.

Checks page identity (title/h1/slug) and surface markers (frontend vs admin,
widget/chrome fingerprints) under heavy interleaved concurrency.

Usage:
  python3 app/code/Weline/Framework/Test/Manual/cache_isolation_fe_be_stress.py
"""
from __future__ import annotations

import concurrent.futures as cf
import json
import re
import subprocess
import tempfile
import time
from collections import Counter, defaultdict
from pathlib import Path
from urllib.parse import quote, urlparse

BASE = "https://p05113ef3.test.weline.com:9555"
WORKER = "http://127.0.0.1:19655"
HOST = "p05113ef3.test.weline.com"
ADMIN_PREFIX = "/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH"
CONCURRENCY = 48
ROUNDS = 8


def curl_to(url: str, out: Path, headers: list[str] | None = None, timeout: int = 120) -> tuple[int, str]:
    cmd = [
        "/usr/bin/curl",
        "-sk",
        "-L",
        "--max-time",
        str(timeout),
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
    return code, eff or url


def extract_widgets(body: str) -> dict:
    ids = sorted(
        set(
            re.findall(r'data-widget(?:-code|-id|Code)?=["\']([^"\']+)["\']', body, re.I)
            + re.findall(r'data-weline-widget=["\']([^"\']+)["\']', body, re.I)
            + re.findall(r'class=["\'][^"\']*\bwidget-([a-z0-9_-]+)', body, re.I)
        )
    )[:40]
    slots = sorted(
        set(
            re.findall(r'data-(?:slot|weline-slot)(?:-id)?=["\']([^"\']+)["\']', body, re.I)
            + re.findall(r'data-layout-slot=["\']([^"\']+)["\']', body, re.I)
        )
    )[:40]
    modules = sorted(set(re.findall(r'data-widget-module=["\']([^"\']+)["\']', body, re.I)))[:40]
    hooks = sorted(set(re.findall(r"Weline_[A-Za-z0-9]+::hooks/[^\"'\s<]+", body)))[:30]
    # Theme often leaves HTML comments / data-source markers
    sources = sorted(set(re.findall(r'data-(?:source|hook)-(?:file|path)=["\']([^"\']+)["\']', body, re.I)))[:30]
    return {
        "widget_ids": ids,
        "slots": slots,
        "modules": modules,
        "hooks_sample": hooks,
        "sources": sources,
        "widget_n": len(ids),
        "slot_n": len(slots),
        "hook_n": len(hooks),
        "source_n": len(sources),
    }


def build_url(path: str, via_worker: bool) -> tuple[str, list[str] | None]:
    """Attach cache-bust query with proper encoding (CJK safe)."""
    if "?" in path:
        base_path, qs = path.split("?", 1)
        # re-encode query values
        parts = []
        for pair in qs.split("&"):
            if "=" in pair:
                k, v = pair.split("=", 1)
                parts.append(f"{quote(k, safe='')}={quote(v, safe='')}")
            elif pair:
                parts.append(quote(pair, safe=""))
        path_enc = base_path + "?" + "&".join(parts) + "&no_cache=1&iso=1"
    else:
        path_enc = path + "?no_cache=1&iso=1"
    if via_worker:
        return f"{WORKER}{path_enc}", [f"Host: {HOST}"]
    return f"{BASE}{path_enc}", None


def parse_body(kind: str, req: str, body: str, code: int, effective: str) -> dict:
    final = urlparse(effective).path or req.split("?", 1)[0]
    slug = final.rstrip("/").split("/")[-1]
    title_m = re.search(r"<title[^>]*>(.*?)</title>", body, re.I | re.S)
    title = re.sub(r"\s+", " ", title_m.group(1)).strip() if title_m else ""
    h1_m = re.search(r"<h1[^>]*>(.*?)</h1>", body, re.I | re.S)
    h1 = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", h1_m.group(1) if h1_m else "")).strip()
    can = re.search(r'rel=["\']canonical["\'][^>]*href=["\']([^"\']+)', body, re.I)
    if not can:
        can = re.search(r'href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']', body, re.I)
    canonical = can.group(1) if can else ""
    can_path = urlparse(canonical).path if canonical else ""
    can_path = re.sub(r"^/(zh_Hans_CN|en_US|zh_Hant_[A-Z]+)/", "/", can_path)
    can_slug = can_path.rstrip("/").split("/")[-1] if can_path else ""

    is_admin = ADMIN_PREFIX in final or "/admin" in final
    has_login = bool(re.search(r"登录|password|type=[\"']password[\"']", body, re.I))
    has_storefront_cart = bool(re.search(r"/cart|加入购物车|data-add-to-cart", body, re.I))
    has_product_gallery = bool(re.search(r"product-gallery|data-product-id|og:type[\"']\s+content=[\"']product", body, re.I))
    widgets = extract_widgets(body)

    # chrome fingerprint: header/footer with product slug scrubbed
    header = re.search(r"<header[\s\S]{0,40000}</header>", body, re.I)
    footer = re.search(r"<footer[\s\S]{0,40000}</footer>", body, re.I)
    hdr = header.group(0) if header else ""
    ftr = footer.group(0) if footer else ""
    if slug:
        hdr = hdr.replace(slug, "{SLUG}")
        ftr = ftr.replace(slug, "{SLUG}")
    import hashlib

    def sha(s: str) -> str:
        return hashlib.sha256(s.encode("utf-8", "replace")).hexdigest()[:16]

    return {
        "kind": kind,
        "req": req,
        "final": final,
        "slug": slug,
        "code": code,
        "title": title,
        "h1": h1,
        "canonical_slug": can_slug,
        "size": len(body),
        "surface": "backend" if is_admin else "frontend",
        "has_login": has_login,
        "has_storefront_cart": has_storefront_cart,
        "has_product_gallery": has_product_gallery,
        "widgets": widgets,
        "hdr_sha": sha(hdr) if hdr else "",
        "ftr_sha": sha(ftr) if ftr else "",
    }


def discover_catalog(outdir: Path) -> list[tuple[str, str]]:
    """Return list of (kind, path)."""
    catalog: list[tuple[str, str]] = []

    def add(kind: str, path: str) -> None:
        if path and path not in {p for _, p in catalog}:
            catalog.append((kind, path))

    # static FE diversity
    for kind, path in [
        ("home", "/zh_Hans_CN/"),
        ("about", "/zh_Hans_CN/about"),
        ("blog", "/zh_Hans_CN/blog"),
        ("cart", "/zh_Hans_CN/cart"),
        ("products", "/zh_Hans_CN/products"),
        ("search", "/zh_Hans_CN/search?q=hanfu"),
        ("search2", "/zh_Hans_CN/search?q=唐制"),
        ("best", "/zh_Hans_CN/best-sellers"),
        ("faq", "/zh_Hans_CN/faq/b2b-wholesale"),
        ("affiliate", "/zh_Hans_CN/affiliate"),
        ("cat_women", "/zh_Hans_CN/category/women"),
        ("cat_men", "/zh_Hans_CN/category/men"),
        ("cat_kids", "/zh_Hans_CN/category/kids"),
        ("cat_hanfu", "/zh_Hans_CN/category/hanfu"),
        ("cat_acc", "/zh_Hans_CN/category/accessories"),
        ("cat_hair", "/zh_Hans_CN/category/accessories/hair"),
        ("blog_news", "/zh_Hans_CN/blog/category/news"),
        ("blog_yunjin", "/zh_Hans_CN/blog/textile-yunjin"),
        ("blog_suxiu", "/zh_Hans_CN/blog/textile-suxiu"),
        ("blog_songjin", "/zh_Hans_CN/blog/textile-songjin"),
    ]:
        add(kind, path)

    # harvest more from listing pages
    for seed in ["/zh_Hans_CN/", "/zh_Hans_CN/products", "/zh_Hans_CN/blog", "/zh_Hans_CN/category/women"]:
        out = outdir / ("harvest_" + re.sub(r"[^a-z0-9]+", "_", seed) + ".html")
        curl_to(f"{BASE}{seed}", out)
        body = out.read_text(errors="replace") if out.exists() else ""
        for p in sorted(set(re.findall(r'href=["\'](/zh_Hans_CN/[^"\'#?]+)', body))):
            if "/product/" in p:
                add("pdp", p if p.endswith("/") else p + "/")
            elif "/blog/" in p and p.count("/") >= 3:
                add("blog_post", p)
            elif "/category/" in p:
                add("category", p)

    # seed file PDPs (slug URLs only — numeric IDs redirect and pollute concurrency)
    seed_file = Path("/tmp/product_urls_100.txt")
    if seed_file.exists():
        for line in seed_file.read_text().splitlines():
            line = line.strip()
            if not line or "/product/" not in line:
                continue
            tail = line.rstrip("/").split("/")[-1]
            if tail.isdigit():
                continue
            add("pdp_seed", line if line.endswith("/") else line + "/")

    # more FE locale diversity (same page type, different locale prefix)
    for loc in ["en_US", "ja_JP", "ko_KR", "fr_FR"]:
        add(f"home_{loc}", f"/{loc}/")
        add(f"products_{loc}", f"/{loc}/products")
        add(f"blog_{loc}", f"/{loc}/blog")

    # backend admin login surfaces (multiple locales = diversity)
    add("admin", f"{ADMIN_PREFIX}/admin")
    for loc in ["zh_Hans_CN", "en_US", "ja_JP", "fr_FR", "de_DE", "ko_KR", "es_ES", "ru_RU"]:
        add("admin_login", f"{ADMIN_PREFIX}/{loc}/admin/login")

    return catalog


def main() -> int:
    outdir = Path(tempfile.mkdtemp(prefix="cache_iso_febe_"))
    print(f"workdir={outdir}")
    catalog = discover_catalog(outdir)
    # cap PDP explosion but keep diversity
    pdps = [(k, p) for k, p in catalog if k.startswith("pdp")][:40]
    others = [(k, p) for k, p in catalog if not k.startswith("pdp")]
    targets = others + pdps
    print(f"catalog total={len(catalog)} targets={len(targets)} non_pdp={len(others)} pdp={len(pdps)}")
    by_kind = Counter(k for k, _ in targets)
    print("kinds", dict(by_kind))

    jobs: list[tuple[str, str, str, int]] = []
    for r in range(ROUNDS):
        for i, (kind, path) in enumerate(targets):
            jobs.append((kind, path, f"{kind}_{r}_{i}", r))

    print(f"jobs={len(jobs)} concurrency={CONCURRENCY}")
    t0 = time.time()

    def run(kind: str, path: str, tag: str, round_i: int) -> dict:
        out = outdir / f"{tag}.html"
        # Mix edge + worker; backend only via edge (admin host routing)
        via_worker = kind.startswith("pdp") and (round_i % 2 == 0) and (hash(tag) % 3 == 0)
        url, headers = build_url(path, via_worker)
        via = "worker" if via_worker else "edge"
        code, eff = curl_to(url, out, headers=headers)
        body = out.read_text(errors="replace") if out.exists() else ""
        probe = parse_body(kind, path, body, code, eff)
        probe["via"] = via
        probe["tag"] = tag
        return probe

    results: list[dict] = []
    errors: list[str] = []
    with cf.ThreadPoolExecutor(max_workers=CONCURRENCY) as ex:
        futs = [ex.submit(run, k, p, t, r) for k, p, t, r in jobs]
        for i, fut in enumerate(cf.as_completed(futs), 1):
            try:
                results.append(fut.result())
            except Exception as e:  # noqa: BLE001
                errors.append(str(e))
            if i % 80 == 0:
                print(f"  progress {i}/{len(jobs)}")

    elapsed = time.time() - t0
    fails: list[dict] = []
    capacity: list[dict] = []

    ok = [r for r in results if r["code"] == 200]
    fe_ok = [r for r in ok if r["surface"] == "frontend"]
    be_ok = [r for r in ok if r["surface"] == "backend"]

    # Capacity / transport (not cache cross-talk)
    for r in results:
        if r["code"] in (502, 503, 504, 0):
            capacity.append({"code": r["code"], "kind": r["kind"], "req": r["req"][:80]})
        elif r["code"] == 400 and ("search" in r["kind"] or "唐" in r["req"]):
            capacity.append({"code": 400, "kind": r["kind"], "req": r["req"][:80], "note": "query_encoding_or_validation"})

    if len(fe_ok) < 50:
        fails.append({"issue": "too_few_fe_200", "n": len(fe_ok), "total_fe": sum(1 for r in results if r["surface"] == "frontend")})
    if not be_ok:
        fails.append({"issue": "backend_no_200", "n": sum(1 for r in results if r["surface"] == "backend")})

    # --- FE/BE cross contamination (200 only) ---
    for r in fe_ok:
        if ADMIN_PREFIX in r["final"]:
            fails.append({"issue": "fe_landed_on_admin", "req": r["req"], "final": r["final"]})
        if "管理面板" in r["title"] or ("Admin Panel" in r["title"] and "Login" in r["title"]):
            fails.append({"issue": "fe_got_admin_login_title", "req": r["req"], "title": r["title"][:80]})
    for r in be_ok:
        if r["has_product_gallery"]:
            fails.append({"issue": "admin_has_product_gallery", "req": r["req"]})
        if r["kind"].startswith("admin") and r["has_storefront_cart"] and "/cart" in r["final"]:
            fails.append({"issue": "admin_redirected_to_cart", "req": r["req"], "final": r["final"]})

    pdp_rs = [r for r in fe_ok if r["kind"].startswith("pdp") and "/product/" in r["final"]]
    pdp_titles = {r["title"] for r in pdp_rs if r["title"] and "502" not in r["title"] and "400" not in r["title"]}
    for r in be_ok:
        if r["title"] in pdp_titles:
            fails.append({"issue": "admin_title_equals_pdp", "title": r["title"][:80]})

    # --- PDP identity among 200 ---
    by_req = defaultdict(list)
    for r in pdp_rs:
        by_req[r["req"]].append(r)

    unstable = []
    cross = []
    canon = {}
    for req, rs in by_req.items():
        if len(rs) < 2:
            # still record identity
            pass
        finals = Counter(r["final"] for r in rs)
        final = finals.most_common(1)[0][0]
        slug = final.rstrip("/").split("/")[-1]
        h1s = {r["h1"] for r in rs if r["h1"]}
        titles = {r["title"] for r in rs if r["title"]}
        if len(h1s) > 1:
            unstable.append({"req": req, "field": "h1", "vals": list(h1s)[:3]})
        if len(titles) > 1:
            unstable.append({"req": req, "field": "title", "vals": [x[:40] for x in list(titles)[:3]]})
        for r in rs:
            if r["slug"] != slug:
                unstable.append({"req": req, "field": "slug", "got": r["slug"], "expect": slug})
            if r["canonical_slug"] and r["canonical_slug"] != slug:
                other_slugs = {x.rstrip("/").split("/")[-1] for x in by_req}
                if r["canonical_slug"] in other_slugs and r["canonical_slug"] != slug:
                    fails.append(
                        {
                            "issue": "pdp_canonical_foreign",
                            "req": req,
                            "canonical_slug": r["canonical_slug"],
                            "slug": slug,
                        }
                    )
        canon[req] = {"slug": slug, "h1": next(iter(h1s), ""), "title": next(iter(titles), ""), "n": len(rs)}

    if unstable:
        fails.append({"issue": "pdp_identity_unstable", "n": len(unstable), "sample": unstable[:10]})

    keys = list(canon)
    for i in range(len(keys)):
        for j in range(i + 1, len(keys)):
            a, b = canon[keys[i]], canon[keys[j]]
            if a["slug"] == b["slug"]:
                continue
            if a["h1"] and a["h1"] == b["h1"]:
                cross.append({"field": "h1", "a": a["slug"], "b": b["slug"], "val": a["h1"]})
            if a["title"] and a["title"] == b["title"]:
                cross.append({"field": "title", "a": a["slug"][:20], "b": b["slug"][:20]})
    if cross:
        fails.append({"issue": "pdp_cross_contamination", "n": len(cross), "sample": cross[:10]})

    # --- non-PDP families among 200 ---
    for kind in [
        "home",
        "about",
        "blog",
        "cart",
        "products",
        "search",
        "search2",
        "category",
        "blog_post",
        "admin",
        "admin_login",
        "best",
        "faq",
        "affiliate",
    ]:
        rs = [r for r in ok if r["kind"] == kind or r["kind"].startswith(kind + "_") or r["kind"].startswith(kind)]
        # avoid pdp_* matching via startswith pdp — already filtered kinds list
        rs = [r for r in ok if r["kind"] == kind]
        if kind == "category":
            rs = [r for r in ok if r["kind"] in {"category", "cat_women", "cat_men", "cat_kids", "cat_hanfu", "cat_acc", "cat_hair"}]
        if kind.startswith("blog"):
            rs = [r for r in ok if r["kind"].startswith("blog")]
        if kind.startswith("admin"):
            rs = [r for r in ok if r["kind"].startswith("admin")]
        if not rs:
            continue
        byp = defaultdict(list)
        for r in rs:
            byp[r["req"]].append(r)
        for req, group in byp.items():
            titles = {g["title"] for g in group if g["title"]}
            if len(titles) > 1:
                fails.append(
                    {
                        "issue": "family_title_unstable",
                        "kind": kind,
                        "req": req,
                        "vals": [t[:50] for t in list(titles)[:3]],
                    }
                )
            title = next(iter(titles), "")
            if not kind.startswith("admin") and not kind.startswith("pdp") and title and title in pdp_titles:
                fails.append({"issue": "non_pdp_title_equals_pdp", "kind": kind, "req": req, "title": title[:80]})

    # --- widget/chrome mix ---
    fe_hooks = Counter()
    be_hooks = Counter()
    for r in ok:
        for h in r["widgets"]["hooks_sample"] + r["widgets"].get("sources", []):
            (fe_hooks if r["surface"] == "frontend" else be_hooks)[h] += 1
    for r in be_ok:
        if r["widgets"]["widget_n"] > 0 and r["has_product_gallery"]:
            fails.append({"issue": "admin_widget_product_mix", "req": r["req"]})

    home_h1 = {r["h1"] for r in ok if r["kind"] == "home" and r["h1"]}
    about_h1 = {r["h1"] for r in ok if r["kind"] == "about" and r["h1"]}
    if home_h1 and about_h1 and home_h1 == about_h1:
        sample = next(iter(home_h1))
        if len(sample) > 4:
            fails.append({"issue": "home_about_same_h1", "h1": sample})

    summary = {
        "concurrency": CONCURRENCY,
        "rounds": ROUNDS,
        "targets": len(targets),
        "jobs": len(jobs),
        "completed": len(results),
        "errors": len(errors),
        "elapsed_s": round(elapsed, 2),
        "rps": round(len(results) / max(elapsed, 0.1), 2),
        "http_200": len(ok),
        "capacity_errors_n": len(capacity),
        "capacity_codes": dict(Counter(c["code"] for c in capacity)),
        "fe_200": len(fe_ok),
        "be_200": len(be_ok),
        "pdp_200": len(pdp_rs),
        "kinds_200": dict(Counter(r["kind"] for r in ok)),
        "via": dict(Counter(r["via"] for r in results)),
        "fail_n": len(fails),
        "pass": len(fails) == 0,
    }

    widget_stats = {
        "fe_avg_widget_n": round(sum(r["widgets"]["widget_n"] for r in fe_ok) / max(1, len(fe_ok)), 2),
        "fe_avg_slot_n": round(sum(r["widgets"]["slot_n"] for r in fe_ok) / max(1, len(fe_ok)), 2),
        "fe_avg_source_n": round(sum(r["widgets"].get("source_n", 0) for r in fe_ok) / max(1, len(fe_ok)), 2),
        "be_avg_widget_n": round(sum(r["widgets"]["widget_n"] for r in be_ok) / max(1, len(be_ok)), 2),
        "top_fe_hooks": fe_hooks.most_common(8),
        "top_be_hooks": be_hooks.most_common(8),
    }

    report = {
        "summary": summary,
        "widget_stats": widget_stats,
        "fails": fails,
        "capacity_sample": capacity[:20],
        "sample_pdp": {k: v for k, v in list(canon.items())[:8]},
        "sample_admin_titles": sorted({r["title"] for r in be_ok})[:8],
        "sample_home_titles": sorted({r["title"] for r in ok if r["kind"] == "home" and r["title"]})[:5],
        "sample_about_titles": sorted({r["title"] for r in ok if r["kind"] == "about" and r["title"]})[:3],
    }
    out = Path("/tmp/cache_isolation_fe_be_stress.json")
    out.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    Path("var/log/cache_isolation_fe_be_stress.json").write_text(out.read_text(), encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    print("widget_stats", json.dumps(widget_stats, ensure_ascii=False)[:900])
    if capacity:
        print("capacity_errors", len(capacity), "codes", summary["capacity_codes"])
    if fails:
        print("FAILS", len(fails))
        for f in fails[:25]:
            print(json.dumps(f, ensure_ascii=False)[:400])
    else:
        print("ALL FE/BE HIGH-DIVERSITY ISOLATION CHECKS PASSED (among HTTP 200)")
    print("report", out)
    return 0 if not fails else 1


if __name__ == "__main__":
    raise SystemExit(main())
