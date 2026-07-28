<?php
require_once __DIR__ . '/../src/Layout.php';

Layout::renderHeader('faq', 'よくある質問', true);

$categories = [
    [
        'title' => 'サービスについて',
        'items' => [
            [
                'q' => 'TAYORIってどんなサービスですか?',
                'a' => '離れて暮らすご家族に代わって、AIコンパニオン「TAYORI」がLINEで毎日会話をするサービスです。何気ない会話を通じて、ご本人には話し相手を、ご家族には日々の様子が伝わる小さな安心をお届けします。',
            ],
            [
                'q' => '新しいアプリのインストールは必要ですか?',
                'a' => '不要です。普段お使いのLINEでそのまま話しかけていただくだけで、TAYORIとの会話が始まります。',
            ],
            [
                'q' => '対応地域は決まっていますか?',
                'a' => 'LINEがご利用いただける環境であれば、全国どこでもお使いいただけます。',
            ],
        ],
    ],
    [
        'title' => '料金・お申込みについて',
        'items' => [
            [
                'q' => '無料お試し期間はありますか?',
                'a' => 'すべてのプランで10日間無料でお試しいただけます。お試し期間中に解約すれば、料金は発生しません。',
            ],
            [
                'q' => 'どのプランを選べばいいですか?',
                'a' => '毎日の会話だけで十分な場合は「寄り添いベーシック」、見守りアラートや安否確認などご家族への通知も重視する場合は「寄り添いスタンダード」がおすすめです。プランの違いは<a href="/#pricing">料金プラン</a>でご確認いただけます。',
            ],
            [
                'q' => '支払い方法は何がありますか?',
                'a' => 'クレジットカードでのお支払いに対応しています(Stripe社の決済システムを利用)。',
            ],
            [
                'q' => '解約はいつでもできますか?',
                'a' => 'マイページの「お支払いを管理」からいつでも解約手続きが可能です。お電話やメールでのお手続きは不要です。',
            ],
        ],
    ],
    [
        'title' => 'ご利用方法について',
        'items' => [
            [
                'q' => '利用者本人がスマホの操作が苦手でも大丈夫ですか?',
                'a' => '普段お使いのLINEのトーク画面で、文字を送っていただくだけです。新しい操作を覚えていただく必要はありません。',
            ],
            [
                'q' => '会話の内容をご家族が全部見られるのですか?',
                'a' => 'いいえ。日々の会話をご家族がのぞき見ることはできません。週に一度の「今週の様子」ダイジェスト(全プラン共通)と、気になる兆候があった場合の見守りアラート(寄り添いスタンダード以上)のみ、ご家族へお届けします。',
            ],
            [
                'q' => '見守りアラートはどんな時に届きますか?',
                'a' => '振込の要求や体調不良の訴えなど、気になる兆候をキーワードから検知した場合や、しばらく会話が確認できない場合に、ご家族へLINEでお知らせします。ただし、すべての詐欺やトラブルを検知できることを保証するものではないため、心配な際は直接ご本人にご連絡ください。',
            ],
            [
                'q' => 'ご家族からTAYORI経由で本人にメッセージを伝えられますか?',
                'a' => 'はい。「TAYORIサポート」にメッセージを送ると、TAYORIが次にご本人と会話するタイミングで、自然な形でさりげなくお伝えします。また、「水分補給を気にかけてほしい」のように普段から気にかけてほしいテーマを設定しておくと、ご家族から言われたこととは明かさず、TAYORI自身の気遣いとして日々の会話に自然に混ざります(テーマはマイページからも確認・削除でき、一定期間で自動的に外れます)。どちらも寄り添いスタンダード以上でご利用いただけます。',
            ],
        ],
    ],
    [
        'title' => 'セキュリティ・プライバシーについて',
        'items' => [
            [
                'q' => '会話の内容はどのように扱われますか?',
                'a' => '応答の生成や、予定・人物の記憶のために処理されますが、広告やマーケティングの目的で利用することはありません。詳しくは<a href="/privacy/">プライバシーポリシー</a>をご覧ください。',
            ],
            [
                'q' => 'ここに無い質問がある場合はどうすればいいですか?',
                'a' => '<a href="/tokushoho/">特定商取引法に基づく表記</a>に記載のお問い合わせ先まで、お気軽にご連絡ください。',
            ],
        ],
    ],
];
?>
<style>
  h1 { font-size:1.6rem; margin-bottom:8px; }
  .faq-lead { color:var(--text-muted); line-height:1.8; margin:0 0 40px; }
  .faq-category { margin-bottom:36px; }
  .faq-category:last-child { margin-bottom:0; }
  .faq-category h2 { font-size:1.05rem; color:var(--brand-dark); margin:0 0 14px; }
  .faq-list { display:flex; flex-direction:column; gap:10px; }
  .faq-item {
    background:var(--surface); border-radius:var(--radius-lg); box-shadow:0 2px 14px rgba(61,58,53,0.05);
    overflow:hidden;
  }
  .faq-item summary {
    list-style:none; cursor:pointer; padding:18px 52px 18px 22px; position:relative;
    font-weight:bold; font-size:0.96rem; line-height:1.6;
  }
  .faq-item summary::-webkit-details-marker { display:none; }
  .faq-item summary::before { content:'Q'; color:var(--brand); font-weight:bold; margin-right:10px; }
  .faq-item summary::after {
    content:''; position:absolute; top:50%; right:22px; width:11px; height:11px;
    border-right:2px solid var(--text-muted); border-bottom:2px solid var(--text-muted);
    transform:translateY(-65%) rotate(45deg); transition:transform 0.2s ease;
  }
  .faq-item[open] summary::after { transform:translateY(-35%) rotate(-135deg); }
  .faq-item .a {
    display:flex; gap:10px; margin:0; padding:0 22px 20px 22px;
    color:var(--text-muted); font-size:0.92rem; line-height:1.85;
  }
  .faq-item .a .a-mark { color:var(--accent); font-weight:bold; flex-shrink:0; }
  .faq-item .a a { color:var(--brand); font-weight:bold; }
</style>

<h1>よくある質問</h1>
<p class="faq-lead">TAYORIについて、よくいただくご質問をまとめました。</p>

<?php foreach ($categories as $category): ?>
  <div class="faq-category">
    <h2><?= htmlspecialchars($category['title'], ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="faq-list">
      <?php foreach ($category['items'] as $item): ?>
        <details class="faq-item">
          <summary><?= htmlspecialchars($item['q'], ENT_QUOTES, 'UTF-8') ?></summary>
          <p class="a"><span class="a-mark">A</span><span><?= $item['a'] ?></span></p>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php Layout::renderFooter(); ?>
