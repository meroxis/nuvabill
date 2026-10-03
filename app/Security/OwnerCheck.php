<?php

namespace App\Security;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Proof that the signed-in client is the account owner and not someone with a stolen session,
 * before a change that could hand the account to someone else: a new email, a connected Google,
 * GitHub or Facebook account, or turning off two-factor sign-in. That is the current password, or
 * for clients without one a code sent to their current email (EmailCode::confirm).
 *
 * The Account page has several of these forms, so each one names its fields with its own prefix
 * ("details_current_password", "connect_email_code"): a mistake in one form shows only there.
 */
class OwnerCheck
{
    /**
     * The prefixed password fields. They are never kept as old input after a form error
     * (bootstrap/app.php), like "current_password" itself.
     */
    public const PASSWORD_FIELDS = ['details_current_password', 'connect_current_password', 'two_factor_current_password'];

    public function __construct(private EmailCode $codes) {}

    /**
     * Stops with a form error under the form's own field when the password or code is wrong.
     *
     * @throws ValidationException
     */
    public function confirm(Request $request, Client $client, string $form): void
    {
        if (! $client->has_password) {
            $this->codes->confirm($client, $request->input($form.'email_code'), $form.'email_code');

            return;
        }

        // Checked as "current_password", so the usual messages and their translations apply.
        $validator = Validator::make(
            ['current_password' => $request->input($form.'current_password')],
            ['current_password' => ['required', 'string', 'current_password:web']],
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages([$form.'current_password' => $validator->errors()->first('current_password')]);
        }
    }
}
