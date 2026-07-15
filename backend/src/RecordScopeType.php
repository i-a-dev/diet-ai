<?php

declare(strict_types=1);

/**
 * AIチャットが参照する記録の対象期間種別。
 * すべての種別は最終的に startDate / endDate へ解決される。
 */
enum RecordScopeType: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case CURRENT_WEEK = 'current_week';
    case PREVIOUS_WEEK = 'previous_week';
    case RECENT_DAYS = 'recent_days';
    case CURRENT_MONTH = 'current_month';
    case PREVIOUS_MONTH = 'previous_month';
    case DATE_RANGE = 'date_range';
    case UNSPECIFIED = 'unspecified';
}
