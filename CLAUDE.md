# 重要: このディレクトリは本番環境です

`/var/www/raisemeup.jp/public_html` は LINE公式アカウント「TAYORI」の**本番DocumentRoot**です(`tayori-net.jp` のApache vhostがこのディレクトリを指しています。`/etc/apache2/sites-available/tayori-net.jp.conf` 参照)。

## 編集ルール

- **コードの編集(Write/Edit/NotebookEdit)は必ずステージング側で行うこと**: `/var/www/staging.tayori-net.jp/public_html`
- 本番へは **`git pull` でのみ** 反映する(ステージングでcommit・push → 本番でpull)
- 本番ディレクトリへの直接のファイル書き込みは、`.claude/settings.json` のhook(`.claude/hooks/block-production-writes.sh`)により確認プロンプトが出る。デプロイ作業として意図的に行う場合のみ承認すること

この方針は2026-08-04、画像認識機能を誤って本番に直接実装してしまった事故を受けて明文化・hook化した。詳細は `.claude/hooks/block-production-writes.sh` のコメントを参照。
