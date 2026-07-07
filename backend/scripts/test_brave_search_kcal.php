<?php

declare(strict_types=1);

require_once __DIR__ . '/EnvLoader.php';
require_once __DIR__ . '/BraveSearchClient.php';
require_once __DIR__ . '/NutritionKcalProbe.php';

load_project_env();

/**
 * @return array{
 *   search: array{ok: bool, http_code: int, error: string|null, urls: list<string>, results: list<array{title: string, url: string, description: string}>},
 *   probe: array{attempts: list<array<string, mixed>>, best: array{kcal: int, url: string, score: int}|null},
 *   used_query: string,
 *   tried_queries: list<string>,
 *   merged_results: list<array{title: string, url: string, description: string}>
 * }
 */
function runSearchAndProbe(
    BraveSearchClient $brave,
    NutritionKcalProbe $probe,
    string $query,
): array {
    $triedQueries = [];
    $queriesToTry = array_values(array_unique(array_merge(
        [$query],
        $brave->buildFallbackQueries($query),
    )));

    /** @var array<string, array{title: string, url: string, description: string}> $mergedResults */
    $mergedResults = [];
    $mergedUrls = [];
    $lastSearch = [
        'ok' => false,
        'http_code' => 0,
        'error' => '検索未実行',
        'urls' => [],
        'results' => [],
    ];
    $usedQuery = $query;
    $bestProbe = [
        'attempts' => [],
        'best' => null,
    ];

    foreach ($queriesToTry as $candidateQuery) {
        $triedQueries[] = $candidateQuery;
        $search = $brave->search($candidateQuery, 10);
        $lastSearch = $search;

        if (!$search['ok']) {
            continue;
        }

        $usedQuery = $candidateQuery;

        foreach ($search['results'] as $result) {
            $url = $result['url'];
            if (!isset($mergedResults[$url])) {
                $mergedResults[$url] = $result;
                $mergedUrls[] = $url;
            }
        }

        $rankedUrls = $probe->rankUrls($mergedUrls, [
            'query' => $query,
            'results' => array_values($mergedResults),
        ]);
        $probeResult = $probe->probeUrls($rankedUrls, ['query' => $query]);
        $bestProbe = $probeResult;

        if ($probeResult['best'] !== null) {
            break;
        }
    }

    return [
        'search' => $lastSearch,
        'probe' => $bestProbe,
        'used_query' => $usedQuery,
        'tried_queries' => $triedQueries,
        'merged_results' => array_values($mergedResults),
    ];
}

$queries = [
    'じゃがりこ じゃがバター 栄養成分 エネルギー kcal',
    'セブン 金のハンバーグ 栄養成分 エネルギー kcal',
    'ローソン からあげクン レッド カロリー',
    'ファミマ 生コッペパン たまご カロリー',
    '無印 バターチキンカレー 栄養成分 kcal',
    '午後の紅茶 ミルクティー 500ml カロリー',
    '明治 エッセル スーパーカップ バニラ カロリー',
    '日清 カップヌードル シーフード 栄養成分 kcal',
    'ブルダック炒め麺 カロリー',
    'コストコ ハイローラー カロリー',
];

$batch2Queries = [
    'カルビー 堅あげポテト ブラックペッパー 栄養成分 エネルギー kcal',
    '森永 inゼリー エネルギー マスカット カロリー',
    'セブンイレブン ななチキ 栄養成分 エネルギー kcal',
    'ローソン プレミアムロールケーキ カロリー',
    'ファミリーマート ファミチキ 栄養成分 エネルギー kcal',
    'ニチレイ 本格炒め炒飯 栄養成分 kcal',
    '味の素 ザ★シュウマイ カロリー',
    'スターバックス 抹茶クリームフラペチーノ トール カロリー',
    'サーティワン ポッピングシャワー レギュラー カロリー',
    '韓国 農心 辛ラーメン 袋麺 栄養成分 kcal',
];

$onlyArg = $argv[1] ?? null;
if ($onlyArg === 'focus') {
    $queries = array_slice($queries, 5, 4);
    echo "フォーカスモード: ケース 6-9 のみ実行\n";
} elseif ($onlyArg === 'batch2') {
    $queries = $batch2Queries;
    echo "バッチ2: 新規10商品テスト\n";
}

