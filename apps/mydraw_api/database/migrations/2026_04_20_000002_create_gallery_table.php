<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')
                ->references('Id')->on('users')
                ->cascadeOnDelete();
            $table->char('draft_uuid', 36)->nullable();
            $table->string('object_key');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['draft_uuid', 'user_id']);
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery');
    }
};
