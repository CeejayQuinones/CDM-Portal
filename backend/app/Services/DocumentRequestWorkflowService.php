<?php

namespace App\Services;

use App\Mail\DocumentRequestApprovedMail;
use App\Models\Appointment;
use App\Models\AppointmentDateCapacity;
use App\Models\DocumentRequest;
use App\Models\RegistrarStaff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DocumentRequestWorkflowService
{
    public const BUSINESS_TIMEZONE = 'Asia/Manila';

    public const DEFAULT_CAPACITY = 5;

    public function __construct(
        private readonly AppointmentAvailabilityService $availability,
        private readonly RegistrarWindowService $windows,
    ) {}

    public function assignDate(DocumentRequest $request, RegistrarStaff $staff, string $date): DocumentRequest
    {
        $businessDate = CarbonImmutable::createFromFormat('Y-m-d', $date, self::BUSINESS_TIMEZONE)->startOfDay();
        abort_if($businessDate->lessThan(CarbonImmutable::now(self::BUSINESS_TIMEZONE)->startOfDay()), 422, 'Past appointment dates are unavailable.');
        abort_if($message = $this->availability->unavailableMessage($date), 422, $message);

        return DB::transaction(function () use ($date, $request, $staff): DocumentRequest {
            $locked = DocumentRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($locked->status === 'pending', 422, 'Only pending requests can be assigned an appointment date.');

            AppointmentDateCapacity::query()->insertOrIgnore(['appointment_date' => $date, 'capacity' => self::DEFAULT_CAPACITY, 'created_at' => now(), 'updated_at' => now()]);
            $capacity = AppointmentDateCapacity::query()->whereDate('appointment_date', $date)->lockForUpdate()->firstOrFail()->capacity;
            $booked = Appointment::query()->whereDate('appointment_date', $date)->where('document_request_id', '!=', $locked->id)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->count();
            abort_if($booked >= $capacity, 422, 'This appointment date is full.');

            $existing = Appointment::query()->where('document_request_id', $locked->id)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->first();
            if ($existing) {
                $existing->update(['appointment_date' => $date, 'registrar_staff_id' => $staff->id, 'status' => 'pending', 'active_slot_key' => null]);
            } else {
                Appointment::create([
                    'student_id' => $locked->student_id,
                    'document_request_id' => $locked->id,
                    'registrar_staff_id' => $staff->id,
                    'appointment_date' => $date,
                    'appointment_time' => '09:00',
                    'purpose' => $locked->purpose ?: 'Document request claim',
                    'status' => 'pending',
                    'active_slot_key' => null,
                ]);
            }
            $locked->update(['registrar_staff_id' => $staff->id]);

            return $locked->fresh(['documentType', 'activeAppointment']);
        });
    }

    /** @return array{request: DocumentRequest, email_delivered: bool} */
    public function approve(DocumentRequest $request, RegistrarStaff $staff): array
    {
        $code = (string) random_int(100000, 999999);
        $approved = DB::transaction(function () use ($code, $request, $staff): DocumentRequest {
            $locked = DocumentRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($locked->status === 'pending', 422, 'Only pending requests can be approved.');
            $appointment = Appointment::query()->where('document_request_id', $locked->id)->where('status', 'pending')->lockForUpdate()->first();
            abort_unless($appointment, 422, 'Assign a valid appointment date before approving this request.');
            abort_if($appointment->appointment_date->lt(CarbonImmutable::now(self::BUSINESS_TIMEZONE)->startOfDay()), 422, 'The assigned appointment date has already passed.');
            abort_if($this->availability->unavailableMessage($appointment->appointment_date->toDateString()), 422, 'The assigned appointment date is no longer available.');

            $locked->update([
                'status' => 'approved', 'registrar_staff_id' => $staff->id, 'approved_at' => now(),
                'verification_code_lookup' => $this->lookup($code), 'verification_code_hash' => Hash::make($code),
                'code_verified_at' => null,
            ]);
            $appointment->update(['status' => 'confirmed', 'registrar_staff_id' => $staff->id]);
            $this->audit($locked, $staff, 'pending', 'approved', 'approved');

            return $locked->fresh(['documentType', 'student.user.profile', 'activeAppointment']);
        });

        $delivered = false;
        try {
            $email = $approved->student?->user?->profile?->email;
            if ($email) {
                Mail::to($email)->send(new DocumentRequestApprovedMail($approved, $code, $this->windows->forDocument($approved->documentType->document_name)));
                $delivered = true;
            }
        } catch (\Throwable $exception) {
            Log::warning('Document approval email delivery failed.', ['document_request_id' => $approved->id, 'exception' => $exception::class]);
        }

        return ['request' => $approved, 'email_delivered' => $delivered];
    }

    public function verify(string $code, RegistrarStaff $staff): DocumentRequest
    {
        return DB::transaction(function () use ($code, $staff): DocumentRequest {
            $request = DocumentRequest::query()->where('verification_code_lookup', $this->lookup($code))->lockForUpdate()->first();
            abort_unless($request && $request->status === 'approved' && Hash::check($code, $request->verification_code_hash), 422, 'The verification code is invalid.');
            abort_if($request->code_verified_at, 422, 'This verification code has already been used.');
            $appointment = Appointment::query()->where('document_request_id', $request->id)->where('status', 'confirmed')->lockForUpdate()->first();
            abort_unless($appointment && $appointment->appointment_date->isSameDay(CarbonImmutable::now(self::BUSINESS_TIMEZONE)), 422, 'This code is not valid for an appointment today.');
            $request->update(['code_verified_at' => now(), 'registrar_staff_id' => $staff->id]);
            $this->audit($request, $staff, 'approved', 'approved', 'code_verified');

            return $request->fresh(['student.user.profile', 'documentType', 'activeAppointment']);
        });
    }

    /** @return array{request: DocumentRequest, email_delivered: bool} */
    public function resendClaimCode(DocumentRequest $request, RegistrarStaff $staff): array
    {
        $code = (string) random_int(100000, 999999);
        $updated = DB::transaction(function () use ($code, $request, $staff): DocumentRequest {
            $locked = DocumentRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($locked->status === 'approved' && $locked->code_verified_at === null, 422, 'A new claim code cannot be issued for this request.');
            $appointment = Appointment::query()->where('document_request_id', $locked->id)->where('status', 'confirmed')->lockForUpdate()->first();
            abort_unless($appointment, 422, 'A valid confirmed appointment is required before resending a claim code.');
            abort_if($appointment->appointment_date->lt(CarbonImmutable::now(self::BUSINESS_TIMEZONE)->startOfDay()), 422, 'The confirmed appointment has already passed.');
            abort_if($this->availability->unavailableMessage($appointment->appointment_date->toDateString()), 422, 'The confirmed appointment is no longer valid.');

            $locked->update([
                'verification_code_lookup' => $this->lookup($code),
                'verification_code_hash' => Hash::make($code),
                'code_verified_at' => null,
                'registrar_staff_id' => $staff->id,
            ]);
            $this->audit($locked, $staff, 'approved', 'approved', 'claim_code_regenerated');

            return $locked->fresh(['documentType', 'student.user.profile', 'activeAppointment']);
        });

        $delivered = false;
        try {
            $email = $updated->student?->user?->profile?->email;
            if ($email) {
                Mail::to($email)->send(new DocumentRequestApprovedMail($updated, $code, $this->windows->forDocument($updated->documentType->document_name)));
                $delivered = true;
            }
        } catch (\Throwable $exception) {
            Log::warning('Claim-code resend email delivery failed.', ['document_request_id' => $updated->id, 'exception' => $exception::class]);
        }

        return ['request' => $updated, 'email_delivered' => $delivered];
    }

    public function finalize(DocumentRequest $request, RegistrarStaff $staff, string $action, ?string $reason = null): DocumentRequest
    {
        return DB::transaction(function () use ($action, $reason, $request, $staff): DocumentRequest {
            $locked = DocumentRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($locked->status === 'approved', 422, 'Only approved requests can be completed or cancelled.');
            abort_unless($locked->code_verified_at, 422, 'Verify the claim code before completing or cancelling this request.');
            $appointment = Appointment::query()->where('document_request_id', $locked->id)->where('status', 'confirmed')->lockForUpdate()->firstOrFail();
            $status = $action === 'complete' ? 'completed' : 'cancelled';
            $locked->update(['status' => $status, 'registrar_staff_id' => $staff->id, $status.'_at' => now(), 'cancellation_reason' => $status === 'cancelled' ? $reason : $locked->cancellation_reason, 'verification_code_lookup' => null, 'verification_code_hash' => null]);
            $appointment->update(['status' => $status, $status.'_at' => now(), 'remarks' => $reason ?: $appointment->remarks, 'active_slot_key' => null]);
            $this->audit($locked, $staff, 'approved', $status, $status, $reason);

            return $locked->fresh(['documentType', 'student.user.profile', 'latestAppointment']);
        });
    }

    public function reject(DocumentRequest $request, RegistrarStaff $staff, string $reason): DocumentRequest
    {
        return DB::transaction(function () use ($reason, $request, $staff): DocumentRequest {
            $locked = DocumentRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($locked->status === 'pending', 422, 'Only pending requests can be rejected.');
            Appointment::query()->where('document_request_id', $locked->id)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->get()->each->update(['status' => 'cancelled', 'cancelled_at' => now(), 'active_slot_key' => null, 'remarks' => $reason]);
            $locked->update(['status' => 'rejected', 'registrar_staff_id' => $staff->id, 'rejected_at' => now(), 'remarks' => $reason, 'verification_code_lookup' => null, 'verification_code_hash' => null]);
            $this->audit($locked, $staff, 'pending', 'rejected', 'rejected', $reason);

            return $locked->fresh(['documentType', 'student.user.profile', 'latestAppointment']);
        });
    }

    public function lookup(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function audit(DocumentRequest $request, ?RegistrarStaff $staff, string $from, string $to, string $action, ?string $reason = null): void
    {
        $request->statusChanges()->create(['registrar_staff_id' => $staff?->id, 'actor_type' => $staff ? 'registrar' : 'system', 'from_status' => $from, 'to_status' => $to, 'action' => $action, 'reason' => $reason]);
    }
}
