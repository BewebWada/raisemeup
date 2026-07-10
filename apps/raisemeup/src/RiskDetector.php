<?php
class RiskDetector
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array|null マッチした場合 ['pattern_id' => int, 'risk_level' => string, 'matched_keywords' => array]、なければnull
     */
    public function check(string $userMessage): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT id, keywords, risk_level FROM risk_patterns WHERE is_active = 1"
        );
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bestMatch = null;

        foreach ($patterns as $pattern) {
            $keywords = json_decode($pattern['keywords'], true);
            $matched = [];

            foreach ($keywords as $keyword) {
                if (mb_strpos($userMessage, $keyword) !== false) {
                    $matched[] = $keyword;
                }
            }

            if (!empty($matched)) {
                // 複数パターンにマッチした場合はrisk_levelが高い方を優先
                if ($bestMatch === null || $this->levelRank($pattern['risk_level']) > $this->levelRank($bestMatch['risk_level'])) {
                    $bestMatch = [
                        'pattern_id' => $pattern['id'],
                        'risk_level' => $pattern['risk_level'],
                        'matched_keywords' => $matched,
                    ];
                }
            }
        }

        return $bestMatch;
    }

    private function levelRank(string $level): int
    {
        return ['low' => 1, 'medium' => 2, 'high' => 3][$level] ?? 0;
    }
}
