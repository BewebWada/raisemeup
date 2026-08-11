<?php
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PlanRepository.php';
require_once __DIR__ . '/../src/Layout.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Database.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

Env::load(__DIR__ . '/../../../.env');

$dbConfig = require __DIR__ . '/../db/config.php';
$pdo = Database::connect($dbConfig);
$plans = (new PlanRepository($pdo))->getActivePlans();

// 料金表の機能比較(codeベース)。実際のプラン制限はConversationHandler::RISK_NOTIFY_PLAN_CODES /
// send_proactive_messages.phpのWELLNESS_NOTIFY_PLAN_CODES(family_watch, premium_medical)と対応
const PLAN_FEATURES = [
    ['label' => '毎日のTAYORIとの会話(LINE)', 'plans' => ['standard', 'family_watch', 'premium_medical']],
    ['label' => 'ご家族への週次ダイジェスト', 'plans' => ['standard', 'family_watch', 'premium_medical']],
    ['label' => '見守りアラート(気になる会話の検知)', 'plans' => ['family_watch', 'premium_medical']],
    ['label' => '安否確認(応答なし検知・緊急時のご家族通知)', 'plans' => ['family_watch', 'premium_medical']],
    ['label' => 'ご家族からの伝言・気にかけてほしいテーマ設定', 'plans' => ['family_watch', 'premium_medical']],
    ['label' => 'お薬・お手紙の高画質読み取り・保存・照合', 'plans' => ['family_watch', 'premium_medical']],
    ['label' => '医療・介護関係者との連携', 'plans' => ['premium_medical']],
];

