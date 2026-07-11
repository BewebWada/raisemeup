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

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

Layout::renderHeader('top', '');
?>
<style>
  .hero { text-align:center; padding:20px 0 40px; }
  .hero h1 { font-size:1.9rem; margin-bottom:12px; }
  .hero p { font-size:1.05rem; color:#555; line-height:1.8; max-width:560px; margin:0 auto; }
  .cta { display:inline-block; margin-top:24px; padding:14px 32px; background:#d98a3d; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; font-size:1.05rem; }
  .cta:hover { background:#c07a30; }
  section { margin:48px 0; }
  section h2 { font-size:1.3rem; border-left:4px solid #d98a3d; padding-left:10px; margin-bottom:20px; }
  .steps { display:grid; gap:16px; }
  .step { background:#fff; border-radius:10px; padding:18px 20px; box-shadow:0 1px 4px rgba(0,0,0,0.05); display:flex; gap:14px; align-items:flex-start; }
  .step .num { flex-shrink:0; width:32px; height:32px; border-radius:50%; background:#d98a3d; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:bold; }
  .step p { margin:4px 0 0; color:#555; font-size:0.95rem; line-height:1.6; }
  .step strong { display:block; margin-bottom:2px; }
  .features { display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); }
  .feature { background:#fff; border-radius:10px; padding:18px 20px; box-shadow:0 1px 4px rgba(0,0,0,0.05); }
  .feature strong { display:block; margin-bottom:6px; }
  .feature p { margin:0; color:#555; font-size:0.9rem; line-height:1.6; }
  .plans { display:grid; gap:16px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); }
  .plan-card { background:#fff; border-radius:10px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.05); text-align:center; }
  .plan-card .name { font-weight:bold; margin-bottom:8px; }
  .plan-card .price { color:#b05a1e; font-size:1.4rem; font-weight:bold; }
  .plan-card .price span { font-size:0.85rem; font-weight:normal; color:#777; }
  .note { background:#fff8ef; border:1px solid #eecb98; border-radius:8px; padding:16px 20px; font-size:0.9rem; color:#555; line-height:1.7; }
</style>

<div class="hero">
  <h1>離れていても、毎日ちょっと話せる相手を。</h1>
  <p>Raise Me Upは、LINEで日々の何気ない会話をする、高齢のご家族のためのAIコンパニオンサービスです。ご本人には気軽な話し相手を、ご家族には安心を。</p>
  <a class="cta" href="/apply/">無料でお試しを申し込む</a>
</div>

<section>
  <h2>Raise Me Upとは</h2>
  <p style="color:#555; line-height:1.8;">
    Raise Me Upは、医療や緊急通報の代わりになるサービスではありません。ご本人がLINEで気軽に世間話をできる相手を用意することで、
    毎日の暮らしに小さな会話のきっかけを増やし、ご家族には「元気にしているだろうか」という不安をやわらげていただくためのサービスです。
    会話の中で話された人物・予定・好みなどをAIが覚えていくので、話すたびに少しずつ「知り合い」になっていきます。
  </p>
</section>

<section>
  <h2>ご利用の流れ</h2>
  <div class="steps">
    <div class="step">
      <div class="num">1</div>
      <div><strong>ご家族が申込フォームからお申込み</strong><p>ご利用者様のお名前や、話し相手となるAIの性別(男性・女性・おまかせ)などを入力します。</p></div>
    </div>
    <div class="step">
      <div class="num">2</div>
      <div><strong>ご本人がLINEで「Raise Me Up」を友だち追加</strong><p>案内された連携コードを送信するだけで連携完了。あとは普段通りLINEでやり取りするだけです。</p></div>
    </div>
    <div class="step">
      <div class="num">3</div>
      <div><strong>日々の会話がはじまります</strong><p>AIが名前を持って自己紹介し、その後は自然な世間話の相手になります。ご家族には別アカウント「RaiseMeUpサポート」から、お支払いに関するご案内などが届きます。</p></div>
    </div>
  </div>
</section>

<section>
  <h2>できること</h2>
  <div class="features">
    <div class="feature">
      <strong>日々の会話相手</strong>
      <p>LINEで送ったメッセージに、親しみやすい口調で返信します。1回のやり取りは短め・読みやすい言葉を意識しています。</p>
    </div>
    <div class="feature">
      <strong>人物・予定を覚えて会話に活かす</strong>
      <p>会話の中で話に出た人物や予定を記憶し、「〇〇さんとの約束、いつだっけ」といった質問にも答えられます。</p>
    </div>
    <div class="feature">
      <strong>専属の名前を持つAI</strong>
      <p>申込時に選んだ性別をもとに、AIが利用者ごとに自分の名前を決めて自己紹介します。</p>
    </div>
    <div class="feature">
      <strong>ご家族への通知(別アカウント)</strong>
      <p>「RaiseMeUpサポート」を通じて、無料期間終了のお知らせやお支払いに関する連絡をご家族にお届けします。</p>
    </div>
  </div>
  <p style="color:#888; font-size:0.85rem; margin-top:14px;">
    ※ 振込要求など不審な会話の兆候があった場合、現在は記録のみ行っています。ご家族への自動通知は今後の機能追加を予定しています。
  </p>
</section>

<?php if (!empty($plans)): ?>
<section>
  <h2>料金プラン</h2>
  <div class="plans">
    <?php foreach ($plans as $plan): ?>
      <div class="plan-card">
        <div class="name"><?= h($plan['name']) ?></div>
        <div class="price"><?= number_format((int) $plan['price_yen']) ?>円<span>/月</span></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p style="color:#888; font-size:0.85rem; margin-top:14px; text-align:center;">
    すべてのプランで10日間無料でお試しいただけます。上記「できること」の内容は全プラン共通です。
  </p>
</section>
<?php endif; ?>

<section>
  <div class="note">
    Raise Me Upはまだ立ち上げたばかりのサービスです。できること・できないことを正直にお伝えしながら、
    利用してくださる方の声をもとに少しずつ育てていきたいと考えています。
  </div>
</section>

<div style="text-align:center; margin:40px 0;">
  <a class="cta" href="/apply/">無料でお試しを申し込む</a>
</div>

<?php Layout::renderFooter(); ?>
