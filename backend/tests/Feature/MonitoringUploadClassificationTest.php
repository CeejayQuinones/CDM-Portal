<?php

namespace Tests\Feature;

use App\Services\MonitoringDocumentReader;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MonitoringUploadClassificationTest extends TestCase
{
    public function test_it_tells_syllabus_notes_and_gradebook_apart(): void
    {
        $reader = new MonitoringDocumentReader();
        $syllabus = 'Course syllabus and course outline. Week 1 variables. Week 2 conditions. Week 3 functions. Course outcome: write a small program. Grading system listed.';
        $notes = 'Lecture notes: definition of a loop. Example nested arrays. Remember this key point when you review.';
        $grades = "Student number,Quiz 1,Midterm,Final\n26-00001,18,80,85";
        $junk = 'Milk, eggs, bread, and butter for the weekend grocery list plus extra snacks.';

        $this->assertSame('syllabus', $reader->classifyStudyText($syllabus));
        $this->assertSame('notes', $reader->classifyStudyText($notes));
        $this->assertTrue($reader->looksLikeGradebook($grades, 'class-grades.csv'));
        $this->assertFalse($reader->looksLikeGradebook($notes, 'loop-notes.txt'));
        $this->assertFalse($reader->looksLikeGradebook($syllabus, 'syllabus.csv'));

        $this->assertStudyUploadAccepts($reader, 'syllabus', $syllabus);
        $this->assertStudyUploadAccepts($reader, 'notes', $notes);
        $this->assertStudyUploadRejects($reader, 'syllabus', $junk, 'syllabus');
        $this->assertStudyUploadRejects($reader, 'notes', $syllabus, 'syllabus');
        $this->assertStudyUploadRejects($reader, 'notes', $junk, 'study notes');
        $this->assertStudyUploadRejects($reader, 'syllabus', $notes, 'syllabus');

        $csv = $this->tempFile($grades, '.csv');
        $reader->assertGradebookSpreadsheet($csv, 'class-grades.csv');
        @unlink($csv);

        $this->assertGradebookRejects($reader, $syllabus, 'syllabus');
        $this->assertGradebookRejects($reader, $notes, 'study notes');
        $this->assertGradebookRejects($reader, $junk, 'class gradebook');
    }

    private function assertStudyUploadAccepts(MonitoringDocumentReader $reader, string $kind, string $text): void
    {
        $path = $this->tempFile($text, '.txt');
        try {
            $file = new UploadedFile($path, $kind === 'syllabus' ? 'course-syllabus.txt' : 'lecture-notes.txt', 'text/plain', null, true);
            $this->assertNotSame('', $reader->assertStudyUpload($kind, $file));
        } finally {
            @unlink($path);
        }
    }

    private function assertStudyUploadRejects(MonitoringDocumentReader $reader, string $kind, string $text, string $needle): void
    {
        $path = $this->tempFile($text, '.txt');
        try {
            $file = new UploadedFile($path, 'upload.txt', 'text/plain', null, true);
            $reader->assertStudyUpload($kind, $file);
            $this->fail('Expected the upload to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsStringIgnoringCase($needle, $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    private function assertGradebookRejects(MonitoringDocumentReader $reader, string $text, string $needle): void
    {
        $path = $this->tempFile($text, '.csv');
        try {
            $reader->assertGradebookSpreadsheet($path, 'upload.csv');
            $this->fail('Expected the spreadsheet to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsStringIgnoringCase($needle, $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    private function tempFile(string $text, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cdmmon');
        $named = $path.$extension;
        rename($path, $named);
        file_put_contents($named, $text);

        return $named;
    }
}
