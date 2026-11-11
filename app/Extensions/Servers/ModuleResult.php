<?php

namespace App\Extensions\Servers;

/**
 * The outcome of a control panel action.
 */
final readonly class ModuleResult
{
    /**
     * @param  array<string, mixed>  $data  Extra values to store, for example ['username' => 'acme'].
     */
    private function __construct(
        public bool $success,
        public string $message,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(string $message = 'Done', array $data = []): self
    {
        return new self(true, $message, $data);
    }

    /**
     * A client panel action (HasClientPanel::clientAction) with a value the client must see once,
     * such as VNC details. Core gives it to the page's script in the answer and shows it once: it is
     * never put in the session or the page, and without JavaScript it is not shown at all.
     *
     * @param  list<array{label: string, value: string}>|array<string, string>  $secret  Rows of label and value, or label => value.
     * @param  array<string, mixed>  $data
     */
    public static function secret(string $message, array $secret, array $data = []): self
    {
        $rows = [];

        foreach ($secret as $label => $row) {
            if (is_array($row) && is_string($row['label'] ?? null) && is_string($row['value'] ?? null)) {
                $rows[] = ['label' => $row['label'], 'value' => $row['value']];
            } elseif (is_string($label) && is_string($row)) {
                $rows[] = ['label' => $label, 'value' => $row];
            }
        }

        return new self(true, $message, ['secret' => $rows] + $data);
    }

    /**
     * @param  array<string, mixed>  $data  Flags for core, for example ['busy' => true] when the server was busy and nothing changed.
     */
    public static function fail(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    /**
     * The rows a client panel action shows once, or an empty list. Module versions from before
     * secret() gave VNC details as data['vnc'] (ip, port, password): those are shown once as well.
     *
     * @return list<array{label: string, value: string}>
     */
    public function shownOnce(): array
    {
        if (! $this->success) {
            return [];
        }

        $rows = [];

        if (is_array($this->data['secret'] ?? null)) {
            foreach ($this->data['secret'] as $row) {
                if (is_array($row) && is_string($row['label'] ?? null) && is_string($row['value'] ?? null) && $row['value'] !== '') {
                    $rows[] = ['label' => $row['label'], 'value' => $row['value']];
                }
            }

            return $rows;
        }

        $vnc = is_array($this->data['vnc'] ?? null) ? $this->data['vnc'] : [];

        foreach (['ip' => __('IP address'), 'port' => __('Port'), 'password' => __('Password')] as $key => $label) {
            if (is_scalar($vnc[$key] ?? null) && (string) $vnc[$key] !== '') {
                $rows[] = ['label' => $label, 'value' => (string) $vnc[$key]];
            }
        }

        return $rows;
    }
}
