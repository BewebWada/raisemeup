<?php
require_once __DIR__ . '/Config.php';

// トップページ・プライバシーポリシー等、複数ページで共通のヘッダー・フッターを描画する。
// apply.php は申込フローとして独立した見た目(1カラムのカード)のままにしているため、こちらは含めない。
class Layout
{
    // ページ個別にmeta descriptionを渡さなかった場合のフォールバック(トップページのヒーローと同じ文言)。
    // OGP(og:description)にも同じ値を使う
    private const DEFAULT_DESCRIPTION = '天気の話でひと笑いするような、何気ないやり取りが、離れて暮らす家族に届きます。';

    // SNS等でリンクを共有された際のOGP画像。TAYORI-mark.svgをもとに1200x630で書き出したもの
    // (SVGはSNSのクローラーがほぼ対応していないため、og:imageには必ずラスター画像を使うこと)
    private const OG_IMAGE_PATH = '/assets/og-image.png';

    private const NAV = [
        'top' => ['/', 'トップ'],
        'about' => ['/about/', 'TAYORIについて'],
        'faq' => ['/faq/', 'よくある質問'],
        'mypage' => ['/mypage/', 'マイページ'],
    ];

    private const CTA = ['/apply/', 'お申込み'];

    // フッターの「サイトマップ」に、NAV・CTAに続けて並べる法定表記等のリンク
    private const FOOTER_LEGAL_LINKS = [
        ['/privacy/', 'プライバシーポリシー'],
        ['/tokushoho/', '特定商取引法に基づく表記'],
    ];

    // 軽量な線画アイコン(外部ライブラリ非導入、他のSVGアイコンと同じ方針)。使う場面が増えたらここに追加する
    private const ICONS = [
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'play' => '<circle cx="12" cy="12" r="9.3"/><path d="M9.5 8v8l7-4z" fill="currentColor" stroke="none"/>',
        'card' => '<rect x="2" y="6" width="20" height="13" rx="2.5"/><path d="M2 10.5h20"/>',
        'shield' => '<path d="M12 21s7.5-3.5 7.5-9.5V6l-7.5-3-7.5 3v5.5C4.5 17.5 12 21 12 21z"/><path d="m9.2 12 2 2 3.6-3.8"/>',
        'chat' => '<path d="M20.5 14.5a2 2 0 0 1-2 2H8l-3.5 3.5V6.5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2z"/>',
        'edit' => '<path d="M12.5 19.5H20"/><path d="M16 4.5a1.9 1.9 0 0 1 2.7 2.7L7.8 18.1l-3.6.9.9-3.6z"/>',
        'login' => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l4-4-4-4"/><path d="M14 12H3"/>',
        'logout' => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M15 16l4-4-4-4"/><path d="M19 12H8"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7"/>',
        'minus' => '<path d="M5 12h14"/>',
        'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
    ];

    public static function icon(string $name, string $class = ''): string
    {
        $path = self::ICONS[$name] ?? null;
        if ($path === null) {
            return '';
        }
        $classAttr = $class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '';
        return '<svg class="icon' . $classAttr . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    }

