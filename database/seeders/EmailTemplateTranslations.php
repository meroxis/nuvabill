<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;

/**
 * The built-in email templates in every language Nuvabill speaks, from database/seeders/email-templates.
 */
class EmailTemplateTranslations
{
    /**
     * @return array<string, array<string, array{0: string, 1: string}>> Locale => template key => [subject, body].
     */
    public static function all(): array
    {
        $all = [];

        foreach (glob(__DIR__.'/email-templates/*.php') ?: [] as $file) {
            $all[basename($file, '.php')] = require $file;
        }

        return $all;
    }

    /**
     * Add the translations of templates that still have Nuvabill's English text. A template a host
     * has rewritten keeps using their own words until they translate it themselves.
     *
     * @param  list<string>|null  $keys  Only these templates, or all of them.
     */
    public static function install(?array $keys = null): void
    {
        $english = DefaultDataSeeder::templates();
        $shipped = self::all();
        $now = now();
        $rows = [];
        $existing = DB::table('email_template_translations')->get(['email_template_id', 'locale'])
            ->map(fn (object $row): string => $row->email_template_id.':'.$row->locale)->flip();

        foreach (DB::table('email_templates')->get(['id', 'key', 'subject', 'body']) as $template) {
            if (($keys !== null && ! in_array($template->key, $keys, true)) || ! isset($english[$template->key])) {
                continue;
            }

            [, $subject, $body] = $english[$template->key];

            if (self::normalize($template->subject) !== self::normalize($subject) || self::normalize($template->body) !== self::normalize($body)) {
                continue;
            }

            foreach ($shipped as $locale => $translations) {
                if (! isset($translations[$template->key]) || $existing->has($template->id.':'.$locale)) {
                    continue;
                }

                $rows[] = [
                    'email_template_id' => $template->id,
                    'locale' => $locale,
                    'subject' => $translations[$template->key][0],
                    'body' => $translations[$template->key][1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('email_template_translations')->insert($chunk);
        }
    }

    private static function normalize(?string $text): string
    {
        return trim(str_replace("\r\n", "\n", (string) $text));
    }
}
