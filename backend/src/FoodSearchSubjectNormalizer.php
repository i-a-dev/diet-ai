<?php

declare(strict_types=1);

/**
 * ユーザー入力を FoodSearchSubject へ正規化する入口。
 * ブランド解決は OfficialSiteBrandResolver に委譲し、重複実装しない。
 */
final class FoodSearchSubjectNormalizer
{
    public function __construct(
        private readonly OfficialSiteBrandResolver $officialSiteBrandResolver = new OfficialSiteBrandResolver(),
        private readonly NutritionSearchQueryBuilder $queryBuilder = new NutritionSearchQueryBuilder(),
    ) {
    }

    public function normalize(string $userInput): FoodSearchSubject
    {
        $rawInput = $this->normalizeWhitespace($userInput);
        if ($rawInput === '') {
            return new FoodSearchSubject(
                rawInput: '',
                brandName: null,
                productName: '',
                productTokens: [],
            );
        }

        $brandName = $this->officialSiteBrandResolver->detectBrandFromInput($rawInput);
        $productName = $this->stripBrandFromInput($rawInput, $brandName);
        if ($productName === '') {
            $productName = $rawInput;
        }

        $productTokens = $this->queryBuilder->extractCoreSearchTokens($productName, $brandName);

        return new FoodSearchSubject(
            rawInput: $rawInput,
            brandName: $brandName,
            productName: $productName,
            productTokens: $productTokens,
        );
    }

    private function normalizeWhitespace(string $value): string
    {
        $value = trim($value);
        $value = (string) preg_replace('/\s*[|｜].*$/u', '', $value);
        $value = (string) preg_replace('/[　\s]+/u', ' ', $value);

        return trim($value);
    }

    private function stripBrandFromInput(string $rawInput, ?string $brandName): string
    {
        $value = $rawInput;
        if ($brandName === null || trim($brandName) === '') {
            return $value;
        }

        $aliases = $this->officialSiteBrandResolver->brandAliases($brandName);
        // 長いエイリアスから除去（マクドナルド > マック）
        usort($aliases, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias === '') {
                continue;
            }
            if (mb_stripos($value, $alias) === 0) {
                $value = trim(mb_substr($value, mb_strlen($alias)));
                break;
            }
            $value = trim((string) preg_replace(
                '/' . preg_quote($alias, '/') . '/iu',
                ' ',
                $value,
            ));
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value;
    }
}
