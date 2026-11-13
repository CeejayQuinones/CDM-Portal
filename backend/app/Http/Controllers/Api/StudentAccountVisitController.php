<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\StudentAccountVisitRequest;
use App\Services\TurnstileVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StudentAccountVisitController extends Controller
{
    public function __construct(private readonly TurnstileVerificationService $turnstile) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:20'],
            'full_name' => ['required', 'string', 'min:3', 'max:200'],
            'contact_email' => ['required', 'email', 'max:255'],
            'preferred_date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'preferred_time' => ['required', 'in:morning,afternoon'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);
        $this->turnstile->verify($data['turnstile_token'] ?? null, 'registration', (string) $request->ip());

        $visit = StudentAccountVisitRequest::query()->create([
            'reference' => (string) Str::uuid(),
            'student_number' => trim($data['student_number']),
            'full_name' => trim($data['full_name']),
            'contact_email' => mb_strtolower(trim($data['contact_email'])),
            'preferred_date' => $data['preferred_date'],
            'preferred_time' => $data['preferred_time'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Visit requested. Your preferred date is not yet confirmed. Bring your physical school ID when the Registrar confirms your visit.',
            'data' => ['reference' => $visit->reference],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role?->role_name === Role::REGISTRAR_STAFF, 403);

        return response()->json(['success' => true, 'data' => StudentAccountVisitRequest::query()
            ->whereIn('status', ['requested', 'scheduled'])
            ->orderBy('preferred_date')->orderBy('id')->limit(100)->get()]);
    }

    public function show(string $reference): JsonResponse
    {
        $visit = StudentAccountVisitRequest::query()->where('reference', $reference)->firstOrFail();

        return response()->json(['success' => true, 'data' => [
            'reference' => $visit->reference,
            'status' => $visit->status,
            'scheduled_date' => $visit->scheduled_date?->toDateString(),
            'scheduled_time' => $visit->scheduled_time,
        ]]);
    }

    public function update(Request $request, StudentAccountVisitRequest $visit): JsonResponse
    {
        abort_unless($request->user()?->role?->role_name === Role::REGISTRAR_STAFF, 403);
        $data = $request->validate([
            'status' => ['required', 'in:scheduled,resolved,declined'],
            'scheduled_date' => ['required_if:status,scheduled', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'scheduled_time' => ['required_if:status,scheduled', 'nullable', 'date_format:H:i'],
            'physical_school_id_checked' => ['required_if:status,resolved', 'boolean'],
        ]);
        if ($data['status'] === 'resolved' && empty($data['physical_school_id_checked'])) {
            abort(422, 'Confirm the physical school ID, name, and student number before resolving the visit.');
        }
        abort_unless(in_array($visit->status, ['requested', 'scheduled'], true), 409);
        $visit->forceFill([
            'status' => $data['status'],
            'scheduled_date' => $data['status'] === 'scheduled' ? $data['scheduled_date'] : null,
            'scheduled_time' => $data['status'] === 'scheduled' ? $data['scheduled_time'] : null,
            'handled_by_user_id' => $request->user()->id,
            'handled_at' => now(),
            'identity_checked_by_user_id' => $data['status'] === 'resolved' ? $request->user()->id : $visit->identity_checked_by_user_id,
            'identity_checked_at' => $data['status'] === 'resolved' ? now() : $visit->identity_checked_at,
        ])->save();

        return response()->json(['success' => true, 'data' => $visit->fresh()]);
    }
}
