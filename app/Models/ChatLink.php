<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's Telegram chat or WhatsApp number, linked from the client area by QR code.
 */
class ChatLink extends Model
{
    public const TELEGRAM = 'telegram';

    public const WHATSAPP = 'whatsapp';

    protected $fillable = ['client_id', 'channel', 'external_id', 'name', 'last_inbound_at'];

    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * WhatsApp lets a business send free text only within 24 hours of the client's last message.
     */
    public function canReceiveFreeText(): bool
    {
        return $this->channel !== self::WHATSAPP || ($this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(23)));
    }

    public function channelLabel(): string
    {
        return $this->channel === self::WHATSAPP ? 'WhatsApp' : 'Telegram';
    }
}
