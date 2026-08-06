#!/usr/bin/env bash
# 本番(/var/www/raisemeup.jp/public_html)の作業ツリーを書き換えるgit操作(pull/merge/reset等)を、
# ユーザーから明示的な指示があるまで実行前に確認させるPreToolUse hook(Bash用)。
#
# block-production-writes.shはWrite/Edit/NotebookEditによる直接書き込みのみを対象にしており、
# Bash経由の`git pull`のようなデプロイ操作は素通りしていた。2026-08-06、「改修をお願いします」と
# いう指示だけで、直前のセッションで得た本番化の許可を包括的なものと誤解し、確認なしにgit pullで
# 本番反映してしまう事故が発生したため、その穴を塞ぐ目的で追加。
set -euo pipefail

PROD_ROOT="/var/www/raisemeup.jp/public_html"
STAGING_ROOT="/var/www/staging.tayori-net.jp/public_html"

input="$(cat)"
command="$(printf '%s' "$input" | jq -r '.tool_input.command // empty')"

if [[ -z "$command" ]]; then
    exit 0
fi

# ステージングのパスを明示的に含むコマンドは、ステージング側での操作とみなして対象外とする
if [[ "$command" == *"$STAGING_ROOT"* ]]; then
    exit 0
fi

# 本番の作業ツリーを書き換えるデプロイ相当のgitサブコマンドか判定
if ! printf '%s' "$command" | grep -qE '\bgit\b[^&|;]*\b(pull|merge|rebase|cherry-pick|switch)\b|\bgit\b[^&|;]*\bcheckout\b|\bgit\b[^&|;]*\breset\b|\bgit\b[^&|;]*\bclean\b|\bgit\s+stash\s+(pop|apply)\b'; then
    exit 0
fi

reason="このコマンドは本番(tayori-net.jpのDocumentRoot)の作業ツリーを書き換えるデプロイ相当のgit操作です。運用ルールでは本番反映はユーザーから明示的な指示があった時のみ行います(直前のやり取りで一度許可されていても、次の変更に自動的に適用されるわけではありません)。今回ユーザーから明示的にデプロイの指示が出ている場合のみ、このまま承認してください。"
jq -n --arg reason "$reason" '{
    hookSpecificOutput: {
        hookEventName: "PreToolUse",
        permissionDecision: "ask",
        permissionDecisionReason: $reason
    }
}'
