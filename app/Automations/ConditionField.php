<?php

namespace App\Automations;

use App\Support\Money;
use Closure;

/**
 * Something an automation can check, such as "the invoice total" or "the client's tags", with
 * the ways it can be compared.
 */
final class ConditionField
{
    /**
     * @param  string  $label  English, used as the start of a sentence: "The invoice total".
     * @param  string  $type  money, number, choice or tag.
     * @param  list<string>  $subjects  Where it can be used; empty means everywhere a client is known.
     * @param  Closure(Context): mixed  $value  The current value.
     * @param  (Closure(): array<string, string>)|null  $options  For choice fields: value => label.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly array $subjects,
        public readonly Closure $value,
        public readonly ?Closure $options = null,
    ) {}

    /**
     * @return array<string, string> Operator => sentence with :field and :value, in English.
     */
    public function operators(): array
    {
        return match ($this->type) {
            'money', 'number' => ['gt' => ':field is more than :value', 'lt' => ':field is less than :value', 'eq' => ':field is :value'],
            'tag' => ['has' => ':field has the tag :value', 'not' => ':field does not have the tag :value'],
            default => ['is' => ':field is :value', 'not' => ':field is not :value'],
        };
    }

    /**
     * The short words between the field and the value in the editor, in English: "is more than".
     *
     * @return array<string, string>
     */
    public function operatorLabels(): array
    {
        return match ($this->type) {
            'money', 'number' => ['gt' => 'is more than', 'lt' => 'is less than', 'eq' => 'is'],
            'tag' => ['has' => 'has the tag', 'not' => 'does not have the tag'],
            default => ['is' => 'is', 'not' => 'is not'],
        };
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->options !== null ? ($this->options)() : [];
    }

    public function appliesTo(string $subject): bool
    {
        return $this->subjects === [] || in_array($subject, $this->subjects, true);
    }

    public function test(Context $context, string $operator, mixed $expected): bool
    {
        $actual = ($this->value)($context);

        return match ($this->type) {
            'money' => $this->compare((int) $actual, Money::toMinor((string) $expected), $operator),
            'number' => $this->compare((int) $actual, (int) $expected, $operator),
            'tag' => $context->client()?->hasTag((string) $expected) === ($operator === 'has'),
            default => ((string) $actual === (string) $expected) === ($operator === 'is'),
        };
    }

    /**
     * "The invoice total is more than $10.00", in the language of the page.
     */
    public function describe(string $operator, mixed $value): string
    {
        $shown = match ($this->type) {
            'money' => money(Money::toMinor((string) $value)),
            'choice' => $this->options()[(string) $value] ?? (string) $value,
            default => (string) $value,
        };

        return __($this->operators()[$operator] ?? ':field is :value', ['field' => __($this->label), 'value' => $shown]);
    }

    private function compare(int $actual, int $expected, string $operator): bool
    {
        return match ($operator) {
            'gt' => $actual > $expected,
            'lt' => $actual < $expected,
            default => $actual === $expected,
        };
    }
}
