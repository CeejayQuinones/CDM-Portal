<?php

namespace App\Http\Controllers\Api\Enrollment;

use App\Http\Controllers\Controller;
use App\Models\OvrfTemplateDraft;
use App\Services\Enrollment\OvrfTemplateDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OvrfTemplateDraftController extends Controller
{
    public function __construct(private readonly OvrfTemplateDraftService $drafts) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = OvrfTemplateDraft::query()
            ->with('uploader.profile:id,user_id,first_name,last_name')
            ->latest('id')->paginate(30);

        return response()->json(['data' => [
            'data' => $page->getCollection()->map(fn (OvrfTemplateDraft $draft): array => $this->present($draft)),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:5120']]);
        $draft = $this->drafts->upload($request->user(), $input['file']);

        return response()->json(['data' => $this->present($draft), 'message' => 'Draft uploaded. It is not an official OVRF template.'], 201);
    }

    public function preview(Request $request, OvrfTemplateDraft $draft): Response
    {
        $bytes = $this->drafts->preview($request->user(), $draft);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="OVRF-draft-'.$draft->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'X-OVRF-Template-Status' => 'draft-not-official',
        ]);
    }

    public function archive(Request $request, OvrfTemplateDraft $draft): JsonResponse
    {
        return response()->json(['data' => $this->present($this->drafts->archive($request->user(), $draft))]);
    }

    private function present(OvrfTemplateDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'version' => $draft->id,
            'filename' => $draft->original_filename,
            'sha256' => $draft->sha256,
            'size_bytes' => $draft->size_bytes,
            'page_count' => $draft->page_count,
            'status' => $draft->status,
            'uploaded_at' => $draft->created_at?->toIso8601String(),
            'uploaded_by' => trim(($draft->uploader?->profile?->first_name ?? '').' '.($draft->uploader?->profile?->last_name ?? '')) ?: 'Registrar account',
            'archived_at' => $draft->archived_at?->toIso8601String(),
        ];
    }
}
