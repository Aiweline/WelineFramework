#!/usr/bin/env bash
# H01：Visitor 像素渠道波次 G 单测门禁（不依赖完整框架 bootstrap）。
# 用法：bash app/code/Weline/Visitor/test/Unit/gate-wave-g.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../../../.." && pwd)"
cd "$ROOT"
PHPUNIT="${ROOT}/vendor/bin/phpunit"
if [[ ! -x "$PHPUNIT" ]]; then
  echo "phpunit not found at $PHPUNIT" >&2
  exit 1
fi

FILES=(
  app/code/Weline/Visitor/test/Unit/Model/PixelStatsHourlyTest.php
  app/code/Weline/Visitor/test/Unit/Model/PixelStatsDailyTest.php
  app/code/Weline/Visitor/test/Unit/Model/PixelStatsJobLogTest.php
  app/code/Weline/Visitor/test/Unit/Model/PixelArchiveTest.php
  app/code/Weline/Visitor/test/Unit/Service/PixelStatsHourlyAggregateServiceTest.php
  app/code/Weline/Visitor/test/Unit/Service/PixelStatsDailyAggregateServiceTest.php
  app/code/Weline/Visitor/test/Unit/Service/Report/PixelQueryRouterWarmTierTest.php
  app/code/Weline/Visitor/test/Unit/Service/Report/PixelReportQueryServiceTest.php
  app/code/Weline/Visitor/test/Unit/Service/PixelArchiveMigrateServiceTest.php
  app/code/Weline/Visitor/test/Unit/Service/PixelHotRetentionServiceTest.php
  app/code/Weline/Visitor/test/Unit/Service/PixelColdArchiveQueryServiceTest.php
  app/code/Weline/Visitor/test/Unit/Controller/Backend/PixelDashboardArchiveListContractTest.php
  app/code/Weline/Visitor/test/Unit/Service/VisitorTrackingConfigRetentionRuntimeTest.php
  app/code/Weline/Visitor/test/Unit/Service/PixelStatisticsServiceListDrilldownQueryTest.php
  app/code/Weline/Visitor/test/Unit/Service/PixelStatisticsServiceListDrilldownContractTest.php
)

exec "$PHPUNIT" --no-configuration "${FILES[@]}"
