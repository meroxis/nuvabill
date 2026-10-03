<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * Actions whose entries show invoices, payments, quotes, saved cards, commissions or the wallet
     * (SQL "like" patterns). Staff without billing.view do not see them. The plan change entries
     * listed here hold amounts or invoice numbers; the other plan change entries do not.
     */
    public const BILLING_ACTIONS = [
        'invoice.%', 'payment.%', 'payment_method.%', 'wallet.%', 'credit_note.%', 'quote.%', 'affiliate.commission', 'affiliate.withdrawn', 'client.auto_pay',
        'service.plan_change_credit', 'service.plan_change_expired', 'service.plan_change_refund',
    ];

    /**
     * Subjects that are billing records, so their entries are billing data whatever the action.
     */
    public const BILLING_SUBJECTS = ['invoice', 'transaction', 'quote', 'payment_method'];

    protected $fillable = ['actor_type', 'actor_id', 'subject_type', 'subject_id', 'client_id', 'action', 'description', 'ip_address'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Leave out the billing entries, for staff who may not see invoices and payments.
     *
     * @param  Builder<ActivityLog>  $query
     */
    public function scopeWithoutBilling(Builder $query): void
    {
        $query
            ->where(fn (Builder $query) => $query->whereNull('subject_type')->orWhereNotIn('subject_type', self::BILLING_SUBJECTS))
            ->where(function (Builder $query): void {
                foreach (self::BILLING_ACTIONS as $pattern) {
                    $query->where('action', 'not like', $pattern);
                }
            });
    }

    public function actorName(): string
    {
        return match (true) {
            $this->actor_type === null => __('System'),
            $this->actor === null => __('Deleted user'),
            default => $this->actor->name,
        };
    }
}
