<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class ImprovementAdvisor
{
    /**
     * @param array<string,mixed> $page
     * @param list<array<string,mixed>> $queries
     * @param array<string,mixed> $inspection
     * @return array<string,mixed>
     */
    public function advise(array $page, array $queries, array $inspection = []): array
    {
        $title = trim((string)($inspection['title'] ?? $page['title'] ?? ''));
        $headings = array_values(array_filter(
            (array)($inspection['headings'] ?? []),
            static fn(mixed $heading): bool => is_array($heading) && trim((string)($heading['text'] ?? '')) !== ''
        ));
        $existingText = $title . ' ' . implode(' ', array_map(
            static fn(array $heading): string => (string)$heading['text'],
            $headings
        ));

        $eligible = array_values(array_filter($queries, static function (array $query): bool {
            return (float)($query['current_impressions'] ?? 0) >= 5;
        }));
        usort($eligible, static function (array $a, array $b): int {
            $score = (float)($b['score'] ?? 0) <=> (float)($a['score'] ?? 0);
            return $score !== 0 ? $score : ((float)($b['current_impressions'] ?? 0) <=> (float)($a['current_impressions'] ?? 0));
        });

        $estimatedGain = 0.0;
        foreach ($eligible as $query) {
            $impressions = (float)($query['current_impressions'] ?? 0);
            $ctr = (float)($query['current_ctr'] ?? 0);
            $expectedCtr = (float)($query['expected_ctr'] ?? 0);
            $estimatedGain += max(0.0, $impressions * ($expectedCtr - $ctr));
        }

        $missingQueries = [];
        foreach ($eligible as $query) {
            $queryText = trim((string)($query['query_text'] ?? ''));
            if ($queryText === '' || $this->contains($existingText, $queryText)) {
                continue;
            }
            $missingQueries[] = $query;
        }

        $titleSuggestions = $this->titleSuggestions($title, $eligible);
        $headingSuggestions = [];
        foreach (array_slice($missingQueries, 0, 6) as $query) {
            $headingSuggestions[] = [
                'heading' => $this->headingFor((string)$query['query_text']),
                'query' => (string)$query['query_text'],
                'reason' => sprintf(
                    '表示%s・平均順位%s・CTR%s%%。検索語を明示した節を足す価値があります。',
                    number_format((float)$query['current_impressions']),
                    number_format((float)$query['current_position'], 1),
                    number_format((float)$query['current_ctr'] * 100, 2)
                ),
                'score' => (float)($query['score'] ?? 0),
            ];
        }

        $actions = $this->actions($page, $eligible, $inspection, $estimatedGain);

        return [
            'estimated_gain_clicks' => round($estimatedGain, 1),
            'priority' => $this->priority((float)($page['score'] ?? 0), $estimatedGain),
            'title_suggestions' => $titleSuggestions,
            'heading_suggestions' => $headingSuggestions,
            'actions' => $actions,
            'existing_heading_count' => count($headings),
        ];
    }

    /** @param list<array<string,mixed>> $queries @return list<string> */
    private function titleSuggestions(string $title, array $queries): array
    {
        if ($title === '' || $queries === []) {
            return [];
        }

        $top = trim((string)($queries[0]['query_text'] ?? ''));
        $second = trim((string)($queries[1]['query_text'] ?? ''));
        $suggestions = [];

        if ($top !== '' && !$this->queryCovered($title, $top)) {
            $suggestions[] = $this->limitTitle($this->titlePhrase($top) . '｜' . $title);
        }
        if ($second !== '' && !$this->queryCovered($title, $second)) {
            $suggestions[] = $this->limitTitle($title . '【' . $this->titlePhrase($second) . 'も解説】');
        }
        if ($top !== '') {
            $suffix = $this->titleSuffix($top);
            $suggestions[] = $this->limitTitle($title . '【' . $suffix . '】');
        }

        return array_values(array_unique(array_filter($suggestions, static fn(string $value): bool => $value !== '')));
    }

    /** @param list<array<string,mixed>> $queries @return list<array<string,string>> */
    private function actions(array $page, array $queries, array $inspection, float $estimatedGain): array
    {
        $actions = [];
        $impressions = (float)($page['current_impressions'] ?? 0);
        $ctr = (float)($page['current_ctr'] ?? 0);
        $position = (float)($page['current_position'] ?? 0);

        if ($impressions >= 50 && $estimatedGain >= 2) {
            $actions[] = [
                'priority' => '高',
                'title' => 'タイトルと検索結果スニペットを先に改善',
                'description' => '現状の表示回数なら、検索意図に合う語をタイトル前半へ寄せるだけでもクリック増が狙えます。推定余地は約' . number_format($estimatedGain, 1) . 'クリックです。',
            ];
        }
        if ($position >= 4 && $position <= 10) {
            $actions[] = [
                'priority' => '高',
                'title' => '上位3位へ押し上げる追記',
                'description' => '1ページ目には入っています。上位検索語をH2へ採用し、設定手順・比較表・失敗時の対処を補強する段階です。',
            ];
        } elseif ($position > 10 && $position <= 20) {
            $actions[] = [
                'priority' => '中',
                'title' => '検索意図を満たす独立セクションを追加',
                'description' => '2ページ目前後です。検索語ごとに答えが完結するH2を追加し、関連記事から内部リンクを集めるのが有効です。',
            ];
        }

        $zeroClick = null;
        foreach ($queries as $query) {
            if ((float)($query['current_clicks'] ?? 0) <= 0 && (float)($query['current_impressions'] ?? 0) >= 20) {
                $zeroClick = $query;
                break;
            }
        }
        if ($zeroClick) {
            $actions[] = [
                'priority' => '高',
                'title' => '0クリック検索語を見出し化',
                'description' => '「' . (string)$zeroClick['query_text'] . '」は' . number_format((float)$zeroClick['current_impressions']) . '回表示されています。本文内で同じ言葉を使い、冒頭で結論を返す節を追加します。',
            ];
        }

        $modified = (string)($inspection['modified_at'] ?? '');
        if ($modified !== '' && strtotime($modified) !== false && strtotime($modified) < strtotime('-365 days')) {
            $actions[] = [
                'priority' => '中',
                'title' => '情報の鮮度を点検',
                'description' => 'WordPress上の最終更新から1年以上経過しています。画面名・コマンド・対応バージョン・リンク切れを確認します。',
            ];
        }

        if ($actions === []) {
            $actions[] = [
                'priority' => '低',
                'title' => '現状維持で推移を監視',
                'description' => '明確な弱点は小さめです。検索語別の順位とCTRを次回取り込み後に比較します。',
            ];
        }
        return array_slice($actions, 0, 5);
    }

    private function headingFor(string $query): string
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        $lower = strtolower($query);
        if (str_contains($lower, 'php') && str_contains($lower, 'glob')) {
            return 'PHP glob()の使い方とファイル検索例';
        }
        if (str_contains($query, 'ネットワークアダプタ') && str_contains($query, '高速化')) {
            return 'ネットワークアダプタの設定で通信を高速化する方法';
        }
        if (str_contains($query, 'イーサネット') && str_contains($query, '高速化')) {
            return 'イーサネットのプロパティ設定で通信を高速化する方法';
        }
        if (str_contains($lower, 'windows11') && str_contains($query, 'ネットワーク') && str_contains($query, '高速化')) {
            return 'Windows 11のネットワーク接続を高速化する設定';
        }
        if (str_contains($lower, 'ubuntu') && (str_contains($query, '軽量化') || str_contains($query, '高速化'))) {
            return 'Ubuntuを軽量化・高速化する設定と確認ポイント';
        }
        foreach (['できない', '不安定', 'エラー', 'リセット', '遅い', '文字化け'] as $word) {
            if (str_contains($query, $word)) {
                return $query . 'の原因と対処法';
            }
        }
        if (str_contains($query, 'おすすめ')) {
            return $query . 'を選ぶポイント';
        }
        if (str_contains($query, 'とは') || str_contains($query, '方法') || str_contains($query, '手順')) {
            return $query;
        }
        if (str_contains($query, '設定') || str_contains($query, '高速化') || str_contains($query, '最適化')) {
            return $query . 'の設定手順';
        }
        return $query . 'を詳しく解説';
    }

    private function titlePhrase(string $query): string
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        $lower = strtolower($query);
        if (str_contains($lower, 'php') && str_contains($lower, 'glob')) {
            return 'PHP glob()の使い方と実例';
        }
        if (str_contains($query, 'ネットワークアダプタ') && str_contains($query, '高速化')) {
            return 'ネットワークアダプタ設定で通信を高速化';
        }
        if (str_contains($query, 'イーサネット') && str_contains($query, '高速化')) {
            return 'イーサネット設定で通信を高速化';
        }
        if (str_contains($lower, 'windows11') && str_contains($query, 'ネットワーク')) {
            return 'Windows 11のネットワーク高速化';
        }
        if (str_contains($lower, 'ubuntu') && str_contains($query, '軽量化')) {
            return 'Ubuntu軽量化の設定手順';
        }
        return $query;
    }

    private function queryCovered(string $text, string $query): bool
    {
        if ($this->contains($text, $query)) {
            return true;
        }
        $tokens = preg_split('/[\s　]+/u', trim($query)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => strlen($token) >= 2));
        if (count($tokens) < 2) {
            return false;
        }
        $matched = 0;
        foreach ($tokens as $token) {
            if ($this->contains($text, $token)) {
                $matched++;
            }
        }
        return $matched / count($tokens) >= 0.75;
    }

    private function titleSuffix(string $query): string
    {
        $lower = strtolower($query);
        if (str_contains($lower, 'php') || str_contains($lower, 'glob')) {
            return '使い方・コード例';
        }
        if (str_contains($query, '高速化') || str_contains($query, '最適化')) {
            return '設定手順・注意点';
        }
        if (str_contains($query, 'おすすめ')) {
            return '比較・選び方';
        }
        return '手順・確認ポイント';
    }

    private function priority(float $score, float $gain): string
    {
        if ($score >= 40 || $gain >= 10) {
            return '最優先';
        }
        if ($score >= 15 || $gain >= 4) {
            return '高';
        }
        if ($score >= 5 || $gain >= 1) {
            return '中';
        }
        return '低';
    }

    private function contains(string $haystack, string $needle): bool
    {
        $haystack = $this->normalizeText($haystack);
        $needle = $this->normalizeText($needle);
        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function normalizeText(string $text): string
    {
        $text = strtolower($text);
        return preg_replace('/[\s　\-_|｜:：・「」『』【】()（）]+/u', '', $text) ?? $text;
    }

    private function limitTitle(string $title): string
    {
        $title = trim($title);
        if (function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > 64) {
            return rtrim(mb_substr($title, 0, 61, 'UTF-8')) . '…';
        }
        return $title;
    }
}
