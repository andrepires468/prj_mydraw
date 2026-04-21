<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona controle de publicação em `posts`:
 *  - `status`     : enum('draft','published'), default 'draft' — usado para
 *                   filtrar listagens públicas e distinguir rascunhos.
 *  - `draft_uuid` : char(36) nullable — permite localizar o post de rascunho
 *                   correspondente ao UUID que o frontend gera no fluxo de
 *                   criação, para agrupar uploads sequenciais no mesmo post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->enum('status', ['draft', 'published'])
                ->default('draft')
                ->after('description');

            $table->char('draft_uuid', 36)
                ->nullable()
                ->after('status');

            $table->index(['user_id', 'draft_uuid'], 'posts_user_draft_uuid_index');
            $table->index(['status', 'created_at'], 'posts_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_user_draft_uuid_index');
            $table->dropIndex('posts_status_created_at_index');
            $table->dropColumn(['status', 'draft_uuid']);
        });
    }
};
