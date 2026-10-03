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
        Schema::rename('availabilities', 'slot_votes');

        Schema::table('meetings', function (Blueprint $table) {
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            $table->date('deadline')->nullable();
            $table->string('status')->default('collecting');
            $table->dateTime('confirmed_starts_at')->nullable();
            $table->dateTime('confirmed_ends_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
        });

        Schema::table('meeting_slots', function (Blueprint $table) {
            $table->dateTime('ends_at')->nullable();
        });

        Schema::create('availability_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->char('cells', 28);
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availability_days');

        Schema::table('meeting_slots', function (Blueprint $table) {
            $table->dropColumn('ends_at');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['range_start', 'range_end', 'deadline', 'status', 'confirmed_starts_at', 'confirmed_ends_at', 'reminder_sent_at']);
        });

        Schema::rename('slot_votes', 'availabilities');
    }
};
