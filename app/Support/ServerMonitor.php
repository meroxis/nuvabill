<?php

namespace App\Support;

use App\Extensions\ExtensionManager;
use App\Mail\TemplateMailer;
use App\Models\Server;
use App\Models\ServerCheck;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Checks every switched-on server every few minutes: can Nuvabill reach its control panel port?
 * A server counts as down after two failed checks in a row, so one lost packet does not raise an
 * alarm. Staff get an email when a server goes down and when it is back.
 */
class ServerMonitor
{
    public const TIMEOUT_SECONDS = 5;

    public const KEEP_DAYS = 90;

    public const FAILURES_BEFORE_DOWN = 2;

    public function __construct(private ExtensionManager $extensions, private TemplateMailer $mailer) {}

    /**
     * @return array{checked: int, down: int}
     */
    public function checkAll(): array
    {
        $checked = 0;
        $down = 0;

        foreach (Server::query()->where('is_active', true)->orderBy('id')->get() as $server) {
            $this->check($server);
            $checked++;
            $down += $server->status_up === false ? 1 : 0;
        }

        ServerCheck::query()->where('checked_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        return ['checked' => $checked, 'down' => $down];
    }

    public function check(Server $server): void
    {
        $now = now();
        $milliseconds = $this->probe($server);
        $isUp = $milliseconds !== null;
        $wasUp = $server->status_up;
        $lastChange = $server->status_changed_at;

        ServerCheck::query()->create(['server_id' => $server->id, 'is_up' => $isUp, 'response_ms' => $milliseconds, 'checked_at' => $now]);

        $failures = $isUp ? 0 : min(255, $server->status_failures + 1);
        $status = $isUp ? true : ($failures >= self::FAILURES_BEFORE_DOWN ? false : $wasUp);
        $changed = $status !== $wasUp;

        $server->forceFill([
            'status_up' => $status,
            'status_failures' => $failures,
            'status_checked_at' => $now,
            'status_changed_at' => $changed ? $now : $server->status_changed_at,
        ])->save();

        // No email for the very first result, only when a known state changes.
        if ($changed && $wasUp !== null) {
            $this->alert($server, (bool) $status, $lastChange);
        }
    }

    /**
     * The port the check connects to: the one set on the server, or the control panel's usual one.
     */
    public function port(Server $server): int
    {
        if ($server->port) {
            return $server->port;
        }

        try {
            return $this->extensions->serverModule((string) $server->module)->defaultPort();
        } catch (Throwable) {
            return 443;
        }
    }

    /**
     * Milliseconds the server took to accept a connection, or null when it did not.
     */
    protected function probe(Server $server): ?int
    {
        $started = hrtime(true);
        $socket = @stream_socket_client('tcp://'.$server->hostname.':'.$this->port($server), $code, $message, self::TIMEOUT_SECONDS);

        if ($socket === false) {
            return null;
        }

        fclose($socket);

        return max(1, (int) round((hrtime(true) - $started) / 1_000_000));
    }

    private function alert(Server $server, bool $isUp, ?Carbon $since): void
    {
        $email = (string) setting('company.email');

        if (! setting('status.alerts') || $email === '') {
            return;
        }

        Locales::in(Locales::default(), function () use ($server, $isUp, $since, $email): void {
            $subject = $isUp
                ? __('Server :name is back', ['name' => $server->name])
                : __('Server :name is not answering', ['name' => $server->name]);
            $body = $isUp
                ? __(":name answers again. It was down for about :time.\n\nIf you posted a note on the network status page, you can mark it resolved: :url", [
                    'name' => $server->name,
                    'time' => $since?->diffForHumans(now(), ['syntax' => Carbon::DIFF_ABSOLUTE]) ?? '—',
                    'url' => route('admin.network.index'),
                ])
                : __(":name (:host) has not answered on port :port since :date. Nuvabill checks again every 5 minutes and emails you when it is back.\n\nTo tell your clients, post a note on the network status page: :url", [
                    'name' => $server->name,
                    'host' => $server->hostname,
                    'port' => $this->port($server),
                    'date' => now()->translatedFormat('d M Y H:i'),
                    'url' => route('admin.network.index'),
                ]);

            rescue(fn () => $this->mailer->sendText($email, (string) setting('company.name'), $subject, $body));
        });
    }
}
