<?php

namespace App\Services;

use App\Models\Admission\AdmissionApplicant;
use App\Models\CampusNotice;
use App\Models\CampusNoticeAcknowledgement;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EnrollmentNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PortalNoticeAggregator
{
    public function __construct(private readonly CampusWeatherService $weather) {}

    /** @return array{notices: array<int, array<string, mixed>>, official_sources: array<int, string>} */
    public function forUser(User $user): array
    {
        $user->loadMissing('role');
        $role = $user->role?->role_name;
        abort_unless(in_array($role, [Role::GUEST, Role::STUDENT], true), 403, 'CDM Buddy is available to Guest and Student accounts.');

        $notices = collect();
        $this->campusNotices($notices, $user, $role);
        $this->enrollmentNotices($notices, $user);
        if ($role === Role::GUEST) {
            $this->admissionNotice($notices, $user);
        }
        $this->weatherNotice($notices);
        $notices->push($this->guidanceNotice());

        return [
            'notices' => $notices
                ->sortByDesc(fn (array $notice): string => str_pad((string) $notice['priority_rank'], 3, '0', STR_PAD_LEFT).':'.($notice['issued_at'] ?? ''))
                ->take(20)->values()->all(),
            'official_sources' => ['campus_notice', 'admission', 'enrollment'],
        ];
    }

    private function campusNotices(Collection $notices, User $user, string $role): void
    {
        $now = now();
        $manila = CarbonImmutable::now('Asia/Manila');
        $acknowledged = CampusNoticeAcknowledgement::query()->where('user_id', $user->id)->pluck('campus_notice_id')->all();

        CampusNotice::query()
            ->where('status', 'published')
            ->whereJsonContains('audiences', $role)
            ->where(fn ($query) => $query->whereNull('publication_start_at')->orWhere('publication_start_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('publication_end_at')->orWhere('publication_end_at', '>', $now))
            ->latest('published_at')->limit(30)->get()
            ->filter(function (CampusNotice $notice) use ($manila): bool {
                if ($notice->effective_start_at && $manila->lessThan($notice->effective_start_at->setTimezone('Asia/Manila'))) {
                    return false;
                }
                if ($notice->effective_end_at && $manila->greaterThan($notice->effective_end_at->setTimezone('Asia/Manila'))) {
                    return false;
                }

                return ! $notice->is_recurring || in_array(strtolower($manila->englishDayOfWeek), $notice->recurrence_weekdays ?? [], true);
            })
            ->each(fn (CampusNotice $notice) => $notices->push([
                'id' => 'campus-'.$notice->id,
                'type' => $notice->category,
                'title' => $notice->title,
                'message' => $notice->message,
                'priority' => $notice->priority,
                'priority_rank' => $this->campusPriority($notice),
                'kind' => 'official',
                'verified' => true,
                'source' => 'campus_notice',
                'source_label' => $notice->official_source ?: 'CDM authorized notice',
                'issued_at' => $notice->published_at?->toIso8601String(),
                'effective_at' => $notice->effective_start_at?->toIso8601String(),
                'expires_at' => ($notice->effective_end_at ?? $notice->publication_end_at)?->toIso8601String(),
                'link' => preg_match('#^/monitoring(?:[/?\x23]|$)#', (string) $notice->target_route) ? null : $notice->target_route,
                'acknowledgement' => [
                    'required' => $notice->requires_acknowledgement,
                    'acknowledged' => in_array($notice->id, $acknowledged, true),
                ],
            ]));
    }

    private function enrollmentNotices(Collection $notices, User $user): void
    {
        $user->notifications()->where('type', EnrollmentNotice::class)->latest()->limit(10)->get()
            ->each(function ($notification) use ($notices): void {
                $data = $notification->data;
                $notices->push($this->normalized(
                    'enrollment-'.$notification->id,
                    'enrollment',
                    $this->enrollmentTitle($data['event'] ?? null),
                    (string) ($data['message'] ?? 'Your Enrollment record was updated.'),
                    'Enrollment record',
                    70,
                    $notification->created_at?->toIso8601String(),
                    '/enrollment/status',
                ));
            });
    }

    private function admissionNotice(Collection $notices, User $user): void
    {
        $application = AdmissionApplicant::query()->where('user_id', $user->id)->latest('created_at')->latest('id')->first(['id', 'status', 'updated_at']);
        if ($application) {
            $notices->push($this->normalized(
                'admission-'.$application->id.'-'.$application->updated_at?->timestamp,
                'admission',
                'Admission application update',
                'Your current application status is '.str_replace('_', ' ', $application->status).'.',
                'Admission record',
                70,
                $application->updated_at?->toIso8601String(),
                '/admission',
            ));
        }
    }

    private function weatherNotice(Collection $notices): void
    {
        $weather = $this->weather->current();
        if (! $weather) {
            return;
        }
        $notices->push([
            'id' => 'weather-'.substr(sha1((string) $weather['forecast_at']), 0, 12),
            'type' => 'weather',
            'title' => $weather['condition'],
            'message' => $weather['message'].' This is a forecast, not a class-suspension advisory.',
            'priority' => 'normal',
            'priority_rank' => 40,
            'kind' => 'information',
            'verified' => false,
            'source' => 'weather',
            'source_label' => 'Weather data by Open-Meteo',
            'attribution_url' => $weather['attribution_url'],
            'issued_at' => $weather['fetched_at'],
            'effective_at' => $weather['forecast_at'],
            'expires_at' => null,
            'link' => null,
            'acknowledgement' => ['required' => false, 'acknowledged' => false],
        ]);
    }

    /** @return array<string, mixed> */
    private function guidanceNotice(): array
    {
        return [
            'id' => 'guide-records', 'type' => 'guidance', 'title' => 'Keep your Portal details current',
            'message' => 'Review your profile and academic records, then report anything that needs correction.',
            'priority' => 'low', 'priority_rank' => 10, 'kind' => 'information', 'verified' => false,
            'source' => 'buddy_guide', 'source_label' => 'CDM Buddy guide', 'issued_at' => null,
            'effective_at' => null, 'expires_at' => null, 'link' => '/settings',
            'acknowledgement' => ['required' => false, 'acknowledged' => false],
        ];
    }

    /** @return array<string, mixed> */
    private function normalized(string $id, string $type, string $title, string $message, string $source, int $rank, ?string $issuedAt, ?string $link): array
    {
        return [
            'id' => $id, 'type' => $type, 'title' => $title, 'message' => $message,
            'priority' => $rank >= 80 ? 'high' : 'normal', 'priority_rank' => $rank,
            'kind' => 'official', 'verified' => true, 'source' => $type, 'source_label' => $source,
            'issued_at' => $issuedAt, 'effective_at' => null, 'expires_at' => null, 'link' => $link,
            'acknowledgement' => ['required' => false, 'acknowledged' => false],
        ];
    }

    private function campusPriority(CampusNotice $notice): int
    {
        if ($notice->category === 'class_suspension') {
            return 100;
        }
        $base = match ($notice->priority) {
            'urgent' => 90, 'high' => 80, 'normal' => 50, default => 20
        };

        return in_array($notice->category, ['enrollment', 'admission'], true) ? max($base, 70) : $base;
    }

    private function enrollmentTitle(?string $event): string
    {
        return match ($event) {
            'schedule_changed' => 'Class schedule updated', 'section_assigned' => 'Section assigned',
            'finalized' => 'Enrollment completed', 'rejected' => 'Enrollment application update', default => 'Enrollment update',
        };
    }
}
