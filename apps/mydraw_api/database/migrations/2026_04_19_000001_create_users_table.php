<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';

            $table->increments('Id');
            $table->string('Name', 200);
            $table->string('Email', 200);
            $table->string('Password', 255);

            $table->unique('Email', 'user_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
