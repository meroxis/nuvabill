<?php

namespace App\Support;

use App\Models\Admin;
use Illuminate\Http\Request;

/**
 * The Settings home: every settings page in its group, with an icon, a short line for the home
 * page, a longer description for the page header and extra words the "Find a setting" box
 * matches. Staff only see the pages their role allows.
 */
class SettingsMenu
{
    /**
     * Groups shown together in one card on the Settings home, so the cards line up in rows.
     *
     * @var list<list<string>>
     */
    private const CARDS = [['business'], ['payments', 'security'], ['messages'], ['growth'], ['team'], ['system']];

    /**
     * @return list<array{key: string, label: string, items: list<array{key: string, label: string, url: string, match: list<string>, icon: string, summary: string, description: string, keywords: string}>}>
     */
    public static function groups(Admin $admin): array
    {
        $settings = $admin->hasPermission('settings.manage');
        $staff = $admin->hasPermission('staff.manage');

        $groups = [
            ['business', __('Business'), $settings, [
                self::item('general', __('General'), route('admin.settings.edit'), ['admin.settings.edit'], 'building',
                    __('Company, billing and look'),
                    __('Company details, billing, the nightly automation, sending email and the look of your client area.'),
                    'company name address phone invoice prefix currency cron automation reminders suspend terminate smtp mail brand color theme language terms privacy'),
                self::item('currencies', __('Currencies'), route('admin.settings.currencies.edit'), ['admin.settings.currencies.*'], 'coins',
                    __('More currencies and exchange rates'),
                    __('Sell in more currencies, with the exchange rates you set.'), 'exchange rate dinar iqd dollar euro'),
                self::item('taxes', __('Taxes'), route('admin.settings.taxes.index'), ['admin.settings.taxes.*'], 'percent',
                    __('VAT, GST or sales tax by country'),
                    __('VAT, GST or sales tax rules by country and state.'), 'vat gst sales tax exempt tax id'),
                self::item('domains', __('Domains'), route('admin.settings.tlds.index'), ['admin.settings.tlds.*'], 'globe',
                    __('Domain endings and prices'),
                    __('The domain endings you sell, their prices and the registrar for each.'), 'tld registrar com net whois epp transfer renew'),
            ]],
            ['payments', __('Payments'), $settings, [
                self::item('autopay', __('Automatic payments'), route('admin.settings.autopay.edit'), ['admin.settings.autopay.*'], 'card',
                    __('Saved cards pay renewals'),
                    __('Charge saved cards and PayPal accounts for renewals.'), 'autopay saved card retry stripe paypal renewal'),
                self::item('gateways', __('Payment gateways'), route('admin.extensions.index', ['tab' => 'gateways']), [], 'list',
                    __('Stripe, PayPal, Iraqi payments'),
                    __('Stripe, PayPal, bank transfer and Iraqi payments.'), 'stripe paypal bank transfer fib fastpay wayl'),
            ]],
            ['messages', __('Messages'), $settings, [
                self::item('email-templates', __('Email templates'), route('admin.settings.email-templates.index'), ['admin.settings.email-templates.*'], 'mail',
                    __('The emails clients and staff get'),
                    __('The emails clients and staff get, in your own words.'), 'email template welcome invoice reminder'),
                self::item('chat', __('Chat apps'), route('admin.settings.chat.edit'), ['admin.settings.chat.*'], 'message',
                    __('Telegram and WhatsApp'),
                    __('Invoices, reminders and ticket replies on Telegram and WhatsApp.'), 'telegram whatsapp meta qr bot'),
                self::item('departments', __('Support departments'), route('admin.settings.departments.index'), ['admin.settings.departments.*'], 'inbox',
                    __('Where tickets go'),
                    __('Clients pick one when they open a ticket.'), 'ticket department sales billing support alert email'),
            ]],
            ['security', __('Sign-in and security'), $settings, [
                self::item('security', __('Security'), route('admin.settings.security.edit'), ['admin.settings.security.*'], 'shield',
                    __('Two-factor sign-in and CAPTCHA'),
                    __('Two-factor sign-in and CAPTCHA for sign-in, sign-up and checkout.'), '2fa two-factor captcha turnstile recaptcha hcaptcha'),
                self::item('social', __('Social login'), route('admin.settings.social.edit'), ['admin.settings.social.*'], 'login',
                    __('Google, GitHub or Facebook'),
                    __('Let clients sign in with Google, GitHub or Facebook.'), 'google github facebook oauth'),
            ]],
            ['growth', __('Growth'), $settings, [
                self::item('seo', __('Search engines'), route('admin.settings.seo.edit'), ['admin.settings.seo.*'], 'search',
                    __('Titles, descriptions, sitemap'),
                    __('Titles and descriptions, the sitemap, link previews and Google verification.'), 'seo google bing sitemap robots title description redirect'),
                self::item('ai', __('AI help'), route('admin.settings.ai.edit'), ['admin.settings.ai.*'], 'sparkles',
                    __('Reply drafts with your own key'),
                    __('Ticket reply drafts, translation and summaries with your own Claude key.'), 'ai claude anthropic translate'),
            ]],
            ['team', __('Team'), $staff, [
                self::item('staff', __('Staff'), route('admin.settings.staff.index'), ['admin.settings.staff.*'], 'users',
                    __('Your team\'s accounts'),
                    __('Your team\'s accounts and their roles.'), 'admin staff account user'),
                self::item('roles', __('Roles'), route('admin.settings.roles.index'), ['admin.settings.roles.*'], 'key',
                    __('What each role can do'),
                    __('What each role can see and do.'), 'role permission access'),
            ]],
            ['system', __('System'), $settings, [
                self::item('license', __('License'), route('admin.settings.license.edit'), ['admin.settings.license.*'], 'award',
                    __('White-label License'),
                    __('Your White-label License, which removes the Nuvabill credit.'), 'white label branding powered by'),
                self::item('import', __('Import'), route('admin.settings.import.index'), ['admin.settings.import.*'], 'download',
                    __('Move from WHMCS and others'),
                    __('Move clients, services and invoices from WHMCS, Blesta, FOSSBilling or Paymenter.'), 'whmcs blesta fossbilling paymenter migrate'),
                self::item('activity', __('Activity log'), route('admin.settings.activity'), ['admin.settings.activity'], 'clock',
                    __('Who did what, and when'),
                    __('Who did what, and when.'), 'log history audit'),
            ]],
        ];

        $visible = [];

        foreach ($groups as [$key, $label, $allowed, $items]) {
            if ($allowed) {
                $visible[] = ['key' => $key, 'label' => $label, 'items' => $items];
            }
        }

        return $visible;
    }

