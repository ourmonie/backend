<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'group', 'key', 'value', 'type', 'label',
        'description', 'options', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Typed lookup for a configured business rule.
     * Returns $default only when the setting has never been configured.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        return static::typecast($setting);
    }

    /** The stored string, interpreted through the setting's declared type. */
    public static function typecast(self $setting): mixed
    {
        return match ($setting->type) {
            'integer' => (int) $setting->value,
            'decimal' => (float) $setting->value,
            'boolean' => in_array($setting->value, ['1', 'true', 'yes'], true),
            'json', 'array' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function put(string $key, mixed $value, string $group = 'general'): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['group' => $group, 'value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value)]
        );
    }
}
