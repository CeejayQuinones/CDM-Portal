<?php

namespace Tests\Unit;

use App\Services\StudentRecords\LegacyExportInspector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LegacyExportInspectorTest extends TestCase
{
    public function test_student_export_reports_structure_without_exposing_values(): void
    {
        $path = $this->csv("Student Number,First Name,Last Name,Course,Date of Admission\n26-00001,Ana,Santos,BSIT,2026-06-01\n26-00001,Ben,Cruz,BSIT,\n,Lea,Reyes,BSIT,\n");
        try {
            $result = (new LegacyExportInspector)->inspect($path, 'students');
            $this->assertSame([], $result['missing_required_columns']);
            $this->assertSame(3, $result['counts']['rows']);
            $this->assertSame(1, $result['counts']['duplicate_keys']);
            $this->assertSame(1, $result['counts']['blank_student_numbers']);
            $this->assertSame(2, $result['blank_fields']['admission_date']);
            $this->assertStringNotContainsString('Santos', json_encode($result));
            $this->assertStringNotContainsString('26-00001', json_encode($result));
        } finally {
            unlink($path);
        }
    }

    public function test_grade_export_reports_duplicates_and_missing_columns(): void
    {
        $path = $this->csv("Student No,School Year,Semester,Subject Code,Final Grade,Remarks,Credits\n26-00001,2025-2026,First Semester,IT101,1.75,Passed,3\n26-00001,2025-2026,First Semester,IT101,1.50,Passed,3\n26-00002,2025-2026,First Semester\n");
        try {
            $result = (new LegacyExportInspector)->inspect($path, 'grades');
            $this->assertSame([], $result['missing_required_columns']);
            $this->assertSame(1, $result['counts']['duplicate_keys']);
            $this->assertSame(1, $result['counts']['malformed_rows']);
        } finally {
            unlink($path);
        }

        $path = $this->csv("Student Number,Final Grade\n26-00001,1.75\n");
        try {
            $result = (new LegacyExportInspector)->inspect($path, 'grades');
            $this->assertContains('subject_code', $result['missing_required_columns']);
        } finally {
            unlink($path);
        }
    }

    public function test_ambiguous_column_aliases_are_rejected(): void
    {
        $path = $this->csv("Student Number,Student No,First Name,Last Name\n26-00001,26-00001,Ana,Santos\n");
        try {
            $this->expectException(InvalidArgumentException::class);
            (new LegacyExportInspector)->inspect($path, 'students');
        } finally {
            unlink($path);
        }
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-inspect-');
        file_put_contents($path, $content);

        return $path;
    }
}
