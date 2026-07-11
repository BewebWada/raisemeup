<?php
// トップページ・プライバシーポリシー等、複数ページで共通のヘッダー・フッターを描画する。
// apply.php は申込フローとして独立した見た目(1カラムのカード)のままにしているため、こちらは含めない。
class Layout
{
    private const NAV = [
        'top' => ['/', 'トップ'],
        'apply' => ['/apply/', 'お申込み'],
        'privacy' => ['/privacy/', 'プライバシーポリシー'],
    ];

    public static function renderHeader(string $activePage, string $pageTitle): void
    {
        $fullTitle = $pageTitle !== '' ? "{$pageTitle} | Raise Me Up" : 'Raise Me Up';
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, "Hiragino Sans", "Yu Gothic", sans-serif; background:#f7f6f3; color:#333; margin:0; }
  a { color:#b05a1e; }
  .site-header { background:#fff; border-bottom:1px solid #eee; }
  .site-header nav { max-width:840px; margin:0 auto; padding:16px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
  .site-header .logo { font-size:1.15rem; font-weight:bold; color:#333; text-decoration:none; }
  .site-header .links { display:flex; gap:20px; flex-wrap:wrap; }
  .site-header .links a { color:#555; text-decoration:none; font-size:0.95rem; }
  .site-header .links a.active { color:#b05a1e; font-weight:bold; }
  .site-header .links a:hover { color:#b05a1e; }
  main { max-width:840px; margin:0 auto; padding:32px 20px 60px; }
  .site-footer { border-top:1px solid #eee; margin-top:40px; }
  .site-footer .inner { max-width:840px; margin:0 auto; padding:24px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; font-size:0.85rem; color:#888; }
  .site-footer a { color:#888; text-decoration:none; }
  .site-footer a:hover { color:#b05a1e; }
</style>
</head>
<body>
<header class="site-header">
  <nav>
    <a class="logo" href="/">Raise Me Up</a>
    <div class="links">
      <?php foreach (self::NAV as $key => [$url, $label]): ?>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="<?= $key === $activePage ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </div>
  </nav>
</header>
<main>
        <?php
    }

    public static function renderFooter(): void
    {
        $year = date('Y');
        ?>
</main>
<footer class="site-footer">
  <div class="inner">
    <span>&copy; <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?> Raise Me Up</span>
    <span><a href="/privacy/">プライバシーポリシー</a></span>
  </div>
</footer>
</body>
</html>
        <?php
    }
}
