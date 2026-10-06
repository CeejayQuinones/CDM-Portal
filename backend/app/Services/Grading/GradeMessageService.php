<?php

namespace App\Services\Grading;

use App\Models\GradeConversation;
use App\Models\GradeMessage;
use App\Models\GradeSheet;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradeMessageService
{
    public function __construct(private readonly GradingService $grading) {}

    public function createStudentConversation(User $actor, GradeSheet $sheet): array
    {
        $student = $this->student($actor);
        abort_unless($sheet->status === 'published' && $sheet->latestSubmission()->whereHas('students', fn ($query) => $query->where('student_id', $student->id))->exists(), 404, 'Published grade not found.');
        $conversation = GradeConversation::firstOrCreate(['grade_sheet_id' => $sheet->id, 'student_id' => $student->id], ['professor_id' => $sheet->professor_id]);

        return $this->open($actor, $conversation);
    }

    public function professorConversations(User $actor): array
    {
        $professor = $this->professor($actor);
        $items = GradeConversation::query()->where('professor_id', $professor->id)->whereHas('gradeSheet', fn ($query) => $query->where('status', 'published'))
            ->with(['student.userProfile', 'gradeSheet.latestSubmission', 'gradeSheet.sectionSubject.subject', 'gradeSheet.sectionSubject.section'])
            ->with(['messages' => fn ($query) => $query->latest()->limit(1)])
            ->withCount(['messages as unread_count' => fn ($query) => $query->where('sender_user_id', '!=', $actor->id)->whereNull('read_at')->whereNull('unsent_at')])
            ->orderByDesc('updated_at')->get();

        return $items->map(function (GradeConversation $conversation): array {
            $profile = $conversation->student->userProfile;
            $context = $this->context($conversation->gradeSheet);
            $latest = $conversation->messages->first();

            return ['id' => $conversation->id, 'student_number' => $conversation->student->student_number, 'student_name' => trim($profile->first_name.' '.$profile->last_name), 'subject_code' => $context['subject_code'], 'subject_name' => $context['subject_name'], 'section' => $context['section'], 'latest_message' => $latest?->unsent_at ? 'Message unsent' : ($latest?->body ?: ($latest?->attachment_path ? 'Image attachment' : null)), 'latest_at' => $latest?->created_at?->toIso8601String(), 'unread_count' => (int) $conversation->unread_count];
        })->all();
    }

    public function open(User $actor, GradeConversation $conversation): array
    {
        $this->authorize($actor, $conversation);
        $read = GradeMessage::query()->where('grade_conversation_id', $conversation->id)->where('sender_user_id', '!=', $actor->id)->whereNull('read_at')->whereNull('unsent_at')->update(['read_at' => now()]);
        if ($read > 0) {
            $this->grading->audit($actor, 'grade_message.read', $conversation->gradeSheet, null, ['conversation_id' => $conversation->id, 'message_count' => $read]);
        }
        $conversation->load(['gradeSheet.latestSubmission', 'gradeSheet.sectionSubject.subject', 'gradeSheet.sectionSubject.section', 'student.userProfile', 'professor.user.profile', 'messages.sender.profile']);
        $context = $this->context($conversation->gradeSheet);

        return [
            'id' => $conversation->id,
            'grade' => ['grade_sheet_id' => $conversation->grade_sheet_id, 'subject_code' => $context['subject_code'], 'subject_name' => $context['subject_name'], 'section' => $context['section'], 'professor' => $context['professor']],
            'student' => ['student_number' => $conversation->student->student_number, 'name' => trim($conversation->student->userProfile->first_name.' '.$conversation->student->userProfile->last_name)],
            'messages' => $conversation->messages->map(fn (GradeMessage $message) => $this->messageData($message, $actor))->all(),
        ];
    }

    public function send(User $actor, GradeConversation $conversation, ?string $body, ?UploadedFile $attachment): array
    {
        $this->authorize($actor, $conversation);
        abort_unless($conversation->gradeSheet()->where('status', 'published')->exists(), 409, 'Messages are available only for Published grades.');
        $body = trim((string) $body);
        abort_unless($body !== '' || $attachment, 422, 'Enter a message or attach an image.');
        $path = null;
        if ($attachment) {
            $extension = strtolower($attachment->extension() ?: 'bin');
            $path = $attachment->storeAs('grade-messages/'.$conversation->id, Str::uuid().'.'.$extension, 'local');
            abort_unless($path, 500, 'The image attachment could not be stored.');
        }
        try {
            $message = DB::transaction(function () use ($actor, $conversation, $body, $attachment, $path) {
                $originalName = $attachment ? basename($attachment->getClientOriginalName()) : null;
                $safeName = $originalName ? Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName), 120, '') : null;
                $message = GradeMessage::create(['grade_conversation_id' => $conversation->id, 'sender_user_id' => $actor->id, 'body' => $body !== '' ? $body : null, 'attachment_path' => $path, 'attachment_name' => $safeName, 'attachment_mime' => $attachment?->getMimeType()]);
                $conversation->touch();
                $this->grading->audit($actor, 'grade_message.created', $conversation->gradeSheet, null, ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'has_attachment' => (bool) $path]);

                return $message;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return $this->messageData($message->load('sender.profile'), $actor);
    }

    public function unsend(User $actor, GradeMessage $message): array
    {
        $message->loadMissing('conversation.gradeSheet');
        $this->authorize($actor, $message->conversation);
        abort_unless($message->sender_user_id === $actor->id, 403, 'Only the sender can unsend this message.');
        if ($message->unsent_at) {
            return $this->messageData($message, $actor);
        }
        $path = $message->attachment_path;
        DB::transaction(function () use ($actor, $message): void {
            $locked = GradeMessage::lockForUpdate()->findOrFail($message->id);
            if ($locked->unsent_at) {
                return;
            }
            $locked->forceFill(['body' => null, 'attachment_path' => null, 'attachment_name' => null, 'attachment_mime' => null, 'unsent_at' => now()])->save();
            $this->grading->audit($actor, 'grade_message.unsent', $message->conversation->gradeSheet, null, ['conversation_id' => $message->grade_conversation_id, 'message_id' => $message->id]);
        });
        if ($path) {
            Storage::disk('local')->delete($path);
        }

        return $this->messageData($message->fresh('sender.profile'), $actor);
    }

    public function attachment(User $actor, GradeMessage $message): StreamedResponse
    {
        $message->loadMissing('conversation');
        $this->authorize($actor, $message->conversation);
        abort_unless(! $message->unsent_at && $message->attachment_path && Storage::disk('local')->exists($message->attachment_path), 404, 'Attachment not found.');

        return Storage::disk('local')->response($message->attachment_path, $message->attachment_name ?: 'grade-message-image', ['Content-Type' => $message->attachment_mime ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'"]);
    }

    private function authorize(User $actor, GradeConversation $conversation): void
    {
        abort_unless($conversation->gradeSheet()->where('status', 'published')->exists(), 404, 'Conversation not found.');
        $actor->loadMissing('role');
        if ($actor->role?->role_name === Role::STUDENT) {
            abort_unless($conversation->student()->where('user_id', $actor->id)->exists(), 404, 'Conversation not found.');

            return;
        }
        if ($actor->role?->role_name === Role::PROFESSOR) {
            abort_unless($conversation->professor()->where('user_id', $actor->id)->exists(), 404, 'Conversation not found.');

            return;
        }
        abort(403, 'Grade messages are available only to the Student and Professor.');
    }

    private function student(User $actor): Student
    {
        $actor->loadMissing('role');
        abort_unless($actor->role?->role_name === Role::STUDENT, 403);

        return Student::where('user_id', $actor->id)->firstOrFail();
    }

    private function professor(User $actor): Professor
    {
        $actor->loadMissing('role');
        abort_unless($actor->role?->role_name === Role::PROFESSOR, 403);

        return Professor::where('user_id', $actor->id)->firstOrFail();
    }

    private function context(GradeSheet $sheet): array
    {
        $snapshot = $sheet->latestSubmission?->configuration['context'] ?? null;
        if (is_array($snapshot)) {
            return $snapshot;
        }
        $sheet->loadMissing(['sectionSubject.subject', 'sectionSubject.section', 'professor.user.profile']);
        $profile = $sheet->professor?->user?->profile;

        return ['subject_code' => $sheet->sectionSubject?->subject?->subject_code, 'subject_name' => $sheet->sectionSubject?->subject?->subject_name, 'section' => $sheet->sectionSubject?->section?->section_name, 'professor' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? ''))];
    }

    private function messageData(GradeMessage $message, User $actor): array
    {
        $message->loadMissing('sender.profile');
        $profile = $message->sender?->profile;

        return ['id' => $message->id, 'mine' => $message->sender_user_id === $actor->id, 'sender' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')) ?: $message->sender?->username, 'body' => $message->unsent_at ? null : $message->body, 'attachment_name' => $message->unsent_at ? null : $message->attachment_name, 'attachment_url' => ! $message->unsent_at && $message->attachment_path ? '/api/grading/messages/'.$message->id.'/attachment' : null, 'read_at' => $message->read_at?->toIso8601String(), 'unsent_at' => $message->unsent_at?->toIso8601String(), 'created_at' => $message->created_at?->toIso8601String()];
    }
}
