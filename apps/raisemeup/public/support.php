<?php
require_once __DIR__ . '/../src/Layout.php';
require_once __DIR__ . '/../src/SupportFaq.php';
require_once __DIR__ . '/../../../shared/db-toolkit/Env.php';

// APP_BASE_URLをEnvから読み込む(未ロードだとLayoutのcanonical/OGP用URLが相対パスになってしまうため)
Env::load(__DIR__ . '/../../../.env');

Layout::renderHeader(
    'support',
    'サポート',
    true,
    'TAYORIについてのご質問に、サポートbotがその場でお答えします。よくある質問もあわせてご確認いただけます。'
);

$categories = SupportFaq::getCategories();
$faqJsonLd = SupportFaq::buildJsonLd($categories);
?>
<style>
  h1 { font-size:1.6rem; margin-bottom:8px; }
  .support-lead { color:var(--text-muted); line-height:1.8; margin:0 0 32px; }

  /* --- セクション見出し(トップページの.section-headと同じ仕様) --- */
  .section-head { text-align:center; max-width:600px; margin:0 auto 36px; }
  .section-head h2 { font-size:clamp(1.4rem, 3vw, 1.8rem); margin:0 0 10px; }
  .section-head p { color:var(--text-muted); line-height:1.8; margin:0; font-size:0.98rem; }

  /* --- サポートbot --- */
  .support-bot {
    background:var(--surface); border-radius:var(--radius-lg); box-shadow:0 2px 14px rgba(61,58,53,0.05);
    padding:22px; margin-top:48px;
  }
  .support-bot h2 { font-size:1.05rem; color:var(--brand-dark); margin:0 0 4px; display:flex; align-items:center; gap:8px; }
  .support-bot h2 .icon { width:1.15em; height:1.15em; color:var(--brand); }
  .support-bot .bot-note { color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin:0 0 16px; }
  .bot-messages {
    display:flex; flex-direction:column; gap:10px; max-height:420px; overflow-y:auto;
    padding:12px; margin-bottom:14px; border:1px solid #e4dfd3; border-radius:var(--radius-lg); background:var(--bg);
  }
  .bot-bubble { display:flex; align-items:center; gap:8px; max-width:88%; }
  .bot-bubble.bot-ai { align-self:flex-start; }
  .bot-bubble.bot-user { align-self:flex-end; flex-direction:row-reverse; }
  .bot-avatar {
    flex-shrink:0; width:30px; height:30px; border-radius:50%; background:var(--card-mint);
    display:flex; align-items:center; justify-content:center;
  }
  .bot-avatar svg { width:18px; height:18px; }
  .bot-text {
    padding:10px 14px; border-radius:16px; font-size:0.92rem; line-height:1.75; white-space:pre-wrap;
  }
  .bot-ai .bot-text { background:var(--bg); border-bottom-left-radius:4px; }
  .bot-user .bot-text { background:var(--brand); color:#fff; border-bottom-right-radius:4px; }
  .bot-text a { color:var(--brand); font-weight:bold; }
  .bot-ai .bot-text a { color:var(--brand); }
  .bot-user .bot-text a { color:#fff; text-decoration:underline; }
  .bot-typing span {
    display:inline-block; width:6px; height:6px; margin-right:3px; border-radius:50%;
    background:var(--text-muted); opacity:0.4; animation:bot-blink 1.1s infinite;
  }
  .bot-typing span:nth-child(2) { animation-delay:0.15s; }
  .bot-typing span:nth-child(3) { animation-delay:0.3s; }
  @keyframes bot-blink { 0%,80%,100% { opacity:0.25; } 40% { opacity:0.9; } }
  .bot-input-row { display:flex; gap:8px; }
  .bot-input-row input {
    flex:1; border:1px solid #e4dfd3; border-radius:var(--radius-pill); padding:12px 18px;
    font-size:0.92rem; font-family:inherit; background:var(--bg); color:var(--text);
  }
  .bot-input-row input:focus { outline:2px solid var(--brand); outline-offset:1px; }
  .bot-input-row button {
    flex-shrink:0; border:none; background:var(--brand); color:#fff; font-weight:bold; font-size:0.9rem;
    padding:0 22px; border-radius:var(--radius-pill); cursor:pointer; transition:background 0.15s;
  }
  .bot-input-row button:hover:not(:disabled) { background:var(--brand-dark); }
  .bot-input-row button:disabled, .bot-input-row input:disabled { opacity:0.55; cursor:not-allowed; }
  .bot-hint { color:var(--text-muted); font-size:0.78rem; margin:10px 2px 0; min-height:1.2em; }

  /* --- FAQ一覧 --- */
  .faq-category { margin-bottom:36px; }
  .faq-category:last-child { margin-bottom:0; }
  .faq-category h3 { font-size:0.98rem; color:var(--brand-dark); margin:0 0 14px; }
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

<h1>サポート</h1>
<p class="support-lead">TAYORIのサービス内容や料金・お申込みについてのご質問にお答えします。</p>

<script type="application/ld+json"><?= json_encode($faqJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<div class="section-head">
  <h2>よくある質問</h2>
  <p>ここにない質問は、下のサポートbotにご質問ください。</p>
</div>

<?php foreach ($categories as $category): ?>
  <div class="faq-category">
    <h3><?= htmlspecialchars($category['title'], ENT_QUOTES, 'UTF-8') ?></h3>
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

<section class="support-bot">
  <h2><?= Layout::icon('chat') ?>サポートbotに質問する</h2>
  <p class="bot-note">TAYORIのサービス内容や料金・お申込みについて、気になることを話しかけてください。ご自身の契約内容など個別のお問い合わせは、マイページまたは特定商取引法に基づく表記の連絡先をご案内します。</p>
  <div class="bot-messages" id="botMessages"></div>
  <div class="bot-input-row">
    <input type="text" id="botInput" placeholder="質問を入力してください" maxlength="300" autocomplete="off">
    <button type="button" id="botSend">送信</button>
  </div>
  <p class="bot-hint" id="botHint"></p>
</section>

<script>
(function () {
  var messagesEl = document.getElementById('botMessages');
  var inputEl = document.getElementById('botInput');
  var sendBtn = document.getElementById('botSend');
  var hintEl = document.getElementById('botHint');
  var TAYORI_MARK_SVG = '<svg viewBox="0 0 128 128" aria-hidden="true">'
    + '<path fill="#4B8B5A" d="M87.2,11.9 A57,57 0 0,1 64,121 L64,99 A35,35 0 0,0 71.9,29.9 L87.2,11.9 Z"/>'
    + '<path fill="#1E4729" d="M40.8,11.9 A57,57 0 0,0 64,121 L64,99 A35,35 0 0,1 56.1,29.9 L40.8,11.9 Z"/>'
    + '</svg>';

  var history = [];
  var sessionToken = null;
  var ended = false;
  var sending = false;

  function appendBubble(role, html) {
    var div = document.createElement('div');
    div.className = 'bot-bubble ' + (role === 'assistant' ? 'bot-ai' : 'bot-user');
    if (role === 'assistant') {
      var avatar = document.createElement('span');
      avatar.className = 'bot-avatar';
      avatar.innerHTML = TAYORI_MARK_SVG;
      div.appendChild(avatar);
    }
    var textEl = document.createElement('span');
    textEl.className = 'bot-text';
    // アシスタントの返信はサーバー側プロンプトで<a href="...">リンクのみ許可しているため、
    // その用途に限りinnerHTMLで挿入する。ユーザー自身の発言は必ずtextContentで挿入し、
    // 自分の入力がそのままHTMLとして解釈されない(XSS)ようにする
    if (role === 'assistant') {
      textEl.innerHTML = html;
    } else {
      textEl.textContent = html;
    }
    div.appendChild(textEl);
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return div;
  }

  function appendTyping() {
    var div = document.createElement('div');
    div.className = 'bot-bubble bot-ai';
    var avatar = document.createElement('span');
    avatar.className = 'bot-avatar';
    avatar.innerHTML = TAYORI_MARK_SVG;
    var textEl = document.createElement('span');
    textEl.className = 'bot-text bot-typing';
    textEl.innerHTML = '<span></span><span></span><span></span>';
    div.appendChild(avatar);
    div.appendChild(textEl);
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return div;
  }

  function endChat(hintText) {
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
    var requestHistory = history.slice();
    var typingEl = appendTyping();

    fetch('/support_chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, history: requestHistory, session_token: sessionToken })
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
      }
      if (data.is_last_turn) {
        endChat('この回答でサポートbotとのやり取りは一区切りです。続けてお聞きになりたい場合はページを再読み込みしてください。');
      } else {
        inputEl.disabled = false;
        sendBtn.disabled = false;
        inputEl.focus();
      }
    }).catch(function () {
      typingEl.remove();
      appendBubble('assistant', 'すみません、うまくお答えできませんでした。少し時間をおいて、もう一度お試しください。');
      inputEl.disabled = false;
      sendBtn.disabled = false;
    }).finally(function () {
      sending = false;
    });
  }

  sendBtn.addEventListener('click', sendMessage);
  inputEl.addEventListener('keydown', function (e) {
    // 日本語入力(IME)で変換候補を確定する際のEnterでも発火してしまうため、
    // 変換中(isComposing、Safariでは代わりにkeyCode 229)は送信しないようにする
    if (e.key === 'Enter' && !e.isComposing && e.keyCode !== 229) {
      sendMessage();
    }
  });

  appendBubble('assistant', 'こんにちは。TAYORIについて気になることがあれば、お気軽にご質問ください。');
})();
</script>

<?php Layout::renderFooter(); ?>
