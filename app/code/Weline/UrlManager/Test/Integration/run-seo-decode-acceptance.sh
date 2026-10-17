#!/usr/bin/env bash
# Merged SEO decode acceptance: Sqlite unit contracts + live multi-scope matrix.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../../../../" && pwd)"
cd "$ROOT"
EVIDENCE_DIR="${ROOT}/dev/session"
mkdir -p "$EVIDENCE_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
UNIT_LOG="${EVIDENCE_DIR}/seo-decode-unit-${STAMP}.log"
LIVE_LOG="${EVIDENCE_DIR}/seo-decode-live-${STAMP}.log"
SUMMARY="${EVIDENCE_DIR}/seo-decode-merged-${STAMP}.json"

echo "== UrlManager Unit (bootstrap_phpunit) =="
php vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --do-not-cache-result \
  app/code/Weline/UrlManager/Test/Unit \
  | tee "$UNIT_LOG"

echo "== Multi-scope live acceptance (app/bootstrap PG) =="
php vendor/bin/phpunit --bootstrap app/bootstrap.php --do-not-cache-result \
  app/code/Weline/UrlManager/Test/Integration/MultiScopeSeoDecodeAcceptanceTest.php \
  | tee "$LIVE_LOG"

php -r '
$unit = file_get_contents($argv[1]);
$live = file_get_contents($argv[2]);
$summary = [
  "ts" => date("c"),
  "unit_log" => $argv[1],
  "live_log" => $argv[2],
  "unit_ok" => str_contains($unit, "OK ("),
  "live_ok" => str_contains($live, "OK ("),
];
preg_match("/OK \((\d+) tests?, (\d+) assertions?\)/", $unit, $um);
preg_match("/OK \((\d+) tests?, (\d+) assertions?\)/", $live, $lm);
if ($um) { $summary["unit_tests"] = (int)$um[1]; $summary["unit_assertions"] = (int)$um[2]; }
if ($lm) { $summary["live_tests"] = (int)$lm[1]; $summary["live_assertions"] = (int)$lm[2]; }
$summary["verdict"] = ($summary["unit_ok"] && $summary["live_ok"]) ? "PASS" : "FAIL";
file_put_contents($argv[3], json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($summary, JSON_UNESCAPED_UNICODE), PHP_EOL;
exit(($summary["unit_ok"] && $summary["live_ok"]) ? 0 : 1);
' "$UNIT_LOG" "$LIVE_LOG" "$SUMMARY"
