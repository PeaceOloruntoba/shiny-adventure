<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
            $table->string('api_token', 80)->nullable()->unique()->after('remember_token');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_cents')->default(0)->after('docx_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'api_token']);
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['amount_cents']);
        });
    }
};
