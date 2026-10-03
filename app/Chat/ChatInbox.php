<?php

namespace App\Chat;

use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Models\ChatLink;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\Locales;
use App\Support\TicketDesk;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Messages clients send to the bot: a link code from the client area, a few commands (invoices,
 * services, tickets, help, stop), and anything else, which becomes a support ticket or a reply on
 * the client's open chat ticket. Returns the answer to send back, or null to stay quiet.
 */
class ChatInbox
{
    /**
     * A chat ticket stays "the open conversation" for this long after its last message.
     */
    private const TICKET_DAYS = 3;

    /**
     * Messages one chat may send in a minute. Each one can email staff and alert their phones, as
     * the client area's own limit on ticket replies keeps in check.
     */
    private const PER_MINUTE = 10;

    public function __construct(private TicketDesk $desk) {}

    public function handle(string $channel, string $externalId, string $text, ?string $name): ?string
    {
        $text = trim($text);

        if ($this->tooFast($channel, $externalId)) {
            return $this->slowDown($channel, $externalId);
        }

        $code = $this->linkCode($channel, $text);

        if ($code !== null) {
            $link = LinkCodes::redeem($code, $channel, $externalId, $name);

            return $link === null
                ? __('This code is old or wrong. Open the client area, go to Account and scan the QR code again.')
                : __('Done! This chat is now connected to your :company account. You will get invoices, reminders and ticket replies here. Send /help to see what you can ask.', ['company' => setting('company.name')], $this->locale($link->client));
        }

        $link = ChatLink::query()->with('client')->where('channel', $channel)->where('external_id', $externalId)->first();
        $command = $this->command($text);

        if ($link === null) {
            // On WhatsApp the owner may answer people in the WhatsApp Business app, so stay quiet
            // unless someone asks how to connect.
            if ($channel === ChatLink::WHATSAPP && ! in_array($command, ['help', 'start'], true)) {
                return null;
            }

            return __('Hello! To get your invoices, reminders and ticket replies here, sign in to the client area, open Account and scan the QR code under “Get alerts on your phone”.');
        }

        $client = $link->client;
        $locale = $this->locale($client);

        // A closed account cannot sign in, so it cannot use the chat either; it may still disconnect it.
        if ($client->status === ClientStatus::Closed && $command !== 'stop') {
            return $channel === ChatLink::WHATSAPP ? null : __('This account is closed. Contact support if you need help.', [], $locale);
        }

        $link->forceFill(['last_inbound_at' => now(), 'name' => $name !== null ? mb_substr($name, 0, 120) : $link->name])->save();

        return match ($command) {
            'help', 'start' => $this->help($locale),
            'invoices' => $this->invoices($client, $locale),
            'services' => $this->services($client, $locale),
            'tickets' => $this->tickets($client, $locale),
            'stop' => $this->stop($link, $locale),
            default => $this->message($link, $text, $locale),
        };
    }

    /**
     * Counts this message, and says whether the chat sent more than its share this minute. Keyed
     * by the chat, so linked clients, strangers and code guesses are all slowed down.
     */
    private function tooFast(string $channel, string $externalId): bool
    {
        $key = 'chat-inbox:'.$channel.':'.$externalId;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    /**
     * Say once a minute that the chat is too fast, and drop the messages. On WhatsApp, strangers
     * are left to the owner, so they get no answer from the bot.
     */
    private function slowDown(string $channel, string $externalId): ?string
    {
        $link = ChatLink::query()->with('client')->where('channel', $channel)->where('external_id', $externalId)->first();

        if (($link === null && $channel === ChatLink::WHATSAPP) || ! Cache::add('chat-inbox-warned:'.$channel.':'.$externalId, true, 60)) {
            return null;
        }

        return __('You are sending messages too fast. Please wait a minute.', [], $link?->client !== null ? $this->locale($link->client) : null);
    }

    /**
     * The code in "/start CODE" (Telegram) or "LINK CODE" (WhatsApp). On WhatsApp the word LINK
     * is needed: one-word messages like "Invoices" or "Received" are not codes.
     */
    private function linkCode(string $channel, string $text): ?string
    {
        $pattern = $channel === ChatLink::TELEGRAM ? '/^\/start\s+([A-Za-z0-9]{8})$/' : '/^link\s+([A-Za-z0-9]{8})$/i';

        return preg_match($pattern, $text, $match) ? $match[1] : null;
    }

    private function command(string $text): ?string
    {
        $word = mb_strtolower(ltrim((string) preg_replace('/@\S+$/', '', $text), '/'));

        return in_array($word, ['help', 'start', 'invoices', 'services', 'tickets', 'stop'], true) ? $word : null;
    }

    private function help(string $locale): string
    {
        return __("You can send:\n/invoices: your unpaid invoices\n/services: your services\n/tickets: your open tickets\n/stop: disconnect this chat\n\nAnything else you write goes to our support team as a ticket.", [], $locale);
    }

    private function invoices(Client $client, string $locale): string
    {
        $invoices = $client->invoices()->where('status', InvoiceStatus::Unpaid)->orderBy('due_at')->limit(5)->get();

        if ($invoices->isEmpty()) {
            return __('You have no unpaid invoices.', [], $locale);
        }

        $lines = $invoices->map(fn ($invoice): string => '• '.$invoice->displayNumber().' · '.money($invoice->balance(), $invoice->currency).' · '.__('due :date', ['date' => $invoice->due_at->format('d M Y')], $locale));

        return __('Your unpaid invoices:', [], $locale)."\n".$lines->implode("\n")."\n\n".route('client.invoices.index');
    }

    private function services(Client $client, string $locale): string
    {
        $services = $client->services()->with('product')->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])->limit(10)->get();

