#!/usr/bin/env bash
# Claude Codeはrootで動いているため、.envをWrite/Editで書き換えると
# (一時ファイル+rename方式の書き込みでinodeが再生成され)所有者がwww-data→rootに
# 変わってしまうことがある。これによりApache(www-data)が.envを読めなくなり、
# 500エラーになった実例がある(2026-08-18、本番でStripeキーをLive用に切り替えた際に発生)。
# PostToolUseで.envの所有者をwww-data:www-dataに戻す。
set -euo pipefail

input="$(cat)"
path="$(printf '%s' "$input" | jq -r '.tool_input.file_path // .tool_input.notebook_path // empty')"

if [[ -z "$path" ]]; then
    exit 0
fi

if [[ "$(basename "$path")" == ".env" ]] && [[ -f "$path" ]]; then
    chown www-data:www-data "$path" 2>/dev/null || true
fi

exit 0
