<?php

namespace App\Modules\Leave\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = [
        'name',
        'color',
        'days_per_year',
        'tiers',
        'gender_restriction',
        'count_calendar_days',
        'requires_attachment',
        'is_active',
    ];

    protected $casts = [
        'days_per_year' => 'float',
        'tiers' => 'array',
        'count_calendar_days' => 'boolean',
        'requires_attachment' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** Colours given to the types that have none, by order of creation */
    public const FALLBACK_COLORS = ['#2563eb', '#dc2626', '#9333ea', '#db2777', '#0891b2', '#475569', '#d97706', '#16a34a'];

    /** Colour of a leave type name that does not exist (deleted type) */
    public const UNKNOWN_COLOR = '#64748b';

    /**
     * Does this leave type (by name) require a document, such as a medical certificate?
     */
    public static function requiresEvidence(?string $name): bool
    {
        return filled($name) && (bool) static::where('name', $name)->value('requires_attachment');
    }

    /**
     * Colour displayed in the calendar: the chosen one, or one of the fallback colours.
     */
    public function displayColor(): string
    {
        return $this->color ?: self::FALLBACK_COLORS[($this->id - 1) % count(self::FALLBACK_COLORS)];
    }

    /**
     * Black or white, whichever is readable on a background colour.
     */
    public static function textColorFor(string $background): string
    {
        $hex = ltrim($background, '#');
        if (strlen($hex) < 6) {
            return '#ffffff';
        }
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        // Perceived brightness (ITU-R BT.601)
        return (($r * 299 + $g * 587 + $b * 114) / 1000) > 150 ? '#111827' : '#ffffff';
    }
}
