#!/usr/bin/env bash
# このプロジェクト(/var/www/raisemeup.jp/public_html)はtayori-net.jpの本番DocumentRootそのもの。
# 開発は必ずステージング(/var/www/staging.tayori-net.jp/public_html)側で行い、本番へは
# `git pull` でのみ反映する運用のため、Write/Edit/NotebookEditが本番配下のファイルを
# 対象にしようとした場合はユーザーに一旦確認を求める(PreToolUse hook, permissionDecision=ask)。
#
# 2026-08-04: この確認が無かったため、画像認識機能を誤って本番に直接実装してしまう事故が発生した。
set -euo pipefail

PROD_ROOT="/var/www/raisemeup.jp/public_html"

input="$(cat)"
path="$(printf '%s' "$input" | jq -r '.tool_input.file_path // .tool_input.notebook_path // empty')"

if [[ -z "$path" ]]; then
    exit 0
fi

case "$path" in
    "$PROD_ROOT"/*)
        reason="このパスは本番(tayori-net.jpのDocumentRoot)配下です。このプロジェクトの運用ルールは「編集は/var/www/staging.tayori-net.jp/public_html側で行い、本番へはgit pullでのみ反映する」です。デプロイ作業として意図的に本番へ直接書き込む場合はこのまま承認してください。"
        jq -n --arg reason "$reason" '{
            hookSpecificOutput: {
                hookEventName: "PreToolUse",
                permissionDecision: "ask",
                permissionDecisionReason: $reason
            }
        }'
        ;;
    *)
        exit 0
        ;;
esac
