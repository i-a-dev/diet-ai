<?php

declare(strict_types=1);

final class MockRepository
{
    /**
     * @return array<string, mixed>
     */
    public function getDailyRecord(): array
    {
        return [
            'date' => '今日 6/14 (日)',
            'weight' => [
                'current' => 62.4,
                'diffFromPreviousDay' => -0.2,
            ],
            'meals' => [
                ['id' => 'breakfast', 'name' => '朝ごはん', 'calories' => 412],
                ['id' => 'lunch', 'name' => '昼ごはん', 'calories' => 618],
                ['id' => 'dinner', 'name' => '夜ごはん', 'calories' => 552],
                ['id' => 'snack', 'name' => '間食・おやつ', 'calories' => 0],
            ],
            'steps' => [
                'count' => 5842,
                'burnedCalories' => 231,
            ],
            'sleep' => [
                'durationMinutes' => 380,
            ],
            'memo' => '今日はケーキを食べちゃった',
            'calorieGoal' => 1800,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWeeklyReport(): array
    {
        return [
            'rangeLabel' => '6/8 (月) 〜 6/14 (日)',
            'weight' => [
                'points' => [
                    ['label' => '6/8', 'value' => 63.0],
                    ['label' => '6/9', 'value' => 62.9],
                    ['label' => '6/10', 'value' => 63.1],
                    ['label' => '6/11', 'value' => 62.8],
                    ['label' => '6/12', 'value' => 62.7],
                    ['label' => '6/13', 'value' => 62.5],
                    ['label' => '6/14', 'value' => 62.4],
                ],
                'weeklyAverage' => 62.8,
                'weeklyDiff' => -0.8,
                'targetDiff' => -5.4,
            ],
            'calories' => [
                'points' => [
                    ['label' => '6/8', 'value' => 1800],
                    ['label' => '6/9', 'value' => 2100],
                    ['label' => '6/10', 'value' => 1600],
                    ['label' => '6/11', 'value' => 1950],
                    ['label' => '6/12', 'value' => 1500],
                    ['label' => '6/13', 'value' => 1880],
                    ['label' => '6/14', 'value' => 2020],
                ],
                'average' => 1836,
                'target' => 1800,
                'achievementRate' => 94,
            ],
            'steps' => [
                'points' => [
                    ['label' => '6/8', 'value' => 6400],
                    ['label' => '6/9', 'value' => 7100],
                    ['label' => '6/10', 'value' => 5200],
                    ['label' => '6/11', 'value' => 4800],
                    ['label' => '6/12', 'value' => 8600],
                    ['label' => '6/13', 'value' => 6100],
                    ['label' => '6/14', 'value' => 5300],
                ],
                'average' => 6215,
                'target' => 8000,
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getChatMessages(): array
    {
        $today = date('Y-m-d');

        return [
            [
                'id' => 'm1',
                'role' => 'user',
                'text' => 'ケーキ食べちゃった。目標達成するにはどうしたらいい？',
                'sentAt' => $today . 'T10:31:00+09:00',
            ],
            [
                'id' => 'm2',
                'role' => 'assistant',
                'text' => '大丈夫です。今回のケーキは約350kcalなので、今日の消費で調整しましょう。',
                'sentAt' => $today . 'T10:32:00+09:00',
            ],
            [
                'id' => 'm3',
                'role' => 'assistant',
                'text' => 'ウォーキング90分、ベビーカー散歩80分、自転車40分のどれかがおすすめです。',
                'sentAt' => $today . 'T10:33:00+09:00',
            ],
        ];
    }
}
