<?php
require_once __DIR__ . '/../src/Layout.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

// APP_BASE_URLをEnvから読み込む(未ロードだとLayoutのcanonical/OGP用URLが相対パスになってしまうため)
Env::load(__DIR__ . '/../../../.env');

Layout::renderHeader(
    'about',
    'TAYORIについて',
    true,
    'TAYORIがどんな想いで生まれ、何ができるのかをご紹介します。日々の会話から予定や体調の変化に気づき、必要な時だけご家族にお伝えする、さりげない見守りの仕組みです。'
);
?>
<style>
  .hero-wrap {
    display:grid; grid-template-columns:minmax(280px, 420px) 1fr; gap:32px; align-items:center;
    width:100vw; margin-left:calc(50% - 50vw); margin-right:calc(50% - 50vw);
    padding:0 0 24px calc(50vw - 50%);
  }
  .splash-overlay {
    position:fixed; inset:0; z-index:1000;
    display:flex; align-items:center; justify-content:center;
    background:var(--bg); padding:20px; text-align:center; cursor:pointer;
    opacity:1; transition:opacity 0.6s ease;
  }
  .splash-overlay.hide { opacity:0; pointer-events:none; }
  .splash-overlay p { margin:0; display:flex; flex-direction:column; gap:12px; }
  .lead-line { display:block; opacity:0; transform:translateY(16px); transition:opacity 0.8s ease, transform 0.8s ease; }
  .lead-line.visible { opacity:1; transform:translateY(0); }
  .lead-line-1 { font-size:clamp(1.6rem, 4.4vw, 2.2rem); font-weight:600; color:var(--text); letter-spacing:0.04em; }
  .lead-line-2 { font-size:clamp(1.9rem, 5.2vw, 2.6rem); font-weight:bold; color:var(--brand-dark); letter-spacing:0.02em; transition:opacity 1.1s ease, transform 1.1s ease; }
  @media (prefers-reduced-motion: reduce) {
    .splash-overlay { display:none; }
  }

  .hero-text { align-self:center; }
  .hero-text h1 { font-size:clamp(1.7rem, 4.4vw, 2.3rem); line-height:1.5; margin:0 0 18px; letter-spacing:0.01em; }
  .hero-text h1 .hl { color:var(--brand-dark); }
  .hero-text p { font-size:1.02rem; color:var(--text-muted); line-height:1.9; max-width:480px; }
  .hero-art { display:flex; justify-content:flex-end; align-self:center; }
  .hero-art .frame { width:100%; aspect-ratio:3/2; border-radius:var(--radius-lg) 0 0 var(--radius-lg); overflow:hidden; }
  .hero-art img { width:100%; height:100%; object-fit:cover; object-position:35% 30%; display:block; }
  @media (max-width: 720px) {
    .hero-wrap { grid-template-columns:1fr; text-align:center; width:auto; margin-left:0; margin-right:0; margin-top:0; padding:8px 20px 24px; }
    .hero-text { padding-top:0; }
    .hero-text p { margin:0 auto; }
    .hero-art { max-width:100%; justify-content:center; align-self:auto; }
    .hero-art .frame { height:auto; aspect-ratio:3/2; border-radius:var(--radius-lg); }
  }

  section { margin:88px 0; }
  .section-head { text-align:center; max-width:640px; margin:0 auto 36px; }
  .section-head h2 { font-size:clamp(1.4rem, 3vw, 1.8rem); margin:0 0 10px; }
  .section-head p { color:var(--text-muted); line-height:1.8; margin:0; font-size:0.98rem; }
  .section-head .eyebrow {
    display:flex; align-items:center; justify-content:center; gap:12px;
    margin:0 0 6px; font-weight:bold; font-size:0.95rem; letter-spacing:0.14em;
    color:var(--accent);
  }
  .section-head .eyebrow::before, .section-head .eyebrow::after {
    content:''; width:28px; height:1px; background:var(--accent); opacity:0.45;
  }

  .about-wrap {
    display:grid; grid-template-columns:1fr minmax(280px, 420px); gap:32px; align-items:center;
    width:100vw; margin-left:calc(50% - 50vw); margin-right:calc(50% - 50vw);
    padding:0 calc(50vw - 50%) 0 0;
  }
  .about-text h2 { font-size:clamp(1.4rem, 3vw, 1.8rem); margin:0 0 14px; }
  .about-text p { color:var(--text-muted); line-height:1.9; margin:0 0 16px; font-size:1rem; }
  .about-text p:last-child { margin-bottom:0; }
  .about-art { display:flex; justify-content:flex-start; }
  .about-art .frame { width:100%; aspect-ratio:3/2; border-radius:0 var(--radius-lg) var(--radius-lg) 0; overflow:hidden; }
  .about-art img { width:100%; height:100%; object-fit:cover; object-position:60% 40%; display:block; }
  @media (max-width: 720px) {
    .about-wrap { grid-template-columns:1fr; text-align:center; width:auto; margin-left:0; margin-right:0; padding:0 20px; }
    .about-art { max-width:100%; justify-content:center; }
    .about-art .frame { border-radius:var(--radius-lg); }
  }

  .bento {
    display:grid; gap:18px; grid-template-columns:repeat(3, 1fr); grid-auto-rows:minmax(150px, auto);
    grid-template-areas:
      "hero b c"
      "d    e g"
      "h    i j";
  }
  .bento-card {
    position:relative; overflow:hidden; border-radius:26px; padding:26px 24px;
    display:flex; flex-direction:column; justify-content:flex-end;
    transition:transform 0.25s ease, box-shadow 0.25s ease;
  }
  .bento-card:hover { transform:translateY(-5px); box-shadow:0 18px 40px rgba(61,58,53,0.16); }
  .bento-card > * { position:relative; z-index:1; }

  /* 巨大化・低opacityの装飾アイコン。本文は下寄せ(justify-content:flex-end)なので、
     空いている上部の角に、はみ出させずそのまま収める */
  .bento-card .icon-ghost {
    position:absolute; z-index:0; pointer-events:none; top:14px; right:14px;
    opacity:0.12; transform:rotate(-10deg);
    transition:transform 0.5s ease, opacity 0.4s ease;
  }
  .bento-card .icon-ghost svg { width:100%; height:100%; display:block; }
  .bento-card:hover .icon-ghost { transform:rotate(-3deg) scale(1.05); opacity:0.18; }
  .bento-card .icon-ghost { width:118px; height:118px; }

  .bento-card .icon {
    width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    margin-bottom:16px; flex-shrink:0; box-shadow:0 6px 16px rgba(61,58,53,0.12);
    transform:rotate(-8deg); transition:transform 0.3s ease;
  }
  .bento-card:hover .icon { transform:rotate(0deg) scale(1.08); }
  .bento-card .icon svg { width:23px; height:23px; }
  .bento-card strong { display:block; margin-bottom:8px; font-size:1.02rem; }
  .bento-card p { margin:0; font-size:0.9rem; line-height:1.75; }
  .bento-card .card-note { margin-top:8px; font-size:0.78rem; opacity:0.75; line-height:1.6; }

  .bento-card.surface { background:var(--surface); box-shadow:0 2px 16px rgba(61,58,53,0.05); }
  .bento-card.surface p { color:var(--text-muted); }
  .bento-card.surface .icon { background:var(--card-mint); color:var(--brand); }
  .bento-card.surface .icon-ghost { color:var(--brand); }

  .bento-card.mint { background:var(--card-mint); }
  .bento-card.mint p { color:#3b5a42; }
  .bento-card.mint .icon { background:#fff; color:var(--brand-dark); }
  .bento-card.mint .icon-ghost { color:var(--brand-dark); }

  .bento-card.peach { background:var(--card-peach); }
  .bento-card.peach p { color:#6b4a2c; }
  .bento-card.peach .icon { background:#fff; color:var(--accent); }
  .bento-card.peach .icon-ghost { color:#8a5a2e; }

  .bento-card.hero {
    grid-area:hero; background:linear-gradient(155deg, var(--brand-dark) 0%, #142b1c 100%); color:#fff;
  }
  .bento-card.hero p { color:#d9e6d4; }
  .bento-card.hero .icon { background:rgba(255,255,255,0.14); color:#fff; }
  .bento-card.hero .icon-ghost { color:#fff; opacity:0.1; }
  .bento-card.hero:hover .icon-ghost { opacity:0.13; }

  .bento-b { grid-area:b; }
  .bento-c { grid-area:c; }
  .bento-d { grid-area:d; }
  .bento-e { grid-area:e; }
  .bento-g { grid-area:g; }
  .bento-h { grid-area:h; }
  .bento-i { grid-area:i; }
  .bento-j { grid-area:j; }

  @media (max-width: 860px) {
    .bento { grid-template-columns:repeat(2, 1fr); grid-template-areas:"hero b" "c d" "e g" "h i" "j ."; }
  }
  @media (max-width: 560px) {
    .bento { grid-template-columns:1fr; grid-template-areas:"hero" "b" "c" "d" "e" "g" "h" "i" "j"; }
  }

  .risk-band { background:var(--surface); border-radius:32px; padding:40px 32px; box-shadow:0 2px 16px rgba(61,58,53,0.05); }
  .risk-groups { display:grid; grid-template-columns:repeat(3, 1fr); gap:18px; }
  .risk-group { border-radius:24px; padding:22px 18px; }
  .risk-group.level-high { background:#FBEAE6; }
  .risk-group.level-medium { background:var(--card-peach); }
  .risk-group.level-low { background:var(--card-mint); }
  .risk-group-head { display:flex; align-items:center; gap:10px; }
  .risk-group-level { display:inline-flex; align-items:center; justify-content:center; font-size:0.78rem; font-weight:bold; padding:4px 14px; border-radius:var(--radius-pill); background:rgba(255,255,255,0.7); white-space:nowrap; }
  .risk-group.level-high .risk-group-level { color:#a12a1f; }
  .risk-group.level-medium .risk-group-level { color:#a06322; }
  .risk-group.level-low .risk-group-level { color:var(--brand-dark); }
  .risk-group-action { font-size:0.8rem; font-weight:bold; }
  .risk-group.level-high .risk-group-action { color:#a12a1f; }
  .risk-group.level-medium .risk-group-action { color:#a06322; }
  .risk-group.level-low .risk-group-action { color:var(--brand-dark); }
  .risk-chips { display:flex; flex-direction:column; gap:8px; margin-top:14px; }
  .risk-chip { display:flex; align-items:flex-start; gap:10px; background:rgba(255,255,255,0.6); border-radius:14px; padding:10px 12px; }
  .risk-chip .chip-icon { width:30px; height:30px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 2px 8px rgba(61,58,53,0.08); }
  .risk-chip .chip-icon svg { width:16px; height:16px; }
  .risk-group.level-high .chip-icon svg { color:#a12a1f; }
  .risk-group.level-medium .chip-icon svg { color:#a06322; }
  .risk-group.level-low .chip-icon svg { color:var(--brand-dark); }
  .risk-chip .chip-text strong { display:block; font-size:0.88rem; margin-bottom:2px; }
  .risk-chip .chip-text span { color:var(--text-muted); font-size:0.76rem; line-height:1.55; }
  @media (max-width: 860px) {
    .risk-groups { grid-template-columns:1fr; }
  }
  .risk-note { color:var(--text-muted); font-size:0.85rem; margin-top:24px; text-align:center; line-height:1.8; }

  .principles { display:grid; gap:18px; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); }
  .principle { background:var(--surface); border-radius:var(--radius-lg); padding:26px 24px; box-shadow:0 2px 16px rgba(61,58,53,0.05); }
  .principle strong { display:block; margin-bottom:10px; font-size:1.02rem; }
  .principle p { margin:0; color:var(--text-muted); font-size:0.92rem; line-height:1.8; }
  /* 「ご家族にとっての安心」セクション: 画像+文章のabout-wrapに続けて要点カード(.principles)を並べるため、
     同じ<section>内でのすき間だけ追加する */
  .about-wrap + .principles { margin-top:40px; }

  .note { background:var(--card-peach); border-radius:var(--radius-lg); padding:24px 28px; font-size:0.92rem; color:var(--text-muted); line-height:1.85; max-width:760px; margin:0 auto; }

  .org-note { text-align:center; font-size:0.9rem; color:var(--text-muted); line-height:1.9; }
  .org-note a { color:var(--brand); font-weight:bold; }

  .cta { display:inline-flex; align-items:center; gap:10px; padding:16px 32px 16px 36px; background:var(--brand); color:#fff; text-decoration:none; border-radius:var(--radius-pill); font-weight:bold; font-size:1.05rem; transition:background 0.15s, transform 0.15s; }
  .cta .icon { width:1.35em; height:1.35em; }
  .cta:hover { background:var(--brand-dark); transform:translateY(-1px); }
  .cta.on-dark { background:#fff; color:var(--brand-dark); }
  .cta.on-dark:hover { background:#f0efe9; }

  .closing-band {
    width:100vw; margin-left:calc(50% - 50vw); margin-right:calc(50% - 50vw);
    background:var(--brand-dark); color:#fff; text-align:center; padding:72px 20px;
  }
  .closing-band h2 { font-size:clamp(1.4rem, 3vw, 1.9rem); margin:0 0 14px; }
  .closing-band p { color:#dfe6da; max-width:480px; margin:0 auto 28px; line-height:1.8; }
</style>

<div class="splash-overlay" id="splashOverlay">
  <p>
    <span class="lead-line lead-line-1">ただ、話しかける</span>
    <span class="lead-line lead-line-2">それだけ</span>
  </p>
</div>
<noscript><style>.splash-overlay { display:none !important; }</style></noscript>
<script>
(function () {
  var overlay = document.getElementById('splashOverlay');
  if (!overlay) {
    return;
  }
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion) {
    overlay.style.display = 'none';
    return;
  }

  var lines = overlay.querySelectorAll('.lead-line');
  var dismissed = false;
  document.body.style.overflow = 'hidden';

  function dismiss() {
    if (dismissed) {
      return;
    }
    dismissed = true;
    overlay.classList.add('hide');
    document.body.style.overflow = '';
    setTimeout(function () { overlay.style.display = 'none'; }, 600);
  }

  var lineDelays = [150, 1500];
  lines.forEach(function (el, i) {
    setTimeout(function () { el.classList.add('visible'); }, lineDelays[i] || 150);
  });

  overlay.addEventListener('click', dismiss);
  setTimeout(dismiss, 4000);
})();
</script>

<div class="hero-wrap">
  <div class="hero-text">
    <h1>離れて暮らす家族の<br><span style="white-space:nowrap">「今日も元気かな？」に、</span><br><span class="hl">日々の会話で応えたい。</span></h1>
    <p>
      電話をかけるほどでもない、小さな出来事。それを気軽に話せる相手が身近にいないことがあります。
      TAYORIは、LINEで自然に交わす何気ない会話を通じて、ご本人には話し相手を、
      離れて暮らすご家族には小さな安心をお届けするサービスです。
    </p>
  </div>
  <div class="hero-art">
    <div class="frame">
      <img src="/assets/about-photo-01.jpg" alt="自宅でTAYORIとのやり取りを楽しむ利用者の写真" width="1408" height="768">
    </div>
  </div>
</div>

<section>
  <div class="about-wrap">
    <div class="about-art">
      <div class="frame">
        <img src="/assets/about-photo-02.jpg" alt="お薬の時間をTAYORIに知らせてもらう利用者の写真" width="1408" height="768">
      </div>
    </div>
    <div class="about-text">
      <h2>なぜTAYORIを作ったのか</h2>
      <p>
        高齢のご家族が一人で暮らしていると、「今日は誰とも話さなかった」という日が意外と多くあります。
        一方で、離れて暮らすご家族は、電話やメッセージだけでは日々の小さな変化に気づきにくいものです。
      </p>
      <p>
        毎日電話をかけるのは、かける方にもかけられる方にも、少し負担になることがあります。
        「わざわざ電話するほどでもないけれど、誰かに話したい」——そんな何気ない瞬間を受け止める相手が、
        すぐそばにいたら。TAYORIは、その間にそっと立つ話し相手として生まれました。
      </p>
      <p>
        特別な操作を覚える必要はありません。普段お使いのLINEで話しかけるだけ。それだけで、
        日々の会話が少しずつ積み重なっていきます。
      </p>
    </div>
  </div>
</section>

<section>
  <div class="section-head">
    <h2>TAYORIができること</h2>
    <p>会話を重ねるほどに、ご本人のことを少しずつ知っていきます。</p>
  </div>
  <div class="bento">
    <div class="bento-card hero">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <strong>日々の会話相手</strong>
      <p>「今日は暑いね」のひと言にも、ちゃんと応えてくれる相手がいる。話しかけるたびに、少し嬉しい気持ちになれる——それが、TAYORIとの毎日です。</p>
    </div>
    <div class="bento-card mint bento-b">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="4.5" y="3" width="15" height="18" rx="2.5"/><path d="M8.5 8h7M8.5 12h7M8.5 16h4.5"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="3" width="15" height="18" rx="2.5"/><path d="M8.5 8h7M8.5 12h7M8.5 16h4.5"/></svg>
      </div>
      <strong>人物・予定を覚えて会話に活かす</strong>
      <p>前に話した孫の名前も、来週の病院の予定も、毎週の習慣も、ちゃんと覚えている。「あれ、いつだっけ」と聞けば、TAYORIが答えてくれます。</p>
    </div>
    <div class="bento-card surface bento-c">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/><circle cx="12" cy="12" r="4.5"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/><circle cx="12" cy="12" r="4.5"/></svg>
      </div>
      <strong>天気に合わせた会話</strong>
      <p>「今日は雨だから、傘を忘れずに」「暖かくなってきたね」。そんな季節や天気に合わせた気づかいが、いつもの会話にそっと混ざります。</p>
    </div>
    <div class="bento-card peach bento-d">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M12 21s7.5-3.5 7.5-9.5V6l-7.5-3-7.5 3v5.5C4.5 17.5 12 21 12 21z"/><path d="M12 8.5v4.3"/><circle cx="12" cy="15.6" r="0.6" fill="currentColor" stroke="none"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7.5-3.5 7.5-9.5V6l-7.5-3-7.5 3v5.5C4.5 17.5 12 21 12 21z"/><path d="M12 8.5v4.3"/><circle cx="12" cy="15.6" r="0.9" fill="currentColor" stroke="none"/></svg>
      </div>
      <strong>不審な兆候の検知</strong>
      <p>いつもの会話の中で、詐欺のサインや体調の変化にもそっと気づく。何も起きなくても、それだけで少し安心できます。</p>
      <p class="card-note">※ この検知結果のご家族への通知は、寄り添いスタンダード以上でご利用いただけます。</p>
    </div>
    <div class="bento-card surface bento-e">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M4 6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-4 3.5V6z"/><path d="M15.3 4c2 .8 3.3 2.6 3.6 4.7"/><path d="M15.6 6.5c1 .5 1.8 1.4 2 2.6"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-4 3.5V6z"/><path d="M15.3 4c2 .8 3.3 2.6 3.6 4.7"/><path d="M15.6 6.5c1 .5 1.8 1.4 2 2.6"/></svg>
      </div>
      <strong>TAYORIから話しかけてくれる</strong>
      <p>ふとした瞬間、TAYORIの方から話しかけることもあります。もしその日一度も会話がなければ、朝と夜に必ず一度、それとなく様子をうかがいます。</p>
    </div>
    <div class="bento-card mint bento-g">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
      </div>
      <strong>伝言や、気にかけてほしいテーマも</strong>
      <p>「今度顔を見せに行くね」のような伝言や、「水分補給を気にかけて」のようなテーマも、ご家族から言われたこととは明かさず、TAYORIがさりげなく会話に混ぜて伝えます。</p>
      <p class="card-note">※ 寄り添いスタンダード以上でご利用いただけます。</p>
    </div>
    <div class="bento-card surface bento-h">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M4 8a2 2 0 0 1 2-2h1.5l1-1.6h7l1 1.6H18a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.3"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8a2 2 0 0 1 2-2h1.5l1-1.6h7l1 1.6H18a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.3"/></svg>
      </div>
      <strong>写真や書類を見せて会話</strong>
      <p>お薬の説明書やお手紙の写真を送るだけで、内容を読み取って一緒に確認できます。難しい操作は必要ありません。</p>
      <p class="card-note">※ 1日3回まで。寄り添いスタンダード以上は1日5回・高画質での原稿読み取りに対応し、保存した内容はマイページでいつでも見返せます。</p>
    </div>
    <div class="bento-card peach bento-i">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><circle cx="12" cy="12" r="9"/><path d="M8.5 10h.01M15.5 10h.01"/><path d="M8.2 14.5c1 1.7 2.4 2.6 3.8 2.6s2.8-.9 3.8-2.6"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 10h.01M15.5 10h.01"/><path d="M8.2 14.5c1 1.7 2.4 2.6 3.8 2.6s2.8-.9 3.8-2.6"/></svg>
      </div>
      <strong>絵文字やスタンプでのやり取りも</strong>
      <p>文字を打つのが億劫な時は、LINEスタンプや絵文字だけでも気持ちが伝わります。TAYORIも気持ちが動いた時には、スタンプで応えることがあります。</p>
    </div>
    <div class="bento-card mint bento-j">
      <span class="icon-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
      <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      </div>
      <strong>ご家族にも、無理なく伝わる</strong>
      <p>週に一度、「今週の様子」をそっとダイジェストでお届けします(全プラン共通)。「TAYORIサポート」から届くのはその程度で、日々の会話をいちいちのぞくことはありません。</p>
      <p class="card-note">※ 気になる兆候があった時のリアルタイムな見守りアラートは、寄り添いスタンダード以上でご利用いただけます。</p>
    </div>
  </div>
</section>

<section>
  <div class="section-head">
    <span class="eyebrow">寄り添いスタンダードプラン以上なら</span>
    <h2>見守りの仕組みを、もう少し詳しく</h2>
    <p>会話の中の特定のキーワードから、気になる兆候を自動で検知しています。リスクの高さに応じて対応を分けています。</p>
  </div>
  <div class="risk-band">
    <div class="risk-groups">
      <div class="risk-group level-high">
        <div class="risk-group-head">
          <span class="risk-group-level">高</span>
        </div>
        <p class="risk-group-action">記録+ご家族へ通知</p>
        <div class="risk-chips">
          <div class="risk-chip">
            <span class="chip-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 7.5 12 12.5 15.5 7.5M12 12.5V17M9.3 14.3h5.4M9.3 16.3h5.4"/></svg></span>
            <span class="chip-text"><strong>振込・送金の要求</strong><span>「振込」「口座番号」「至急送金」などの表現</span></span>
          </div>
          <div class="risk-chip">
            <span class="chip-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2.5"/><circle cx="8.5" cy="12.3" r="2"/><path d="M13 11h5M13 14.2h3.5"/></svg></span>
            <span class="chip-text"><strong>個人情報の聞き出し</strong><span>「暗証番号」「マイナンバー」「キャッシュカード」などの表現</span></span>
          </div>
          <div class="risk-chip">
            <span class="chip-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13.5" r="8"/><path d="M12 9.5v4l3 2"/><path d="M9 3.5h6M19 5.5l1.5-1.5M5 5.5 3.5 4"/></svg></span>
            <span class="chip-text"><strong>緊急性を煽る表現</strong><span>「今すぐ」「誰にも言わないで」などの判断を急がせる言い回し</span></span>
          </div>
          <div class="risk-chip">
            <span class="chip-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 12.5h4l2-6.5 4 13 2-9.5 2 5.5h3.5"/></svg></span>
            <span class="chip-text"><strong>体調の急変・事故</strong><span>「倒れた」「息ができない」「転んだ」「火事」などの緊急性の高い訴え</span></span>
          </div>
        </div>
      </div>
      <div class="risk-group level-medium">
        <div class="risk-group-head">
          <span class="risk-group-level">中</span>
        </div>
        <p class="risk-group-action">記録+ご家族へ通知</p>
        <div class="risk-chips">
          <div class="risk-chip">
            <span class="chip-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.3"/><path d="M5.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M4.5 4.5l15 15"/></svg></span>
            <span class="chip-text"><strong>相談を止めさせる誘導</strong><span>「家族には内緒」「一人で対応して」などの孤立化の兆候</span></span>
          </div>
          <div class="risk-chip">
            <span class="chip-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 9.5l2 2M10 9.5l-2 2M14 9.5l2 2M16 9.5l-2 2"/><path d="M8.5 16c1-1 2.2-1.5 3.5-1.5s2.5.5 3.5 1.5"/></svg></span>
            <span class="chip-text"><strong>体調不良・トラブルの訴え</strong><span>「しんどい」「めまい」「怖い人」など、様子を見るべき発言</span></span>
          </div>
        </div>
      </div>
      <div class="risk-group level-low">
        <div class="risk-group-head">
          <span class="risk-group-level">低</span>
        </div>
        <p class="risk-group-action">記録のみ</p>
        <div class="risk-chips">
          <div class="risk-chip">
            <span class="chip-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="8" r="3.3"/><path d="M4.5 19.5c0-3.2 2.6-5.3 5.7-5.3"/><circle cx="17.5" cy="15.5" r="4"/><path d="M16.3 14.3a1.4 1.4 0 1 1 2 1.3c-.6.4-.9.7-.9 1.4M17.4 18.3h.01"/></svg></span>
            <span class="chip-text"><strong>不審な人物の初出</strong><span>「知らない番号」「初めての人」など、まだ様子見でよい発言</span></span>
          </div>
        </div>
      </div>
    </div>
    <p class="risk-note">
      通知の頻度が高くなりすぎないよう、「低」レベルはマイページの「安心レポート」への記録のみに留め、「中」以上を検知した場合にご家族へLINEでお知らせします。
      あわせて、読み取った書類や写真の内容も、マイページでいつでも確認いただけます。<br>
      ※ この機能はキーワードに基づく検知であり、すべての詐欺やトラブルを見逃さないことを保証するものではありません。心配な際は、直接ご本人にご連絡ください。
    </p>
  </div>
</section>

<section>
  <div class="section-head">
    <h2>ご家族にとっての安心</h2>
    <p>日々の会話をのぞくことはありません。本当に必要な時だけ、そっとご家族に届きます。</p>
  </div>
  <div class="about-wrap">
    <div class="about-art">
      <div class="frame">
        <img src="/assets/about-photo-03.jpg" alt="TAYORIサポートからの安否確認の連絡にほっとするご家族の写真" width="1536" height="1024">
      </div>
    </div>
    <div class="about-text">
      <h2>「連絡が取れました」その一言まで届く</h2>
      <p>
        普段のやり取りをそのままお伝えすることはありません。ただ、しばらくTAYORIとの会話が確認できない時は、
        直接ご本人にご連絡いただくか様子を見ていただきたい旨をご家族にお知らせします。
        そして実際に連絡が取れた際も、「ご心配をおかけしました」ときちんとお伝えします。
        心配なまま置き去りにされることがないように、という思いからの仕組みです。
      </p>
    </div>
  </div>
  <div class="principles">
    <div class="principle">
      <strong>週次ダイジェスト</strong>
      <p>週に一度、「今週の様子」をLINEでお届けします。すべてのプランでご利用いただけます。</p>
    </div>
    <div class="principle">
      <strong>見守りアラート</strong>
      <p>詐欺の兆候や体調の変化など、気になる会話があった時にお知らせします(寄り添いスタンダード以上)。</p>
    </div>
    <div class="principle">
      <strong>安否確認</strong>
      <p>しばらく応答が無い場合にご本人へ確認し、連絡が取れたことも含めてご家族にお伝えします(寄り添いスタンダード以上)。</p>
    </div>
    <div class="principle">
      <strong>伝言・気にかけてほしいテーマ</strong>
      <p>「今度顔を見せに行くね」のような伝言や、気にかけてほしいテーマも、TAYORIが自然な会話の中で伝えます(寄り添いスタンダード以上)。</p>
    </div>
  </div>
</section>

<section>
  <div class="section-head">
    <h2>大切にしていること</h2>
  </div>
  <div class="principles">
    <div class="principle">
      <strong>新しいアプリを覚えなくていい</strong>
      <p>ご高齢の方にとって、新しいアプリの操作を覚えるのはハードルになりがちです。すでに使い慣れたLINEの中で完結するようにしています。</p>
    </div>
    <div class="principle">
      <strong>話すほどに、知っていく</strong>
      <p>一度きりの応答で終わらせず、会話を重ねるたびにご本人のことを少しずつ知っていく設計にしています。</p>
    </div>
    <div class="principle">
      <strong>さりげない見守り</strong>
      <p>監視されている印象を与えないよう、通知はご家族にとって本当に必要な情報だけに絞っています。</p>
    </div>
    <div class="principle">
      <strong>信頼をお金に変えない</strong>
      <p>ご本人との会話は、あくまでご本人のためのものです。会話の内容を広告やマーケティングの目的で利用することはありません。</p>
    </div>
  </div>
</section>

<section>
  <div class="note">
    TAYORIはまだ立ち上げたばかりのサービスです。できること・できないことを正直にお伝えしながら、
    利用してくださる方の声をもとに、少しずつ育てていきたいと考えています。
  </div>
</section>

<section>
  <p class="org-note">
    運営会社・お問い合わせ先などの詳細は<a href="/tokushoho/">特定商取引法に基づく表記</a>をご覧ください。
  </p>
</section>

<div class="closing-band">
  <h2>離れていても、そばにいるように。</h2>
  <p>まずは10日間、無料でお試しください。</p>
  <a class="cta on-dark" href="/apply/">無料でお試しを申し込む<?= Layout::icon('play') ?></a>
</div>

<?php Layout::renderFooter(); ?>
