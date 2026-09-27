<?php

namespace App\Models;

use Database\Factories\TaxRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tax, such as "VAT 20%", for clients in one country (and optionally one state), or for everyone
 * when the country is empty. The rate is in hundredths of a percent: 2000 is 20%.
 */
class TaxRule extends Model
{
    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'rate', 'country', 'state'];

    protected function casts(): array
    {
        return [
            'rate' => 'integer',
        ];
    }

    /**
     * The rate as people write it, for example "20%" or "7.5%".
     */
    public function percentLabel(): string
    {
        return self::formatRate($this->rate);
    }

    public static function formatRate(int $rate): string
    {
        return rtrim(rtrim(number_format($rate / 100, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * Where the rule applies, for lists: "Everyone", "GB" or "US / TX".
     */
    public function placeLabel(): string
    {
        if ($this->country === null) {
            return __('Everyone');
        }

        return $this->state ? "{$this->country} / {$this->state}" : $this->country;
    }
}
