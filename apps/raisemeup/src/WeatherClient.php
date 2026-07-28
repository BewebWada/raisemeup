<?php
// 気象庁(JMA)の防災情報API(bosai、無料・無登録・公式)から天気予報と警報注意報を取得し、
// 会話プロンプトにそのまま差し込める短い日本語の要約にするクライアント。
// 頻繁にJMAへ問い合わせないよう、DB(weather_cacheテーブル)にエリアコード単位でキャッシュする。
class WeatherClient
{
    private PDO $pdo;

    private const CACHE_TTL_MINUTES = 30;

    // 都道府県名 => JMA予報区(office)コード。複数地域に分かれる県は、県庁所在地を含む代表地域を採用している
    // (北海道は石狩・空知・後志地方=札幌市、鹿児島県は奄美地方を除く本土、沖縄県は沖縄本島地方)
    private const PREFECTURE_AREA_CODES = [
        '北海道' => '016000',
        '青森県' => '020000', '岩手県' => '030000', '宮城県' => '040000', '秋田県' => '050000',
        '山形県' => '060000', '福島県' => '070000', '茨城県' => '080000', '栃木県' => '090000',
        '群馬県' => '100000', '埼玉県' => '110000', '千葉県' => '120000', '東京都' => '130000',
        '神奈川県' => '140000', '新潟県' => '150000', '富山県' => '160000', '石川県' => '170000',
        '福井県' => '180000', '山梨県' => '190000', '長野県' => '200000', '岐阜県' => '210000',
        '静岡県' => '220000', '愛知県' => '230000', '三重県' => '240000', '滋賀県' => '250000',
        '京都府' => '260000', '大阪府' => '270000', '兵庫県' => '280000', '奈良県' => '290000',
        '和歌山県' => '300000', '鳥取県' => '310000', '島根県' => '320000', '岡山県' => '330000',
        '広島県' => '340000', '山口県' => '350000', '徳島県' => '360000', '香川県' => '370000',
        '愛媛県' => '380000', '高知県' => '390000', '福岡県' => '400000', '佐賀県' => '410000',
        '長崎県' => '420000', '熊本県' => '430000', '大分県' => '440000', '宮崎県' => '450000',
        '鹿児島県' => '460100',
        '沖縄県' => '471000',
    ];

