<?php

namespace App\Automations;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What starts an automation. Instant triggers follow something that just happened (an invoice
 * was paid); timed ones are checked once a day ("an invoice is 7 days overdue").
 */
final class Trigger
{
    /**
     * @param  string  $label  English; translated when shown.
     * @param  string  $subject  What a run is about: invoice, client, service, ticket, order, domain or quote.
     * @param  string  $group  Invoices, Clients, Services, Support, Orders or Domains, in English.
     * @param  string|null  $daysUnit  For timed triggers, the words after the number of days: "days after the due date".
     * @param  string|null  $daysSentence  The same as a plural sentence: ":count day after the due date|:count days after the due date".
     * @param  (Closure(CarbonImmutable, int): Builder<covariant Model>)|null  $due  For timed triggers: the subjects due today.
     * @param  (Closure(Model): bool)|null  $stillTrue  Checked after every wait: when false, the run stops.
     * @param  (Closure(Model): string)|null  $occasion  For timed triggers that can come back for the same subject, such as
     *                                                   a domain that expires again next year: what tells this time apart.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $subject,
        public readonly string $group,
        public readonly ?string $daysUnit = null,
        public readonly ?string $daysSentence = null,
        public readonly ?Closure $due = null,
        public readonly ?Closure $stillTrue = null,
        public readonly ?Closure $occasion = null,
    ) {}

    /**
     * The occasion part of a timed run's dedupe key: "d30:2027-05-01" for a domain that expires
     * on 1 May 2027, so the same domain gets a new run when it expires again next year.
     */
    public function occasionFor(Model $subject, int $days): string
    {
        $occasion = $this->occasion !== null ? (string) ($this->occasion)($subject) : '';

        return 'd'.$days.($occasion !== '' ? ':'.$occasion : '');
    }

    public function isTimed(): bool
    {
        return $this->due !== null;
    }

    /**
     * "An invoice is overdue: 7 days after the due date".
     */
    public function describe(?int $days): string
    {
        return $this->isTimed()
            ? __(':trigger: :days', ['trigger' => __($this->label), 'days' => trans_choice((string) $this->daysSentence, (int) $days, ['count' => (int) $days])])
            : __($this->label);
    }
}
