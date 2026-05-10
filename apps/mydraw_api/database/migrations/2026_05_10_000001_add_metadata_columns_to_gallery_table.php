<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery', function (Blueprint $table) {
            $table->string('metadata_type', 32)->nullable()->after('position');
            $table->text('metadata_prompt')->nullable();
            $table->text('metadata_negative_prompt')->nullable();
            $table->unsignedSmallInteger('metadata_steps')->nullable();
            $table->string('metadata_sampler', 64)->nullable();
            $table->string('metadata_schedule_type', 64)->nullable();
            $table->decimal('metadata_cfg_scale', 6, 2)->nullable();
            $table->unsignedBigInteger('metadata_seed')->nullable();
            $table->unsignedInteger('metadata_width')->nullable();
            $table->unsignedInteger('metadata_height')->nullable();
            $table->string('metadata_model', 255)->nullable();
            $table->string('metadata_model_hash', 64)->nullable();
            $table->unsignedTinyInteger('metadata_clip_skip')->nullable();
            $table->string('metadata_version', 128)->nullable();
            $table->json('metadata_loras')->nullable();
            $table->json('metadata_raw')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gallery', function (Blueprint $table) {
            $table->dropColumn([
                'metadata_type',
                'metadata_prompt',
                'metadata_negative_prompt',
                'metadata_steps',
                'metadata_sampler',
                'metadata_schedule_type',
                'metadata_cfg_scale',
                'metadata_seed',
                'metadata_width',
                'metadata_height',
                'metadata_model',
                'metadata_model_hash',
                'metadata_clip_skip',
                'metadata_version',
                'metadata_loras',
                'metadata_raw',
            ]);
        });
    }
};
