<?php

namespace App\Services;

use App\Models\CampusNotice;
use App\Models\User;
use App\Policies\CampusNoticePolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampusNoticeService
{
    public function __construct(
        private readonly CampusNoticeAuditWriter $audit,
        private readonly CampusNoticePolicy $policy,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): CampusNotice
    {
        $this->assertCategoryAllowed($actor, $data['category']);

        return DB::transaction(function () use ($actor, $data): CampusNotice {
            $notice = CampusNotice::query()->create($this->writable($data) + [
                'status' => 'draft',
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->audit->record($actor, $notice, 'created', $this->auditMetadata($notice));

            return $notice->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, CampusNotice $notice, array $data): CampusNotice
    {
        $this->assertCategoryAllowed($actor, $data['category']);

        return DB::transaction(function () use ($actor, $notice, $data): CampusNotice {
            $locked = CampusNotice::query()->lockForUpdate()->findOrFail($notice->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft notices can be edited.']);
            }
            $locked->fill($this->writable($data));
            $locked->updated_by_user_id = $actor->id;
            $locked->save();
            $this->audit->record($actor, $locked, 'updated', $this->auditMetadata($locked));

            return $locked->fresh();
        });
    }

    public function publish(User $actor, CampusNotice $notice): CampusNotice
    {
        return DB::transaction(function () use ($actor, $notice): CampusNotice {
            $locked = CampusNotice::query()->lockForUpdate()->findOrFail($notice->id);
            $this->assertCategoryAllowed($actor, $locked->category);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft notices can be published.']);
            }
            if ($locked->category === 'class_suspension' && (! $locked->official_source || ! $locked->affected_scope || ! $locked->effective_start_at || ! $locked->effective_end_at)) {
                throw ValidationException::withMessages(['official_source' => 'A verified source, affected scope, and effective period are required before publishing a class suspension.']);
            }
            $locked->forceFill([
                'status' => 'published',
                'publication_start_at' => $locked->publication_start_at ?? now(),
                'published_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                'published_at' => now(),
            ])->save();
            $this->audit->record($actor, $locked, 'published', $this->auditMetadata($locked));

            return $locked->fresh();
        });
    }

    public function archive(User $actor, CampusNotice $notice): CampusNotice
    {
        return DB::transaction(function () use ($actor, $notice): CampusNotice {
            $locked = CampusNotice::query()->lockForUpdate()->findOrFail($notice->id);
            $this->assertCategoryAllowed($actor, $locked->category);
            if ($locked->status === 'archived') {
                return $locked;
            }
            $locked->forceFill([
                'status' => 'archived',
                'updated_by_user_id' => $actor->id,
                'archived_at' => now(),
            ])->save();
            $this->audit->record($actor, $locked, 'archived', $this->auditMetadata($locked));

            return $locked->fresh();
        });
    }

    private function assertCategoryAllowed(User $actor, string $category): void
    {
        abort_unless($this->policy->canManageCategory($actor, $category), 403, 'You are not authorized to manage this notice category.');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function writable(array $data): array
    {
        return Arr::only($data, [
            'title', 'message', 'category', 'priority', 'audiences', 'publication_start_at',
            'publication_end_at', 'effective_start_at', 'effective_end_at', 'is_recurring',
            'recurrence_weekdays', 'requires_acknowledgement', 'official_source', 'affected_scope', 'target_route',
        ]);
    }

    /** @return array<string, mixed> */
    private function auditMetadata(CampusNotice $notice): array
    {
        return [
            'status' => $notice->status,
            'category' => $notice->category,
            'priority' => $notice->priority,
            'audiences' => $notice->audiences,
            'is_recurring' => $notice->is_recurring,
        ];
    }
}
