<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradingScale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GradingScaleController extends Controller
{
    public function index()
    {
        return $this->ok(GradingScale::query()->orderByDesc('max_percentage')->get());
    }

    public function store(Request $request)
    {
        $scale = DB::transaction(function () use ($request) {
            GradingScale::query()->lockForUpdate()->get(['id']);
            $input = $this->validated($request);
            $this->assertNoActiveOverlap($input);

            return GradingScale::create($input);
        });

        return $this->ok($scale, 201);
    }

    public function update(Request $request, GradingScale $gradingScale)
    {
        $scale = DB::transaction(function () use ($request, $gradingScale) {
            GradingScale::query()->lockForUpdate()->get(['id']);
            $scale = GradingScale::query()->findOrFail($gradingScale->id);
            $input = $this->validated($request, $scale);
            $this->assertNoActiveOverlap($input, $scale->id);
            $scale->update($input);

            return $scale;
        });

        return $this->ok($scale);
    }

    public function status(Request $request, GradingScale $gradingScale)
    {
        $input = $request->validate(['is_active' => 'required|boolean']);
        $scale = DB::transaction(function () use ($gradingScale, $input) {
            GradingScale::query()->lockForUpdate()->get(['id']);
            $scale = GradingScale::query()->findOrFail($gradingScale->id);
            $candidate = $scale->only(['min_percentage', 'max_percentage', 'grade_point', 'remark']) + $input;
            $this->assertNoActiveOverlap($candidate, $scale->id);
            $scale->update($input);

            return $scale;
        });

        return $this->ok($scale);
    }

    private function validated(Request $request, ?GradingScale $scale = null): array
    {
        return $request->validate([
            'min_percentage' => 'required|numeric|min:0|max:100|lte:max_percentage',
            'max_percentage' => 'required|numeric|min:0|max:100|gte:min_percentage',
            'grade_point' => 'required|numeric',
            'remark' => 'required|string|max:80',
            'is_active' => $scale ? 'sometimes|boolean' : 'required|boolean',
        ]);
    }

    private function assertNoActiveOverlap(array $input, ?int $ignoreId = null): void
    {
        if (! ($input['is_active'] ?? true)) {
            return;
        }

        $overlaps = GradingScale::query()->where('is_active', true)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('min_percentage', '<=', $input['max_percentage'])
            ->where('max_percentage', '>=', $input['min_percentage'])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['min_percentage' => 'This active percentage range overlaps an existing active range.']);
        }
    }

    private function ok(mixed $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}
