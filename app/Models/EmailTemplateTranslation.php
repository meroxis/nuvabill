<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email template in another language. Empty fields use the main text of the template.
 */
class EmailTemplateTranslation extends Model
{
    protected $fillable = ['email_template_id', 'locale', 'subject', 'body'];

    /**
     * @return BelongsTo<EmailTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
