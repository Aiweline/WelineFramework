#!/usr/bin/env bash
# WelineFramework deploy webhook helper.
# 默认只更新当前 Git 工作区；监听模式使用 PHP 内置 HTTP 服务接收 Git Webhook。

set -euo pipefail

SCRIPT_SOURCE="${BASH_SOURCE[0]:-$0}"
SCRIPT_DIR="$(cd "$(dirname "$SCRIPT_SOURCE")" && pwd)"
SCRIPT_FILE="$SCRIPT_DIR/$(basename "$SCRIPT_SOURCE")"
DEFAULT_DEPLOY_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CONFIG_FILE="${DEPLOY_CONFIG_FILE:-$SCRIPT_DIR/.config}"

timestamp() {
  date '+%Y-%m-%d %H:%M:%S'
}

log() {
  local line
  line="[$(timestamp)] $*"
  printf '%s\n' "$line"
  if [ -n "${LOG_FILE:-}" ]; then
    mkdir -p "$(dirname "$LOG_FILE")"
    printf '%s\n' "$line" >> "$LOG_FILE"
  fi
}

die() {
  log "ERROR: $*"
  exit 1
}

is_truthy() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

load_config() {
  if [ ! -f "$CONFIG_FILE" ]; then
    die "Config file not found: $CONFIG_FILE"
  fi

  set -a
  # shellcheck disable=SC1090
  . "$CONFIG_FILE"
  set +a
}

