<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    /** Comma-separated station codes whose timetable is synced (overrides KCI_SYNC_STATIONS). */
    public const SYNC_STATIONS = 'kci.sync_stations';

    /** "true"/"false": whether krl-sync (Vercel) may sync on its own schedule (GET /ingest/config). */
    public const INGEST_AUTO_SYNC = 'kci.ingest_auto_sync';

    /** JSON map of KCI operational area id => name, e.g. {"0":"Jabodetabek","6":"Yogyakarta"}. */
    public const OPERATIONAL_AREAS = 'kci.operational_areas';

    /**
     * @return array<int, string>
     */
    public static function operationalAreas(): array
    {
        // Memoized: resources call this for every station row. Reset by put().
        return static::$areas ??= array_map('strval', (array) json_decode(static::value(self::OPERATIONAL_AREAS) ?? '{}', true));
    }

    /** @var array<int, string>|null */
    private static ?array $areas = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Stored value, or $default when the setting was never saved.
     * An explicitly saved empty string is returned as "".
     */
    public static function value(string $key, ?string $default = null): ?string
    {
        $setting = static::find($key);

        return $setting ? (string) $setting->value : $default;
    }

    public static function put(string $key, ?string $value, ?int $userId = null): self
    {
        static::$areas = null;

        return static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
    }
}
