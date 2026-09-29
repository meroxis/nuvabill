<?php

namespace App\Ai;

/**
 * The Claude models a site owner can pick in Settings → AI, with Anthropic's prices in US dollars
 * per million tokens. Nuvabill adds nothing: the owner pays Anthropic directly with their own key.
 */
final class AiModels
{
    public const DEFAULT = 'claude-haiku-4-5';

    /**
     * @var array<string, array{label: string, name: string, help: string, input: float, output: float, fallbacks: bool}>
     */
    public const ALL = [
        'claude-haiku-4-5' => [
            'label' => 'Fast and low cost',
            'name' => 'Claude Haiku 4.5',
            'help' => 'Good for summaries, translations and most replies.',
            'input' => 1.00,
            'output' => 5.00,
            'fallbacks' => false,
        ],
        'claude-sonnet-5-5' => [
            'label' => 'Best answers',
            'name' => 'Claude Sonnet 5.5',
            'help' => 'For hard technical tickets. Costs about twice as much per draft.',
            'input' => 2.00,
            'output' => 10.00,
            'fallbacks' => true,
        ],
        'claude-opus-5-5' => [
            'label' => 'Most capable',
            'name' => 'Claude Opus 5.5',
            'help' => 'The strongest model. Costs about four times as much per draft, and takes longer.',
            'input' => 4.00,
            'output' => 20.00,
            'fallbacks' => true,
        ],
    ];

    public static function current(): string
    {
        $model = (string) setting('ai.model');

        return isset(self::ALL[$model]) ? $model : self::DEFAULT;
    }

    /**
     * What tokens cost on a model, in millionths of a dollar per token. A model Nuvabill does not
     * know yet (for example one Anthropic used to rescue a declined answer) is priced like the
     * most expensive one, so the monthly limit is never undercounted.
     *
     * @return array{input: float, output: float}
     */
    public static function price(string $model): array
    {
        foreach (self::ALL as $id => $details) {
            if ($model === $id || str_starts_with($model, $id.'-')) {
                return ['input' => $details['input'], 'output' => $details['output']];
            }
        }

        $prices = array_map(fn (array $details): float => $details['output'], self::ALL);
        $mostExpensive = self::ALL[array_search(max($prices), $prices, true)];

        return ['input' => $mostExpensive['input'], 'output' => $mostExpensive['output']];
    }

    public static function costMicros(string $model, int $inputTokens, int $outputTokens): int
    {
        $price = self::price($model);

        return (int) round($inputTokens * $price['input'] + $outputTokens * $price['output']);
    }
}
