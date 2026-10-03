<?php

namespace App\Ai;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Mail\TemplateMailer;
use App\Models\AiUsage;
use App\Support\Demo;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JsonException;

/**
 * Talks to Claude with the owner's own Anthropic key: checks that the feature is on and the monthly
 * limit is not reached, sends one request, logs what it cost and warns staff at 80% of the limit.
 * Callers take private details out of the text first (see Redactor).
 */
class Claude
{
    public const FEATURES = ['drafts', 'translate', 'summaries', 'descriptions'];

    /**
     * Enough room for the answer and, on models that think first, for their thinking.
     */
    private const MAX_TOKENS = 16000;

    public function __construct(private ClientFactory $factory, private Settings $settings) {}

    /**
     * AI help is set up: there is a key, or this is the public demo, where sample answers stand in.
     */
    public function isReady(): bool
    {
        return Demo::isEnabled() || trim((string) setting('ai.key')) !== '';
    }

    public function isOn(string $feature): bool
    {
        return $this->isReady() && in_array($feature, self::FEATURES, true) && (bool) setting('ai.'.$feature);
    }

    /**
     * Ask for plain text back.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function text(string $feature, string $system, array $messages, string $effort = 'low', ?Model $subject = null): string
    {
        return $this->ask($feature, $system, $messages, $effort, null, $subject);
    }

    /**
     * Ask for JSON that matches the schema.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function json(string $feature, string $system, array $messages, array $schema, string $effort = 'low', ?Model $subject = null): array
    {
        $text = $this->ask($feature, $system, $messages, $effort, $schema, $subject);

        try {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiUnavailable(__('The AI answered in a way Nuvabill could not read. Please try again.'));
        }

        return is_array($data) ? $data : [];
    }

    /**
     * A tiny request to check the key and model, for the Test button in Settings → AI.
     */
    public function test(): string
    {
        $answer = $this->ask('test', 'Answer with the single word OK.', [['role' => 'user', 'content' => 'Are you there?']], 'low', null, null, checkFeature: false);

        return AiModels::ALL[AiModels::current()]['name'].': '.mb_substr(trim($answer), 0, 40);
    }

    /**
     * What AI requests cost this calendar month, in millionths of a dollar.
     */
    public function spentThisMonth(): int
    {
        return (int) AiUsage::query()->where('created_at', '>=', now()->startOfMonth())->sum('cost_micros');
    }

    public function requestsThisMonth(): int
    {
        return AiUsage::query()->where('created_at', '>=', now()->startOfMonth())->count();
    }

    /**
     * The monthly limit in millionths of a dollar. 0 means no limit.
     */
    public function limitMicros(): int
    {
        return max(0, (int) setting('ai.monthly_limit')) * 10_000;
    }

