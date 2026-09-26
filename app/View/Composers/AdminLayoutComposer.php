<?php

namespace App\View\Composers;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\View\View;

/**
 * Supplies the badge counts shown in the admin sidebar.
 */
class AdminLayoutComposer
{
    public function compose(View $view): void
    {
        $latest = setting('updates.latest');

        $view->with([
            'pendingOrders' => Order::query()->where('status', OrderStatus::Pending)->count(),
            'ticketsAwaitingReply' => Ticket::query()->whereIn('status', [TicketStatus::Open, TicketStatus::CustomerReply])->count(),
            'updateAvailable' => is_array($latest)
                && isset($latest['version'])
                && version_compare((string) $latest['version'], (string) config('nuvabill.version'), '>'),
        ]);
    }
}
