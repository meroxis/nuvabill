<?php

namespace App\Chat;

use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * The Telegram groups staff alerts can go to (Settings → Chat apps).
 *
 * Anyone on Telegram can add the bot to a group of their own and give that group any name. So a
 * group is only offered after someone sent the message from the settings page in it: only staff
 * see that message. It carries a code that works once and for a short time.
 */
class TelegramGroups
{
    /** How many groups are kept. When there are more, the ones confirmed longest ago go first. */
    public const LIMIT = 20;

    /** How long a code lasts, and how much of that must be left when the settings page shows it. */
    private const CODE_MINUTES = 60;

    private const SHOWN_MINUTES = 30;

    public function __construct(private Settings $settings) {}

    /**
     * The groups staff can pick, as chat id => name.
     *
     * @return array<int|string, string>
     */
    public function choices(): array
    {
        return array_map(fn (mixed $group): string => is_array($group) ? (string) ($group['title'] ?? '') : (string) $group, $this->kept());
    }

    /**
     * The message to send in the group: the bot's own /start command with the code.
     */
    public function command(): string
    {
        return '/start@'.setting('chat.telegram_bot').' '.$this->code();
    }

    /**
     * A link that lets the staff member pick a group in Telegram: Telegram adds the bot to it and
     * sends the message there.
     */
    public function link(): string
    {
        return 'https://t.me/'.setting('chat.telegram_bot').'?startgroup='.$this->code();
    }

    /**
     * A message from a group. When it is the bot's /start command with the code the settings page
     * shows, the group becomes a choice and the code is used up. Returns the group's name then,
     * and null for every other message.
     *
     * @param  array<string, mixed>  $message
     */
    public function confirm(array $message): ?string
    {
        $chat = (array) ($message['chat'] ?? []);
        $id = (string) ($chat['id'] ?? '');
        $code = $this->currentCode();

        if ($code === null
            || ! in_array($chat['type'] ?? '', ['group', 'supergroup'], true)
            || ! preg_match('/^-?\d{1,20}$/', $id)
            || ! preg_match('/^\/start(?:@(\w+))?\s+([A-Za-z0-9_-]{1,64})$/', trim((string) ($message['text'] ?? '')), $match)
            || ($match[1] !== '' && strcasecmp($match[1], (string) setting('chat.telegram_bot')) !== 0)
            || ! hash_equals($code, $match[2])) {
            return null;
        }

        $title = mb_substr((string) ($chat['title'] ?? $id), 0, 80);
        $chosen = (string) setting('chat.telegram_staff_chat');
        $groups = $this->kept();
        unset($groups[$id]);
        $groups[$id] = ['title' => $title, 'confirmed_at' => now()->timestamp];

        foreach (array_keys($groups) as $oldest) {
            if (count($groups) <= self::LIMIT) {
                break;
            }

            if ((string) $oldest !== $chosen && (string) $oldest !== $id) {
                unset($groups[$oldest]);
            }
        }

        $this->settings->setMany(['chat.telegram_groups' => $groups, 'chat.telegram_group_code' => '']);

        $sender = mb_substr((string) ($message['from']['first_name'] ?? $message['from']['username'] ?? ''), 0, 60);
        Activity::log('chat.telegram', "Telegram group \"{$title}\" was confirmed for staff alerts".($sender !== '' ? " by {$sender}" : ''));

        return $title;
    }

    /**
     * The bot was removed from a group: it is no longer a choice.
     */
    public function forget(string $id): void
    {
        $groups = (array) setting('chat.telegram_groups');

        if (array_key_exists($id, $groups)) {
            unset($groups[$id]);
            $this->settings->set('chat.telegram_groups', $groups);
        }
    }

    /**
     * Forget the groups that are not kept, see kept().
     */
    public function dropUnconfirmed(): void
    {
        $kept = $this->kept();

        if (count($kept) !== count((array) setting('chat.telegram_groups'))) {
            $this->settings->set('chat.telegram_groups', $kept);
        }
    }

    /**
     * Confirmed groups are saved with their details. An older version saved every group the bot
     * was added to, as a plain name: of those only the group already chosen for staff alerts is
     * kept, so alerts do not stop after an update.
     *
     * @return array<int|string, array{title: string, confirmed_at: int}|string>
     */
    private function kept(): array
    {
        $chosen = (string) setting('chat.telegram_staff_chat');

        return array_filter(
            (array) setting('chat.telegram_groups'),
            fn (mixed $group, int|string $id): bool => is_array($group) || (is_string($group) && (string) $id === $chosen),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The code for the settings page: the current one while enough of its time is left, else a new one.
     */
    private function code(): string
    {
        $current = setting('chat.telegram_group_code');

        if ($this->currentCode() !== null && $current['expires_at'] >= now()->addMinutes(self::SHOWN_MINUTES)->timestamp) {
            return $current['code'];
        }

        $code = Str::random(12);
        $this->settings->set('chat.telegram_group_code', ['code' => $code, 'expires_at' => now()->addMinutes(self::CODE_MINUTES)->timestamp]);

        return $code;
    }

    /**
     * The code that still works, or null.
     */
    private function currentCode(): ?string
    {
        $current = setting('chat.telegram_group_code');

        return is_array($current) && is_string($current['code'] ?? null) && $current['code'] !== '' && (int) ($current['expires_at'] ?? 0) >= now()->timestamp
            ? $current['code']
            : null;
    }
}
