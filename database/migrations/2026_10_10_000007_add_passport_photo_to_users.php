<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requirement 17 - the passport photograph the dashboard demands is an
     * identity artefact, kept apart from `avatar` (which holds a Google
     * profile picture for sign-ins that brought one). A member can have both;
     * only this column satisfies the mandatory prompt.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('passport_photo')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('passport_photo');
        });
    }
};
