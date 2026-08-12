<?php
// 申込フローStep2(最終確認画面)の描画。特定商取引法12条の6が要求する、申込みボタン直前での
// 分量・価格・支払時期・方法・解約方法の明示と、利用規約・プライバシーポリシーへの同意取得を担う。
require_once __DIR__ . '/Layout.php';
require_once __DIR__ . '/ApplyDoneView.php'; // h(), renderStepIndicator(), TRIAL_DAYS

function renderConfirm(array $v, array $selectedPlan, string $csrfToken, array $errors = [], bool $agreeTerms = false, bool $agreePrivacy = false): void
{
    $genderLabels = ['male' => '男性', 'female' => '女性', '' => '回答しない'];
    $companionLabels = ['male' => '男性', 'female' => '女性', 'random' => 'おまかせ'];

    Layout::renderHeader(
        'apply',
        'お申込み内容のご確認',
        false,
        'お申込み内容をご確認のうえ、利用規約・プライバシーポリシーにご同意のうえお申込みください。'
    );
    ?>
<style>
  .card { max-width: 680px; margin: 0 auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
  .card h1 { font-size:1.4rem; margin-top:0; }
  .card h2 { font-size:1.05rem; margin:28px 0 12px; border-left:4px solid #4B8B5A; padding-left:8px; }
  .errors { background:#fdecea; border:1px solid #f5b0a8; color:#a12a1f; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  .summary-box { background:#f8f6f0; border:1px solid #ece7dc; border-radius:10px; padding:4px 20px; margin-bottom:8px; }
  .summary-box dt { font-size:0.82rem; color:#777; margin-top:14px; }
  .summary-box dd { margin:2px 0 14px; font-weight:bold; }
  .law-box { background:#fff; border:2px solid #4B8B5A; border-radius:12px; padding:18px 20px; margin:16px 0; }
  .law-box .law-title { display:flex; align-items:center; gap:8px; font-weight:bold; color:#1E4729; font-size:1.02rem; margin-bottom:10px; }
  .law-box dt { font-size:0.85rem; color:#726d63; margin-top:12px; }
  .law-box dt:first-child { margin-top:0; }
  .law-box dd { margin:2px 0 0; line-height:1.7; }
  .law-box dd a { font-weight:bold; }
  .agree-box { border:1px solid #ddd; border-radius:8px; padding:14px 16px; margin-top:20px; }
  .agree-box label { display:flex; align-items:flex-start; gap:10px; font-size:0.95rem; line-height:1.6; margin:0; font-weight:normal; }
  .agree-box label + label { margin-top:10px; }
  .agree-box input[type=checkbox] { margin-top:3px; flex-shrink:0; width:18px; height:18px; }
  .agree-box a { font-weight:bold; }
  .card button { display:inline-flex; align-items:center; justify-content:center; gap:10px; margin-top:16px; width:100%; padding:14px; font-size:1.05rem; background:#4B8B5A; color:#fff; border:none; border-radius:8px; cursor:pointer; }
  .card button.secondary { background:#fff; color:#4B8B5A; border:1px solid #4B8B5A; }
  .card button:hover { background:#1E4729; }
  .card button.secondary:hover { background:#f4f8f2; color:#1E4729; }
  .apply-steps { display:flex; list-style:none; margin:0 0 24px; padding:0; }
  .apply-step { flex:1; position:relative; display:flex; flex-direction:column; align-items:center; text-align:center; font-size:0.72rem; color:#aaa; }
  .apply-step:not(:last-child)::after { content:''; position:absolute; top:14px; left:calc(50% + 20px); right:calc(-50% + 20px); height:2px; background:#e6e2d8; }
  .apply-step.is-done:not(:last-child)::after { background:#4B8B5A; }
  .apply-step-circle { display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:#e6e2d8; color:#8a8578; font-weight:bold; font-size:0.85rem; margin-bottom:6px; }
  .apply-step.is-done .apply-step-circle { background:#4B8B5A; color:#fff; }
  .apply-step.is-current .apply-step-circle { background:#fff; color:#4B8B5A; border:2px solid #4B8B5A; }
  .apply-step.is-done .apply-step-label, .apply-step.is-current .apply-step-label { color:#3D3A35; font-weight:bold; }
</style>
<div class="card">
  <?php renderStepIndicator([1 => 'current']); ?>
  <h1>お申込み内容のご確認</h1>
  <p>以下の内容でお間違いなければ、利用規約・プライバシーポリシーにご同意のうえお申込みください。</p>

  <?php if (!empty($errors)): ?>
    <div class="errors">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2>お申込み内容</h2>
  <dl class="summary-box">
    <dt>お申込者様(ご家族)</dt>
    <dd>
      <?= h(trim($v['family_last_name'] . ' ' . $v['family_first_name'])) ?> 様
      <?php if ($v['family_email'] !== ''): ?><br><?= h($v['family_email']) ?><?php endif; ?>
      <?php if ($v['family_phone'] !== ''): ?><br><?= h($v['family_phone']) ?><?php endif; ?>
    </dd>

    <dt>ご利用者様(ご本人)</dt>
    <dd>
      <?php if ($v['user_last_name'] !== '' || $v['user_first_name'] !== ''): ?>
        <?= h(trim($v['user_last_name'] . ' ' . $v['user_first_name'])) ?> 様<?php if ($v['relation'] !== ''): ?>(続柄: <?= h($v['relation']) ?>)<?php endif; ?><br>
      <?php else: ?>
        (お名前は未登録。あとからマイページで登録できます)<br>
      <?php endif; ?>
      <?php if ($v['user_phone'] !== ''): ?><?= h($v['user_phone']) ?><br><?php endif; ?>
      <?php if ($v['user_zip'] !== '' || $v['user_address'] !== ''): ?><?= h($v['user_zip']) ?> <?= h($v['user_address']) ?><br><?php endif; ?>
      <?php if ($v['user_birthdate'] !== ''): ?><?= h((new DateTime($v['user_birthdate']))->format('Y年n月j日')) ?> 生まれ<br><?php endif; ?>
      性別: <?= h($genderLabels[$v['user_gender']] ?? '回答しない') ?>
    </dd>

    <dt>話し相手の性別</dt>
    <dd><?= h($companionLabels[$v['companion_gender']] ?? 'おまかせ') ?></dd>

    <dt>お申込みプラン</dt>
    <dd><?= h($selectedPlan['name']) ?>(月額<?= number_format((int) $selectedPlan['price_yen']) ?>円)</dd>
  </dl>

  <div class="law-box">
    <div class="law-title"><?= Layout::icon('shield') ?> ご契約に関する重要事項</div>
    <dl>
      <dt>サービス期間</dt>
      <dd>本日から<?= TRIAL_DAYS ?>日間無料でお試しいただけます。無料期間終了後は自動的に有料プランへ移行し、毎月同日に自動更新されます。</dd>
      <dt>価格</dt>
      <dd><?= h($selectedPlan['name']) ?> 月額<?= number_format((int) $selectedPlan['price_yen']) ?>円(税込)</dd>
      <dt>お支払い時期・方法</dt>
      <dd>無料期間終了後、クレジットカード(Stripe, Inc.の決済システム)にて初回のお支払いが発生します。以降は毎月自動的に課金されます。</dd>
      <dt>解約について</dt>
      <dd>無料期間中はいつでも解約いただけます。有料契約への移行後に解約された場合、日割りによる返金は行っておりません。解約はマイページからいつでもお手続きいただけます。詳しくは<a href="/tokushoho/" target="_blank" rel="noopener">特定商取引法に基づく表記</a>をご覧ください。</dd>
    </dl>
  </div>

  <form method="post" action="/apply/">
    <?php foreach ($v as $key => $value): ?>
      <?php if ($key === 'user_birthdate') continue; ?>
      <input type="hidden" name="<?= h($key) ?>" value="<?= h($value) ?>">
    <?php endforeach; ?>
    <?php [$bdYear, $bdMonth, $bdDay] = $v['user_birthdate'] !== '' ? explode('-', $v['user_birthdate']) : ['', '', '']; ?>
    <input type="hidden" name="user_birthdate_year" value="<?= h($bdYear) ?>">
    <input type="hidden" name="user_birthdate_month" value="<?= h($bdMonth !== '' ? (string) (int) $bdMonth : '') ?>">
    <input type="hidden" name="user_birthdate_day" value="<?= h($bdDay !== '' ? (string) (int) $bdDay : '') ?>">
    <input type="hidden" name="website" value="">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

    <div class="agree-box">
      <label><input type="checkbox" name="agree_terms" value="1" <?= $agreeTerms ? 'checked' : '' ?>> <a href="/terms/" target="_blank" rel="noopener">利用規約</a>に同意します</label>
      <label><input type="checkbox" name="agree_privacy" value="1" <?= $agreePrivacy ? 'checked' : '' ?>> <a href="/privacy/" target="_blank" rel="noopener">プライバシーポリシー</a>に同意します</label>
    </div>

    <button type="submit" name="apply_stage" value="confirm">上記内容を確認し同意して申し込む<?= Layout::icon('play') ?></button>
    <button type="submit" name="apply_stage" value="edit" class="secondary">内容を修正する</button>
  </form>
</div>
    <?php
    Layout::renderFooter();
}
