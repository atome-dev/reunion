<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep requests and invitations when their author's account is deleted.
     */
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
        });
        Schema::table('meetings', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->change();
        });
        Schema::table('meetings', function (Blueprint $table): void {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('group_invitations', function (Blueprint $table): void {
            $table->dropForeign(['invited_by']);
        });
        Schema::table('group_invitations', function (Blueprint $table): void {
            $table->foreignId('invited_by')->nullable()->change();
        });
        Schema::table('group_invitations', function (Blueprint $table): void {
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Restore the not-null cascade. Rows whose author was already deleted (null) cannot satisfy it and are removed.
     */
    public function down(): void
    {
        DB::table('meetings')->whereNull('created_by')->delete();
        DB::table('group_invitations')->whereNull('invited_by')->delete();

        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
        });
        Schema::table('meetings', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable(false)->change();
        });
        Schema::table('meetings', function (Blueprint $table): void {
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('group_invitations', function (Blueprint $table): void {
            $table->dropForeign(['invited_by']);
        });
        Schema::table('group_invitations', function (Blueprint $table): void {
            $table->foreignId('invited_by')->nullable(false)->change();
        });
        Schema::table('group_invitations', function (Blueprint $table): void {
            $table->foreign('invited_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
