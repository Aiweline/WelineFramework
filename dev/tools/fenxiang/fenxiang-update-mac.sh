#!/usr/bin/env bash
set -euo pipefail

branch="dev"
core_repo=""
commit_message=""
site_commit_message=""
include_paths=()
sites=()
skip_commit=0
skip_push=0
skip_site_update=0
skip_site_commit=0
skip_site_push=0
skip_wls_reload=0
dry_run=0

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

split_csv_into() {
    local value="$1"
    local target="$2"
    local part
    IFS=',' read -r -a parts <<< "$value"
    for part in "${parts[@]}"; do
        [[ -n "$part" ]] || continue
        eval "$target+=(\"\$part\")"
    done
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -b|--branch|-Branch)
            branch="$2"
            shift 2
            ;;
        --core-repo|-CoreRepo)
            core_repo="$2"
            shift 2
            ;;
        --commit-message|-CommitMessage)
            commit_message="$2"
            shift 2
            ;;
        --site-commit-message|-SiteCommitMessage)
            site_commit_message="$2"
            shift 2
            ;;
        --include-paths|-IncludePaths)
            split_csv_into "$2" include_paths
            shift 2
            ;;
        --site|--sites|-Sites)
            split_csv_into "$2" sites
            shift 2
            ;;
        --skip-commit|-SkipCommit)
            skip_commit=1
            shift
            ;;
        --skip-push|-SkipPush)
            skip_push=1
            shift
            ;;
        --skip-site-update|-SkipSiteUpdate)
            skip_site_update=1
            shift
            ;;
        --skip-site-commit|-SkipSiteCommit)
            skip_site_commit=1
            shift
            ;;
        --skip-site-push|-SkipSitePush)
            skip_site_push=1
            shift
            ;;
        --skip-wls-reload|-SkipWlsReload)
            skip_wls_reload=1
            shift
            ;;
        --dry-run|-DryRun)
            dry_run=1
            shift
            ;;
        -*)
            echo "Unknown option: $1" >&2
            exit 2
            ;;
        *)
            branch="$1"
            shift
            ;;
    esac
done

if [[ -z "$core_repo" ]]; then
    core_repo="$(cd "$script_dir/../../.." && pwd)"
fi

if [[ ! -d "$core_repo/.git" ]]; then
    echo "Core repo is not a git repository: $core_repo" >&2
    exit 1
fi

export PATH="$core_repo/extend/server/php:$core_repo/bin:$PATH"

run_checked() {
    local working_dir="$1"
    local allow_failure="$2"
    shift 2

    echo "[$working_dir] $*"
    if [[ "$dry_run" -eq 1 ]]; then
        return 0
    fi

    local output
    local exit_code
    set +e
    output="$(cd "$working_dir" && "$@" 2>&1)"
    exit_code=$?
    set -e

    if [[ -n "$output" ]]; then
        printf '%s\n' "$output"
    fi

    if [[ "$exit_code" -ne 0 && "$allow_failure" -ne 1 ]]; then
        echo "Command failed with exit code $exit_code: $*" >&2
        exit "$exit_code"
    fi

    return "$exit_code"
}

git_output() {
    (cd "$core_repo" && git "$@")
}

framework_paths=(
    "app/.htaccess"
    "app/autoload.php"
    "app/bootstrap.php"
    "app/bootstrap_phpunit.php"
    "app/code/.gitignore"
    "app/code/config.php"
    "app/code/Weline"
    "app/etc/.gitignore"
    "app/etc/.gitkeep"
    "app/etc/env.sample.php"
    "app/etc/module_dependencies.php"
    "app/etc/modules.php"
    "bin"
    "dev"
    "pub"
    "setup"
)

sensitive_site_paths=(
    ".env"
    "app/.env"
    "app/etc/env.php"
    "dev/deploy/.config"
)

resolve_site_project_root() {
    local site_base="$1"
    if [[ -f "$site_base/bin/w" ]]; then
        printf '%s\n' "$site_base"
        return 0
    fi

    if [[ -f "$site_base/weline/bin/w" ]]; then
        printf '%s\n' "$site_base/weline"
        return 0
    fi

    return 1
}