set_defaults() {
  DEPLOY_ROOT="${DEPLOY_ROOT:-$DEFAULT_DEPLOY_ROOT}"
  case "$DEPLOY_ROOT" in
    /*|[A-Za-z]:*) ;;
    *) DEPLOY_ROOT="$(cd "$SCRIPT_DIR/$DEPLOY_ROOT" && pwd)" ;;
  esac

  GIT_REMOTE="${GIT_REMOTE:-origin}"
  GIT_REMOTE_URL="${GIT_REMOTE_URL:-}"
  GIT_BRANCH="${GIT_BRANCH:-}"
  GIT_UPDATE_MODE="${GIT_UPDATE_MODE:-reset}"
  DEPLOY_FORCE_RESET="${DEPLOY_FORCE_RESET:-0}"
  DEPLOY_SWITCH_BRANCH="${DEPLOY_SWITCH_BRANCH:-0}"
  GIT_SUBMODULE_UPDATE="${GIT_SUBMODULE_UPDATE:-0}"

  RUN_COMPOSER_INSTALL="${RUN_COMPOSER_INSTALL:-0}"
  COMPOSER_COMMAND="${COMPOSER_COMMAND:-composer install --no-dev --prefer-dist --optimize-autoloader}"
  POST_DEPLOY_COMMAND="${POST_DEPLOY_COMMAND:-}"

  LOCK_FILE="${LOCK_FILE:-$DEPLOY_ROOT/var/deploy-webhook.lock}"
  LOG_FILE="${LOG_FILE:-$DEPLOY_ROOT/var/log/deploy-webhook.log}"

  WEBHOOK_HOST="${WEBHOOK_HOST:-127.0.0.1}"
  WEBHOOK_PORT="${WEBHOOK_PORT:-9097}"
  WEBHOOK_PATH="${WEBHOOK_PATH:-/deploy}"
  WEBHOOK_SECRET="${WEBHOOK_SECRET:-}"
  WEBHOOK_BRANCH="${WEBHOOK_BRANCH:-$GIT_BRANCH}"
  WEBHOOK_BASH="${WEBHOOK_BASH:-bash}"
  case "$WEBHOOK_PATH" in
    /*) ;;
    *) WEBHOOK_PATH="/$WEBHOOK_PATH" ;;
  esac

  CLOUDFLARE_ENABLED="${CLOUDFLARE_ENABLED:-0}"
  CLOUDFLARE_API_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
  CLOUDFLARE_ZONE_ID="${CLOUDFLARE_ZONE_ID:-}"
}

enter_lock() {
  local lock_dir
  lock_dir="${LOCK_FILE}.d"
  if mkdir "$lock_dir" 2>/dev/null; then
    DEPLOY_LOCK_DIR="$lock_dir"
    printf '%s\n' "$$" > "$lock_dir/pid"
    trap 'rm -f "$DEPLOY_LOCK_DIR/pid"; rmdir "$DEPLOY_LOCK_DIR" 2>/dev/null || true' EXIT INT TERM
    return 0
  fi
  die "Deploy is already running, lock exists: $lock_dir"
}

ensure_clean_tree_or_allowed() {
  local status
  status="$(git status --porcelain --untracked-files=no)"
  if [ -n "$status" ] && ! is_truthy "$DEPLOY_FORCE_RESET"; then
    die "Tracked files have local changes. Set DEPLOY_FORCE_RESET=1 only on a dedicated deploy checkout."
  fi
}

maybe_set_remote_url() {
  if [ -n "$GIT_REMOTE_URL" ]; then
    git remote set-url "$GIT_REMOTE" "$GIT_REMOTE_URL"
    log "Git remote URL updated from config for remote: $GIT_REMOTE"
  fi
}

maybe_update_submodules() {
  if is_truthy "$GIT_SUBMODULE_UPDATE"; then
    log "Updating Git submodules."
    git submodule update --init --recursive
  fi
}

maybe_run_composer() {
  if is_truthy "$RUN_COMPOSER_INSTALL"; then
    require_command composer
    log "Running composer command."
    bash -lc "$COMPOSER_COMMAND"
  fi
}

maybe_run_post_deploy() {
  if [ -n "$POST_DEPLOY_COMMAND" ]; then
    log "Running post deploy command."
    bash -lc "$POST_DEPLOY_COMMAND"
  fi
}

maybe_purge_cloudflare() {
  if ! is_truthy "$CLOUDFLARE_ENABLED"; then
    log "Cloudflare purge disabled, skip."
    return 0
  fi

  require_command curl
  if [ -z "$CLOUDFLARE_API_TOKEN" ] || [ -z "$CLOUDFLARE_ZONE_ID" ]; then
    die "Cloudflare is enabled but CLOUDFLARE_API_TOKEN or CLOUDFLARE_ZONE_ID is empty."
  fi

  log "Purging Cloudflare cache."
  curl --fail --silent --show-error \
    -X POST "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/purge_cache" \
    -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
    -H "Content-Type: application/json" \
    --data '{"purge_everything":true}' >/dev/null
  log "Cloudflare cache purge completed."
}

deploy() {
  load_config
  set_defaults
  enter_lock

  require_command git
  cd "$DEPLOY_ROOT"
  git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "Not a Git working tree: $DEPLOY_ROOT"

  local current_branch target_branch target_commit
  current_branch="$(git rev-parse --abbrev-ref HEAD)"
  if [ "$current_branch" = "HEAD" ] && [ -z "$GIT_BRANCH" ]; then
    die "Current checkout is detached. Set GIT_BRANCH in $CONFIG_FILE."
  fi
  target_branch="${GIT_BRANCH:-$current_branch}"

  if [ "$current_branch" != "$target_branch" ]; then
    if is_truthy "$DEPLOY_SWITCH_BRANCH"; then
      log "Switching branch from $current_branch to $target_branch."
      git checkout "$target_branch"
    else
      die "Current branch is $current_branch, target branch is $target_branch. Set DEPLOY_SWITCH_BRANCH=1 to allow switching."
    fi
  fi

  maybe_set_remote_url
  ensure_clean_tree_or_allowed

  log "Deploy start: root=$DEPLOY_ROOT remote=$GIT_REMOTE branch=$target_branch mode=$GIT_UPDATE_MODE"
  case "$GIT_UPDATE_MODE" in
    reset)
      git fetch --prune "$GIT_REMOTE" "$target_branch"
      target_commit="$(git rev-parse --verify 'FETCH_HEAD^{commit}')"
      git reset --hard "$target_commit"
      ;;
    pull_ff_only)
      git pull --ff-only "$GIT_REMOTE" "$target_branch"
      ;;
    *)
      die "Unsupported GIT_UPDATE_MODE: $GIT_UPDATE_MODE"
      ;;
  esac

  maybe_update_submodules
  maybe_run_composer
  maybe_run_post_deploy
  maybe_purge_cloudflare

  log "Deploy completed: $(git rev-parse --short HEAD)"
}

write_php_router() {
  local router_file="$1"
  cat > "$router_file" <<'PHP'
<?php
declare(strict_types=1);

function respond(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function header_value(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    if ($name === 'Authorization') {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $authKey) {
            if (isset($_SERVER[$authKey]) && is_string($_SERVER[$authKey])) {
                return $_SERVER[$authKey];
            }
        }
    }
    return '';
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/health') {
    respond(200, ['ok' => true]);
}

$webhookPath = getenv('WEBHOOK_PATH') ?: '/deploy';
if ($path !== $webhookPath) {
    respond(404, ['ok' => false, 'error' => 'not found']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'only POST is allowed']);
}

$secret = getenv('WEBHOOK_SECRET') ?: '';
if ($secret === '') {
    respond(500, ['ok' => false, 'error' => 'WEBHOOK_SECRET is empty']);
}

$raw = file_get_contents('php://input');
$raw = is_string($raw) ? $raw : '';
$valid = false;

$giteeToken = header_value('X-Gitee-Token');
$giteeTimestamp = header_value('X-Gitee-Timestamp');
if ($giteeToken !== '' && $giteeTimestamp !== '') {
    $computed = base64_encode(hash_hmac('sha256', $giteeTimestamp . "\n" . $secret, $secret, true));
    $valid = hash_equals($computed, $giteeToken);
}
if (!$valid && $giteeToken !== '') {
    $valid = hash_equals($secret, $giteeToken);
}

$gitlabToken = header_value('X-Gitlab-Token');
if (!$valid && $gitlabToken !== '') {
    $valid = hash_equals($secret, $gitlabToken);
}

$githubSignature = header_value('X-Hub-Signature-256');
if (!$valid && str_starts_with($githubSignature, 'sha256=')) {
    $computed = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    $valid = hash_equals($computed, $githubSignature);
}

$authorization = header_value('Authorization');
if (!$valid && preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) {
    $valid = hash_equals($secret, $m[1]);
}

$queryToken = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if (!$valid && $queryToken !== '') {
    $valid = hash_equals($secret, $queryToken);
}

if (!$valid) {
    respond(403, ['ok' => false, 'error' => 'invalid webhook token']);
}

$branch = getenv('WEBHOOK_BRANCH') ?: getenv('GIT_BRANCH') ?: '';
if ($branch !== '') {
    $payload = json_decode($raw, true);
    $ref = is_array($payload) && isset($payload['ref']) ? (string) $payload['ref'] : '';
    if ($ref !== '' && $ref !== $branch && $ref !== 'refs/heads/' . $branch) {
        respond(202, ['ok' => true, 'skipped' => true, 'reason' => 'branch mismatch', 'ref' => $ref]);
    }
}

$script = getenv('WEBHOOK_SCRIPT') ?: '';
if ($script === '' || !is_file($script)) {
    respond(500, ['ok' => false, 'error' => 'WEBHOOK_SCRIPT is invalid']);
}

$bash = getenv('WEBHOOK_BASH') ?: 'bash';
$cmd = escapeshellarg($bash) . ' ' . escapeshellarg($script) . ' deploy --from-webhook 2>&1';
$output = [];
$exitCode = 1;
exec($cmd, $output, $exitCode);

respond($exitCode === 0 ? 200 : 500, [
    'ok' => $exitCode === 0,
    'exit_code' => $exitCode,
    'output_tail' => array_slice($output, -20),
]);
PHP
}

listen() {
  load_config
  set_defaults

  require_command php
  if [ -z "$WEBHOOK_SECRET" ]; then
    die "WEBHOOK_SECRET must be configured before starting the listener."
  fi

  local router_file
  router_file="$(mktemp "${TMPDIR:-/tmp}/weline-deploy-webhook-router.XXXXXX.php")"
  write_php_router "$router_file"
  trap 'rm -f "$router_file"' EXIT INT TERM

  WEBHOOK_SCRIPT="$SCRIPT_FILE"
  DEPLOY_CONFIG_FILE="$CONFIG_FILE"
  export WEBHOOK_PATH WEBHOOK_SECRET WEBHOOK_BRANCH WEBHOOK_SCRIPT WEBHOOK_BASH DEPLOY_CONFIG_FILE GIT_BRANCH

  log "Webhook listener started: http://$WEBHOOK_HOST:$WEBHOOK_PORT$WEBHOOK_PATH"
  log "Health check: http://$WEBHOOK_HOST:$WEBHOOK_PORT/health"
  php -S "$WEBHOOK_HOST:$WEBHOOK_PORT" "$router_file"
}

check_config() {
  load_config
  set_defaults

  local failed=0
  printf 'Config: %s\n' "$CONFIG_FILE"
  printf 'Deploy root: %s\n' "$DEPLOY_ROOT"
  printf 'Git remote: %s\n' "$GIT_REMOTE"
  printf 'Git branch: %s\n' "${GIT_BRANCH:-<current>}"
  printf 'Update mode: %s\n' "$GIT_UPDATE_MODE"
  printf 'Webhook listen: http://%s:%s%s\n' "$WEBHOOK_HOST" "$WEBHOOK_PORT" "$WEBHOOK_PATH"

  if ! command -v git >/dev/null 2>&1; then
    printf 'ERROR: git is not installed or not in PATH.\n'
    failed=1
  fi
  if [ ! -d "$DEPLOY_ROOT/.git" ]; then
    printf 'ERROR: DEPLOY_ROOT is not a Git checkout.\n'
    failed=1
  fi
  if ! command -v php >/dev/null 2>&1; then
    printf 'WARN: php is not installed or not in PATH, listen mode will not work.\n'
  fi
  if [ -z "$WEBHOOK_SECRET" ]; then
    printf 'WARN: WEBHOOK_SECRET is empty, listen mode will refuse to start.\n'
  fi
  if is_truthy "$CLOUDFLARE_ENABLED"; then
    if ! command -v curl >/dev/null 2>&1; then
      printf 'ERROR: curl is required when CLOUDFLARE_ENABLED=1.\n'
      failed=1
    fi
    if [ -z "$CLOUDFLARE_API_TOKEN" ] || [ -z "$CLOUDFLARE_ZONE_ID" ]; then
      printf 'ERROR: Cloudflare is enabled but token or zone id is empty.\n'
      failed=1
    fi
  else
    printf 'Cloudflare: disabled.\n'
  fi

  return "$failed"
}

usage() {
  cat <<'USAGE'
Usage:
  bash dev/deploy/webhook.sh deploy   # Update current Git checkout
  bash dev/deploy/webhook.sh listen   # Start local Git webhook listener
  bash dev/deploy/webhook.sh check    # Validate config and dependencies

Config:
  dev/deploy/.config
  dev/deploy/.config.exsample
USAGE
}

main() {
  local command_name="${1:-deploy}"
  case "$command_name" in
    deploy) deploy ;;
    listen) listen ;;
    check) check_config ;;
    help|-h|--help) usage ;;
    *) usage; die "Unknown command: $command_name" ;;
  esac
}

main "$@"
