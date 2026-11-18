<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redirect manager: staff's own redirects (301 or 302, with or without the part after ?), where
 * each one came from and when it was last used, and a short list of addresses people could not
 * open. Rows made before keep today's behaviour: 301, and the part after ? goes along.
 *
 * Safe to run again: each column and the table are only added when they are missing.
 */
return new class extends Migration
{
    /**
     * @return array<string, Closure(Blueprint): mixed>
     */
    private function columns(): array
    {
        return [
            'status' => fn (Blueprint $table) => $table->unsignedSmallInteger('status')->default(301),
            'source' => fn (Blueprint $table) => $table->string('source', 20)->default('auto'),
            'keep_query' => fn (Blueprint $table) => $table->boolean('keep_query')->default(true),
            'last_hit_at' => fn (Blueprint $table) => $table->timestamp('last_hit_at')->nullable(),
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('seo_redirects')) {
            foreach ($this->columns() as $column => $add) {
                if (! Schema::hasColumn('seo_redirects', $column)) {
                    Schema::table('seo_redirects', fn (Blueprint $table) => $add($table));
                }
            }
        }

        if (! Schema::hasTable('seo_missing_pages')) {
            Schema::create('seo_missing_pages', function (Blueprint $table) {
                $table->id();
                $table->string('path', 191)->unique();
                $table->unsignedInteger('hits')->default(1);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_missing_pages');

        if (Schema::hasTable('seo_redirects')) {
            foreach (array_keys($this->columns()) as $column) {
                if (Schema::hasColumn('seo_redirects', $column)) {
                    Schema::table('seo_redirects', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
