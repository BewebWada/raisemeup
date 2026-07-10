<?php
// Web申込時にusers/family_accountsへ発行する、LINE連携用の使い切りコード。
class InviteCodeGenerator
{
    // 高齢者本人が手入力することを想定し、見分けにくい文字(0/O, 1/I/L等)を除いた英数字のみ使う
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const LENGTH = 6;

    public static function generate(PDO $pdo, string $table): string
    {
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE invite_code = ?");
        do {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $stmt->execute([$code]);
        } while ($stmt->fetch());

        return $code;
    }
}
