<?php

namespace App\Security;

use App\Mail\TemplateMailer;
use App\Models\Client;
use Illuminate\Contracts\Session\Session;

/**
 * Six-digit codes sent by email for two-factor sign-in. Only a hash is kept (in the session);
 * a code works for 10 minutes and for 5 tries, and a new one can be sent once a minute.
 */
class EmailCode
{
    private const MINUTES = 10;

    private const TRIES = 5;

    private const RESEND_SECONDS = 60;

    public function __construct(private Session $session, private TemplateMailer $mailer) {}

    /**
     * Send a new code, unless one was sent less than a minute ago. Returns false when it was too soon.
     */
    public function send(Client $client, string $purpose): bool
    {
        $current = $this->session->get($this->key($purpose));

        if (is_array($current) && ($current['client_id'] ?? null) === $client->id && now()->timestamp - ($current['sent_at'] ?? 0) < self::RESEND_SECONDS) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->session->put($this->key($purpose), [
            'client_id' => $client->id,
            'hash' => hash_hmac('sha256', $code, (string) config('app.key')),
            'sent_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(self::MINUTES)->timestamp,
            'tries' => 0,
        ]);

        $this->mailer->send('client.two_factor_code', $client, ['code' => $code]);

        return true;
    }

    /**
     * Whether a code was sent for this purpose and can still be used.
     */
    public function isPending(Client $client, string $purpose): bool
    {
        $current = $this->session->get($this->key($purpose));

        return is_array($current) && ($current['client_id'] ?? null) === $client->id && ($current['expires_at'] ?? 0) >= now()->timestamp;
    }

    public function check(Client $client, string $purpose, string $code): bool
    {
        $current = $this->session->get($this->key($purpose));
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! is_array($current) || ($current['client_id'] ?? null) !== $client->id || ($current['expires_at'] ?? 0) < now()->timestamp || ($current['tries'] ?? 0) >= self::TRIES) {
            return false;
        }

        if (! preg_match('/^\d{6}$/', $code) || ! hash_equals($current['hash'], hash_hmac('sha256', $code, (string) config('app.key')))) {
            $current['tries']++;
            $this->session->put($this->key($purpose), $current);

            return false;
        }

        $this->session->forget($this->key($purpose));

        return true;
    }

    private function key(string $purpose): string
    {
        return 'email_code.'.$purpose;
    }
}
