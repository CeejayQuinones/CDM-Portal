<?php

namespace App\Services\Event;

use App\Models\Course;
use App\Models\Event;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventService
{
    public function __construct(private readonly EventAuditWriter $audit) {}

    public function create(array $data, User $actor): Event
    {
        return DB::transaction(function () use ($data, $actor): Event {
            $status = ($data['intent'] ?? 'draft') === 'publish' ? 'published' : 'draft';
            $this->validateAudiences($data['audiences']);
            if ($status === 'published') {
                $this->assertVenueAvailable($data);
            }
            $event = Event::query()->create($this->attributes($data) + [
                'status' => $status, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->replaceAudiences($event, $data['audiences']);
            $this->audit->write($event, $actor, 'created', ['status' => $status, 'version' => 1]);
            if ($status === 'published') {
                $this->audit->write($event, $actor, 'published', ['status' => $status, 'version' => 1]);
            }

            return $event->load(['audiences.course', 'audiences.section']);
        }, 3);
    }

    public function update(Event $event, array $data, User $actor): Event
    {
        return DB::transaction(function () use ($event, $data, $actor): Event {
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);
            if ((int) $data['version'] !== $locked->version) {
                throw ValidationException::withMessages(['version' => 'This event changed after you opened it. Reload and try again.']);
            }
            if (in_array($locked->status, ['cancelled', 'archived'], true)) {
                abort(409, 'Cancelled or archived events cannot be edited.');
            }
            $this->validateAudiences($data['audiences']);
            if ($locked->status === 'published') {
                $this->assertVenueAvailable($data, $locked->id);
            }
            $locked->fill($this->attributes($data) + ['updated_by' => $actor->id, 'version' => $locked->version + 1])->save();
            $this->replaceAudiences($locked, $data['audiences']);
            $this->audit->write($locked, $actor, 'updated', ['version' => $locked->version]);

            return $locked->load(['audiences.course', 'audiences.section']);
        }, 3);
    }

    public function transition(Event $event, string $action, int $version, User $actor): Event
    {
        return DB::transaction(function () use ($event, $action, $version, $actor): Event {
            $locked = Event::query()->lockForUpdate()->with('audiences')->findOrFail($event->id);
            if ($version !== $locked->version) {
                throw ValidationException::withMessages(['version' => 'This event changed after you opened it. Reload and try again.']);
            }
            $next = match ($action) {
                'publish' => $locked->status === 'draft' ? 'published' : null,
                'cancel' => in_array($locked->status, ['draft', 'published'], true) ? 'cancelled' : null,
                'archive' => in_array($locked->status, ['draft', 'published', 'cancelled'], true) ? 'archived' : null,
                default => null,
            };
            if (! $next) {
                abort(409, 'That event status change is not allowed.');
            }
            if ($next === 'published') {
                $this->assertVenueAvailable($locked->toArray(), $locked->id);
            }
            $locked->forceFill(['status' => $next, 'updated_by' => $actor->id, 'version' => $locked->version + 1])->save();
            $this->audit->write($locked, $actor, $action === 'cancel' ? 'cancelled' : ($action === 'archive' ? 'archived' : 'published'), ['status' => $next, 'version' => $locked->version]);

            return $locked->load(['audiences.course', 'audiences.section']);
        }, 3);
    }

    private function attributes(array $data): array
    {
        $venue = trim(preg_replace('/\s+/', ' ', $data['venue']));

        return Arr::only($data, ['title', 'description', 'starts_at', 'ends_at']) + ['venue' => $venue, 'venue_key' => mb_strtolower($venue)];
    }

    private function replaceAudiences(Event $event, array $audiences): void
    {
        $event->audiences()->delete();
        foreach (collect($audiences)->unique(fn ($a) => implode(':', [$a['audience_type'], $a['course_id'] ?? '', $a['year_level'] ?? '', $a['section_id'] ?? ''])) as $audience) {
            $record = ['audience_type' => $audience['audience_type']];
            if ($audience['audience_type'] === 'course') {
                $record['course_id'] = $audience['course_id'];
            } elseif ($audience['audience_type'] === 'year_level') {
                $record['year_level'] = $audience['year_level'];
            } elseif ($audience['audience_type'] === 'section') {
                $record['section_id'] = $audience['section_id'];
            }
            $event->audiences()->create($record);
        }
    }

    private function validateAudiences(array $audiences): void
    {
        foreach ($audiences as $index => $audience) {
            $type = $audience['audience_type'];
            if ($type === 'course' && ! Course::query()->whereKey($audience['course_id'] ?? 0)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(["audiences.$index.course_id" => 'Select an active course.']);
            }
            if ($type === 'section' && ! Section::query()->whereKey($audience['section_id'] ?? 0)->where('status', 'open')
                ->whereHas('academicYear', fn ($query) => $query->where('status', 'active'))
                ->whereHas('semester', fn ($query) => $query->where('status', 'active'))->exists()) {
                throw ValidationException::withMessages(["audiences.$index.section_id" => 'Select an open section.']);
            }
            if ($type === 'year_level' && empty($audience['year_level'])) {
                throw ValidationException::withMessages(["audiences.$index.year_level" => 'Select a year level.']);
            }
        }
    }

    private function assertVenueAvailable(array $data, ?int $except = null): void
    {
        $venue = mb_strtolower(trim(preg_replace('/\s+/', ' ', $data['venue'])));
        $overlap = Event::query()->where('venue_key', $venue)->where('status', 'published')
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['venue' => 'This venue is already assigned to another published event during that time.']);
        }
    }
}
