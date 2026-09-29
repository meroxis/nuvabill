<?php

use Database\Seeders\EmailTemplateTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email templates in every language: clients get emails in the language they picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_template_translations')) {
            Schema::create('email_template_translations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('email_template_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 10);
                $table->string('subject')->nullable();
                $table->text('body')->nullable();
                $table->timestamps();

                $table->unique(['email_template_id', 'locale']);
            });
        }

        EmailTemplateTranslations::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_translations');
    }
};
