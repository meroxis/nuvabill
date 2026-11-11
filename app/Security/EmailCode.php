<?php

namespace App\Security;

use App\Mail\TemplateMailer;
use App\Models\Client;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

/**
 * Six-digit codes sent by email: for two-factor sign-in, to set it up, and to confirm an account
 * change. Only a hash is kept (in the session); a code works for 10 minutes and for 5 tries, and
 * a new one can be sent once a minute.
 *
 * A new code brings new tries, so wrong codes are also counted per client over all codes
 * (SignInLimiter): after too many, no code is accepted or sent until the wait is over. Sign-in
 * codes are counted by the sign-in itself (TwoFactorChallengeController).
 */
class EmailCode
{
    private const MINUTES = 10;

    private const TRIES = 5;

    private const RESEND_SECONDS = 60;

    /** The purpose of codes that confirm an account change, see confirm(). */
    public const CONFIRM = 'confirm';

    /** The purpose of two-factor sign-in codes. Their wrong tries are counted by the sign-in. */
    private const LOGIN = 'login';

    public function __construct(private Session $session, private TemplateMailer $mailer) {}

    /**
     * Send a new code, unless one was sent less than a minute ago or the client typed too many
     * wrong codes (see locked()). Returns false when no code was sent.
     */
    public function send(Client $client, string $purpose): bool
    {
        if ($this->locked($client, $purpose)) {
            return false;
        }

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

    /**
     * Whether the client typed too many wrong codes and has to wait: until then no code for this
     * purpose is accepted or sent, also not the right one.
     */
    public function locked(Client $client, string $purpose = self::CONFIRM): bool
    {
        return $purpose !== self::LOGIN && SignInLimiter::emailCodesLocked($client->id);
    }

    /**
     * What to tell a client who has to wait, see locked().
     */
    public static function lockedMessage(): string
    {
        return __('Too many wrong codes. Wait an hour, then ask for a new code.');
    }

    public function check(Client $client, string $purpose, string $code): bool
    {
        if ($purpose === self::LOGIN) {
            return $this->matches($client, $purpose, $code) === true;
        }

        // One check at a time per client, so codes sent at the same moment are all counted.
        return SignInLimiter::oneEmailCodeAtATime($client->id, function () use ($client, $purpose, $code): bool {
            if (SignInLimiter::emailCodesLocked($client->id)) {
                return false;
            }

            $matches = $this->matches($client, $purpose, $code);

            if ($matches === false) {
                SignInLimiter::emailCodeFailed($client->id);
            } elseif ($matches === true) {
                SignInLimiter::emailCodePassed($client->id);
            }

            return $matches === true;
        });
    }

    /**
     * True for the right code, false for a wrong one, and null when there is no code to try:
     * none was sent, it is too old, or its tries are used up.
     */
    private function matches(Client $client, string $purpose, string $code): ?bool
    {
        $current = $this->session->get($this->key($purpose));
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! is_array($current) || ($current['client_id'] ?? null) !== $client->id || ($current['expires_at'] ?? 0) < now()->timestamp || ($current['tries'] ?? 0) >= self::TRIES) {
            return null;
        }

        if (! preg_match('/^\d{6}$/', $code) || ! hash_equals($current['hash'], hash_hmac('sha256', $code, (string) config('app.key')))) {
            $current['tries']++;
            $this->session->put($this->key($purpose), $current);

            return false;
        }

        $this->session->forget($this->key($purpose));

        return true;
    }

    /**
     * Clients without a password prove a change is theirs (a new email, a first password, turning
     * off two-factor sign-in) with a code sent to their current email, so a stolen session alone
     * cannot do it. Stops with a form error under $field ("email_code" unless the form names it
     * otherwise) when the code is wrong, or when the client has to wait after too many wrong codes.
     *
     * @throws ValidationException
     */
    public function confirm(Client $client, mixed $code, string $field = 'email_code'): void
    {
        if (! is_string($code) || ! $this->check($client, self::CONFIRM, $code)) {
            throw ValidationException::withMessages([$field => $this->locked($client)
                ? self::lockedMessage()
                : __('That code is not right, or it is too old. Ask for a new code.')]);
        }
    }

    private function key(string $purpose): string
    {
        return 'email_code.'.$purpose;
    }
}
