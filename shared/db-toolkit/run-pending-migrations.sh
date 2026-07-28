#!/usr/bin/env bash
# git hookから呼ばれる想定のスクリプト。標準入力から変更されたファイルパスの一覧を受け取り、
# apps/*/db/ 配下(schema/migrate.php/alterations/seeds)またはshared/db-toolkit配下に変更が
# あれば、該当アプリ(shared配下の変更なら全アプリ)のdb/migrate.phpを自動実行する。
#
# これは「コードは保存した瞬間に本番へ反映されるが、DBスキーマ変更だけはmigrate.phpの
# 手動実行が別途必要」というギャップを埋め、コミット漏れによる本番障害
# (例: 2026-07-27 medication_logsテーブル未作成でLINE Webhookが全滅した件)を防ぐためのもの。
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

changed_files="$(cat)"

shared_changed=false
if echo "$changed_files" | grep -q '^shared/db-toolkit/'; then
    shared_changed=true
fi

for migrate_script in apps/*/db/migrate.php; do
    [ -f "$migrate_script" ] || continue
    app_dir="$(dirname "$(dirname "$migrate_script")")"
    app_name="$(basename "$app_dir")"

    run_it=false
    if [ "$shared_changed" = true ]; then
        run_it=true
    elif echo "$changed_files" | grep -q "^${app_dir}/db/"; then
        run_it=true
    fi

    if [ "$run_it" = true ]; then
        echo "[migrate-hook] running ${migrate_script} (app: ${app_name})"
        if ! php "$migrate_script"; then
            echo "[migrate-hook] WARNING: migration failed for ${app_name}. Run manually: php ${migrate_script}" >&2
        fi
    fi
done
