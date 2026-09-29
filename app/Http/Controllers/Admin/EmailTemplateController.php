<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Support\Activity;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    /**
     * Placeholders available in every template, plus the extra ones per group.
     *
     * @var array<string, list<string>>
     */
    public const PLACEHOLDERS = [
        'all' => ['company.name', 'company.email', 'company.url', 'client_area_url'],
        'client' => ['client.first_name', 'client.last_name', 'client.name', 'client.email', 'client.company_name'],
        'invoice' => ['invoice.number', 'invoice.subtotal', 'invoice.tax', 'invoice.total', 'invoice.balance', 'invoice.due_date', 'invoice.url', 'days_overdue', 'payment_method.name', 'charge_date', 'next_try', 'failure', 'payment_methods_url', 'credit_note.number', 'credit_note.total', 'credit_note.reason', 'credit_note.note'],
        'payment' => ['payment_method.name', 'payment_method.expires', 'payment_methods_url'],
        'order' => ['order.number', 'order.total', 'admin_url'],
        'service' => ['service.product', 'service.domain', 'service.username', 'service.server', 'service.next_due_date', 'service.amount', 'service.url', 'reason'],
        'ticket' => ['ticket.number', 'ticket.subject', 'ticket.department', 'ticket.status', 'ticket.url', 'reply.message', 'reply.author', 'admin_url'],
        'quote' => ['quote.number', 'quote.subject', 'quote.total', 'quote.valid_until', 'quote.url'],
        'affiliate' => ['commission.amount', 'commission.available_on', 'affiliate_url'],
    ];

    public function index(): View
    {
        return view('admin.settings.email-templates.index', [
            'templates' => EmailTemplate::query()->withCount(['translations' => fn ($query) => $query->whereIn('locale', array_keys(self::languages()))])->orderBy('key')->get(),
            'languageCount' => count(self::languages()),
        ]);
    }

    public function edit(Request $request, EmailTemplate $emailTemplate): View
    {
        $languages = self::languages();
        $locale = isset($languages[(string) $request->query('lang')]) ? (string) $request->query('lang') : 'en';
        $emailTemplate->load('translations');
        $group = explode('.', $emailTemplate->key)[0];
        $extra = match ($group) {
            'admin' => array_merge(self::PLACEHOLDERS['order'], self::PLACEHOLDERS['ticket'], str_starts_with($emailTemplate->key, 'admin.quote') ? [...self::PLACEHOLDERS['quote'], 'invoice.number'] : []),
            default => self::PLACEHOLDERS[$group] ?? [],
        };

        return view('admin.settings.email-templates.edit', [
            'template' => $emailTemplate,
            'languages' => ['en' => Locales::ALL['en']['native']] + $languages,
            'locale' => $locale,
            'translation' => $locale === 'en' ? null : $emailTemplate->translations->firstWhere('locale', $locale),
            'placeholders' => array_values(array_unique(array_merge(self::PLACEHOLDERS['all'], self::PLACEHOLDERS['client'], $extra))),
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $locale = (string) $request->input('locale', 'en');

        if ($locale !== 'en') {
            return $this->updateTranslation($request, $emailTemplate, $locale);
        }

        $emailTemplate->update($request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ]));

        Activity::log('email_template.updated', "Email template {$emailTemplate->key} changed");

        return redirect()->route('admin.settings.email-templates.index')->with('status', __('Template saved.'));
    }

    /**
     * The template in another language. Empty fields use the English text; both empty removes it.
     */
    private function updateTranslation(Request $request, EmailTemplate $emailTemplate, string $locale): RedirectResponse
    {
        abort_unless(isset(self::languages()[$locale]), 404);

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        if (blank($data['subject'] ?? null) && blank($data['body'] ?? null)) {
            $emailTemplate->translations()->where('locale', $locale)->delete();
        } else {
            $emailTemplate->translations()->updateOrCreate(['locale' => $locale], ['subject' => $data['subject'] ?? null, 'body' => $data['body'] ?? null]);
        }

        Activity::log('email_template.updated', "Email template {$emailTemplate->key} ({$locale}) changed");

        return redirect()->route('admin.settings.email-templates.edit', [$emailTemplate, 'lang' => $locale])->with('status', __('Template saved.'));
    }

    /**
     * The languages besides English that templates can be translated into: the ones clients can pick.
     *
     * @return array<string, string>
     */
    private static function languages(): array
    {
        return array_diff_key(Locales::enabled(), ['en' => true]);
    }
}
