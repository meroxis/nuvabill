<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search engines: a title, description and "hide from search engines" per product and product
 * group, and old store addresses that forward to new ones after a web address changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'product_groups'] as $table) {
            if (! Schema::hasColumn($table, 'seo_title')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->string('seo_title', 120)->nullable();
                    $table->string('seo_description', 320)->nullable();
                    $table->boolean('seo_hidden')->default(false);
                });
            }
        }

        if (! Schema::hasTable('seo_redirects')) {
            Schema::create('seo_redirects', function (Blueprint $table) {
                $table->id();
                $table->string('from_path', 191)->unique();
                $table->string('to_path', 191);
                $table->unsignedInteger('hits')->default(0);
                $table->timestamps();
            });
        }

        $this->removeOldRobotsFile();
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');

        foreach (['products', 'product_groups'] as $table) {
            if (Schema::hasColumn($table, 'seo_title')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn(['seo_title', 'seo_description', 'seo_hidden']);
                });
            }
        }
    }

    /**
     * Earlier versions shipped a public/robots.txt that allowed everything. The web server shows that
     * file instead of the one Nuvabill now makes, so it goes, but only while nobody has changed it.
     */
    private function removeOldRobotsFile(): void
    {
        $file = public_path('robots.txt');

        if (is_file($file) && preg_replace('/\s+/', ' ', trim((string) file_get_contents($file))) === 'User-agent: * Disallow:') {
            @unlink($file);
        }
    }
};
