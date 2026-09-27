<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Support\Activity;
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
        'invoice' => ['invoice.number', 'invoice.subtotal', 'invoice.tax', 'invoice.total', 'invoice.balance', 'invoice.due_date', 'invoice.url', 'days_overdue'],
        'order' => ['order.number', 'order.total', 'admin_url'],
        'service' => ['service.product', 'service.domain', 'service.username', 'service.server', 'service.next_due_date', 'service.amount', 'service.url', 'reason'],
        'ticket' => ['ticket.number', 'ticket.subject', 'ticket.department', 'ticket.status', 'ticket.url', 'reply.message', 'reply.author', 'admin_url'],
        'quote' => ['quote.number', 'quote.subject', 'quote.total', 'quote.valid_until', 'quote.url'],
        'affiliate' => ['commission.amount', 'commission.available_on', 'affiliate_url'],
    ];

    public function index(): View
    {
        return view('admin.settings.email-templates.index', [
            'templates' => EmailTemplate::query()->orderBy('key')->get(),
        ]);
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        $group = explode('.', $emailTemplate->key)[0];
        $extra = match ($group) {
            'admin' => array_merge(self::PLACEHOLDERS['order'], self::PLACEHOLDERS['ticket'], str_starts_with($emailTemplate->key, 'admin.quote') ? [...self::PLACEHOLDERS['quote'], 'invoice.number'] : []),
            default => self::PLACEHOLDERS[$group] ?? [],
        };

        return view('admin.settings.email-templates.edit', [
            'template' => $emailTemplate,
            'placeholders' => array_values(array_unique(array_merge(self::PLACEHOLDERS['all'], self::PLACEHOLDERS['client'], $extra))),
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->update($request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ]));

        Activity::log('email_template.updated', "Email template {$emailTemplate->key} changed");

        return redirect()->route('admin.settings.email-templates.index')->with('status', __('Template saved.'));
    }
}
