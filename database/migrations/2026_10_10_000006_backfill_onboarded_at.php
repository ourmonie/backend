<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Onboarding (requirement 16) is for people who have not seen the app
     * yet. Members who have signed in before already know the dashboard, so
     * their first-ever sign-in marks them as onboarded; accounts that were
     * created but never opened will meet the guided screens on next login.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('onboarded_at')
            ->whereNotNull('last_login_at')
            ->update(['onboarded_at' => DB::raw('last_login_at')]);
    }

    public function down(): void
    {
        // Backfill only - nothing structural to undo.
    }
};
