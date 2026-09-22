<?php

namespace App\Models;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SystemSetting extends BaseModel
{
    protected $fillable = ['key', 'value', 'value_type', 'description'];

    public function audits(): HasMany
    {
        return $this->hasMany(SystemSettingAudit::class);
    }

    public static function valueFor(string $key): string
    {
        return Cache::remember("system-settings.{$key}", now()->addMinutes(15), function () use ($key): string {
            $value = static::query()->where('key', $key)->value('value');
            if (! is_string($value)) {
                throw new RuntimeException("System setting [{$key}] is not configured.");
            }

            return $value;
        });
    }

    public function changeValue(string $value, ?User $user = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($value, $user, $reason): void {
            $setting = static::query()->lockForUpdate()->findOrFail($this->id);
            if ($setting->value === $value) {
                return;
            }

            SystemSettingAudit::query()->create([
                'system_setting_id' => $setting->id,
                'changed_by_user_id' => $user?->id,
                'previous_value' => $setting->value,
                'new_value' => $value,
                'reason' => $reason,
            ]);
            $setting->update(['value' => $value]);
            Cache::forget("system-settings.{$setting->key}");
        });
    }
}
