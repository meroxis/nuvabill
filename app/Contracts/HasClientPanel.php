<?php

namespace App\Contracts;

use App\Extensions\Servers\ModuleResult;
use App\Models\Service;

/**
 * A server module that shows its own panel on the client's service page, for example
 * VPS power buttons and usage for Virtualizor or Proxmox. The client never leaves the client area,
 * and API keys stay on the server: every action goes through Nuvabill.
 */
interface HasClientPanel
{
    /**
     * The Blade view for the panel: the shared "theme::client.services.vps-panel", or the extension's own view ("ext-{slug}::panel" from its views folder).
     */
    public function clientPanelView(): string;

    /**
     * Values for the panel view, read live from the control panel.
     *
     * @return array<string, mixed>
     */
    public function clientPanel(Service $service): array;

    /**
     * Actions the client may run from the panel, as action => label.
     *
     * @return array<string, string>
     */
    public function clientActions(): array;

    /**
     * Run one of the client actions with the values from the panel's form.
     *
     * The message is shown to the client. Core keeps only data['pending'] (a power action still
     * running) for the next page: nothing else from the result reaches the session. To show the
     * client a value once, such as VNC details, answer with ModuleResult::secret(). A failure text
     * that names the server's host or IP address, holds a web address or carries a raw connection
     * error is kept for staff in the activity log, and the client gets a plain message instead. The
     * same goes for anything this method throws.
     *
     * @param  array<string, mixed>  $input
     */
    public function clientAction(Service $service, string $action, array $input): ModuleResult;
}
