<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Services\Event\EventAuthorizationService;
use App\Services\Event\EventCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EventCertificateController extends Controller
{
    public function __construct(private readonly EventCertificateService $certificates, private readonly EventAuthorizationService $authorization) {}

    public function configure(Request $request, Event $event): JsonResponse
    {
        $actor = $this->active($request);
        abort_unless($this->authorization->isCoordinator($actor, $event), 403, 'Only an Event Coordinator may configure certificates.');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'certificates_enabled' => ['required', 'boolean'], 'certificate_minimum_minutes' => ['nullable', 'integer', 'between:1,100000'], 'required_event_ids' => ['array', 'max:100'], 'required_event_ids.*' => ['integer', 'distinct']]);
        $updated = $this->certificates->configure($event, $data, $actor);

        return response()->json(['success' => true, 'message' => 'Certificate requirements updated.', 'data' => ['version' => $updated->version, 'settings' => $this->settings($updated)]]);
    }

    public function status(Request $request, Event $event): JsonResponse
    {
        $actor = $this->student($request);

        return response()->json(['success' => true, 'data' => $this->certificates->status($event, $actor->student)])->header('Cache-Control', 'private, no-store');
    }

    public function issue(Request $request, Event $event): JsonResponse
    {
        $actor = $this->student($request);
        $certificate = $this->certificates->issue($event, $actor->student, $actor);

        return response()->json(['success' => true, 'message' => 'Certificate is ready.', 'data' => $this->certificates->serialize($certificate)]);
    }

    public function download(Request $request, Event $event): Response
    {
        $actor = $this->student($request);
        $certificate = $this->certificates->issue($event, $actor->student, $actor);

        return $this->certificates->download($certificate);
    }

    public function verify(string $code): JsonResponse
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $code), 404);
        $result = $this->certificates->verify($code);
        abort_unless($result, 404, 'Certificate not found.');

        return response()->json(['success' => true, 'data' => $result])->header('Cache-Control', 'public, max-age=300');
    }

    private function active(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403, 'Your account is not active.');
        $user->loadMissing(['role', 'student']);

        return $user;
    }

    private function student(Request $request): User
    {
        $user = $this->active($request);
        abort_unless($user->role?->role_name === Role::STUDENT && $user->student, 403, 'Only a Student may access their certificate.');

        return $user;
    }

    private function settings(Event $event): array
    {
        return ['enabled' => $event->certificates_enabled, 'minimum_minutes' => $event->certificate_minimum_minutes, 'required_event_ids' => $event->certificateRequirements->pluck('required_event_id')->values()];
    }
}
