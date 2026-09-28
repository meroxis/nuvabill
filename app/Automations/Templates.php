<?php

namespace App\Automations;

use App\Enums\TicketPriority;
use App\Models\Admin;

/**
 * Ready-made automations to start from. Staff change anything before they switch one on.
 * Names, descriptions and messages are in English, like the email templates.
 */
class Templates
{
    /**
     * @return array<string, array{name: string, description: string, trigger: string, days: int|null, conditions: list<array<string, mixed>>, steps: list<array{type: string, config: array<string, mixed>}>}>
     */
    public static function all(): array
    {
        $firstAdmin = (string) (Admin::query()->where('is_active', true)->orderBy('id')->value('id') ?? '');

        return [
            'late-fee' => [
                'name' => 'Late fee after 7 days',
                'description' => 'Add a fee to overdue invoices and tell the client.',
                'trigger' => 'invoice.overdue',
                'days' => 7,
                'conditions' => [
                    ['field' => 'invoice.total', 'operator' => 'gt', 'value' => '10'],
                    ['field' => 'client.tag', 'operator' => 'not', 'value' => 'VIP'],
                ],
                'steps' => [
                    ['type' => 'add_fee', 'config' => ['kind' => 'percent', 'amount' => '5', 'minimum' => '1', 'maximum' => '20', 'description' => 'Late payment fee', 'once' => true]],
                    ['type' => 'send_email', 'config' => [
                        'subject' => 'Late fee added to invoice {{ invoice.number }}',
                        'body' => "Hello {{ client.first_name }},\n\nInvoice {{ invoice.number }} was due on {{ invoice.due_date }} and is still unpaid, so a late fee was added. The total is now **{{ invoice.total }}**.\n\n[Pay the invoice]({{ invoice.url }})\n\nIf you already paid, please ignore this email.\n\n{{ company.name }}",
                    ]],
                    ['type' => 'wait', 'config' => ['amount' => '7', 'unit' => 'days']],
                    ['type' => 'only_if', 'config' => ['condition' => ['field' => 'invoice.status', 'operator' => 'is', 'value' => 'unpaid']]],
                    ['type' => 'send_email', 'config' => [
                        'subject' => 'Last reminder: invoice {{ invoice.number }}',
                        'body' => "Hello {{ client.first_name }},\n\nInvoice {{ invoice.number }} for **{{ invoice.total }}** is still unpaid. Please pay it soon so your services keep running.\n\n[Pay the invoice]({{ invoice.url }})\n\n{{ company.name }}",
                    ]],
                ],
            ],
            'welcome' => [
                'name' => 'Welcome new clients',
                'description' => 'A welcome email, and tips three days later.',
                'trigger' => 'client.registered',
                'days' => null,
                'conditions' => [],
                'steps' => [
                    ['type' => 'wait', 'config' => ['amount' => '1', 'unit' => 'hours']],
                    ['type' => 'send_email', 'config' => [
                        'subject' => 'Welcome to {{ company.name }}',
                        'body' => "Hello {{ client.first_name }},\n\nThank you for choosing {{ company.name }}. Your client area is where you pay invoices, manage services and ask us for help.\n\n[Open your client area]({{ client_area_url }})\n\n{{ company.name }}",
                    ]],
                    ['type' => 'wait', 'config' => ['amount' => '3', 'unit' => 'days']],
                    ['type' => 'send_email', 'config' => [
                        'subject' => 'Getting started with {{ company.name }}',
                        'body' => "Hello {{ client.first_name }},\n\nA few things our clients find useful:\n\n- Turn on two-factor sign-in in your account for extra safety.\n- Add money to your wallet so renewals are paid by themselves.\n- Open a ticket any time you need help.\n\n[Open your client area]({{ client_area_url }})\n\n{{ company.name }}",
                    ]],
                ],
            ],
            'thank-you' => [
                'name' => 'Thank you after the first payment',
                'description' => 'A short thank-you when a new client pays for the first time.',
                'trigger' => 'invoice.paid',
                'days' => null,
                'conditions' => [['field' => 'client.paid_invoices', 'operator' => 'eq', 'value' => '1']],
                'steps' => [
                    ['type' => 'send_email', 'config' => [
                        'subject' => 'Thank you, {{ client.first_name }}',
                        'body' => "Hello {{ client.first_name }},\n\nThank you for your first payment to {{ company.name }}. If anything is unclear, just reply to this email or open a ticket.\n\n{{ company.name }}",
                    ]],
                ],
            ],
            'domain-expiring' => [
                'name' => 'Domain expires soon',
                'description' => 'A reminder 30 days before a domain expires.',
                'trigger' => 'domain.expiring',
                'days' => 30,
                'conditions' => [],
                'steps' => [
                    ['type' => 'send_email', 'config' => [
                        'subject' => '{{ domain.name }} expires soon',
                        'body' => "Hello {{ client.first_name }},\n\nYour domain **{{ domain.name }}** expires on {{ domain.expires_at }}. Renew it in time so your website and email keep working.\n\n[Open your client area]({{ client_area_url }})\n\n{{ company.name }}",
                    ]],
                ],
            ],
            'review' => [
                'name' => 'Ask for a review after 30 days',
                'description' => 'Only clients with no open tickets.',
                'trigger' => 'service.age',
                'days' => 30,
                'conditions' => [['field' => 'client.open_tickets', 'operator' => 'eq', 'value' => '0']],
                'steps' => [
                    ['type' => 'send_email', 'config' => [
                        'subject' => 'How is {{ service.product }} working for you?',
                        'body' => "Hello {{ client.first_name }},\n\nYou have used {{ service.product }} for a month now. Would you tell others what you think? A short review helps us a lot.\n\n[Write a review](https://example.com/review)\n\n{{ company.name }}",
                    ]],
                ],
            ],
            'quote-chase' => [
                'name' => 'Chase quotes that were not answered',
                'description' => 'A reminder 5 days after sending a quote.',
                'trigger' => 'quote.unanswered',
                'days' => 5,
                'conditions' => [],
                'steps' => [
                    ['type' => 'send_email', 'config' => [
                        'subject' => 'Your quote {{ quote.number }}',
                        'body' => "Hello {{ client.first_name }},\n\nWe sent you quote {{ quote.number }} a few days ago. Do you have any questions about it?\n\n[See the quote]({{ quote.url }})\n\n{{ company.name }}",
                    ]],
                ],
            ],
            'win-back' => [
                'name' => 'Win back clients who left',
                'description' => 'A coupon 14 days after a service is terminated.',
                'trigger' => 'service.terminated',
                'days' => null,
                'conditions' => [['field' => 'client.active_services', 'operator' => 'eq', 'value' => '0']],
                'steps' => [
                    ['type' => 'wait', 'config' => ['amount' => '14', 'unit' => 'days']],
                    ['type' => 'send_email', 'config' => [
                        'subject' => 'We would like you back, {{ client.first_name }}',
                        'body' => "Hello {{ client.first_name }},\n\nWe miss you at {{ company.name }}. Come back with **20% off** your first order: use the code COMEBACK20 in our store.\n\n{{ company.name }}",
                    ]],
                ],
            ],
            'big-order' => [
                'name' => 'Tell the team about big orders',
                'description' => 'An email to staff when an order total is over 200.',
                'trigger' => 'order.placed',
                'days' => null,
                'conditions' => [['field' => 'order.total', 'operator' => 'gt', 'value' => '200']],
                'steps' => [
                    ['type' => 'email_staff', 'config' => [
                        'to' => 'company',
                        'subject' => 'Big order {{ order.number }}: {{ order.total }}',
                        'body' => '{{ client.name }} placed order {{ order.number }} for **{{ order.total }}**.',
                    ]],
                ],
            ],
            'vip-tickets' => [
                'name' => 'VIP tickets first',
                'description' => 'Tickets from clients tagged VIP get high priority and one person.',
                'trigger' => 'ticket.opened',
                'days' => null,
                'conditions' => [['field' => 'client.tag', 'operator' => 'has', 'value' => 'VIP']],
                'steps' => array_values(array_filter([
                    $firstAdmin !== '' ? ['type' => 'assign_ticket', 'config' => ['admin' => $firstAdmin]] : null,
                    ['type' => 'ticket_priority', 'config' => ['priority' => TicketPriority::High->value]],
                ])),
            ],
        ];
    }

    /**
     * @return array{name: string, description: string, trigger: string, days: int|null, conditions: list<array<string, mixed>>, steps: list<array{type: string, config: array<string, mixed>}>}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