    public static function renderHeader(string $activePage, string $pageTitle, bool $indexable = false, string $description = ''): void
    {
        $fullTitle = $pageTitle !== '' ? "{$pageTitle} | TAYORI" : 'TAYORI';
        $metaDescription = $description !== '' ? $description : self::DEFAULT_DESCRIPTION;
        // OGP/Twitterカードは絶対URLが必須のため、APP_BASE_URL(https://tayori-net.jp)を基準に組み立てる
        $baseUrl = rtrim((string) Config::get('APP_BASE_URL', ''), '/');
        // クエリ文字列(?done や ?plan_id=1 等)を含めると同一ページが別URL扱いされ重複コンテンツになるため、
        // canonical/OGP共に常にパスのみのURLを正としてクローラーに伝える
        $canonicalPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $currentUrl = $baseUrl . $canonicalPath;
        $ogImageUrl = $baseUrl . self::OG_IMAGE_PATH;
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!$indexable): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<title><?= htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
<link rel="canonical" href="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>">

<link rel="icon" type="image/svg+xml" href="/assets/TAYORI-mark.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16.png">
<link rel="shortcut icon" href="/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">

<meta property="og:type" content="website">
<meta property="og:locale" content="ja_JP">
<meta property="og:site_name" content="TAYORI">
<meta property="og:title" content="<?= htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
<style>
  :root {
    --bg: #FAF6ED;
    --surface: #FFFFFF;
    --text: #3D3A35;
    --text-muted: #726d63;
    --brand: #4B8B5A;
    --brand-dark: #1E4729;
    --accent: #E0904F;
    --card-mint: #DCEEDA;
    --card-peach: #FBE2C4;
    --radius-lg: 22px;
    --radius-pill: 999px;
  }
  * { box-sizing: border-box; }
  html { overflow-x:hidden; }
  body { font-family: -apple-system, "Hiragino Sans", "Yu Gothic", sans-serif; background:var(--bg); color:var(--text); margin:0; overflow-x:hidden; }
  a { color:var(--brand); }
  .site-header { position:fixed; top:0; left:0; right:0; z-index:1100; background:transparent; padding:16px 20px; }
  #site-header-spacer { height:104px; }
  .site-header nav { max-width:1120px; margin:0 auto; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
  .site-header .logo { display:flex; align-items:center; text-decoration:none; margin-top:-22px; border-radius:0 0 14px 14px; overflow:hidden; box-shadow:0 2px 12px rgba(61,58,53,0.08); margin-right:auto; }
  .site-header .logo img { display:block; }
  .site-header .links { display:flex; align-items:center; gap:18px; flex-wrap:wrap; background:rgba(255,255,255,0.78); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-radius:var(--radius-pill); padding:10px 20px; box-shadow:0 2px 12px rgba(61,58,53,0.07); }
  .site-header .links a { color:var(--text-muted); text-decoration:none; font-size:0.92rem; }
  .site-header .links a.active { color:var(--text); font-weight:bold; }
  .site-header .links a:hover { color:var(--text); }
  .site-header .cta-btn { display:inline-flex; align-items:center; gap:8px; background:var(--brand); color:#fff; text-decoration:none; font-size:0.88rem; font-weight:bold; padding:10px 16px 10px 22px; border-radius:var(--radius-pill); transition:background 0.15s; box-shadow:0 2px 12px rgba(61,58,53,0.12); }
  .site-header .cta-btn:hover { background:var(--brand-dark); }
  .site-header .cta-btn .icon { width:1.3em; height:1.3em; }
  .site-header .mypage-btn {
    display:inline-flex; align-items:center; gap:8px;
    background:var(--card-mint);
    color:var(--brand-dark); text-decoration:none; font-size:0.88rem; font-weight:bold;
    padding:10px 18px; border-radius:var(--radius-pill); box-shadow:0 2px 12px rgba(61,58,53,0.07);
    transition:background 0.15s;
  }
  .site-header .mypage-btn:hover { background:#cde6c9; }
  .site-header .mypage-btn.active { background:var(--brand); color:#fff; }
  .site-header .mypage-btn .icon { width:1.2em; height:1.2em; }
  .site-header .links a.mypage-mobile-link { display:none; }
  .icon { width:1.1em; height:1.1em; display:inline-block; vertical-align:-0.15em; flex-shrink:0; }
  .menu-toggle { display:none; position:relative; align-items:center; justify-content:center; width:44px; height:44px; flex-shrink:0; border:none; background:rgba(255,255,255,0.78); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-radius:50%; box-shadow:0 2px 12px rgba(61,58,53,0.07); color:var(--text); cursor:pointer; padding:0; transition:box-shadow 0.15s; }
  .menu-toggle:hover { box-shadow:0 2px 14px rgba(61,58,53,0.14); }
  .menu-toggle .icon {
    position:absolute; top:50%; left:50%; width:1.3em; height:1.3em;
    transform:translate(-50%,-50%) rotate(0deg);
    transition:opacity 0.25s ease, transform 0.25s ease;
  }
  .menu-toggle .icon-close { opacity:0; transform:translate(-50%,-50%) rotate(-90deg); }
  .menu-toggle.open .icon-menu { opacity:0; transform:translate(-50%,-50%) rotate(90deg); }
  .menu-toggle.open .icon-close { opacity:1; transform:translate(-50%,-50%) rotate(0deg); }
  .menu-backdrop { display:none; }
  @media (max-width: 1020px) {
    .site-header nav { flex-wrap:nowrap; position:relative; }
    .site-header .logo img { height:48px; width:auto; }
    .site-header .cta-btn { order:2; padding:10px 16px 10px 20px; font-size:0.82rem; white-space:nowrap; }
    .site-header .mypage-btn { display:none; }
    .menu-toggle { display:inline-flex; order:3; }
    .site-header .links {
      display:flex; position:absolute; top:calc(100% + 10px); right:0; left:auto; min-width:200px;
      flex-direction:column; align-items:stretch; gap:2px; padding:10px; border-radius:var(--radius-lg);
      box-shadow:0 16px 40px rgba(61,58,53,0.22);
      opacity:0; visibility:hidden; pointer-events:none;
      transform:translateY(-8px) scale(0.96); transform-origin:top right;
      transition:opacity 0.2s ease, transform 0.2s ease, visibility 0s linear 0.2s;
    }
    .site-header .links.open {
      opacity:1; visibility:visible; pointer-events:auto; transform:translateY(0) scale(1);
      transition:opacity 0.2s ease, transform 0.2s ease, visibility 0s linear 0s;
    }
    .site-header .links a {
      padding:12px 16px; border-radius:14px;
      opacity:0; transform:translateY(-4px);
      transition:opacity 0.18s ease, transform 0.18s ease, background 0.15s;
    }
    .site-header .links.open a { opacity:1; transform:translateY(0); }
    .site-header .links.open a:nth-child(1) { transition-delay:0.05s; }
    .site-header .links.open a:nth-child(2) { transition-delay:0.1s; }
    .site-header .links.open a:nth-child(3) { transition-delay:0.15s; }
    .site-header .links a:hover, .site-header .links a.active { background:var(--card-mint); }
    .site-header .links a.mypage-mobile-link {
      display:flex; align-items:center; gap:8px;
      margin-top:8px; padding-top:14px; border-top:1px solid #ece7dc;
      background:var(--card-mint); color:var(--brand-dark); font-weight:bold;
    }
    .site-header .links a.mypage-mobile-link .icon { width:1.1em; height:1.1em; }
    .site-header .links a.mypage-mobile-link.active { background:var(--brand); color:#fff; }
    .menu-backdrop {
      display:block; position:fixed; inset:0; z-index:90;
      background:rgba(30,28,24,0.25); opacity:0; pointer-events:none;
      transition:opacity 0.2s ease;
    }
    .menu-backdrop.open { opacity:1; pointer-events:auto; }
  }
  main { max-width:1120px; margin:0 auto; padding:32px 20px 60px; }
  .breadcrumb { font-size:0.82rem; color:var(--text-muted); margin:0 0 24px; }
  .breadcrumb ol { display:flex; flex-wrap:wrap; align-items:center; gap:6px; list-style:none; margin:0; padding:0; }
  .breadcrumb a { color:var(--text-muted); text-decoration:none; }
  .breadcrumb a:hover { color:var(--text); text-decoration:underline; }
  .breadcrumb li { display:flex; align-items:center; gap:6px; }
  .breadcrumb li:not(:last-child)::after { content:"/"; color:#c9c2b3; }
  .breadcrumb li[aria-current="page"] { color:var(--text); font-weight:bold; }
  .site-footer { border-top:1px solid #ece7dc; margin-top:40px; }
  .site-footer .inner { max-width:1120px; margin:0 auto; padding:28px 20px 24px; font-size:0.85rem; color:var(--text-muted); }
  .site-footer a { color:var(--text-muted); text-decoration:none; }
  .site-footer a:hover { color:var(--text); }
  .footer-sitemap { display:flex; flex-wrap:wrap; justify-content:center; gap:10px 28px; padding-bottom:20px; margin-bottom:16px; border-bottom:1px solid #ece7dc; }
  .footer-copyright { text-align:center; font-size:0.8rem; }
</style>
<?php $gaId = Config::get('GA_MEASUREMENT_ID', ''); ?>
<?php if ($gaId !== ''): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($gaId, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?= htmlspecialchars($gaId, ENT_QUOTES, 'UTF-8') ?>');
</script>
<?php endif; ?>
</head>
<body>
<header class="site-header">
  <nav>
    <a class="logo" href="/"><img src="/assets/logo-horizontal.svg" alt="TAYORI" height="92"></a>
    <button type="button" class="menu-toggle" id="menuToggle" aria-expanded="false" aria-controls="siteMenu" aria-label="メニューを開く">
      <?= self::icon('menu', 'icon-menu') ?><?= self::icon('close', 'icon-close') ?>
    </button>
    <div class="links" id="siteMenu">
      <?php foreach (self::NAV as $key => [$url, $label]): ?>
        <?php if ($key === 'mypage') continue; // 別枠のボタン(.mypage-btn)で表示するため、こちらでは除外(モバイル用リンクは後述) ?>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="<?= $key === $activePage ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
      <?php [$mypageUrl, $mypageLabel] = self::NAV['mypage']; ?>
      <a href="<?= htmlspecialchars($mypageUrl, ENT_QUOTES, 'UTF-8') ?>" class="mypage-mobile-link <?= $activePage === 'mypage' ? 'active' : '' ?>"><?= self::icon('login') ?><?= htmlspecialchars($mypageLabel, ENT_QUOTES, 'UTF-8') ?></a>
    </div>
    <a class="mypage-btn <?= $activePage === 'mypage' ? 'active' : '' ?>" href="<?= htmlspecialchars($mypageUrl, ENT_QUOTES, 'UTF-8') ?>"><?= self::icon('login') ?><?= htmlspecialchars($mypageLabel, ENT_QUOTES, 'UTF-8') ?></a>
    <a class="cta-btn" href="<?= htmlspecialchars(self::CTA[0], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(self::CTA[1], ENT_QUOTES, 'UTF-8') ?><?= self::icon('play') ?></a>
  </nav>
</header>
<div id="site-header-spacer" aria-hidden="true"></div>
<script>
(function () {
  var header = document.querySelector('.site-header');
  var spacer = document.getElementById('site-header-spacer');
  if (!header || !spacer) {
    return;
  }
  function syncSpacerHeight() {
    spacer.style.height = header.offsetHeight + 'px';
  }
  syncSpacerHeight();
  window.addEventListener('resize', syncSpacerHeight);
})();
</script>
<div class="menu-backdrop" id="menuBackdrop"></div>
<script>
(function () {
  var toggle = document.getElementById('menuToggle');
  var menu = document.getElementById('siteMenu');
  var backdrop = document.getElementById('menuBackdrop');
  if (!toggle || !menu) {
    return;
  }
  function setOpen(isOpen) {
    menu.classList.toggle('open', isOpen);
    toggle.classList.toggle('open', isOpen);
    if (backdrop) {
      backdrop.classList.toggle('open', isOpen);
    }
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    toggle.setAttribute('aria-label', isOpen ? 'メニューを閉じる' : 'メニューを開く');
  }
  toggle.addEventListener('click', function () {
    setOpen(!menu.classList.contains('open'));
  });
  if (backdrop) {
    backdrop.addEventListener('click', function () { setOpen(false); });
  }
  menu.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () { setOpen(false); });
  });
})();
</script>
<main>
<?php if ($activePage !== 'top'): ?>
<nav class="breadcrumb" aria-label="パンくずリスト">
  <ol>
    <li><a href="/">ホーム</a></li>
    <li aria-current="page"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></li>
  </ol>
</nav>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'ホーム', 'item' => $baseUrl . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $pageTitle, 'item' => $currentUrl],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
        <?php
    }

    public static function renderFooter(): void
    {
        $year = date('Y');
        ?>
</main>
<footer class="site-footer">
  <div class="inner">
    <nav class="footer-sitemap" aria-label="サイトマップ">
      <?php foreach (self::NAV as [$url, $label]): ?>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
      <a href="<?= htmlspecialchars(self::CTA[0], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(self::CTA[1], ENT_QUOTES, 'UTF-8') ?></a>
      <?php foreach (self::FOOTER_LEGAL_LINKS as [$url, $label]): ?>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="footer-copyright">&copy; <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?> TAYORI</div>
  </div>
</footer>
</body>
</html>
        <?php
    }
}
