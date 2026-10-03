<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Availability becomes personal: one row per user and day, whatever the meeting.
     */
    public function up(): void
    {
        $seen = [];
        $duplicateIds = [];

        foreach (DB::table('availability_days')->orderByDesc('updated_at')->orderByDesc('id')->get(['id', 'user_id', 'day']) as $row) {
            $key = $row->user_id.'|'.$row->day;

            if (isset($seen[$key])) {
                $duplicateIds[] = $row->id;
            } else {
                $seen[$key] = true;
            }
        }

        foreach (array_chunk($duplicateIds, 500) as $ids) {
            DB::table('availability_days')->whereIn('id', $ids)->delete();
        }

        Schema::table('availability_days', function (Blueprint $table) {
            $table->dropForeign(['meeting_id']);
            $table->dropUnique(['meeting_id', 'user_id', 'day']);
        });

        Schema::table('availability_days', function (Blueprint $table) {
            $table->dropColumn('meeting_id');
            $table->unique(['user_id', 'day']);
        });
    }

    /**
     * Reverse the migrations (rows lose their meeting; acceptable).
     */
    public function down(): void
    {
        Schema::table('availability_days', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('availability_days', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'day']);
        });

        Schema::table('availability_days', function (Blueprint $table) {
            $table->foreignId('meeting_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['meeting_id', 'user_id', 'day']);
        });
    }
};
