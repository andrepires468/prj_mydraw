<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery', function (Blueprint $table) {
            $table->json('ai_tags')->nullable()->after('metadata_raw');
            $table->string('ai_rating', 16)->nullable()->after('ai_tags');
            $table->string('ai_rating_reason', 512)->nullable()->after('ai_rating');
            $table->timestamp('ai_categorized_at')->nullable()->after('ai_rating_reason');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->json('ai_tags')->nullable()->after('description');
            $table->string('content_rating', 16)->nullable()->after('ai_tags');
            $table->timestamp('ai_categorized_at')->nullable()->after('content_rating');
        });
    }

    public function down(): void
    {
        Schema::table('gallery', function (Blueprint $table) {
            $table->dropColumn([
                'ai_tags',
                'ai_rating',
                'ai_rating_reason',
                'ai_categorized_at',
            ]);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn([
                'ai_tags',
                'content_rating',
                'ai_categorized_at',
            ]);
        });
    }
};