discover_default_sites() {
    local official_root
    local repo_full
    local candidate
    local candidate_full

    official_root="$(cd "$core_repo/.." && pwd)"
    repo_full="$(cd "$core_repo" && pwd)"

    for candidate in "$official_root"/*; do
        [[ -d "$candidate" ]] || continue
        candidate_full="$(cd "$candidate" && pwd)"
        [[ "$candidate_full" != "$repo_full" ]] || continue
        if resolve_site_project_root "$candidate_full" >/dev/null; then
            sites+=("$candidate_full")
        fi
    done
}

has_weline_command_failure() {
    local output="$1"
    [[ "$output" =~ 没有找到匹配的命令 ]] && return 0
    [[ "$output" =~ 请先更新模块 ]] && return 0
    [[ "$output" == *"Command registry update failed"* ]] && return 0
    [[ "$output" == *"Fatal error"* ]] && return 0
    [[ "$output" == *"Parse error"* ]] && return 0
    [[ "$output" == *"Uncaught "* ]] && return 0
    return 1
}

assert_no_sensitive_core_changes() {
    local status
    if [[ "${#include_paths[@]}" -gt 0 ]]; then
        status="$(cd "$core_repo" && git status --porcelain -- "${include_paths[@]}")"
    else
        status="$(cd "$core_repo" && git status --porcelain)"
    fi

    [[ -n "$status" ]] || return 0

    local line
    local path
    local normalized
    while IFS= read -r line; do
        [[ -n "$line" && "${#line}" -ge 4 ]] || continue
        path="${line:3}"
        path="${path%\"}"
        path="${path#\"}"
        normalized="${path//\\//}"
        case "$normalized" in
            .env|app/.env|app/etc/env.php|dev/deploy/.config)
                echo "Refusing to commit sensitive/protected file: $path" >&2
                exit 1
                ;;
        esac
        if [[ "$normalized" =~ (^|/)(id_rsa|id_dsa|id_ecdsa|id_ed25519)$ || "$normalized" =~ \.(pem|key|pfx|p12)$ ]]; then
            echo "Refusing to commit sensitive/protected file: $path" >&2
            exit 1
        fi
    done <<< "$status"
}

normalize_git_status_path() {
    local line="$1"
    local path="${line:3}"
    path="${path%\"}"
    path="${path#\"}"
    if [[ "$path" == *" -> "* ]]; then
        path="${path##* -> }"
    fi
    printf '%s\n' "${path//\\//}"
}

is_framework_path() {
    local path="$1"
    local allowed
    for allowed in "${framework_paths[@]}"; do
        if [[ "$path" == "$allowed" || "$path" == "$allowed/"* ]]; then
            return 0
        fi
    done
    return 1
}

is_sensitive_site_path() {
    local path="$1"
    local sensitive
    for sensitive in "${sensitive_site_paths[@]}"; do
        [[ "$path" == "$sensitive" ]] && return 0
    done
    [[ "$path" =~ (^|/)(id_rsa|id_dsa|id_ecdsa|id_ed25519)$ ]] && return 0
    [[ "$path" =~ \.(pem|key|pfx|p12)$ ]] && return 0
    return 1
}

assert_site_clean_before_update() {
    local project_root="$1"
    local status
    status="$(cd "$project_root" && git status --porcelain)"
    if [[ -n "$status" ]]; then
        echo "Site has local changes before core:update; refusing to mix business or manual changes: $project_root" >&2
        printf '%s\n' "$status" >&2
        return 1
    fi
    return 0
}

collect_site_framework_changes() {
    local project_root="$1"
    local status
    local line
    local path
    site_framework_changes=()
    site_non_framework_changes=()
    site_sensitive_changes=()

    status="$(cd "$project_root" && git status --porcelain)"
    [[ -n "$status" ]] || return 0

    while IFS= read -r line; do
        [[ -n "$line" && "${#line}" -ge 4 ]] || continue
        path="$(normalize_git_status_path "$line")"
        if is_sensitive_site_path "$path"; then
            site_sensitive_changes+=("$path")
        elif is_framework_path "$path"; then
            site_framework_changes+=("$path")
        else
            site_non_framework_changes+=("$path")
        fi
    done <<< "$status"
}

commit_site_framework_changes() {
    local project_root="$1"
    local message="$site_commit_message"
    [[ -n "$message" ]] || message="core: update framework core from $branch"

    collect_site_framework_changes "$project_root"

    if [[ "${#site_sensitive_changes[@]}" -gt 0 ]]; then
        echo "Site has sensitive/protected changes after core:update; refusing to commit: ${site_sensitive_changes[*]}" >&2
        return 1
    fi
    if [[ "${#site_non_framework_changes[@]}" -gt 0 ]]; then
        echo "Site has non-framework changes after core:update; refusing to commit business paths: ${site_non_framework_changes[*]}" >&2
        return 1
    fi
    if [[ "${#site_framework_changes[@]}" -eq 0 ]]; then
        echo "[$project_root] no framework changes to commit."
        return 0
    fi

    run_checked "$project_root" 0 git add -A -- "${site_framework_changes[@]}"
    run_checked "$project_root" 0 git diff --cached --check
    run_checked "$project_root" 0 git commit -m "$message"

    if [[ "$skip_site_push" -eq 1 ]]; then
        echo "[$project_root] site push skipped by --skip-site-push."
        return 0
    fi

    local site_remotes
    site_remotes="$(cd "$project_root" && git remote)"
    if ! printf '%s\n' "$site_remotes" | grep -qx 'origin'; then
        echo "Site repo must have remote 'origin' before pushing: $project_root" >&2
        return 1
    fi
    run_checked "$project_root" 0 git push origin "HEAD:$branch"
    if printf '%s\n' "$site_remotes" | grep -qx 'github'; then
        run_checked "$project_root" 0 git push github "HEAD:$branch"
    fi
}

if [[ "${#sites[@]}" -eq 0 ]]; then
    discover_default_sites
fi
if [[ "${#sites[@]}" -eq 0 ]]; then
    echo "No fenxiang site projects were found for core repo: $core_repo" >&2
    exit 1
fi

current_branch="$(git_output branch --show-current)"
if [[ "$current_branch" != "$branch" ]]; then
    echo "Current core branch is '$current_branch', but fenxiang target branch is '$branch'. Switch branch or pass --branch explicitly." >&2
    exit 1
fi

echo "Fenxiang core repo: $core_repo"
echo "Fenxiang branch: $branch"
echo "Fenxiang dry-run: $dry_run"
echo "Fenxiang sites: ${sites[*]}"

remotes="$(git_output remote)"
if ! printf '%s\n' "$remotes" | grep -qx 'origin'; then
    echo "Core repo must have remote 'origin'." >&2
    exit 1
fi

if [[ "$skip_commit" -ne 1 ]]; then
    assert_no_sensitive_core_changes
    if [[ "${#include_paths[@]}" -gt 0 ]]; then
        status="$(cd "$core_repo" && git status --porcelain -- "${include_paths[@]}")"
    else
        status="$(cd "$core_repo" && git status --porcelain)"
    fi

    if [[ -n "$status" ]]; then
        if [[ "${#include_paths[@]}" -gt 0 ]]; then
            run_checked "$core_repo" 0 git add -A -- "${include_paths[@]}"
        else
            run_checked "$core_repo" 0 git add -A
        fi
        run_checked "$core_repo" 0 git diff --cached --check
        if [[ -z "$commit_message" ]]; then
            run_checked "$core_repo" 0 git commit
        else
            run_checked "$core_repo" 0 git commit -m "$commit_message"
        fi
    else
        echo "Core repo has no local changes; commit skipped."
    fi
fi

if [[ "$skip_push" -ne 1 ]]; then
    run_checked "$core_repo" 0 git push origin "HEAD:$branch"
    if printf '%s\n' "$remotes" | grep -qx 'github'; then
        run_checked "$core_repo" 0 git push github "HEAD:$branch"
    elif [[ "$dry_run" -eq 1 ]]; then
        echo "Remote 'github' is not configured; would push origin only." >&2
    else
        echo "Remote 'github' is not configured; pushed origin only." >&2
    fi
fi

if [[ "$skip_site_update" -eq 1 ]]; then
    echo "Site update skipped by --skip-site-update."
    exit 0
fi

failures=()
for site in "${sites[@]}"; do
    if ! project_root="$(resolve_site_project_root "$site")"; then
        failures+=("$site => bin/w not found")
        echo "Skipping $site because bin/w was not found." >&2
        continue
    fi

    if [[ "$dry_run" -eq 1 ]]; then
        echo "[$project_root] git status --porcelain"
        echo "[$project_root] php bin/w core:update -b $branch"
        if [[ "$skip_site_commit" -ne 1 ]]; then
            echo "[$project_root] git add -A -- <framework changes only>"
            echo "[$project_root] git diff --cached --check"
            echo "[$project_root] git commit -m \"${site_commit_message:-core: update framework core from $branch}\""
            if [[ "$skip_site_push" -ne 1 ]]; then
                echo "[$project_root] git push origin HEAD:$branch"
            fi
        fi
        if [[ "$skip_wls_reload" -ne 1 ]]; then
            echo "[$project_root] php bin/w server:reload -n"
        fi
        continue
    fi

    if ! assert_site_clean_before_update "$project_root"; then
        failures+=("$project_root => site worktree is not clean before core:update")
        continue
    fi

    set +e
    output="$(cd "$project_root" && php bin/w core:update -b "$branch" 2>&1)"
    exit_code=$?
    set -e
    echo "[$project_root] php bin/w core:update -b $branch"
    [[ -z "$output" ]] || printf '%s\n' "$output"
    if [[ "$exit_code" -ne 0 ]] || has_weline_command_failure "$output"; then
        failures+=("$project_root => core:update failed")
        continue
    fi

    if [[ "$skip_site_commit" -ne 1 ]]; then
        if ! commit_site_framework_changes "$project_root"; then
            failures+=("$project_root => framework commit failed")
            continue
        fi
    fi

    if [[ "$skip_wls_reload" -ne 1 ]]; then
        set +e
        reload_output="$(cd "$project_root" && php bin/w server:reload -n 2>&1)"
        reload_exit=$?
        set -e
        echo "[$project_root] php bin/w server:reload -n"
        [[ -z "$reload_output" ]] || printf '%s\n' "$reload_output"
        if [[ "$reload_exit" -ne 0 ]] || has_weline_command_failure "$reload_output"; then
            failures+=("$project_root => WLS reload failed")
        fi
    fi
done

if [[ "${#failures[@]}" -gt 0 ]]; then
    printf 'Fenxiang completed with site update failures: %s\n' "${failures[*]}" >&2
    exit 1
fi

echo "Fenxiang completed successfully."
