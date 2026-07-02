<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE transactions MODIFY type ENUM('topup', 'expense', 'return_to_admin') NOT NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $hasReturnToAdmin = DB::select("SELECT COUNT(*) as cnt FROM transactions WHERE type = 'return_to_admin'");

        if (($hasReturnToAdmin[0]->cnt ?? 0) > 0) {
            throw new RuntimeException(
                'Cannot rollback: return_to_admin transactions exist. Remove or migrate those records first before rolling back.'
            );
        }

        DB::statement("ALTER TABLE transactions MODIFY type ENUM('topup', 'expense') NOT NULL");
    }
};
