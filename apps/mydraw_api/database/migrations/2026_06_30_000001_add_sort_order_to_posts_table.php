<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('status');
            $table->index(['status', 'sort_order']);
        });

        // Published admin posts: order 1 = newest (matches default gallery sort).
        DB::statement("
            UPDATE posts p
            INNER JOIN (
                SELECT p2.id,
                       ROW_NUMBER() OVER (ORDER BY p2.created_at DESC, p2.id DESC) AS rn
                FROM posts p2
                INNER JOIN users u ON u.Id = p2.user_id
                WHERE p2.status = 'published'
                  AND u.IsAdmin = 1
            ) ranked ON ranked.id = p.id
            SET p.sort_order = ranked.rn
        ");
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