    public function limitReached(): bool
    {
        return $this->limitMicros() > 0 && $this->spentThisMonth() >= $this->limitMicros();
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $schema
     */
    private function ask(string $feature, string $system, array $messages, string $effort, ?array $schema, ?Model $subject, bool $checkFeature = true): string
    {
        $key = trim((string) setting('ai.key'));

        if ($key === '') {
            throw new AiUnavailable(__('AI help is not set up yet. Add your Anthropic key in Settings → AI.'));
        }

        if ($checkFeature && ! $this->isOn($feature)) {
            throw new AiUnavailable(__('This AI help is switched off in Settings → AI.'));
        }

        if ($this->limitReached()) {
            throw new AiUnavailable(__('The monthly AI limit is reached, so AI help is paused until :date. You can raise the limit in Settings → AI.', [
                'date' => now()->addMonthNoOverflow()->startOfMonth()->translatedFormat('d M'),
            ]));
        }

        $model = AiModels::current();
        $details = AiModels::ALL[$model];
        $outputConfig = [];

        // Haiku 4.5 takes no effort level; the newer models think first, and effort sets how long.
        if ($model !== 'claude-haiku-4-5') {
            $outputConfig['effort'] = $effort;
        }

        if ($schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];
        }

        // A web request waits for the answer; the newer models can take a while on long tickets. A queue
        // worker has no limit and gets none here, so the jobs it runs after this one are not cut off.
        if ((int) ini_get('max_execution_time') > 0) {
            set_time_limit(150);
        }

        try {
            $response = $this->factory->make($key)->beta->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: $messages,
                model: $model,
                // If the model declines, Anthropic lets another model answer instead of returning nothing.
                fallbacks: $details['fallbacks'] ? 'default' : null,
                outputConfig: $outputConfig === [] ? null : $outputConfig,
                system: $system,
                betas: $details['fallbacks'] ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (AuthenticationException) {
            throw new AiUnavailable(__('Anthropic did not accept the AI key. Check it in Settings → AI.'));
        } catch (PermissionDeniedException) {
            throw new AiUnavailable(__('This Anthropic key may not use :model. Pick another model in Settings → AI.', ['model' => $details['name']]));
        } catch (RateLimitException) {
            throw new AiUnavailable(__('The AI got too many requests at once. Try again in a minute.'));
        } catch (BadRequestException $exception) {
            throw new AiUnavailable(__('The AI could not answer: :error', ['error' => $this->errorText($exception)]));
        } catch (APIStatusException $exception) {
            report($exception);

            throw new AiUnavailable(__('The AI service is busy or having problems. Try again in a minute.'));
        } catch (APIConnectionException $exception) {
            throw new AiUnavailable(__('Nuvabill could not reach the AI service: :error', ['error' => mb_substr($exception->getMessage(), 0, 160)]));
        }

        $this->record($feature, $model, $response, $subject);

        if ($response->stopReason === 'refusal') {
            throw new AiUnavailable(__('The AI declined to answer this one. Please write it yourself.'));
        }

        $text = '';

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        if (trim($text) === '') {
            throw new AiUnavailable(__('The AI sent an empty answer. Please try again.'));
        }

        return $text;
    }

    private function errorText(APIStatusException $exception): string
    {
        $body = $exception->body;
        $message = is_array($body) && is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : '';

        return mb_substr($message !== '' ? $message : 'HTTP '.$exception->status, 0, 200);
    }

    /**
     * Log what the request cost. When another model answered after a decline, each part is priced
     * at the rates of the model that ran it.
     */
    private function record(string $feature, string $model, BetaMessage $response, ?Model $subject): void
    {
        $usage = $response->usage;
        $input = $usage->inputTokens + (int) $usage->cacheCreationInputTokens + (int) $usage->cacheReadInputTokens;
        $cost = 0;

        foreach ($usage->iterations ?? [] as $part) {
            $partModel = ($part->type ?? '') === 'fallback_message' ? (string) $part->model : $model;
            $cost += AiModels::costMicros($partModel, (int) ($part->inputTokens ?? 0), (int) ($part->outputTokens ?? 0));
        }

        if (($usage->iterations ?? []) === []) {
            $cost = AiModels::costMicros($response->model, $input, $usage->outputTokens);
        }

        AiUsage::create([
            'admin_id' => auth('admin')->id(),
            'feature' => $feature,
            'model' => $response->model,
            'input_tokens' => $input,
            'output_tokens' => $usage->outputTokens,
            'cost_micros' => $cost,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);

        $this->warnNearLimit();
    }

    /**
     * Email the company once a month when AI spending passes 80% of the limit.
     */
    private function warnNearLimit(): void
    {
        $limit = $this->limitMicros();
        $month = now()->format('Y-m');
        $email = (string) setting('company.email');

        if ($limit === 0 || setting('ai.warned_month') === $month || $email === '' || $this->spentThisMonth() < $limit * 0.8) {
            return;
        }

        $this->settings->set('ai.warned_month', $month);

        rescue(fn () => app(TemplateMailer::class)->sendText(
            $email,
            (string) setting('company.name'),
            __('AI help has used 80% of its monthly limit'),
            __("AI help has used :spent of your :limit monthly limit. When the limit is reached, AI help pauses until :date.\n\nYou can change the limit in Settings → AI.", [
                'spent' => self::dollars($this->spentThisMonth()),
                'limit' => self::dollars($limit),
                'date' => Carbon::now()->addMonthNoOverflow()->startOfMonth()->translatedFormat('d M'),
            ]),
        ));
    }

    /**
     * Millionths of a dollar as "$3.40".
     */
    public static function dollars(int $micros): string
    {
        return '$'.number_format($micros / 1_000_000, 2);
    }
}
