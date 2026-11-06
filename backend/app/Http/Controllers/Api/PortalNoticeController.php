<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampusNotice;
use App\Models\CampusNoticeAcknowledgement;
use App\Models\Role;
use App\Services\PortalNoticeAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalNoticeController extends Controller
{
    public function __construct(private readonly PortalNoticeAggregator $notices) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('role');
        abort_unless(in_array($user->role?->role_name, [Role::GUEST, Role::STUDENT], true), 403, 'CDM Buddy is available to Guest and Student accounts.');

        return response()->json([
            'success' => true,
            'data' => $this->notices->forUser($user),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function acknowledge(Request $request, CampusNotice $campusNotice): JsonResponse
    {
        $user = $request->user()->loadMissing('role');
        $role = $user->role?->role_name;
        abort_unless(in_array($role, [Role::GUEST, Role::STUDENT], true), 403);

        $now = now();
        abort_unless(
            $campusNotice->status === CampusNotice::STATUS_PUBLISHED
            && $campusNotice->requires_acknowledgement
            && in_array($role, $campusNotice->audiences ?? [], true)
            && (! $campusNotice->published_at || $campusNotice->published_at->lte($now))
            && (! $campusNotice->publication_start_at || $campusNotice->publication_start_at->lte($now))
            && (! $campusNotice->publication_end_at || $campusNotice->publication_end_at->gte($now)),
            404
        );

        CampusNoticeAcknowledgement::query()->firstOrCreate([
            'campus_notice_id' => $campusNotice->id,
            'user_id' => $user->id,
        ], ['acknowledged_at' => $now]);

        return response()->json([
            'success' => true,
            'data' => ['notice_id' => 'campus-'.$campusNotice->id, 'acknowledged' => true],
        ])->header('Cache-Control', 'private, no-store');
    }
}
