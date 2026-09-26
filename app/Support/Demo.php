<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The public demo (NUVABILL_DEMO=true): shared sign-ins, fresh data every hour, and no
 * changes that could lock visitors out, send email or reach other servers.
 */
class Demo
{
    public const ADMIN_EMAIL = 'admin@nuvabill.test';

    public const CLIENT_EMAIL = 'client@nuvabill.test';

    public const PASSWORD = 'nuvabill-demo';

    /**
     * Routes visitors can open but not save in the demo.
     *
     * @var list<string>
     */
    public const LOCKED_ROUTES = [
        'admin.profile.*',
        'admin.settings.update',
        'admin.settings.mail',
        'admin.settings.mail.test',
        'admin.settings.gateways.update',
        'admin.settings.registrars.*',
        'admin.domains.action',
        'admin.settings.staff.*',
        'admin.settings.roles.*',
        'admin.updates.*',
        'admin.servers.*',
        'admin.services.module',
        'client.account.*',
        'client.services.login',
        'client.domains.nameservers',
        'client.invoices.pay',
    ];

    public static function isEnabled(): bool
    {
        return (bool) config('nuvabill.demo');
    }

    /**
     * False on a new demo site until the first reset has filled the database.
     */
    public static function hasData(): bool
    {
        try {
            return Schema::hasTable('admins');
        } catch (Throwable) {
            return false;
        }
    }
}
