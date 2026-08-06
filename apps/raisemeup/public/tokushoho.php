<?php
require_once __DIR__ . '/../src/Layout.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

// APP_BASE_URLをEnvから読み込む(未ロードだとLayoutのcanonical/OGP用URLが相対パスになってしまうため)
Env::load(__DIR__ . '/../../../.env');

// クローラーによる氏名・住所・電話番号・メールアドレスの機械的な取得と、コピー&ペーストを防ぐための表示方法。
// 各文字を実テキストではなくCSS疑似要素(::before + data-c属性)として描画するため、
// DOM上のテキストノードには文字が存在せず、単純なHTML/テキストスクレイピングでは取得できない。
// 疑似要素の内容はブラウザの選択・コピー対象にもならないため、コピペ防止も兼ねる。
// アクセシビリティのためaria-labelに元の文字列を残す(スクリーンリーダー向け)。
function jam(string $text): string
{
    $spans = '';
    foreach (mb_str_split($text) as $char) {
        $spans .= '<span class="jam" data-c="' . htmlspecialchars($char, ENT_QUOTES, 'UTF-8') . '"></span>';
    }
    return '<span class="jam-wrap" aria-label="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '">' . $spans . '</span>';
}

Layout::renderHeader(
    'tokushoho',
    '特定商取引法に基づく表記',
    true,
    'TAYORI(タヨリ)の特定商取引法に基づく表記です。運営会社・お問い合わせ先などをご確認いただけます。'
);
?>
<style>
  h1 { font-size:1.5rem; margin-bottom:24px; }
  .tokushoho table { width:100%; border-collapse:collapse; margin-top:8px; }
  .tokushoho th, .tokushoho td { text-align:left; padding:14px 12px; border-bottom:1px solid #ece7dc; font-size:0.95rem; line-height:1.8; vertical-align:top; }
  .tokushoho th { width:11em; color:#726d63; font-weight:bold; white-space:nowrap; }
  .tokushoho td { color:#444; }
  .tokushoho .updated { color:#888; font-size:0.85rem; margin-top:24px; }
  .jam-wrap { user-select:none; -webkit-user-select:none; -moz-user-select:none; -ms-user-select:none; }
  .jam::before { content: attr(data-c); }
</style>

<h1>特定商取引法に基づく表記</h1>

<div class="tokushoho">
  <table>
    <tr>
      <th>販売事業者</th>
      <td><?= jam('ビーウェブコミュニケーションズ') ?></td>
    </tr>
    <tr>
      <th>運営統括責任者</th>
      <td><?= jam('和田亮輔') ?></td>
    </tr>
    <tr>
      <th>所在地</th>
      <td><?= jam('〒605-0977') ?><br><?= jam('京都府京都市東山区泉涌寺雀ケ森町7-12') ?></td>
    </tr>
    <tr>
      <th>電話番号</th>
      <td><?= jam('075-561-7517') ?></td>
    </tr>
    <tr>
      <th>メールアドレス</th>
      <td><?= jam('support@tayori-net.jp') ?></td>
    </tr>
    <tr>
      <th>販売価格</th>
      <td>各プラン月額980円〜2,980円(税込)。プランごとの価格は<a href="/#pricing">料金プラン</a>およびお申込みフォームに表示の金額をご参照ください。</td>
    </tr>
    <tr>
      <th>商品代金以外の必要料金</th>
      <td>LINEおよび本サイトのご利用にかかる通信料は、お客様のご負担となります。</td>
    </tr>
    <tr>
      <th>お支払い方法</th>
      <td>クレジットカード決済(Stripe, Inc.が提供する決済システムを利用)</td>
    </tr>
    <tr>
      <th>お支払い時期</th>
      <td>無料期間(10日間)終了後、初回のお支払いが発生します。以降は毎月同日に自動更新・課金されます。</td>
    </tr>
    <tr>
      <th>サービス提供時期</th>
      <td>お申込み完了後、ご利用者様によるLINEアカウントとの連携完了と同時にご利用いただけます。</td>
    </tr>
    <tr>
      <th>返品・キャンセルについて</th>
      <td>
        本サービスはデジタルサービスのため、性質上返品はお受けできません。<br>
        無料期間(10日間)中は、いつでも解約いただけます。有料契約への移行後に解約された場合、日割りによる返金は行っておりません。<br>
        解約は、マイページの「お支払いを管理」からいつでもお手続きいただけます。
      </td>
    </tr>
    <tr>
      <th>動作環境</th>
      <td>LINEアプリをご利用いただけるスマートフォン等の端末、およびインターネット接続環境が必要です。</td>
    </tr>
  </table>

  <p class="updated">制定日:2026年7月22日</p>
</div>
<script>
(function () {
  document.querySelectorAll('.jam-wrap').forEach(function (el) {
    el.addEventListener('copy', function (e) { e.preventDefault(); });
    el.addEventListener('cut', function (e) { e.preventDefault(); });
  });
})();
</script>

<?php Layout::renderFooter(); ?>