// カードの見た目上のアイコン。プランの性格を一目で伝えるための添え物なので、機能フラグとは独立させている
const PLAN_ICONS = [
    'standard' => 'chat',
    'family_watch' => 'shield',
    'premium_medical' => 'heart',
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

Layout::renderHeader(
    'top',
    '離れて暮らす家族に安心を届けるLINE見守りAI',
    true,
    'LINEで毎日、ちょっとした会話を。TAYORIは離れて暮らすご家族の話し相手になり、気になる変化があればそっとお知らせするAI見守りサービスです。10日間無料でお試しいただけます。'
);
$orgBaseUrl = rtrim((string) Config::get('APP_BASE_URL', ''), '/');
?>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'TAYORI',
    'url' => $orgBaseUrl . '/',
    'logo' => $orgBaseUrl . '/assets/og-image.png',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'TAYORI',
    'url' => $orgBaseUrl . '/',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<style>
  .splash-overlay {
    position:fixed; inset:0; z-index:1000;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    background:var(--bg); padding:20px; text-align:center; cursor:pointer;
    opacity:1; transition:opacity 0.6s ease;
  }
  .splash-overlay.hide { opacity:0; pointer-events:none; }
  .splash-overlay p { margin:0; display:flex; flex-direction:column; gap:12px; }
  .lead-line { display:block; opacity:0; transform:translateY(16px); transition:opacity 0.8s ease, transform 0.8s ease; }
  .lead-line.visible { opacity:1; transform:translateY(0); }
  .lead-line-1 { font-size:clamp(1.9rem, 5.2vw, 2.6rem); font-weight:bold; color:var(--brand-dark); letter-spacing:0.02em; }
  .lead-line-2 { font-size:clamp(1.9rem, 5.2vw, 2.6rem); font-weight:bold; color:var(--brand-dark); letter-spacing:0.02em; transition:opacity 1.1s ease, transform 1.1s ease; }
  .splash-mark-wrap { position:relative; width:4.6rem; height:4.6rem; margin:0 auto 22px; }
  .splash-glow {
    position:absolute; z-index:0; top:50%; left:50%; width:220px; height:220px;
    transform:translate(-50%, -50%);
    background:radial-gradient(circle, rgba(75,139,90,0.35) 0%, rgba(75,139,90,0.16) 40%, rgba(75,139,90,0) 62%);
    pointer-events:none; animation:glow-heartbeat 2.6s ease-in-out infinite;
  }
  .splash-mark { position:relative; z-index:1; width:100%; height:100%; display:block; }
  @media (prefers-reduced-motion: reduce) {
    .splash-overlay { display:none; }
  }

  .hero-wrap {
    display:grid; grid-template-columns:minmax(280px, 420px) 1fr; gap:32px; align-items:center;
    width:100vw; margin-left:calc(50% - 50vw); margin-right:calc(50% - 50vw);
    margin-top:-156px;
    padding:0 0 24px calc(50vw - 50%);
  }
  .hero-text { align-self:start; padding-top:200px; }
  .hero-text h1 { font-size:clamp(1.9rem, 5vw, 2.6rem); line-height:1.4; margin:0 0 18px; letter-spacing:0.01em; white-space:nowrap; }
  .hero-text h1 .hl { color:var(--brand-dark); }
  .hero-text p { font-size:1.05rem; color:var(--text-muted); line-height:1.85; max-width:480px; }
  .hero-art { display:flex; justify-content:flex-end; padding-right:0; align-self:stretch; }
  .hero-art .frame { width:100%; height:calc(100% + 72px); border-radius:0 0 0 var(--radius-lg); overflow:hidden; }
  .hero-art img { width:100%; height:100%; object-fit:cover; object-position:42% 30%; display:block; }
  @media (max-width: 720px) {
    .hero-wrap { grid-template-columns:1fr; text-align:center; width:auto; margin-left:0; margin-right:0; margin-top:0; padding:8px 20px 24px; }
    .hero-text { padding-top:0; }
    .hero-text h1 { font-size:clamp(1.6rem, 7vw, 2.1rem); }
    .hero-text p { margin:0 auto; }
    .hero-art { max-width:100%; justify-content:center; align-self:auto; }
    .hero-art .frame { height:auto; aspect-ratio:3/2; border-radius:var(--radius-lg); }
  }

  .cta { display:inline-flex; align-items:center; gap:10px; margin-top:28px; padding:16px 32px 16px 36px; background:var(--brand); color:#fff; text-decoration:none; border-radius:var(--radius-pill); font-weight:bold; font-size:1.05rem; transition:background 0.15s, transform 0.15s; }
  .cta .icon { width:1.35em; height:1.35em; }
  .cta:hover { background:var(--brand-dark); transform:translateY(-1px); }
  .cta.on-dark { background:#fff; color:var(--brand-dark); }
  .cta.on-dark:hover { background:#f0efe9; }

  section { margin:88px 0; }
  .section-head { text-align:center; max-width:600px; margin:0 auto 36px; }
  .section-head h2 { font-size:clamp(1.4rem, 3vw, 1.8rem); margin:0 0 10px; }
  .section-head p { color:var(--text-muted); line-height:1.8; margin:0; font-size:0.98rem; }
  .section-head.emblem { position:relative; padding:36px 0 4px; z-index:2; }
  .emblem-mark {
    position:absolute; z-index:0; top:50%; left:50%; transform:translate(-50%, -54%);
    width:15rem; height:15rem; opacity:0.16; pointer-events:none; user-select:none;
  }
  .section-head.emblem h2 {
    position:relative; z-index:1; font-size:clamp(2rem, 5vw, 2.7rem); font-weight:700; letter-spacing:0.02em;
  }
  @media (max-width: 720px) {
    .emblem-mark { width:11rem; height:11rem; }
  }
  @media (max-width: 560px) {
    .emblem-mark { width:9rem; height:9rem; }
  }

  .about-wrap {
    display:grid; grid-template-columns:1fr minmax(280px, 420px); gap:32px; align-items:center;
    width:100vw; margin-left:calc(50% - 50vw); margin-right:calc(50% - 50vw);
    padding:0 calc(50vw - 50%) 0 0;
  }
  .about-text h2 { font-size:clamp(1.4rem, 3vw, 1.8rem); margin:0 0 14px; }
  .about-text p { color:var(--text-muted); line-height:1.9; margin:0; font-size:1rem; }
  .about-teaser-section { margin-bottom:140px; }
  .about-teaser-band { background:var(--card-mint); border-radius:32px; padding:52px 32px; text-align:center; }
  .about-teaser-hook { color:var(--brand-dark); font-size:clamp(1.2rem, 2.6vw, 1.5rem); font-weight:700; margin:0 0 22px; }
  .about-link {
    display:inline-flex; align-items:center; gap:8px;
    background:var(--surface); color:var(--brand-dark); font-weight:bold; font-size:0.98rem;
    padding:14px 28px; border-radius:var(--radius-pill); text-decoration:none;
    box-shadow:0 4px 16px rgba(61,58,53,0.08);
    transition:background 0.15s, transform 0.15s, box-shadow 0.15s;
  }
  .about-link:hover { background:#fff; transform:translateY(-2px); box-shadow:0 8px 22px rgba(61,58,53,0.12); }
  .about-link .icon { width:1.1em; height:1.1em; transition:transform 0.15s; }
  .about-link:hover .icon { transform:translateX(3px); }
  .about-art { display:flex; justify-content:flex-start; }
  .about-art .frame { width:100%; aspect-ratio:3/2; border-radius:0 var(--radius-lg) var(--radius-lg) 0; overflow:hidden; }
  .about-art img { width:100%; height:100%; object-fit:cover; object-position:60% 40%; display:block; }
  .about-wrap-reverse { grid-template-columns:minmax(280px, 420px) 1fr; padding:0 0 0 calc(50vw - 50%); }
  .about-wrap-reverse .about-art { justify-content:flex-end; }
  .about-wrap-reverse .about-art .frame { border-radius:var(--radius-lg) 0 0 var(--radius-lg); }
  @media (max-width: 720px) {
    .about-wrap { grid-template-columns:1fr; text-align:center; width:auto; margin-left:0; margin-right:0; padding:0 20px; }
    .about-wrap-reverse { padding:0 20px; }
    .about-art { max-width:100%; justify-content:center; }
    .about-art .frame { border-radius:var(--radius-lg); }
  }

  .steps { display:flex; flex-direction:column; gap:16px; max-width:640px; margin:0 auto; }
  .step {
    display:flex; align-items:center; gap:22px;
    background:var(--surface); border-radius:var(--radius-lg); padding:22px 26px;
    box-shadow:0 2px 16px rgba(61,58,53,0.05);
    opacity:0; transform:translateY(18px); transition:opacity 0.5s ease, transform 0.5s ease;
  }
  .step.visible { opacity:1; transform:translateY(0); }
  @media (prefers-reduced-motion: reduce) {
    .step { transition:none; }
  }
  .step-icon {
    position:relative; flex-shrink:0; width:58px; height:58px; border-radius:50%;
    background:var(--card-mint); display:flex; align-items:center; justify-content:center;
  }
  .step-icon svg { width:26px; height:26px; color:var(--brand-dark); }
  .step-num {
    position:absolute; top:-6px; left:-6px; width:28px; height:28px; border-radius:50%;
    background:var(--brand); color:#fff; font-size:0.88rem; font-weight:bold;
    display:flex; align-items:center; justify-content:center; box-shadow:0 2px 6px rgba(75,139,90,0.35);
  }
  .step-content strong { display:block; margin-bottom:6px; font-size:1.05rem; letter-spacing:0.01em; }
  .step-content p { margin:0; color:var(--text-muted); font-size:0.92rem; line-height:1.75; }
  @media (max-width: 560px) {
    .step { padding:18px 20px; gap:16px; }
    .step-icon { width:48px; height:48px; }
    .step-icon svg { width:22px; height:22px; }
  }

  .capability-band { position:relative; padding:8px 40px 8px; text-align:center; }
  .capability-glow {
    position:absolute; z-index:0; top:50%; left:50%; width:560px; height:560px;
    transform:translate(-50%, -50%);
    background:radial-gradient(circle, rgba(251,226,196,0.55) 0%, rgba(251,226,196,0.3) 35%, rgba(251,226,196,0) 58%);
    pointer-events:none;
    animation:glow-heartbeat 2.6s ease-in-out infinite;
    transform-origin:center center;
  }
  @keyframes glow-heartbeat {
    0%, 100% { transform:translate(-50%, -50%) scale(1); opacity:0.7; }
    12% { transform:translate(-50%, -50%) scale(1.12); opacity:1; }
    24% { transform:translate(-50%, -50%) scale(1); opacity:0.7; }
    36% { transform:translate(-50%, -50%) scale(1.06); opacity:0.85; }
    56% { transform:translate(-50%, -50%) scale(1); opacity:0.7; }
  }
  .capability-lines { position:relative; z-index:1; max-width:640px; margin:0 auto; }
  .cap-line {
    font-size:clamp(1.15rem, 2.9vw, 1.5rem); line-height:2; color:#5c6f60; font-weight:500; margin:0 0 10px;
    letter-spacing:0.01em;
    opacity:0; transform:translateY(10px); transition:opacity 0.6s ease, transform 0.6s ease;
  }
  .cap-line.visible { opacity:1; transform:translateY(0); }
  .cap-line:last-child { margin-bottom:0; }
  .cap-line .hl { color:var(--brand-dark); font-weight:bold; }
  .capability-note { max-width:600px; margin:28px auto 0; text-align:center; color:#8a9188; font-size:0.82rem; line-height:1.8; }
  @media (max-width: 560px) {
    .capability-band { padding:8px 20px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .cap-line { transition:none; }
    .capability-glow { animation:none; }
  }

  .pricing-band { background:var(--card-mint); border-radius:32px; padding:56px 32px 48px; }
  .plans { display:grid; gap:24px; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); max-width:920px; margin:0 auto; align-items:start; }
  .plan-card {
    position:relative; display:flex; flex-direction:column; height:100%;
    background:var(--surface); border-radius:var(--radius-lg); padding:30px 26px; text-align:center;
    border:2px solid transparent; box-shadow:0 2px 14px rgba(61,58,53,0.06);
  }
  .plan-card .plan-icon {
    width:52px; height:52px; margin:0 auto 14px; border-radius:50%;
    background:var(--card-mint); display:flex; align-items:center; justify-content:center;
  }
  .plan-card .plan-icon .icon { width:24px; height:24px; color:var(--brand-dark); }
  .plan-card.soon .plan-icon { background:#f3efe4; }
  .plan-card .name { font-weight:bold; margin-bottom:8px; font-size:1.25rem; min-height:2.2em; display:flex; align-items:center; justify-content:center; }
  .plan-card .price { color:var(--brand); font-size:1.7rem; font-weight:bold; }
  .plan-card .price span { font-size:0.85rem; font-weight:normal; color:var(--text-muted); }
  .plan-card .teaser { color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-top:8px; min-height:2.6em; }
  .plan-card.soon { background:transparent; border:1.5px dashed #c9c0ae; box-shadow:none; }
  .plan-card .badge-soon {
    position:absolute; top:-13px; left:50%; transform:translateX(-50%);
    display:inline-flex; align-items:center; white-space:nowrap; font-size:0.72rem; font-weight:bold; color:#fff;
    background:var(--accent); border-radius:var(--radius-pill); padding:4px 14px; box-shadow:0 3px 10px rgba(224,144,79,0.35);
  }

  .plan-card.recommended { border-color:var(--brand); box-shadow:0 12px 32px rgba(75,139,90,0.22); transform:translateY(-6px); }
  /* 絶対配置のリボンにして通常フローから外すことで、他のカードのname/price/featureの縦位置がズレないようにする */
  .plan-card .badge-recommend {
    position:absolute; top:-15px; left:50%; transform:translateX(-50%);
    display:inline-flex; align-items:center; white-space:nowrap; font-size:0.72rem; font-weight:bold; color:#fff;
    background:var(--brand); border-radius:var(--radius-pill); padding:5px 16px; box-shadow:0 3px 10px rgba(75,139,90,0.35);
  }
  @media (max-width: 720px) {
    .plan-card.recommended { transform:none; }
  }

  .plan-card .feature-list { list-style:none; margin:20px 0 24px; padding:0; text-align:left; display:flex; flex-direction:column; gap:10px; flex-grow:1; }
  .plan-card .feature-list li { display:flex; align-items:flex-start; gap:10px; font-size:0.86rem; line-height:1.5; }
  .plan-card .feature-list li .icon { width:1.15em; height:1.15em; margin-top:0.1em; flex-shrink:0; }
  .plan-card .feature-list li.has { color:var(--text); }
  .plan-card .feature-list li.has .icon { color:var(--brand); }
  .plan-card .feature-list li.no { color:var(--text-muted); opacity:0.6; }
  .plan-card .feature-list li.no .icon { color:var(--text-muted); }

  .plan-cta {
    margin-top:auto; display:block; text-align:center; text-decoration:none; font-weight:bold; font-size:0.92rem;
    padding:13px 20px; border-radius:var(--radius-pill); transition:background 0.15s, border-color 0.15s, color 0.15s;
    background:transparent; color:var(--brand-dark); border:1.5px solid var(--brand);
  }
  .plan-cta:hover { background:var(--card-mint); }
  .plan-card.recommended .plan-cta { background:var(--brand); color:#fff; border-color:var(--brand); }
  .plan-card.recommended .plan-cta:hover { background:var(--brand-dark); }

  .pricing-note { color:var(--text-muted); font-size:0.85rem; margin-top:32px; text-align:center; }

  .note { display:flex; align-items:flex-end; gap:10px; max-width:420px; margin:0 auto; }
  .note-avatar {
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
    width:34px; height:34px; border-radius:50%; background:#fff;
    box-shadow:0 2px 8px rgba(61,58,53,0.12);
  }
  .note-avatar svg { width:19px; height:19px; }
  .note-bubble { background:var(--card-mint); border-radius:18px; border-bottom-left-radius:4px; padding:14px 18px; }
  .note p { margin:0; font-size:0.86rem; color:var(--text); line-height:1.8; }

  .sim-wrap { display:grid; grid-template-columns:1fr minmax(280px, 360px); gap:40px; align-items:center; }
  .sim-text-col h2 { font-size:clamp(1.4rem, 3vw, 1.8rem); margin:0 0 14px; }
  .sim-text-col p { color:var(--text-muted); line-height:1.9; margin:0; font-size:1rem; max-width:440px; }
  /* LINEのロゴ画像は商標のため使用せず、公式ブランドカラー(#06C755)で文字色のみ寄せている */
  .line-brand { color:#06C755; font-weight:bold; }
  .sim-phone-col { display:flex; justify-content:center; }
  .sim-phone-stack { display:flex; flex-direction:column; align-items:center; gap:16px; }
  .sim-cta-badge {
    display:inline-flex; align-items:center; gap:10px; position:relative;
    background:var(--brand); color:#fff; font-weight:bold; font-size:1.08rem;
    padding:16px 30px; border-radius:var(--radius-pill);
    box-shadow:0 10px 28px rgba(75,139,90,0.4);
    animation:sim-cta-bounce 2.4s ease-in-out infinite;
  }
  .sim-cta-badge .icon { width:1.3em; height:1.3em; }
  .sim-cta-badge::after {
    content:''; position:absolute; bottom:-8px; left:50%; margin-left:-8px;
    width:16px; height:16px; background:var(--brand);
    clip-path:polygon(0 0, 100% 0, 50% 100%);
  }
  @keyframes sim-cta-bounce {
    0%, 100% { transform:translateY(0) scale(1); }
    50% { transform:translateY(-9px) scale(1.03); }
  }
  @media (prefers-reduced-motion: reduce) {
    .sim-cta-badge { animation:none; }
  }

  .sim-phone {
    width:100%; max-width:340px; aspect-ratio:9/17.5;
    display:flex; flex-direction:column;
    background:var(--surface); border-radius:36px; box-shadow:0 2px 24px rgba(61,58,53,0.1);
    padding:22px 16px 16px; overflow:hidden;
  }
  .sim-messages { flex:1 1 auto; min-height:0; overflow-y:auto; display:flex; flex-direction:column; gap:12px; padding-right:2px; }
  .sim-bubble { display:flex; align-items:flex-end; gap:8px; max-width:88%; flex-shrink:0; transition:opacity 0.45s ease, transform 0.45s ease; }
  .js .sim-bubble { opacity:0; transform:translateY(8px); }
  .sim-bubble.visible { opacity:1; transform:translateY(0); }
  .sim-ai { align-self:flex-start; }
  .sim-user { align-self:flex-end; flex-direction:row-reverse; }
  .sim-avatar { width:26px; height:26px; border-radius:50%; background:#fff; box-shadow:0 1px 4px rgba(61,58,53,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .sim-avatar svg { width:15px; height:15px; }
  .sim-text { padding:10px 14px; border-radius:18px; font-size:0.86rem; line-height:1.6; }
  .sim-ai .sim-text { background:var(--card-mint); color:var(--text); border-bottom-left-radius:4px; }
  .sim-user .sim-text { background:var(--brand); color:#fff; border-bottom-right-radius:4px; }
  .sim-divider { align-self:center; color:var(--text-muted); font-size:0.72rem; background:var(--bg); padding:3px 14px; border-radius:var(--radius-pill); flex-shrink:0; transition:opacity 0.45s ease; }
  .js .sim-divider { opacity:0; }
  .sim-divider.visible { opacity:1; }
  .sim-text.sim-typing { display:inline-flex; gap:4px; padding-top:13px; padding-bottom:13px; }
  .sim-text.sim-typing span { width:6px; height:6px; border-radius:50%; background:var(--text-muted); opacity:0.5; animation:sim-typing-blink 1.2s infinite ease-in-out; }
  .sim-text.sim-typing span:nth-child(2) { animation-delay:0.2s; }
  .sim-text.sim-typing span:nth-child(3) { animation-delay:0.4s; }
  @keyframes sim-typing-blink { 0%, 80%, 100% { opacity:0.3; } 40% { opacity:0.9; } }

  .sim-input-row { flex-shrink:0; display:flex; gap:6px; margin-top:14px; transition:opacity 0.4s ease; }
  .js .sim-input-row { opacity:0; pointer-events:none; }
  .sim-input-row.visible { opacity:1; pointer-events:auto; }
  .sim-input { flex:1; min-width:0; padding:10px 14px; border:1.5px solid #e5dfd0; border-radius:var(--radius-pill); font-size:0.86rem; font-family:inherit; color:var(--text); background:var(--bg); }
  .sim-input:focus { outline:none; border-color:var(--brand); }
  .sim-input:disabled { opacity:0.6; }
  .sim-send { flex-shrink:0; padding:0 16px; border:none; border-radius:var(--radius-pill); background:var(--brand); color:#fff; font-weight:bold; font-size:0.84rem; cursor:pointer; transition:background 0.15s; }
  .sim-send:hover:not(:disabled) { background:var(--brand-dark); }
  .sim-send:disabled { opacity:0.5; cursor:default; }
  .sim-hint { flex-shrink:0; text-align:center; color:var(--text-muted); font-size:0.72rem; margin:10px 0 0; transition:opacity 0.4s ease; }
  .js .sim-hint { opacity:0; }
  .sim-hint.visible { opacity:1; }
  @media (max-width: 720px) {
    .sim-wrap { grid-template-columns:1fr; text-align:center; }
    .sim-text-col p { margin:0 auto; }
    .sim-phone { max-width:300px; }
  }

  .closing-band {
    width:100vw; margin-left:calc(50% - 50vw); margin-right:calc(50% - 50vw);
    background:var(--brand-dark); color:#fff; text-align:center; padding:72px 20px;
  }
  .closing-band h2 { font-size:clamp(1.4rem, 3vw, 1.9rem); margin:0 0 14px; }
  .closing-band p { color:#dfe6da; max-width:480px; margin:0 auto; line-height:1.8; }
</style>
<script>
  // JS有効時のみシミュレーション(.sim-phone)のバブルを最初は隠す(CSS側の.jsスコープと対応)。
  // ページ冒頭で同期実行することで、表示→非表示のチラつき(FOUC)を防ぐ
  document.documentElement.classList.add('js');
</script>

<div class="splash-overlay" id="splashOverlay">
  <div class="splash-mark-wrap lead-line">
    <span class="splash-glow" aria-hidden="true"></span>
    <svg class="splash-mark" viewBox="0 0 128 128" aria-hidden="true">
      <path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/>
      <path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/>
    </svg>
  </div>
  <p>
    <span class="lead-line lead-line-1">ずっと、話そう…</span>
    <span class="lead-line lead-line-2">ずっと、聞いてるよ。</span>
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

  var lineDelays = [100, 550, 1950];
  lines.forEach(function (el, i) {
    setTimeout(function () { el.classList.add('visible'); }, lineDelays[i] || 150);
  });

  overlay.addEventListener('click', dismiss);
  setTimeout(dismiss, 4400);
})();
</script>

<div class="hero-wrap">
  <div class="hero-text">
    <h1>今日、公園で<br>のんびりしていた。<br><span class="hl">それを知る、<br>小さな安心。</span></h1>
    <p>天気の話でひと笑いするような、何気ないやり取りが、離れて暮らす家族に届きます。</p>
    <div><a class="cta" href="/apply/">無料でお試しを申し込む<?= Layout::icon('play') ?></a></div>
  </div>
  <div class="hero-art">
    <div class="frame">
      <img src="/assets/mv-photo-01.jpg" alt="公園でLINEを楽しむ利用者の写真" width="1408" height="768">
    </div>
  </div>
</div>

<section>
  <div class="about-wrap">
    <div class="about-art">
      <div class="frame">
        <img src="/assets/mv-photo-02.jpg" alt="自宅でLINEを楽しむ利用者の写真" width="1408" height="768">
      </div>
    </div>
    <div class="about-text">
      <h2>話すほどに、知っていく。</h2>
      <p>
        毎日の会話を重ねるうちに、TAYORIは大切な人のことを少しずつ知り、忘れそうな予定や約束を、さりげなく思い出させてくれます。
        気になる変化があれば、ご家族にもお知らせします。
      </p>
    </div>
  </div>
</section>

<section>
  <div class="about-wrap about-wrap-reverse">
    <div class="about-text">
      <h2>ご家族にも、そっと安心を。</h2>
      <p>
        週に一度、「今週の様子」をダイジェストでお届けします。しばらく会話が確認できない時はご連絡し、
        その後連絡が取れたこともきちんとお伝えします。日々の会話をのぞくことはなく、必要な時だけそっと届きます。
      </p>
    </div>
    <div class="about-art">
      <div class="frame">
        <img src="/assets/about-photo-03.jpg" alt="TAYORIサポートからの安否確認の連絡にほっとするご家族の写真" width="1536" height="1024">
      </div>
    </div>
  </div>
</section>

<section>
  <div class="sim-wrap">
    <div class="sim-text-col">
      <h2>話しかけると、わかります。</h2>
      <p>新しいアプリは不要です。いつもの<span class="line-brand">LINE</span>で、いつも通りに話しかけるだけ。続きは、あなた自身の言葉で試してみてください。</p>
    </div>
    <div class="sim-phone-col">
      <div class="sim-phone-stack">
        <div class="sim-cta-badge"><?= Layout::icon('chat') ?>ぜひ話しかけてみてください</div>
        <div class="sim-phone">
        <div class="sim-messages" id="simMessages">
          <div class="sim-bubble sim-ai"><span class="sim-avatar"><svg viewBox="0 0 128 128" aria-hidden="true"><path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/><path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/></svg></span><span class="sim-text">こんにちは。今日はいい天気ですね。お散歩日和ですよ。</span></div>
          <div class="sim-bubble sim-user"><span class="sim-text">そうだね、さっき公園歩いてきたよ</span></div>
          <div class="sim-bubble sim-ai"><span class="sim-avatar"><svg viewBox="0 0 128 128" aria-hidden="true"><path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/><path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/></svg></span><span class="sim-text">いいですね。公園の景色、気持ちよかったでしょうね。</span></div>
          <div class="sim-bubble sim-user"><span class="sim-text">来週の火曜、病院の予約があるんだよね</span></div>
          <div class="sim-bubble sim-ai"><span class="sim-avatar"><svg viewBox="0 0 128 128" aria-hidden="true"><path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/><path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/></svg></span><span class="sim-text">わかりました。覚えておきますね。</span></div>
          <div class="sim-divider">1週間後</div>
          <div class="sim-bubble sim-ai"><span class="sim-avatar"><svg viewBox="0 0 128 128" aria-hidden="true"><path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/><path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/></svg></span><span class="sim-text">明日は病院のご予約でしたね(10:00〜)。お忘れなく!</span></div>
        </div>
        <div class="sim-input-row" id="simInputRow">
          <input type="text" id="simInput" class="sim-input" placeholder="続きを話しかけてみてください" maxlength="200" autocomplete="off">
          <button type="button" id="simSend" class="sim-send">送信</button>
        </div>
        <p class="sim-hint" id="simHint">これはTAYORIとの体験版チャットです。実際の会話はLINEで行われます。</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="about-teaser-section">
  <div class="about-teaser-band">
    <p class="about-teaser-hook">TAYORIって、どんな存在なんだろう。</p>
    <a class="about-link" href="/about/">TAYORIについて詳しく見る<?= Layout::icon('arrow-right') ?></a>
  </div>
</section>

<section>
  <div class="section-head emblem">
    <svg class="emblem-mark" viewBox="0 0 128 128" aria-hidden="true">
      <path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/>
      <path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/>
    </svg>
    <h2>そばに、いる。</h2>
  </div>
  <div class="capability-band">
    <span class="capability-glow" aria-hidden="true"></span>
    <div class="capability-lines" id="capabilityLines">
      <p class="cap-line">話しかければ、<span class="hl">応える。</span></p>
      <p class="cap-line">大切な人と交わした約束や予定も、<span class="hl">忘れない。</span></p>
      <p class="cap-line">その日の天気にも、気になる兆候にも、<span class="hl">そっと気づく。</span></p>
      <p class="cap-line">会話のない日は、<span class="hl">TAYORIから</span>話しかける。</p>
      <p class="cap-line">家族にも、<span class="hl">そっと届く。</span></p>
    </div>
    <p class="capability-note">※ 気になる兆候の検知(見守りアラート)は、寄り添いスタンダード以上でご利用いただけます。ご家族への週次ダイジェストは全プラン共通です。</p>
  </div>
</section>

<section>
  <div class="section-head">
    <h2>ご利用の流れ</h2>
    <p>お申込みから会話開始まで、4ステップで完了します。</p>
  </div>
  <div class="steps" id="stepsList">
    <div class="step">
      <div class="step-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="3" width="15" height="18" rx="2.5"/><path d="M8.5 8h7M8.5 12h7M8.5 16h4.5"/></svg>
        <span class="step-num">1</span>
      </div>
      <div class="step-content">
        <strong>ご家族が申込フォームからお申込み</strong>
        <p>ご利用者様のお名前や、話し相手となるTAYORIの性別(男性・女性・おまかせ)などを入力します。</p>
      </div>
    </div>
    <div class="step">
      <div class="step-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="2.6"/><circle cx="16" cy="8" r="2.6"/><path d="M3 19c0-3 2.2-5 5-5s5 2 5 5"/><path d="M13.4 14.2c.5-.15 1-.2 1.6-.2 2.8 0 5 2 5 5"/></svg>
        <span class="step-num">2</span>
      </div>
      <div class="step-content">
        <strong>まずご家族がLINEで「TAYORIサポート」を友だち追加</strong>
        <p>お申込みいただいたご家族様ご自身の端末で、ボタンひとつで連携完了。週1回の「今週の様子」ダイジェストや、お支払いのご案内はこちらに届きます(見守りアラートは寄り添いスタンダード以上)。</p>
      </div>
    </div>
    <div class="step">
      <div class="step-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.3"/><path d="M3.5 19.5c0-3.6 2.5-5.8 5.5-5.8s5.5 2.2 5.5 5.8"/><path d="M17 7.5h4.5M19.25 5.25v4.5"/></svg>
        <span class="step-num">3</span>
      </div>
      <div class="step-content">
        <strong>ご利用者様(ご本人)がLINEで「TAYORI」を友だち追加</strong>
        <p>ご家族が画面に表示された連携用URLをコピーし、LINEなどでご本人のスマートフォンに送るだけ。ご本人はそのURLを開いて友だち追加するだけで連携完了。完了するとご家族に通知が届きます。</p>
      </div>
    </div>
    <div class="step">
      <div class="step-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span class="step-num">4</span>
      </div>
      <div class="step-content">
        <strong>日々の会話がはじまります</strong>
        <p>TAYORIが名前を持って自己紹介し、その後は自然な世間話の相手になります。</p>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  var steps = document.querySelectorAll('#stepsList .step');
  var container = document.getElementById('stepsList');
  if (!steps.length || !container) {
    return;
  }
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function reveal() {
    steps.forEach(function (el, i) {
      if (reduceMotion) {
        el.classList.add('visible');
      } else {
        setTimeout(function () { el.classList.add('visible'); }, i * 150);
      }
    });
  }

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          reveal();
          observer.disconnect();
        }
      });
    }, { threshold: 0.2 });
    observer.observe(container);
  } else {
    reveal();
  }
})();
</script>
<script>
(function () {
  var lines = document.querySelectorAll('#capabilityLines .cap-line');
  var container = document.getElementById('capabilityLines');
  if (!lines.length || !container) {
    return;
  }
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function reveal() {
    lines.forEach(function (el, i) {
      if (reduceMotion) {
        el.classList.add('visible');
      } else {
        setTimeout(function () { el.classList.add('visible'); }, i * 160);
      }
    });
  }

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          reveal();
          observer.disconnect();
        }
      });
    }, { threshold: 0.4 });
    observer.observe(container);
  } else {
    reveal();
  }
})();
</script>

