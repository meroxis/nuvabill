<?php

namespace App\Marketplace;

/**
 * The things a package says it may do, in its manifest's "permissions" list. Staff see them in
 * plain words before installing, and reviewers check the code does nothing else.
 *
 * Codes: "client-area" (changes client area pages), "store" (changes store and order pages),
 * "client-page" (adds a client area page), "admin-settings", "admin-page", "events" (reacts to
 * orders, payments and tickets), "head-script" (adds a script to pages), "payments" (takes
 * payments), "servers" (manages accounts on servers), "domains" (registers domains),
 * "database" (adds its own tables), "schedule" (runs on a schedule), "backups" (reads the whole site
 * to copy it) and "http:host.example.com" (connects to that host).
 */
class Permissions
{
    /**
     * @return array{title: string, text: string}
     */
    public static function describe(string $code): array
    {
        if (str_starts_with($code, 'http:')) {
            return ['title' => __('Connects to :host', ['host' => substr($code, 5)]), 'text' => __('Sends and receives data from this address.')];
        }

        return match ($code) {
            'client-area' => ['title' => __('Changes client area pages'), 'text' => __('Replaces how the client area looks.')],
            'store' => ['title' => __('Changes the store and order pages'), 'text' => __('Replaces how clients choose and order products.')],
            'client-page' => ['title' => __('Adds a client area page'), 'text' => __('Clients see a new page or panel.')],
            'admin-settings' => ['title' => __('Adds settings for staff'), 'text' => __('Staff set it up in the admin area.')],
            'admin-page' => ['title' => __('Adds an admin page'), 'text' => __('Staff see a new page in the admin area.')],
            'events' => ['title' => __('Reacts to orders, payments and tickets'), 'text' => __('Runs when these things happen.')],
            'head-script' => ['title' => __('Adds a script to your pages'), 'text' => __('For example a chat window.')],
            'payments' => ['title' => __('Takes payments'), 'text' => __('Clients pay invoices with it.')],
            'servers' => ['title' => __('Manages accounts on your servers'), 'text' => __('Creates, suspends and removes hosting accounts.')],
            'domains' => ['title' => __('Registers domains'), 'text' => __('Registers, renews and transfers domains.')],
            'database' => ['title' => __('Adds its own database tables'), 'text' => __('Keeps its own data.')],
            'schedule' => ['title' => __('Runs on a schedule'), 'text' => __('Does its work at set times through your cron job.')],
            'backups' => ['title' => __('Copies your whole site'), 'text' => __('Reads the database and every file of the site, including the .env settings file, to make backups.')],
            default => ['title' => $code, 'text' => ''],
        };
    }

    /**
     * What a package cannot do, shown next to what it can, for theme-like packages without code
     * that reaches other servers.
     *
     * @param  list<string>  $codes
     * @return list<array{title: string, text: string}>
     */
    public static function limits(array $codes): array
    {
        $limits = [];

        if (! collect($codes)->contains(fn (string $code): bool => str_starts_with($code, 'http:'))) {
            $limits[] = ['title' => __('No outside connections'), 'text' => __('It does not send data to other servers.')];
        }

        if (! in_array('payments', $codes, true)) {
            $limits[] = ['title' => __('No payment details'), 'text' => __('It cannot read cards, keys or passwords.')];
        }

        return $limits;
    }
}
