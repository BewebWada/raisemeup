<?php
// AI会話生成の低レベル送信口。ClaudeClientの各メソッドが組み立てるリクエストボディ・レスポンスの
// 形はAnthropic Messages APIのものをそのまま「共通言語」として使う(既存の15メソッドが元々この形で
// システムプロンプト・messagesを組み立てているため、変換コストを持つのはOpenAiBackard側だけでよい)。
// AnthropicBackendはこの形をそのままAPIに渡すだけ、OpenAiBackendは変換した上でOpenAIに渡し、
// レスポンスをこの形に包み直して返す。これにより呼び出し元(extractResponseText/extractJson等)は
// プロバイダを意識せず動く。
interface AiBackend
{
    /**
     * @param array $body Anthropic Messages API形式のリクエストボディ。
     *   'model', 'max_tokens', 'system'(string または [{'type','text','cache_control'?}, ...]),
     *   'messages'(role/content。contentはstring または [{'type':'text'|'image', ...}, ...]),
     *   必要に応じて 'tools' を含む。
     * @return array Anthropic Messages APIレスポンス形式に正規化した配列。
     *   最低限 ['content' => [['type' => 'text', 'text' => string], ...], 'stop_reason' => string] を満たす。
     * @throws AiBackendException 通信エラー・非200・プロバイダが対応していない機能(例: OpenAiBackendでのtools)の場合
     */
    public function send(array $body, int $timeoutSeconds): array;
}
