<?php
// AiBackend::send()が失敗した際にthrowする例外。呼び出し元(ClaudeClient::callApi)は
// これをcatchしてerror_logに落とし、既存の「null復帰→フォールバック応答」という挙動に合流させる。
class AiBackendException extends RuntimeException
{
}
