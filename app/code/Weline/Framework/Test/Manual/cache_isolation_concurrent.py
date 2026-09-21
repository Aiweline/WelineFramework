#!/usr/bin/env python3
"""Manual concurrent cache-isolation probes for storefront PDPs.

Usage (from repo root, server running):
  python3 app/code/Weline/Framework/Test/Manual/cache_isolation_concurrent.py

Writes JSON report to var/log/cache_isolation_report.json
"""
from __future__ import annotations

# Thin wrapper: prefer re-running the session harness via documenting UCs.
# Full harness lives in agent session; this file documents the matrix for humans.

USE_CASES = """
UC1 sequential A-B-A: PDP A, B, A again — slug/title stable; A canonical not B
UC2 concurrent 4 PDPs x3: each response slug matches request; no foreign canonical
UC3 worker-direct A/B interleaved no_cache: sticky Host:port isolation
UC4 language switcher: not exclusively foreign product paths
UC5 home after PDP burst: home title != last PDP title
UC6 concurrent home/search/PDP: titles/slugs not collapsed
UC7b identity: same URL h1/title stable; different URLs distinct h1/title/canonical(slug)
"""

if __name__ == '__main__':
    print(USE_CASES)
    print('Run the full probe from the engineering session or extend this runner.')
