<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search titles and descriptions in the site's other languages, for the home page, store groups
 * and products. The main language stays where it is (the settings, products and product groups);
 * these rows only hold the other languages. "subject" names the page: "home", "product_group:12"
 * or "product:7".
 *
 * Safe to run again: the table is only made when it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_translations')) {
            Schema::create('seo_translations', function (Blueprint $table) {
                $table->id();
                $table->string('subject', 60);
                $table->string('locale', 10);
                $table->string('title', 120)->nullable();
                $table->string('description', 320)->nullable();
                $table->timestamps();
                $table->unique(['subject', 'locale'], 'seo_translations_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_translations');
    }
};
