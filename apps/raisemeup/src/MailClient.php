<?php
// LineClient/StripeClientと同じ方針(SDK非導入)で書いた最小限のSMTPクライアント。
// Google WorkspaceのSMTP(smtp.gmail.com:587, STARTTLS, AUTH LOGIN)を主な用途として想定しているが、
// 同方式のSMTPサーバーであれば流用できる。
class MailClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    public function __construct(string $host, int $port, string $username, string $password, string $fromEmail, string $fromName)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    // SMTP_HOST未設定の環境(ローカル開発等)では、呼び出し側が null チェック1つでメール送信自体を
    // スキップできるようにする(check_subscriptions.phpで最初に使われた方針を他の呼び出し元にも共通化)
    public static function fromConfig(): ?self
    {
        if (Config::get('SMTP_HOST', '') === '') {
            return null;
        }
        return new self(
            Config::get('SMTP_HOST', ''),
            (int) Config::get('SMTP_PORT', '587'),
            Config::get('SMTP_USERNAME', ''),
            Config::get('SMTP_PASSWORD', ''),
            Config::get('SMTP_USERNAME', ''),
            Config::get('SMTP_FROM_NAME', 'TAYORI')
        );
    }

    public function send(string $toEmail, string $subject, string $body): void
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if ($socket === false) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, 10);

        try {
            // EHLOの引数はクライアント側のホスト名を名乗る規約だが、多くのSMTPサーバーは厳密検証しないため
            // 送信元メールアドレスのドメイン部分を流用する(自前のホスト名を別途持つ必要がなく単純)
            $ehloHost = substr($this->fromEmail, strpos($this->fromEmail, '@') + 1) ?: 'localhost';

            $this->expect($socket, 220);
            $this->command($socket, "EHLO {$ehloHost}", 250);
            $this->command($socket, 'STARTTLS', 220);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed');
            }

            // STARTTLS後はTLS化されたコネクション上で再度EHLOする必要がある(SMTP仕様)
            $this->command($socket, "EHLO {$ehloHost}", 250);
            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($this->username), 334);
            $this->command($socket, base64_encode($this->password), 235);

            $this->command($socket, "MAIL FROM:<{$this->fromEmail}>", 250);
            $this->command($socket, "RCPT TO:<{$toEmail}>", 250);
            $this->command($socket, 'DATA', 354);

            $headers = [
                'From: ' . $this->encodeHeader($this->fromName) . " <{$this->fromEmail}>",
                "To: <{$toEmail}>",
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body)) . '.';
            $this->command($socket, $data, 250);

            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** @param resource $socket */
    private function command($socket, string $command, int $expectedCode): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    /** @param resource $socket */
    private function expect($socket, int $expectedCode): string
    {
        $response = '';
        while (($line = fgets($socket, 512)) !== false) {
            $response .= $line;
            // マルチライン応答は各行が"250-"のようにハイフンで続き、最終行だけ"250 "(スペース)になる
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("Unexpected SMTP response (expected {$expectedCode}): {$response}");
        }
        return $response;
    }
}
