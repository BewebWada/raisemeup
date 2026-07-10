<?php
class Database
{
    private static array $instances = [];

    // $config = ['host' => ..., 'dbname' => ..., 'user' => ..., 'pass' => ...]
    public static function connect(array $config): PDO
    {
        $key = $config['dbname']; // DB名ごとに接続をキャッシュ
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new PDO(
                "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4",
                $config['user'],
                $config['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return self::$instances[$key];
    }
}
