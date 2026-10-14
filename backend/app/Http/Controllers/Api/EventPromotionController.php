<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventPromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = Event::query()
            ->where('status', 'published')
            ->whereNull('parent_event_id')
            ->where('ends_at', '>', now())
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(fn ($match) => $match
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('venue', 'like', "%{$search}%")))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->where('ends_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->where('starts_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderBy('starts_at')
            ->paginate($validated['per_page'] ?? 24);
        $page->getCollection()->transform(fn (Event $event) => $this->serialize($event));

        return response()->json(['success' => true, 'data' => $page])->header('Cache-Control', 'public, max-age=60');
    }

    public function show(Event $event): JsonResponse
    {
        abort_unless($event->status === 'published' && $event->parent_event_id === null && $event->ends_at?->isFuture(), 404, 'Event not found.');

        return response()->json(['success' => true, 'data' => $this->serialize($event)])->header('Cache-Control', 'public, max-age=60');
    }

    /** @return array<string, mixed> */
    private function serialize(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'venue' => $event->venue,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'status' => $event->presentationStatus(),
            'organizer' => 'Colegio de Montalban',
            'public_audience' => 'CDM community',
            'image_url' => null,
        ];
    }
}