    // 警報・注意報コード => 表示名。気象庁の防災気象情報コード表に準拠(2026-07時点でJMA実データと照合済み)
    private const WARNING_CODE_NAMES = [
        '02' => '暴風雪警報', '03' => '大雨警報', '04' => '洪水警報', '05' => '暴風警報',
        '06' => '大雪警報', '07' => '波浪警報', '08' => '高潮警報', '09' => '土砂災害警報',
        '10' => '大雨注意報', '12' => '大雪注意報', '13' => '風雪注意報', '14' => '雷注意報',
        '15' => '強風注意報', '16' => '波浪注意報', '17' => '融雪注意報', '18' => '洪水注意報',
        '19' => '高潮注意報', '20' => '濃霧注意報', '21' => '乾燥注意報', '22' => 'なだれ注意報',
        '23' => '低温注意報', '24' => '霜注意報', '25' => '着氷注意報', '26' => '着雪注意報',
        '29' => '土砂災害注意報', '32' => '暴風雪特別警報', '33' => '大雨特別警報',
        '35' => '暴風特別警報', '36' => '大雪特別警報', '37' => '波浪特別警報',
        '38' => '高潮特別警報', '39' => '土砂災害特別警報', '43' => '大雨危険警報',
        '48' => '高潮危険警報', '49' => '土砂災害危険警報',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // 住所文字列(例:「京都府京都市東山区...」)の先頭の都道府県名からJMA予報区コードを判定する。
    // 判定できない場合はnull(呼び出し側は天気情報無しとして扱う)
    public static function resolveAreaCode(string $address): ?string
    {
        foreach (self::PREFECTURE_AREA_CODES as $prefecture => $code) {
            if (str_starts_with($address, $prefecture)) {
                return $code;
            }
        }
        return null;
    }

    /**
     * 指定エリアコードの天気予報・警報注意報を、会話プロンプトにそのまま差し込める短い日本語の要約で返す。
     * 取得・解析に失敗した場合は空文字を返す(呼び出し側は「天気情報無し」として扱えばよい)。
     */
    public function getSummary(string $areaCode): string
    {
        $cached = $this->getFreshCache($areaCode);
        if ($cached !== null) {
            return $cached;
        }

        $summary = $this->fetchAndSummarize($areaCode);
        $this->saveCache($areaCode, $summary);
        return $summary;
    }

    private function getFreshCache(string $areaCode): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT summary FROM weather_cache WHERE area_code = ? AND fetched_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([$areaCode, self::CACHE_TTL_MINUTES]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row['summary'] : null;
    }

    private function saveCache(string $areaCode, string $summary): void
    {
        $this->pdo->prepare(
            'INSERT INTO weather_cache (area_code, summary, fetched_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE summary = VALUES(summary), fetched_at = NOW()'
        )->execute([$areaCode, $summary]);
    }

    private function fetchAndSummarize(string $areaCode): string
    {
        $forecast = $this->fetchJson("https://www.jma.go.jp/bosai/forecast/data/forecast/{$areaCode}.json");
        $warning = $this->fetchJson("https://www.jma.go.jp/bosai/warning/data/warning/{$areaCode}.json");

        $lines = [];
        if (is_array($forecast)) {
            $forecastLine = $this->summarizeForecast($forecast);
            if ($forecastLine !== '') {
                $lines[] = $forecastLine;
            }
        }
        if (is_array($warning)) {
            $warningLine = $this->summarizeWarnings($warning);
            if ($warningLine !== '') {
                $lines[] = $warningLine;
            }
        }

        return implode("\n", $lines);
    }

    private function fetchJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['User-Agent: TAYORI/tayori-net.jp (weather integration; contact via site)'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log("WeatherClient: fetch failed for {$url} (HTTP {$httpCode})");
            return null;
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    // timeSeries[0](今日〜数日先の天気概況)の先頭エリアから、今日・明日の天気文言だけを短くまとめる。
    // 予報区内の代表地域(先頭エリア)のみを見ており、県内の地域差までは反映していない
    private function summarizeForecast(array $forecast): string
    {
        $first = $forecast[0] ?? null;
        if (!is_array($first)) {
            return '';
        }
        $timeSeries = $first['timeSeries'][0] ?? null;
        $area = $timeSeries['areas'][0] ?? null;
        $weathers = $area['weathers'] ?? null;
        if (!is_array($weathers) || empty($weathers)) {
            return '';
        }

        // JMAの表記は全角スペース区切りで冗長なので、単一の半角スペース区切りに整形する
        $clean = fn(string $s): string => trim(preg_replace('/[\x{3000}\s]+/u', ' ', $s));

        $line = '今日の天気: ' . $clean($weathers[0]);
        if (isset($weathers[1])) {
            $line .= ' / 明日の天気: ' . $clean($weathers[1]);
        }
        return $line;
    }

    // 現在有効な(status=解除ではない)警報・注意報だけを、重複を除いて列挙する
    private function summarizeWarnings(array $warning): string
    {
        $active = [];
        foreach ($warning['areaTypes'] ?? [] as $areaType) {
            foreach ($areaType['areas'] ?? [] as $area) {
                foreach ($area['warnings'] ?? [] as $w) {
                    $status = $w['status'] ?? '';
                    $code = $w['code'] ?? '';
                    if ($status === '' || $status === '解除') {
                        continue;
                    }
                    $name = self::WARNING_CODE_NAMES[$code] ?? null;
                    if ($name !== null) {
                        $active[$name] = true;
                    }
                }
            }
        }

        if (empty($active)) {
            return '';
        }
        return '発表中の警報・注意報: ' . implode('、', array_keys($active));
    }
}