$apiKey = trim((string) (getenv('BRAVE_SEARCH_API_KEY') ?: ($_ENV['BRAVE_SEARCH_API_KEY'] ?? '')));

if ($apiKey === '') {
    fwrite(STDERR, "ERROR: .env に BRAVE_SEARCH_API_KEY を設定してください。\n");
    fwrite(STDERR, "例: BRAVE_SEARCH_API_KEY=your_api_key_here\n");
    exit(1);
}

$brave = new BraveSearchClient($apiKey);
$probe = new NutritionKcalProbe();

$summaryRows = [];
$successCount = 0;

echo "Brave Search API + HTML kcal 抽出テスト\n";
echo str_repeat('=', 72) . "\n\n";

foreach ($queries as $index => $query) {
    $number = $index + 1;
    echo "[{$number}/" . count($queries) . "] クエリ: {$query}\n";

    $run = runSearchAndProbe($brave, $probe, $query);
    $search = $run['search'];
    $probeResult = $run['probe'];

    if (!$search['ok'] && $probeResult['best'] === null) {
        echo "  Brave Search: NG (HTTP {$search['http_code']}) {$search['error']}\n\n";
        $summaryRows[] = [
            'query' => $query,
            'brave_ok' => false,
            'result_count' => 0,
            'kcal' => null,
            'url' => null,
            'note' => $search['error'] ?? 'search failed',
        ];
        continue;
    }

    echo "  Brave Search: OK / 使用クエリ: {$run['used_query']}\n";
    if (count($run['tried_queries']) > 1) {
        echo "  試行クエリ数: " . count($run['tried_queries']) . "\n";
    }

    foreach (array_slice($run['merged_results'], 0, 5) as $resultIndex => $result) {
        $rank = $resultIndex + 1;
        echo "    [{$rank}] {$result['title']}\n";
        echo "        {$result['url']}\n";
    }

    echo "  HTML 抽出試行:\n";

    foreach ($probeResult['attempts'] as $attempt) {
        if ($attempt['fetch'] === 'skipped_fetch_limit') {
            continue;
        }

        $kcalText = $attempt['kcal'] === null ? '-' : (string) $attempt['kcal'];
        $noteText = isset($attempt['note']) ? " / {$attempt['note']}" : '';
        echo "    - [score {$attempt['score']}] {$attempt['fetch']} / kcal={$kcalText}{$noteText}\n";
        echo "      {$attempt['url']}\n";
    }

    if ($probeResult['best'] !== null) {
        $successCount++;
        $best = $probeResult['best'];
        echo "  => 成功: {$best['kcal']} kcal (combined score {$best['score']})\n";
        echo "     {$best['url']}\n\n";
        $summaryRows[] = [
            'query' => $query,
            'brave_ok' => true,
            'result_count' => count($run['merged_results']),
            'kcal' => $best['kcal'],
            'url' => $best['url'],
            'note' => 'ok',
        ];
        continue;
    }

    echo "  => 失敗: kcal を含む URL を取得できませんでした\n\n";
    $summaryRows[] = [
        'query' => $query,
        'brave_ok' => true,
        'result_count' => count($run['merged_results']),
        'kcal' => null,
        'url' => null,
        'note' => 'no kcal in fetched pages',
    ];
}

echo str_repeat('=', 72) . "\n";
echo "サマリー\n";
echo str_repeat('-', 72) . "\n";
printf("%-4s %-8s %-6s %-8s %s\n", 'No', 'Brave', '件数', 'kcal', 'クエリ');
echo str_repeat('-', 72) . "\n";

foreach ($summaryRows as $index => $row) {
    $number = $index + 1;
    $braveLabel = $row['brave_ok'] ? 'OK' : 'NG';
    $kcalLabel = $row['kcal'] === null ? '-' : (string) $row['kcal'];
    $shortQuery = mb_strlen($row['query']) > 28
        ? mb_substr($row['query'], 0, 28) . '...'
        : $row['query'];
    printf(
        "%-4d %-8s %-6d %-8s %s\n",
        $number,
        $braveLabel,
        $row['result_count'],
        $kcalLabel,
        $shortQuery,
    );
}

echo str_repeat('-', 72) . "\n";
echo "kcal 取得成功: {$successCount}/" . count($queries) . "\n";

exit($successCount === count($queries) ? 0 : 1);
