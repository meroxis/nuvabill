<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The knowledge base, announcements and the network status page: help articles in categories,
 * news for clients, server checks every few minutes, and issues or planned maintenance with
 * their updates. Articles, categories and announcements can be translated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kb_categories')) {
            Schema::create('kb_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 120)->unique();
                $table->string('description', 500)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_visible')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('kb_articles')) {
            Schema::create('kb_articles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('kb_category_id')->constrained()->cascadeOnDelete();
                $table->string('title', 190);
                $table->string('slug', 190)->unique();
                $table->longText('body');
                $table->boolean('is_published')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedInteger('helpful_yes')->default(0);
                $table->unsignedInteger('helpful_no')->default(0);
                $table->timestamps();

                $table->index(['kb_category_id', 'is_published']);
            });
        }

        if (! Schema::hasTable('announcements')) {
            Schema::create('announcements', function (Blueprint $table) {
                $table->id();
                $table->string('title', 190);
                $table->string('slug', 190)->unique();
                $table->longText('body');
                $table->boolean('is_published')->default(true);
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamps();
            });
        }

        // The title and text of an article, category or announcement in another language.
        if (! Schema::hasTable('content_translations')) {
            Schema::create('content_translations', function (Blueprint $table) {
                $table->id();
                $table->string('translatable_type', 40);
                $table->unsignedBigInteger('translatable_id');
                $table->string('locale', 10);
                $table->string('title', 190)->nullable();
                $table->longText('body')->nullable();
                $table->timestamps();

                $table->unique(['translatable_type', 'translatable_id', 'locale'], 'content_translations_unique');
            });
        }

        if (! Schema::hasTable('network_incidents')) {
            Schema::create('network_incidents', function (Blueprint $table) {
                $table->id();
                $table->string('title', 190);
                $table->string('kind', 20)->default('issue');
                $table->string('status', 20);
                $table->string('impact', 20)->default('minor');
                $table->json('server_ids')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('network_incident_updates')) {
            Schema::create('network_incident_updates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('network_incident_id')->constrained()->cascadeOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 20);
                $table->text('message');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('server_checks')) {
            Schema::create('server_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('server_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_up');
                $table->unsignedInteger('response_ms')->nullable();
                $table->timestamp('checked_at');

                $table->index(['server_id', 'checked_at']);
            });
        }

        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'status_public')) {
                $table->boolean('status_public')->default(false);
                $table->string('status_name', 100)->nullable();
                $table->boolean('status_up')->nullable();
                $table->unsignedTinyInteger('status_failures')->default(0);
                $table->timestamp('status_checked_at')->nullable();
                $table->timestamp('status_changed_at')->nullable();
            }
        });

        // Staff who answer tickets may write help articles and post network news.
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];

            if (! in_array('support.manage', $permissions, true) && ! in_array('settings.manage', $permissions, true)) {
                continue;
            }

            $added = array_values(array_diff(['content.manage', 'status.manage'], $permissions));

            if ($added !== []) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, ...$added])]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['status_public', 'status_name', 'status_up', 'status_failures', 'status_checked_at', 'status_changed_at']);
        });

        Schema::dropIfExists('server_checks');
        Schema::dropIfExists('network_incident_updates');
        Schema::dropIfExists('network_incidents');
        Schema::dropIfExists('content_translations');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
