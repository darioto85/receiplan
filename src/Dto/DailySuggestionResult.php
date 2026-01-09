<?php

namespace App\Dto;

use App\Entity\DailyMealSuggestion;

final class DailySuggestionResult
{
    public function __construct(
        public readonly DailyMealSuggestion $suggestion,
        public readonly bool $created,
    ) {}
}
