<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Key / value application settings (settings table). Keys are grouped by prefix: "company.name",
 * "mail.host", etc. The whole table is small, so it is read once and cached.
 */
class SettingsStore
{
    private const CACHE_KEY = 'settings.all';

    /** @var array<string, string|null>|null */
    private ?array $loaded = null;

    /**
     * The settings of a group, keyed by name without the prefix (empty values are null).
     *
     * @param list<string> $fields the names of the group
     * @return array<string, string|null>
     */
    public function group(string $prefix, array $fields): array
    {
        $all = $this->all();
        $values = [];
        foreach ($fields as $field) {
            $value = $all["$prefix.$field"] ?? null;
            $values[$field] = ($value === null || $value === '') ? null : $value;
        }
        return $values;
    }

    /**
     * @param array<string, string|null> $values name => value, without the prefix
     */
    public function save(string $prefix, array $values): void
    {
        foreach ($values as $field => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => "$prefix.$field"],
                ['value' => ($value === '' ? null : $value), 'updated_at' => now(), 'created_at' => now()]
            );
        }
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    private function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $this->loaded = $cached;
            }
            $all = DB::table('settings')->pluck('value', 'key')->all();
            Cache::forever(self::CACHE_KEY, $all);
        } catch (Throwable) {
            // The settings or the cache table do not exist yet (before the first migration): this
            // is read at every boot, even by "php artisan migrate", so it must not fail. Nothing is kept.
            return [];
        }
        return $this->loaded = $all;
    }
}
