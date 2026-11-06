<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_subject_meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_subject_id')->constrained('section_subjects')->cascadeOnDelete();
            $table->string('day', 20);
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room', 50);
            $table->timestamps();

            $table->unique(['section_subject_id', 'day'], 'section_subject_meeting_day_unique');
            $table->index(['day', 'start_time', 'end_time'], 'section_subject_meeting_overlap_idx');
            $table->index('room', 'section_subject_meeting_room_idx');
        });

        DB::table('section_subjects')->whereNotNull('day')->whereNotNull('start_time')->whereNotNull('end_time')
            ->orderBy('id')->chunkById(500, function ($assignments): void {
                foreach ($assignments as $assignment) {
                    DB::table('section_subject_meetings')->insert([
                        'section_subject_id' => $assignment->id,
                        'day' => $assignment->day,
                        'start_time' => $assignment->start_time,
                        'end_time' => $assignment->end_time,
                        'room' => $assignment->room ?? '',
                        'created_at' => $assignment->created_at,
                        'updated_at' => $assignment->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_subject_meetings');
    }
};
