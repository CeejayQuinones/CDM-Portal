<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentAccountActivation;
use App\Services\RegistrationEmailVerificationService;
use App\Services\StudentAccountActivationService;
use App\Services\TurnstileVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAccountActivationController extends Controller
{
    public function __construct(
        private readonly StudentAccountActivationService $activation,
        private readonly TurnstileVerificationService $turnstile,
        private readonly RegistrationEmailVerificationService $emailDelivery,
    ) {}

    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);
        $this->turnstile->verify($data['turnstile_token'] ?? null, 'registration', (string) $request->ip());
        if (! $this->emailDelivery->deliveryIsConfigured()) {
            return response()->json(['success' => false, 'message' => 'Email delivery is unavailable. Please contact the portal administrator.'], 503);
        }
        $this->activation->sendCode($data['student_number'], $data['email']);

        return response()->json([
            'success' => true,
            'message' => 'If these details match an eligible school record, a verification code has been sent to the recorded email.',
        ]);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);
        $token = $this->activation->verifyCode($data['student_number'], $data['email'], $data['code']);

        return response()->json(['success' => true, 'data' => ['verification_token' => $token]]);
    }

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            'verification_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $this->activation->activate($data['student_number'], $data['email'], $data['verification_token'], $data['password']);

        return response()->json(['success' => true, 'message' => 'Account activated. You can now sign in.']);
    }

    public function prepare(Request $request, Student $student): JsonResponse
    {
        abort_unless($request->user()?->role?->role_name === Role::REGISTRAR_STAFF, 403);
        $data = $request->validate([
            'record_reference' => ['required', 'string', 'max:120'],
            'email_checked_against_record' => ['required', 'accepted'],
        ]);
        $this->activation->prepare($request->user(), $student, $data['record_reference']);

        return response()->json(['success' => true, 'message' => 'Student account prepared for activation.']);
    }

    public function show(Student $student): JsonResponse
    {
        $student->load(['user.role', 'userProfile']);
        $activation = StudentAccountActivation::query()->where('student_id', $student->id)->first();

        return response()->json(['success' => true, 'data' => [
            'email' => $student->userProfile?->email,
            'account_status' => $student->user?->status,
            'eligible_to_prepare' => $activation === null
                && $student->user?->role?->role_name === Role::STUDENT
                && $student->user?->status === 'inactive'
                && $student->user?->last_login === null
                && $student->user?->is_first_login
                && $student->user?->google_subject_id === null
                && ! $student->user->tokens()->exists(),
            'prepared' => $activation !== null,
            'claimed' => $activation?->claimed_at !== null,
        ]]);
    }

    public function correctEmail(Request $request, Student $student): JsonResponse
    {
        abort_unless($request->user()?->role?->role_name === Role::REGISTRAR_STAFF, 403);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'identity_check_reference' => ['required', 'string', 'max:120'],
            'physical_school_id_checked' => ['required', 'accepted'],
        ]);
        $this->activation->correctEmail($request->user(), $student, $data['email'], $data['identity_check_reference']);

        return response()->json(['success' => true, 'message' => 'Recorded email updated. The student must verify the new address to activate the account.']);
    }
}
