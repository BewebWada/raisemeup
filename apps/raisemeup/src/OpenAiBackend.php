<?php
require_once __DIR__ . '/AiBackend.php';
require_once __DIR__ . '/AiBackendException.php';

// 「もしもの時」用のOpenAI(ChatGPT API)バックエンド。ClaudeClient側の15メソッドはAnthropic
// Messages API形式でsystem/messagesを組み立てているため、ここでOpenAI Chat Completions形式に
// 変換し、レスポンスをAnthropic互換の形(content配列)に包み直して返す。プロンプトの文言自体は
// 一切変更しない(Haiku向けに調整された指示文のままOpenAIに投げる)。実際に切り替える際は、
// プロンプトの再チューニングや response_format(Structured Outputs)の導入を別途検討すること。
//
// 参照: https://developers.openai.com/api/docs/api-reference/chat/create (2026-08-10確認)
class OpenAiBackend implements AiBackend
{
    // $serviceTier: OpenAIのサービスティア(auto/default/flex/priority/fast等)。nullなら省略(=auto相当)
    public function __construct(private string $apiKey, private ?string $serviceTier = null)
    {
    }

    public function send(array $body, int $timeoutSeconds): array
    {
        if (!empty($body['tools'])) {
            // 例: answerWithWebSearchのAnthropicサーバー側web_searchツール。OpenAI側は別体系の
            // ツールが必要で1:1移植ではないため、今回はスコープ外として明示的に未対応にする
            throw new AiBackendException('OpenAiBackend: tools(サーバー側ツール)は未対応です');
        }

        $messages = [];
        $systemText = $this->flattenSystem($body['system'] ?? '');
        if ($systemText !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemText];
        }
        foreach ($body['messages'] ?? [] as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $this->convertContent($message['content'] ?? ''),
            ];
        }

        $maxCompletionTokens = $body['max_tokens'] ?? 1024;
        $isReasoningModel = $this->isReasoningModel($body['model']);
        if ($isReasoningModel) {
            // gpt-5系/o系は非表示の推論トークンをmax_completion_tokensから消費する。この既存コードベースの
            // max_tokens値(150〜1024)は非推論モデル(Haiku)向けに決めた値で、原稿対応モードのような複雑な
            // タスク+画像では'low'指定でも推論に全て使われ、可視の応答が空/途中で切れる事象を実機で確認した
            // (stop_reason=lengthでcontentが空文字になるケース)。可視の出力分の余地を必ず残すため、
            // 元の上限に一定のオーバーヘッドを積み増す(推論トークン自体を0にはできないため)
            $maxCompletionTokens += 3000;
            // 最小値の名称はモデル世代で異なる(gpt-5系: minimal/low/medium/high、gpt-5.6系: none/low/medium/
            // high/xhigh)が、'low'は実機確認で両世代とも受理されたため、これを使う
        }

        $requestBody = [
            'model' => $body['model'],
            // max_tokensは廃止方向・o系モデル非対応のため、現行の推奨パラメータを使う
            'max_completion_tokens' => $maxCompletionTokens,
            'messages' => $messages,
        ];
        if ($isReasoningModel) {
            $requestBody['reasoning_effort'] = 'low';
        }
        if ($this->serviceTier !== null) {
            // 例: 'flex'(低優先度・低コスト。応答が遅延しうる)。実際に使われたティアはレスポンスの
            // service_tierフィールドに入るが、Anthropic互換形式には持ち越していない(呼び出し元は未使用のため)
            $requestBody['service_tier'] = $this->serviceTier;
        }

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new AiBackendException("curl error - {$curlError}");
        }
        if ($httpCode !== 200) {
            throw new AiBackendException("HTTP {$httpCode} - {$response}");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new AiBackendException('invalid JSON response - ' . substr($response, 0, 500));
        }

        $text = (string) ($data['choices'][0]['message']['content'] ?? '');
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'unknown';

        // Anthropic Messages APIレスポンス互換の形に包み直す。これによりClaudeClient側の
        // extractResponseText()/extractJson()は無変更で動く
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $finishReason,
        ];
    }

    // gpt-5系・o系(o1/o3/o4-mini等)は推論トークンを使うモデル。reasoning_effortはこれらにしか
    // 存在しないパラメータで、gpt-4o/gpt-4.1等の非推論モデルに送ると
    // invalid_request_error(Unrecognized request argument)になるため、モデル名で判定する
    private function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(o\d|gpt-5)/i', $model);
    }

    // buildSystemPrompt()が返す [{'type'=>'text','text'=>..,'cache_control'?}, ...] 形式、
    // または通常メソッドが渡す単純な文字列のどちらも受け付け、1本のテキストにまとめる。
    // cache_controlはAnthropicのプロンプトキャッシュ専用の指定なのでここでは無視してよい
    // (OpenAI側は共通接頭辞を自動でキャッシュする仕様のため、コード側の対応は不要)
    private function flattenSystem(string|array $system): string
    {
        if (is_string($system)) {
            return $system;
        }
        $parts = [];
        foreach ($system as $block) {
            if (isset($block['text'])) {
                $parts[] = $block['text'];
            }
        }
        return implode("\n\n", $parts);
    }

    // Anthropicのcontentブロック形式(text/image)をOpenAIの形式に変換する。
    // textブロックは形が互換なのでそのまま、imageブロックだけ変換が必要
    private function convertContent(string|array $content): string|array
    {
        if (is_string($content)) {
            return $content;
        }

        $converted = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'image') {
                $mediaType = $block['source']['media_type'] ?? 'image/jpeg';
                $data = $block['source']['data'] ?? '';
                $converted[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$mediaType};base64,{$data}"],
                ];
                continue;
            }
            // type='text' はAnthropic/OpenAIで形が同じなのでそのまま使う
            $converted[] = $block;
        }
        return $converted;
    }
}
