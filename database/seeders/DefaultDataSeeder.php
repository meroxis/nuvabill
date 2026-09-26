<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\Role;
use App\Models\TicketDepartment;
use Illuminate\Database\Seeder;

/**
 * Data every installation needs: roles, support departments and email templates.
 * Safe to run again: existing records are left as they are.
 */
class DefaultDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->roles();
        $this->departments();
        $this->emailTemplates();
    }

    private function roles(): void
    {
        $roles = [
            'Owner' => ['*'],
            'Billing' => ['clients.view', 'clients.manage', 'orders.manage', 'services.manage', 'domains.manage', 'billing.manage'],
            'Support' => ['clients.view', 'support.manage'],
        ];

        foreach ($roles as $name => $permissions) {
            Role::query()->firstOrCreate(['name' => $name], ['permissions' => $permissions]);
        }
    }

    private function departments(): void
    {
        if (TicketDepartment::query()->exists()) {
            return;
        }

        foreach (['Technical support', 'Billing', 'Sales'] as $order => $name) {
            TicketDepartment::create(['name' => $name, 'is_visible' => true, 'sort_order' => $order]);
        }
    }

    private function emailTemplates(): void
    {
        foreach (self::templates() as $key => [$name, $subject, $body]) {
            EmailTemplate::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'subject' => $subject,
                'body' => $body,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function templates(): array
    {
        return [
            'client.welcome' => [
                'Welcome email',
                'Welcome to {{ company.name }}',
                <<<'MD'
                Hi {{ client.first_name }},

                Thank you for creating an account with {{ company.name }}.

                Sign in any time to order services, pay invoices and contact support:

                [Go to your account]({{ client_area_url }})

                If you have not chosen a password yet, use "Forgot password" on the sign-in page.

                Thanks,
                {{ company.name }}
                MD,
            ],
            'client.two_factor_code' => [
                'Sign-in code',
                'Your sign-in code for {{ company.name }}',
                <<<'MD'
                Hi {{ client.first_name }},

                Your sign-in code is: **{{ code }}**

                It works for 10 minutes. Never share this code: our staff will never ask for it.

                If you did not try to sign in, change your password now.

                {{ company.name }}
                MD,
            ],
            'order.confirmation' => [
                'Order confirmation',
                'Order #{{ order.number }} received',
                <<<'MD'
                Hi {{ client.first_name }},

                Thank you for your order **#{{ order.number }}**. The total is **{{ order.total }}**.

                [Pay invoice {{ invoice.number }}]({{ invoice.url }})

                We set up your service as soon as the payment arrives and email you the details.

                Thanks,
                {{ company.name }}
                MD,
            ],
            'invoice.created' => [
                'New invoice',
                'Invoice {{ invoice.number }} is ready',
                <<<'MD'
                Hi {{ client.first_name }},

                Invoice **{{ invoice.number }}** for **{{ invoice.total }}** is ready. It is due on **{{ invoice.due_date }}**.

                [View and pay the invoice]({{ invoice.url }})

                Thanks,
                {{ company.name }}
                MD,
            ],
            'invoice.payment_received' => [
                'Payment received',
                'Payment received for invoice {{ invoice.number }}',
                <<<'MD'
                Hi {{ client.first_name }},

                We received your payment for invoice **{{ invoice.number }}**. Thank you!

                [View the invoice]({{ invoice.url }})

                {{ company.name }}
                MD,
            ],
            'invoice.reminder' => [
                'Overdue reminder',
                'Reminder: invoice {{ invoice.number }} is overdue',
                <<<'MD'
                Hi {{ client.first_name }},

                Invoice **{{ invoice.number }}** for **{{ invoice.balance }}** was due on {{ invoice.due_date }} and is still unpaid.

                [Pay now]({{ invoice.url }})

                Services with unpaid invoices are suspended after a few days. If you already paid, please ignore this email.

                {{ company.name }}
                MD,
            ],
            'service.welcome' => [
                'Service ready',
                'Your {{ service.product }} is ready',
                <<<'MD'
                Hi {{ client.first_name }},

                Your **{{ service.product }}** for **{{ service.domain }}** is set up and ready to use.

                - Username: {{ service.username }}
                - Server: {{ service.server }}

                Your password and a one-click control panel login are in your client area:

                [Open service details]({{ service.url }})

                {{ company.name }}
                MD,
            ],
            'service.suspended' => [
                'Service suspended',
                'Service suspended: {{ service.domain }}',
                <<<'MD'
                Hi {{ client.first_name }},

                Your **{{ service.product }}** for **{{ service.domain }}** has been suspended.

                Reason: {{ reason }}

                Pay any open invoice to switch it back on automatically, or reply to this email if you need help.

                [Go to your account]({{ client_area_url }})

                {{ company.name }}
                MD,
            ],
            'service.unsuspended' => [
                'Service active again',
                'Service active again: {{ service.domain }}',
                <<<'MD'
                Hi {{ client.first_name }},

                Good news: your **{{ service.product }}** for **{{ service.domain }}** is active again.

                {{ company.name }}
                MD,
            ],
            'domain.registered' => [
                'Domain registered',
                'Your domain {{ domain.name }} is registered',
                <<<'MD'
                Hi {{ client.first_name }},

                Good news: **{{ domain.name }}** is now registered to you until **{{ domain.expires_at }}**.

                Nameservers: {{ domain.nameservers }}

                It can take a few hours until the domain works everywhere on the internet.

                [Manage your domain]({{ domain.url }})

                {{ company.name }}
                MD,
            ],
            'domain.transfer_started' => [
                'Domain transfer started',
                'Transfer of {{ domain.name }} has started',
                <<<'MD'
                Hi {{ client.first_name }},

                We started the transfer of **{{ domain.name }}** to us. Transfers usually take 5 to 7 days.

                Your current registrar may email you to approve the transfer. Approving it makes the transfer faster.

                [Follow the transfer]({{ domain.url }})

                {{ company.name }}
                MD,
            ],
            'domain.renewed' => [
                'Domain renewed',
                'Your domain {{ domain.name }} is renewed',
                <<<'MD'
                Hi {{ client.first_name }},

                Thank you! **{{ domain.name }}** is renewed. It now expires on **{{ domain.expires_at }}**.

                [Manage your domain]({{ domain.url }})

                {{ company.name }}
                MD,
            ],
            'domain.expiring' => [
                'Domain expiring soon',
                '{{ domain.name }} expires in {{ days_left }} days',
                <<<'MD'
                Hi {{ client.first_name }},

                Your domain **{{ domain.name }}** expires on **{{ domain.expires_at }}**, and it will not renew by itself.

                If you want to keep it, renew it now. An expired domain stops working, and someone else may register it.

                [Renew {{ domain.name }}]({{ domain.url }})

                {{ company.name }}
                MD,
            ],
            'ticket.opened' => [
                'Ticket received',
                '[Ticket #{{ ticket.number }}] {{ ticket.subject }}',
                <<<'MD'
                Hi {{ client.first_name }},

                We received your ticket and will reply as soon as we can.

                **{{ ticket.subject }}** · {{ ticket.department }}

                [View your ticket]({{ ticket.url }})

                {{ company.name }}
                MD,
            ],
            'ticket.reply' => [
                'Staff replied to a ticket',
                '[Ticket #{{ ticket.number }}] New reply: {{ ticket.subject }}',
                <<<'MD'
                Hi {{ client.first_name }},

                {{ reply.author }} replied to your ticket:

                {{ reply.message }}

                [View the ticket and reply]({{ ticket.url }})

                {{ company.name }}
                MD,
            ],
            'admin.new_order' => [
                'Staff: new order',
                'New order #{{ order.number }} from {{ client.name }}',
                <<<'MD'
                {{ client.name }} ({{ client.email }}) placed order **#{{ order.number }}** for **{{ order.total }}**.

                [Open the order]({{ admin_url }})
                MD,
            ],
            'admin.ticket_opened' => [
                'Staff: new ticket',
                'New ticket #{{ ticket.number }}: {{ ticket.subject }}',
                <<<'MD'
                {{ client.name }} opened a ticket in {{ ticket.department }}:

                **{{ ticket.subject }}**

                {{ reply.message }}

                [Reply now]({{ admin_url }})
                MD,
            ],
            'admin.ticket_reply' => [
                'Staff: client replied',
                'Client replied to ticket #{{ ticket.number }}',
                <<<'MD'
                {{ client.name }} replied to **{{ ticket.subject }}**:

                {{ reply.message }}

                [Reply now]({{ admin_url }})
                MD,
            ],
        ];
    }
}
