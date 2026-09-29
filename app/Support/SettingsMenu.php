<?php

namespace App\Support;

use App\Models\Admin;
use Illuminate\Http\Request;

/**
 * The Settings menu: every settings page in its group, with an icon, a one-line description for
 * the page header and extra words the "Find a setting" box matches. Staff only see the pages
 * their role allows.
 */
class SettingsMenu
{
    /**
     * @return list<array{label: string, items: list<array{key: string, label: string, url: string, match: list<string>, icon: string, description: string, keywords: string, external: bool}>}>
     */
    public static function groups(Admin $admin): array
    {
        $settings = $admin->hasPermission('settings.manage');
        $staff = $admin->hasPermission('staff.manage');

        $groups = [
            [__('Business'), $settings, [
                self::item('general', __('General'), route('admin.settings.edit'), ['admin.settings.edit'], 'building',
                    __('Company details, billing, the nightly automation, sending email and the look of your client area.'),
                    'company name address phone invoice prefix currency cron automation reminders suspend terminate smtp mail brand color theme language terms privacy'),
                self::item('currencies', __('Currencies'), route('admin.settings.currencies.edit'), ['admin.settings.currencies.*'], 'coins',
                    __('Sell in more currencies, with the exchange rates you set.'), 'exchange rate dinar iqd dollar euro'),
                self::item('taxes', __('Taxes'), route('admin.settings.taxes.index'), ['admin.settings.taxes.*'], 'percent',
                    __('VAT, GST or sales tax rules by country and state.'), 'vat gst sales tax exempt tax id'),
                self::item('domains', __('Domains'), route('admin.settings.tlds.index'), ['admin.settings.tlds.*'], 'globe',
                    __('The domain endings you sell, their prices and the registrar for each.'), 'tld registrar com net whois epp transfer renew'),
            ]],
            [__('Payments'), $settings, [
                self::item('autopay', __('Automatic payments'), route('admin.settings.autopay.edit'), ['admin.settings.autopay.*'], 'card',
                    __('Charge saved cards and PayPal accounts for renewals.'), 'autopay saved card retry stripe paypal renewal'),
                self::item('gateways', __('Payment gateways'), route('admin.extensions.index', ['tab' => 'gateways']), [], 'plug',
                    __('Stripe, PayPal, bank transfer and Iraqi payments.'), 'stripe paypal bank transfer fib fastpay wayl', external: true),
            ]],
            [__('Messages'), $settings, [
                self::item('email-templates', __('Email templates'), route('admin.settings.email-templates.index'), ['admin.settings.email-templates.*'], 'mail',
                    __('The emails clients and staff get, in your own words.'), 'email template welcome invoice reminder'),
                self::item('chat', __('Chat apps'), route('admin.settings.chat.edit'), ['admin.settings.chat.*'], 'message',
                    __('Invoices, reminders and ticket replies on Telegram and WhatsApp.'), 'telegram whatsapp meta qr bot'),
                self::item('departments', __('Support departments'), route('admin.settings.departments.index'), ['admin.settings.departments.*'], 'inbox',
                    __('Clients pick one when they open a ticket.'), 'ticket department sales billing support alert email'),
            ]],
            [__('Sign-in and security'), $settings, [
                self::item('security', __('Security'), route('admin.settings.security.edit'), ['admin.settings.security.*'], 'shield',
                    __('Two-factor sign-in and CAPTCHA for sign-in, sign-up and checkout.'), '2fa two-factor captcha turnstile recaptcha hcaptcha'),
                self::item('social', __('Social login'), route('admin.settings.social.edit'), ['admin.settings.social.*'], 'login',
                    __('Let clients sign in with Google, GitHub or Facebook.'), 'google github facebook oauth'),
            ]],
            [__('Growth'), $settings, [
                self::item('seo', __('Search engines'), route('admin.settings.seo.edit'), ['admin.settings.seo.*'], 'search',
                    __('Titles and descriptions, the sitemap, link previews and Google verification.'), 'seo google bing sitemap robots title description redirect'),
                self::item('ai', __('AI help'), route('admin.settings.ai.edit'), ['admin.settings.ai.*'], 'sparkles',
                    __('Ticket reply drafts, translation and summaries with your own Claude key.'), 'ai claude anthropic translate'),
            ]],
            [__('Team'), $staff, [
                self::item('staff', __('Staff'), route('admin.settings.staff.index'), ['admin.settings.staff.*'], 'users',
                    __('Your team\'s accounts and their roles.'), 'admin staff account user'),
                self::item('roles', __('Roles'), route('admin.settings.roles.index'), ['admin.settings.roles.*'], 'key',
                    __('What each role can see and do.'), 'role permission access'),
            ]],
            [__('System'), $settings, [
                self::item('license', __('License'), route('admin.settings.license.edit'), ['admin.settings.license.*'], 'award',
                    __('Your White-label License, which removes the Nuvabill credit.'), 'white label branding powered by'),
                self::item('import', __('Import'), route('admin.settings.import.index'), ['admin.settings.import.*'], 'download',
                    __('Move clients, services and invoices from WHMCS, Blesta, FOSSBilling or Paymenter.'), 'whmcs blesta fossbilling paymenter migrate'),
                self::item('activity', __('Activity log'), route('admin.settings.activity'), ['admin.settings.activity'], 'clock',
                    __('Who did what, and when.'), 'log history audit'),
            ]],
        ];

        $visible = [];

        foreach ($groups as [$label, $allowed, $items]) {
            if ($allowed) {
                $visible[] = ['label' => $label, 'items' => $items];
            }
        }

        return $visible;
    }

    /**
     * The menu item of the page being shown, if it is a settings page.
     *
     * @return array{group: string, key: string, label: string, url: string, match: list<string>, icon: string, description: string, keywords: string, external: bool}|null
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
     * @return array{key: string, label: string, url: string, match: list<string>, icon: string, description: string, keywords: string, external: bool}
     */
    private static function item(string $key, string $label, string $url, array $match, string $icon, string $description, string $keywords, bool $external = false): array
    {
        return compact('key', 'label', 'url', 'match', 'icon', 'description', 'keywords', 'external');
    }
}
