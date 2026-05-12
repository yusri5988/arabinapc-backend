<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            if (Schema::hasColumn('users', 'email') && ! Schema::hasColumn('users', 'phone')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->renameColumn('email', 'phone');
                });
            }

            if (Schema::hasColumn('users', 'email_verified_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropColumn('email_verified_at');
                });
            }
        }

        if (Schema::hasTable('password_reset_tokens')
            && Schema::hasColumn('password_reset_tokens', 'email')
            && ! Schema::hasColumn('password_reset_tokens', 'phone')
        ) {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->renameColumn('email', 'phone');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            if (Schema::hasColumn('users', 'phone') && ! Schema::hasColumn('users', 'email')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->renameColumn('phone', 'email');
                });
            }

            if (! Schema::hasColumn('users', 'email_verified_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->timestamp('email_verified_at')->nullable()->after('email');
                });
            }
        }

        if (Schema::hasTable('password_reset_tokens')
            && Schema::hasColumn('password_reset_tokens', 'phone')
            && ! Schema::hasColumn('password_reset_tokens', 'email')
        ) {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->renameColumn('phone', 'email');
            });
        }
    }
};
