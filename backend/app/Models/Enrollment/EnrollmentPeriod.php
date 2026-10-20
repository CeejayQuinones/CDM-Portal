<?php

namespace App\Models\Enrollment;

use App\Models\AcademicYear;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Model;

class EnrollmentPeriod extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'enabled' => false];

    protected function casts(): array
    {
        return ['opens_at' => 'immutable_datetime', 'closes_at' => 'immutable_datetime', 'enabled' => 'boolean', 'document_requirements' => 'array'];
    }

    protected $appends = ['state'];

    public function getStateAttribute(): string
    {
        return ! $this->enabled || now()->gte($this->closes_at) ? 'closed' : (now()->lt($this->opens_at) ? 'upcoming' : 'open');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function documentConfiguration(string $classification): array
    {
        $configuration = $this->document_requirements[$classification] ?? [];
        if (array_is_list($configuration)) {
            return ['required' => array_values($configuration), 'optional' => []];
        }

        return [
            'required' => array_values($configuration['required'] ?? []),
            'optional' => array_values($configuration['optional'] ?? []),
        ];
    }

    public function documentTypeIds(string $classification): array
    {
        $configuration = $this->documentConfiguration($classification);

        return array_values(array_unique([...$configuration['required'], ...$configuration['optional']]));
    }
}
