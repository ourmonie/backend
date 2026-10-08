<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardConversionTier extends Model
{
    protected $fillable = [
        'min_points', 'max_points', 'cash_value', 'label', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_points' => 'integer',
            'max_points' => 'integer',
            'cash_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Cash value of an arbitrary point balance under the active brackets.
     *
     * Each tier is a bracket: [min_points .. max_points] and `cash_value` is
     * what `min_points` points are worth inside that bracket, so the rate is
     * cash_value / min_points and it scales linearly. The lowest bracket is
     * counted from zero so that points start accruing value immediately -
     * `min_points` only decides when a member is allowed to convert.
     * The formula lives in data.
     */
    public static function cashValueFor(int $points): float
    {
        if ($points <= 0) {
            return 0.0;
        }

        $value = 0.0;
        $first = true;

        foreach (static::query()->where('is_active', true)->orderBy('min_points')->get() as $tier) {
            $threshold = (int) $tier->min_points;

            if ($threshold <= 0) {
                $first = false;
                continue;
            }

            // The lowest bracket is valued from zero. min_points is the
            // conversion *gate*, not the point at which saving starts paying:
            // charging nothing for the first bracket would make a member
            // sitting exactly on the threshold convertible but worth 0.
            $bracketStart = $first ? 0 : $threshold;
            $bracketEnd = $tier->max_points !== null ? (int) $tier->max_points : PHP_INT_MAX;
            $first = false;

            if ($points <= $bracketStart) {
                continue;
            }

            $inBracket = min($points, $bracketEnd) - $bracketStart;
            if ($inBracket <= 0) {
                continue;
            }

            $value += $inBracket * ((float) $tier->cash_value / $threshold);
        }

        return round($value, 2);
    }

    /**
     * Minimum points before any conversion becomes possible.
     */
    public static function threshold(): int
    {
        return (int) static::query()->where('is_active', true)->min('min_points');
    }
}
