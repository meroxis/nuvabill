<?php

namespace App\Extensions;

use App\Extensions\Servers\ModuleConfig;
use App\Http\SecretInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The settings form of a gateway, registrar or add-on, as the extension describes it in
 * settingsFields(). A secret field (type "password", or marked "secret") is never filled in again:
 * left empty, the saved value stays, and after a form error it is not kept in the session.
 *
 * An add-on can mark a field "owner": only staff with the Owner role may change it. For everyone
 * else the saved value stays, and a new value is a form error. Since Nuvabill 0.7.3.
 */
final class SettingsForm
{
    /**
     * Check the posted settings[] and give the validated input. On an error the form comes back
     * with what was typed, except the secret fields.
     *
     * @param  array<string, array<string, mixed>>  $fields  The extension's settingsFields().
     * @param  array<string, mixed>  $current  The saved settings.
     * @param  bool  $owner  False when the staff member is not an owner: fields marked "owner" cannot change.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(Request $request, array $fields, array $current, bool $enabled, int $textareaMax = 5000, bool $owner = true): array
    {
        $rules = ['enabled' => ['boolean']];

        foreach ($fields as $key => $field) {
            $locked = ! $owner && self::isOwnerOnly($field);
            // A locked field keeps its saved value, so it only misses when nothing is saved.
            $keepsSaved = filled($current[$key] ?? null) && ($locked || self::isSecret($field));
            $required = ($field['required'] ?? false) && $enabled && ! $keepsSaved;
            $rules["settings.{$key}"] = array_filter([
                $required ? 'required' : 'nullable',
                'string',
                ($field['type'] ?? null) === 'textarea' ? 'max:'.$textareaMax : 'max:1000',
                isset($field['options']) ? 'in:'.implode(',', array_keys($field['options'])) : null,
                $locked ? self::unchanged($field, $current[$key] ?? null) : null,
            ]);
        }

        $labels = collect($fields)->mapWithKeys(fn (array $field, string $key): array => ["settings.{$key}" => $field['label'] ?? $key])->all();
        $validator = Validator::make($request->all(), $rules, [], $labels);

        if ($validator->fails()) {
            throw new ValidationException($validator, $request->expectsJson() ? null : back()
                ->withErrors($validator)
                ->withInput($request->except([...SecretInput::names(), ...self::secretInputKeys($fields)])));
        }

        return $validator->validated();
    }

    /**
     * The settings to save: a secret field left empty keeps its saved value, and so does every
     * field marked "owner" when the staff member is not an owner.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input  The validated input.
     * @param  array<string, mixed>  $current  The saved settings.
     * @return array<string, mixed>
     */
    public static function values(array $fields, array $input, array $current, bool $owner = true): array
    {
        $settings = [];

        foreach ($fields as $key => $field) {
            $value = $input['settings'][$key] ?? null;
            $keepsSaved = (! $owner && self::isOwnerOnly($field)) || (self::isSecret($field) && blank($value));
            $settings[$key] = $keepsSaved ? ($current[$key] ?? null) : $value;
        }

        return $settings;
    }

    /**
     * The request keys of the secret fields, such as "settings.secret_key".
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return list<string>
     */
    public static function secretInputKeys(array $fields, string $prefix = 'settings'): array
    {
        return array_values(array_map(
            fn (int|string $key): string => "{$prefix}.{$key}",
            array_keys(array_filter($fields, fn (mixed $field): bool => is_array($field) && self::isSecret($field))),
        ));
    }

    /**
     * Whether only an owner may change this field (marked "owner").
     *
     * @param  array<string, mixed>  $field
     */
    public static function isOwnerOnly(array $field): bool
    {
        return ($field['owner'] ?? false) === true;
    }

    /**
     * The rule for a field the staff member may not change. A secret takes no value at all, so the
     * form never tells whether a guess matches the saved one; any other field may only come back
     * as it is saved (the form shows it).
     *
     * @param  array<string, mixed>  $field
     */
    private static function unchanged(array $field, mixed $saved): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field, $saved): void {
            if (filled($value) && (self::isSecret($field) || ! is_scalar($value) || (string) $value !== (string) $saved)) {
                $fail(__('Only an owner can change this setting.'));
            }
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function isSecret(array $field): bool
    {
        return ModuleConfig::isSecret($field);
    }
}
