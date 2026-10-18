<?php

namespace App\Modules\Administration\Services;

use App\Modules\Administration\Models\PlatformSetting;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Operator-adjustable settings. Only keys on the allow-list below can be
 * stored, each with a type and bounds. Drivers, secrets and anything that
 * enables real money movement are deliberately NOT here: they are environment
 * configuration, changed through deployment.
 *
 * Overrides are applied over config() when the application boots. Long-running
 * workers and the scheduler read them at start-up, so they need a restart to
 * pick up a change (documented in docs/deployment.md).
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings.overrides';

    /**
     * `tier` system = financial or access-control sensitive: super admin only.
     *
     * @var array<string, array{config: string, type: string, min?: int, max?: int, tier: string, description: string}>
     */
    public const DEFINITIONS = [
        'scheduling.payment_window_minutes' => ['config' => 'medconnect.scheduling.payment_window_minutes', 'type' => 'int', 'min' => 5, 'max' => 120, 'tier' => 'admin', 'description' => 'Minutes a booking holds its slot while the patient pays.'],
        'scheduling.no_show_grace_minutes' => ['config' => 'medconnect.scheduling.no_show_grace_minutes', 'type' => 'int', 'min' => 0, 'max' => 120, 'tier' => 'admin', 'description' => 'Minutes after the start time before an appointment can be marked no-show.'],
        'scheduling.max_active_appointments_per_patient' => ['config' => 'medconnect.scheduling.max_active_appointments_per_patient', 'type' => 'int', 'min' => 1, 'max' => 20, 'tier' => 'admin', 'description' => 'Maximum concurrent active appointments per patient.'],
        'consultations.follow_up_messaging_hours' => ['config' => 'medconnect.consultations.follow_up_messaging_hours', 'type' => 'int', 'min' => 0, 'max' => 168, 'tier' => 'admin', 'description' => 'Hours participants may keep messaging after a consultation.'],
        'consultations.care_access_days' => ['config' => 'medconnect.consultations.care_access_days', 'type' => 'int', 'min' => 1, 'max' => 365, 'tier' => 'system', 'description' => 'Days a doctor keeps read access to the record after a consultation.'],
        'reviews.require_moderation' => ['config' => 'medconnect.reviews.require_moderation', 'type' => 'bool', 'tier' => 'admin', 'description' => 'Hold reviews for moderation before publishing.'],
        'reviews.window_days' => ['config' => 'medconnect.reviews.window_days', 'type' => 'int', 'min' => 1, 'max' => 90, 'tier' => 'admin', 'description' => 'Days after completion within which a consultation can be reviewed.'],
        'payments.commission_bps' => ['config' => 'medconnect.payments.commission_bps', 'type' => 'int', 'min' => 0, 'max' => 5000, 'tier' => 'system', 'description' => 'Platform commission in basis points, applied to new payments only.'],
    ];

    /** @var array<string, mixed> */
    private static array $defaults = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Apply stored overrides over config(). Safe to call before migrations ran.
     */
    public static function applyOverrides(): void
    {
        foreach (self::DEFINITIONS as $key => $definition) {
            self::$defaults[$key] ??= config($definition['config']);
            config([$definition['config'] => self::$defaults[$key]]);
        }

        try {
            $overrides = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::query()->pluck('value', 'key')->all());
        } catch (Throwable) {
            return; // database not ready (fresh install / migrating): defaults apply
        }

        foreach ($overrides as $key => $value) {
            if (isset(self::DEFINITIONS[$key])) {
                config([self::DEFINITIONS[$key]['config'] => $value]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(User $viewer): array
    {
        $stored = PlatformSetting::query()->get()->keyBy('key');

        return collect(self::DEFINITIONS)->map(fn (array $d, string $key) => [
            'key' => $key,
            'description' => $d['description'],
            'type' => $d['type'],
            'min' => $d['min'] ?? null,
            'max' => $d['max'] ?? null,
            'tier' => $d['tier'],
            'editable' => $this->canEdit($viewer, $d),
            'default' => self::$defaults[$key] ?? config($d['config']),
            'value' => $stored->has($key) ? $stored[$key]->value : (self::$defaults[$key] ?? config($d['config'])),
            'overridden' => $stored->has($key),
            'updated_at' => $stored->get($key)?->updated_at?->toIso8601ZuluString(),
        ])->values()->all();
    }

    public function set(string $key, mixed $value, User $actor): void
    {
        $definition = $this->definition($key, $actor);
        $value = $this->coerce($definition, $value);
        $old = PlatformSetting::find($key)?->value ?? (self::$defaults[$key] ?? config($definition['config']));

        PlatformSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by_user_id' => $actor->id]);
        $this->refresh();

        $this->audit->record('settings.updated', null, ['actor' => $actor, 'changes' => [$key => ['old' => $old, 'new' => $value]]]);
    }

    public function reset(string $key, User $actor): void
    {
        $definition = $this->definition($key, $actor);
        $old = PlatformSetting::find($key)?->value;

        PlatformSetting::whereKey($key)->delete();
        $this->refresh();

        $this->audit->record('settings.reset', null, ['actor' => $actor, 'changes' => [$key => ['old' => $old, 'new' => self::$defaults[$key] ?? config($definition['config'])]]]);
    }

    private function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::applyOverrides();
    }

    /** @return array<string, mixed> */
    private function definition(string $key, User $actor): array
    {
        $definition = self::DEFINITIONS[$key] ?? throw ApiException::make('unknown_setting', 'This setting does not exist or cannot be changed here.', 404);

        if (! $this->canEdit($actor, $definition)) {
            throw ApiException::forbidden('This setting can only be changed by a super administrator.');
        }

        return $definition;
    }

    /** @param array<string, mixed> $definition */
    private function canEdit(User $actor, array $definition): bool
    {
        return $definition['tier'] !== 'system' || $actor->hasRole(RoleName::SuperAdmin->value);
    }

    /** @param array<string, mixed> $definition */
    private function coerce(array $definition, mixed $value): int|bool
    {
        if ($definition['type'] === 'bool') {
            return is_bool($value) ? $value : throw ApiException::make('invalid_setting_value', 'A true/false value is required.');
        }

        if (! is_int($value) || $value < $definition['min'] || $value > $definition['max']) {
            throw ApiException::make('invalid_setting_value', "An integer between {$definition['min']} and {$definition['max']} is required.");
        }

        return $value;
    }
}
