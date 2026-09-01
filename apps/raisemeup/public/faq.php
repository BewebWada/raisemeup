<?php
// 旧FAQ単独ページ。内容はサポートページ(support.php)に統合したため、恒久的に移転したことを
// 検索エンジン・既存ブックマークの双方に伝える301リダイレクトのみを残す
header('Location: /support/', true, 301);
exit;
