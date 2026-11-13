<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\StudentRecords\StudentRosterImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentRosterImportController extends Controller
{
    public function __construct(private readonly StudentRosterImportService $import) {}

    public function template(Request $request): StreamedResponse
    {
        $this->registrarOnly($request);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, StudentRosterImportService::HEADERS);
            fclose($out);
        }, 'student-alumni-roster-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->registrarOnly($request);
        $file = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']])['file'];

        return response()->json(['success' => true, 'data' => $this->import->preview($file, $request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->registrarOnly($request);
        $token = $request->validate(['preview_token' => ['required', 'string', 'size:64']])['preview_token'];

        return response()->json(['success' => true, 'data' => $this->import->confirm($token, $request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    private function registrarOnly(Request $request): void
    {
        abort_unless($request->user()?->role?->role_name === Role::REGISTRAR_STAFF, 403);
    }
}
