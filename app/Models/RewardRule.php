<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardRule extends Model
{
    protected $fillable = [
        'code', 'name', 'activity', 'trigger_event', 'points',
        'points_type', 'max_points', 'min_qualifying_amount',
        'max_uses_per_member', 'is_active', 'starts_on', 'ends_on',
        'conditions',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'max_points' => 'decimal:2',
            'min_qualifying_amount' => 'decimal:2',
            'conditions' => 'array',
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * Points this rule grants for an optional qualifying amount.
     */
    public function pointsFor(?float $amount = null): float
    {
        if ($this->points_type === 'percent_of_amount' && $amount !== null) {
            $points = $amount * ((float) $this->points / 100);
        } else {
            $points = (float) $this->points;
        }

        if ($this->max_points !== null) {
            $points = min($points, (float) $this->max_points);
        }

        return round($points, 2);
    }
}