        if ($services->isEmpty()) {
            return __('You have no active services.', [], $locale);
        }

        $lines = $services->map(fn ($service): string => '• '.$service->label().' · '.$service->status->label()
            .($service->next_due_date ? ' · '.__('renews :date', ['date' => $service->next_due_date->format('d M Y')], $locale) : ''));

        return __('Your services:', [], $locale)."\n".$lines->implode("\n");
    }

    private function tickets(Client $client, string $locale): string
    {
        $tickets = Ticket::query()->where('client_id', $client->id)->where('status', '!=', TicketStatus::Closed)->latest('last_reply_at')->limit(5)->get();

        if ($tickets->isEmpty()) {
            return __('You have no open tickets. Write your question here and we will open one.', [], $locale);
        }

        return __('Your open tickets:', [], $locale)."\n".$tickets->map(fn (Ticket $ticket): string => '• #'.$ticket->number.' · '.$ticket->subject.' · '.$ticket->status->label())->implode("\n");
    }

    private function stop(ChatLink $link, string $locale): string
    {
        $link->delete();

        return __('This chat is disconnected. You will not get messages here anymore. You can connect again from the client area.', [], $locale);
    }

    /**
     * Anything else goes to support: a reply on the client's open chat ticket, or a new ticket.
     */
    private function message(ChatLink $link, string $text, string $locale): ?string
    {
        if ($text === '') {
            return __('Please write your message as text. You can add screenshots to your ticket in the client area.', [], $locale);
        }

        if ($link->channel === ChatLink::WHATSAPP && ! setting('chat.whatsapp_tickets')) {
            return null;
        }

        $client = $link->client;
        $ticket = Ticket::query()
            ->where('client_id', $client->id)
            ->where('status', '!=', TicketStatus::Closed)
            ->where('last_reply_at', '>=', now()->subDays(self::TICKET_DAYS))
            ->whereHas('replies', fn ($query) => $query->where('channel', $link->channel))
            ->latest('last_reply_at')
            ->first();

        if ($ticket !== null) {
            $this->desk->replyAsClient($ticket, $client, mb_substr($text, 0, 20000))->forceFill(['channel' => $link->channel])->save();

            return __('Added to ticket #:number. We will answer here and by email.', ['number' => $ticket->number], $locale);
        }

        $department = TicketDepartment::query()->find(setting('chat.department')) ?? TicketDepartment::query()->orderBy('sort_order')->first();

        if ($department === null) {
            return null;
        }

        $subject = mb_substr(trim(strtok($text, "\n") ?: $text), 0, 80);
        $ticket = $this->desk->open($client, $department, $subject, mb_substr($text, 0, 20000));
        $ticket->replies()->oldest('id')->first()?->forceFill(['channel' => $link->channel])->save();

        return __('I opened ticket #:number for you in :department. We will answer here and by email.', ['number' => $ticket->number, 'department' => $department->name], $locale);
    }

    private function locale(Client $client): string
    {
        return Locales::isSupported($client->language) ? (string) $client->language : Locales::default();
    }
}