<?php if (!empty($plans)): ?>
<section id="pricing">
  <div class="section-head">
    <h2>料金プラン</h2>
    <p>ご本人の毎日の会話に、ご家族への見守り機能をどこまで加えるかで選べます。</p>
  </div>
  <div class="pricing-band">
    <div class="plans">
      <?php foreach ($plans as $plan): ?>
        <?php if (!empty($plan['coming_soon'])): ?>
          <div class="plan-card soon">
            <div class="badge-soon">近日公開</div>
            <div class="plan-icon"><?= Layout::icon(PLAN_ICONS[$plan['code']] ?? 'chat') ?></div>
            <div class="name"><?= h($plan['name']) ?></div>
            <?php if (!empty($plan['description'])): ?><p class="teaser"><?= h($plan['description']) ?></p><?php endif; ?>
          </div>
        <?php else: ?>
          <?php $isRecommended = ($plan['code'] ?? '') === 'family_watch'; ?>
          <div class="plan-card<?= $isRecommended ? ' recommended' : '' ?>">
            <?php if ($isRecommended): ?><div class="badge-recommend">ご家族に人気No.1</div><?php endif; ?>
            <div class="plan-icon"><?= Layout::icon(PLAN_ICONS[$plan['code']] ?? 'chat') ?></div>
            <div class="name"><?= h($plan['name']) ?></div>
            <div class="price"><?= number_format((int) $plan['price_yen']) ?>円<span>/月</span></div>
            <?php if (!empty($plan['description'])): ?><p class="teaser"><?= h($plan['description']) ?></p><?php endif; ?>
            <ul class="feature-list">
              <?php foreach (PLAN_FEATURES as $feature): ?>
                <?php $has = in_array($plan['code'], $feature['plans'], true); ?>
                <li class="<?= $has ? 'has' : 'no' ?>"><?= Layout::icon($has ? 'check' : 'minus') ?><span><?= h($feature['label']) ?></span></li>
              <?php endforeach; ?>
            </ul>
            <a class="plan-cta" href="/apply/?plan_id=<?= (int) $plan['id'] ?>">このプランで申し込む</a>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <p class="pricing-note">すべてのプランで10日間無料でお試しいただけます。<br>ご両親お二人など、複数の利用者様分を後からマイページで追加することもできます(利用者様ごとにプランをお選びいただけます)。</p>
  </div>