    /**
     * The groups this person may see, as the cards of the Settings home.
     *
     * @return list<list<array{key: string, label: string, items: list<array<string, mixed>>}>>
     */
    public static function cards(Admin $admin): array
    {
        $groups = collect(self::groups($admin))->keyBy('key');
        $cards = [];

        foreach (self::CARDS as $keys) {
            $card = array_values(array_filter(array_map(fn (string $key): ?array => $groups->get($key), $keys)));

            if ($card !== []) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * The menu item of the page being shown, if it is a settings page.
     *
     * @return array{group: string, key: string, label: string, url: string, match: list<string>, icon: string, summary: string, description: string, keywords: string}|null
     */
    public static function current(Admin $admin, Request $request): ?array
    {
        foreach (self::groups($admin) as $group) {
            foreach ($group['items'] as $item) {
                if ($item['match'] !== [] && $request->routeIs(...$item['match'])) {
                    return ['group' => $group['label']] + $item;
                }
            }
        }

        return null;
    }

    /**
     * @return array{key: string, label: string, url: string, match: list<string>, icon: string, summary: string, description: string, keywords: string}
     */
    private static function item(string $key, string $label, string $url, array $match, string $icon, string $summary, string $description, string $keywords): array
    {
        return compact('key', 'label', 'url', 'match', 'icon', 'summary', 'description', 'keywords');
    }
}
