<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('IsAdmin')
                ->nullable()
                ->default(null)
                ->after('AvatarKey');

            $table->unique('IsAdmin', 'users_is_admin_unique');
        });

        DB::statement(
            'UPDATE `users`
             SET `IsAdmin` = 1
             WHERE `Id` = (
                 SELECT `Id` FROM (
                     SELECT `Id` FROM `users` ORDER BY `Id` ASC LIMIT 1
                 ) AS first_user
             )'
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_is_admin_unique');
            $table->dropColumn('IsAdmin');
        });
    }
};