</section>
<?php endif; ?>

<section>
  <div class="note">
    <span class="note-avatar" aria-hidden="true">
      <svg viewBox="0 0 128 128">
        <path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/>
        <path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/>
      </svg>
    </span>
    <div class="note-bubble">
      <p>
        TAYORIは、まだ生まれたばかりです。<br>
        これから先も、使ってくださる方の声を聞きながら、<br>
        少しずつ育てていけたら…<br>
        と思っています。
      </p>
    </div>
  </div>
</section>

<div class="closing-band">
  <h2>離れていても、そばにいるように。</h2>
  <p>まずは10日間、無料でお試しください。</p>
  <a class="cta on-dark" href="/apply/">無料でお試しを申し込む<?= Layout::icon('play') ?></a>
</div>

<script>
(function () {
  var phone = document.querySelector('.sim-phone');
  if (!phone) {
    return;
  }
  var messagesEl = document.getElementById('simMessages');
  var inputRow = document.getElementById('simInputRow');
  var hintEl = document.getElementById('simHint');
  var inputEl = document.getElementById('simInput');
  var sendBtn = document.getElementById('simSend');
  var scriptedItems = phone.querySelectorAll('.sim-bubble, .sim-divider');
  // .sim-avatarの中身(ロゴマーク)。静的なマークアップのみでユーザー入力を含まないため、innerHTMLで挿入して問題ない
  var TAYORI_MARK_SVG = '<svg viewBox="0 0 128 128" aria-hidden="true">'
    + '<path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/>'
    + '<path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/>'
    + '</svg>';

  // 台本(1週間後のリマインドを除く)を、そのままチャットの文脈として引き継ぐ。
  // これにより「続きを話しかける」と、体験版のAIが台本の会話の流れをふまえて返信できる
  var history = [
    { role: 'assistant', content: 'こんにちは。今日はいい天気ですね。お散歩日和ですよ。' },
    { role: 'user', content: 'そうだね、さっき公園歩いてきたよ' },
    { role: 'assistant', content: 'いいですね。公園の景色、気持ちよかったでしょうね。' },
    { role: 'user', content: '来週の火曜、病院の予約があるんだよね' },
    { role: 'assistant', content: 'わかりました。覚えておきますね。' }
  ];
  // 台本の「1週間後」で確定した事実(会話履歴には含めていない分)を、既知の予定として明示的に持たせておく。
  // 実際の会話ループが「知っている予定一覧」をDBから正確に注入するのと同じ仕組みを、この体験セッションでも
  // 再現するためのもの(会話履歴からAIが思い出す方式より、この方が正確に答えられる)
  var knownSchedules = [
    { title: '病院', when: '明日 10:00〜' }
  ];
  var sessionToken = null;
  var ended = false;
  var sending = false;
  var played = false;

  function playScripted() {
    if (played) {
      return;
    }
    played = true;
    scriptedItems.forEach(function (el, i) {
      setTimeout(function () {
        el.classList.add('visible');
        messagesEl.scrollTop = messagesEl.scrollHeight;
      }, i * 450);
    });
    var revealDelay = scriptedItems.length * 450 + 200;
    setTimeout(function () {
      inputRow.classList.add('visible');
      hintEl.classList.add('visible');
    }, revealDelay);
  }

  function appendBubble(role, text) {
    var div = document.createElement('div');
    div.className = 'sim-bubble visible ' + (role === 'assistant' ? 'sim-ai' : 'sim-user');
    if (role === 'assistant') {
      var avatar = document.createElement('span');
      avatar.className = 'sim-avatar';
      avatar.innerHTML = TAYORI_MARK_SVG;
      div.appendChild(avatar);
    }
    var textEl = document.createElement('span');
    textEl.className = 'sim-text';
    textEl.textContent = text;
    div.appendChild(textEl);
    messagesEl.appendChild(div);
    messagesEl.scrollTo({ top: messagesEl.scrollHeight, behavior: 'smooth' });
    return div;
  }

  function appendTyping() {
    var div = document.createElement('div');
    div.className = 'sim-bubble sim-ai visible';
    var avatar = document.createElement('span');
    avatar.className = 'sim-avatar';
    avatar.innerHTML = TAYORI_MARK_SVG;
    var textEl = document.createElement('span');
    textEl.className = 'sim-text sim-typing';
    textEl.innerHTML = '<span></span><span></span><span></span>';
    div.appendChild(avatar);
    div.appendChild(textEl);
    messagesEl.appendChild(div);
    messagesEl.scrollTo({ top: messagesEl.scrollHeight, behavior: 'smooth' });
    return div;
  }

  function endSimulation(hintText) {
    ended = true;
    inputEl.disabled = true;
    sendBtn.disabled = true;
    hintEl.textContent = hintText;
  }

  function sendMessage() {
    var text = inputEl.value.trim();
    if (!text || ended || sending) {
      return;
    }
    sending = true;
    inputEl.value = '';
    inputEl.disabled = true;
    sendBtn.disabled = true;

    appendBubble('user', text);
    // 送信時点(今回の発言を含まない)の履歴をそのままリクエストに使う。ここでhistoryへpushしてしまうと
    // 「message」と「historyの最後の1件」で同じ発言が二重に送られ、AIの文脈が噛み合わなくなるため、
    // historyへの追加は応答を受け取った後にまとめて行う
    var requestHistory = history.slice();
    var typingEl = appendTyping();

    fetch('/simulate_chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, history: requestHistory, known_schedules: knownSchedules, session_token: sessionToken })
    }).then(function (res) {
      return res.json();
    }).then(function (data) {
      typingEl.remove();
      if (data.session_token) {
        sessionToken = data.session_token;
      }
      history.push({ role: 'user', content: text });
      if (data.reply) {
        appendBubble('assistant', data.reply);
        history.push({ role: 'assistant', content: data.reply });
      } else {
        // generation_failed等、返信本文が無いまま応答が返ってきた場合。無言で終わらせず必ず何か表示する
        appendBubble('assistant', 'ごめんね、うまく返事を作れなかったみたい。もう一度送ってみて。');
      }
      if (data.new_schedule && data.new_schedule.title) {
        knownSchedules.push({ title: data.new_schedule.title, when: data.new_schedule.when || '' });
      }
      if (data.is_last_turn) {
        endSimulation(data.reply ? '体験版はここまでです。実際のTAYORIをぜひお試しください。' : '通信の都合でここまでにしておきますね。');
        return;
      }
      inputEl.disabled = false;
      sendBtn.disabled = false;
      inputEl.focus();
    }).catch(function () {
      typingEl.remove();
      appendBubble('assistant', 'ごめんね、少し通信がうまくいかなかったみたい。もう一度送ってみてください。');
      inputEl.disabled = false;
      sendBtn.disabled = false;
    }).finally(function () {
      sending = false;
    });
  }

  // Enterでの送信は行わない(IMEの変換確定Enterと誤って送信されるのを避けるため)。送信はボタンのみ
  sendBtn.addEventListener('click', sendMessage);

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          playScripted();
          observer.disconnect();
        }
      });
    }, { threshold: 0.3 });
    observer.observe(phone);
  } else {
    playScripted();
  }
})();
</script>
<?php Layout::renderFooter(); ?>
