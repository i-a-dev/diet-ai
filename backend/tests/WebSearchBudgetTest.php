<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/WebSearchBudget.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

echo "WebSearchBudget early-stop helpers\n";
echo str_repeat('=', 48) . "\n";

$budget = new WebSearchBudget();
assertTrue($budget->hasHtmlFetchBudgetRemaining(), 'fresh budget has html remaining');

for ($i = 0; $i < WebSearchBudget::MAX_HTML_FETCHES; $i++) {
    assertTrue($budget->canFetchHtml('https://example.com/p/' . $i), 'can fetch #' . $i);
    $budget->recordHtmlFetch('https://example.com/p/' . $i);
}

assertTrue(!$budget->hasHtmlFetchBudgetRemaining(), 'html budget exhausted');
assertTrue(!$budget->canFetchHtml('https://example.com/new'), 'cannot fetch after exhaust');
echo "OK html remaining helper\n";

echo str_repeat('=', 48) . "\n";
echo "All WebSearchBudget helper tests passed\n";
