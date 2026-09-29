<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Seo\Seo;
use App\Support\NetworkStatus;
use Illuminate\View\View;

/**
 * The network status page: whether each server staff chose to show answers, its uptime, open
 * issues, planned maintenance and what was fixed lately.
 */
class NetworkStatusController extends Controller
{
    public function __invoke(NetworkStatus $status, Seo $seo): View
    {
        abort_unless(setting('status.enabled'), 404);
        $seo->setDescription(__('Is everything working? The state of our servers, open issues and planned maintenance.'));

        $servers = $status->publicServers();
        $open = $status->open();

        return view('theme::network-status', [
            'summary' => $status->summary($servers, $open),
            'servers' => $servers,
            'uptime' => Server::uptime($servers->modelKeys()),
            'days' => $servers->mapWithKeys(fn (Server $server): array => [$server->id => $server->dailyUptime()])->all(),
            'issues' => $open->reject->isMaintenance()->values(),
            'maintenance' => $open->filter->isMaintenance()->values(),
            'recent' => $status->recent(),
        ]);
    }
}
