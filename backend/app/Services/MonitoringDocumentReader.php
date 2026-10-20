<?php

namespace App\Services;

use App\Support\SimpleXlsx;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class MonitoringDocumentReader
{
    public function assertStudyUpload(string $kind, UploadedFile $file): string
    {
        $text = $this->textFromUpload($file);
        if (mb_strlen($text) < 50) {
            throw new RuntimeException('That file has too little readable text. Use TXT, DOCX, or a PDF with selectable text.');
        }

        if ($this->looksLikeGradebook($text, $file->getClientOriginalName())) {
            throw new RuntimeException('That file looks like a class spreadsheet, not study material. Professors import scores in Gradebook.');
        }

        $expected = strtolower($kind) === 'syllabus' ? 'syllabus' : 'notes';
        $detected = $this->classifyStudyText($text);

        if ($expected === 'syllabus' && $detected !== 'syllabus') {
            throw new RuntimeException('That file does not look like a course syllabus. Upload the official course outline, or use Upload notes for lecture material.');
        }

        if ($expected === 'notes' && $detected === 'syllabus') {
            throw new RuntimeException('That file looks like a syllabus. Use Upload syllabus so the study guide follows the course outline.');
        }

        if ($expected === 'notes' && $detected !== 'notes') {
            throw new RuntimeException('That file does not look like study notes. Upload lecture notes, a reviewer, or a topic summary.');
        }

        return $text;
    }

    public function assertGradebookSpreadsheet(string $path, string $originalName): void
    {
        $text = $this->spreadsheetPreview($path, $originalName);
        if ($this->looksLikeGradebook($text, $originalName)) {
            return;
        }

        $detected = $this->classifyStudyText($text);
        if ($detected === 'syllabus') {
            throw new RuntimeException('That file looks like a syllabus, not a class gradebook. Import a spreadsheet with Student number and score columns.');
        }
        if ($detected === 'notes') {
            throw new RuntimeException('That file looks like study notes, not a class gradebook. Import a .xlsx or .csv with Student number plus Quiz, Midterm, or Final columns.');
        }

        throw new RuntimeException('This file does not look like a class gradebook. Import a .xlsx or .csv with Student number plus Quiz, Activity, Midterm, or Final columns.');
    }

    public function textFromUpload(UploadedFile $file): string
    {
        $path = (string) $file->getRealPath();
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        return $this->textFromPath($path, $file->getClientOriginalName());
    }

    public function textFromPath(string $path, string $originalName = ''): string
    {
        $name = $originalName !== '' ? $originalName : $path;
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $text = match ($extension) {
            'txt', 'csv', 'md' => (string) file_get_contents($path),
            'docx' => $this->docxText($path),
            'pdf' => $this->pdfText($path),
            default => (string) file_get_contents($path),
        };
        $text = html_entity_decode(strip_tags($text));
        $text = trim((string) preg_replace('/[ \t]+/', ' ', $text));
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        return mb_substr($text, 0, 20000);
    }

    public function classifyStudyText(string $text): string
    {
        $syllabusHits = $this->hitCount($text, $this->syllabusMarkers());
        $notesHits = $this->hitCount($text, $this->notesMarkers());

        if ($syllabusHits >= 3 && $syllabusHits >= $notesHits) {
            return 'syllabus';
        }
        if ($notesHits >= 1 || $this->hasStudyProse($text)) {
            return 'notes';
        }

        return 'unknown';
    }

    public function looksLikeGradebook(string $text, string $filename = ''): bool
    {
        return preg_match('/student\s*(number|no\.?|id)/i', $text) === 1
            && preg_match('/\b(quiz|activity|midterm|finals?|assignment|score)\b/i', $text) === 1;
    }

    /** @param  list<string>  $markers */
    private function hitCount(string $text, array $markers): int
    {
        $haystack = ' '.strtolower($text).' ';
        $hits = 0;
        foreach ($markers as $marker) {
            if (str_contains($haystack, strtolower($marker))) {
                $hits++;
            }
        }

        return $hits;
    }

    private function hasStudyProse(string $text): bool
    {
        $letters = preg_replace('/[^A-Za-z]+/', '', $text) ?? '';

        return mb_strlen($letters) >= 80 && preg_match('/[.!?].{20,}[.!?]/s', $text) === 1;
    }

    /** @return list<string> */
    private function syllabusMarkers(): array
    {
        return [
            'syllabus',
            'course outline',
            'course description',
            'course outcomes',
            'course outcome',
            'learning outcomes',
            'intended learning',
            'weekly schedule',
            'week 1',
            'week 2',
            'week 3',
            'grading system',
            'consultation hours',
            'credit unit',
            'prerequisite',
            'course code',
            'course title',
            'course requirements',
            'lecture hours',
            'laboratory hours',
            'course content',
            'topic outline',
            'clo',
            'ilo',
        ];
    }

    /** @return list<string> */
    private function notesMarkers(): array
    {
        return [
            'notes',
            'lecture',
            'definition',
            'example',
            'chapter',
            'summary',
            'reviewer',
            'key point',
            'important',
            'remember',
            'formula',
            'theorem',
            'loops',
            'array',
            'variable',
            'function',
            'topic',
        ];
    }

    private function spreadsheetPreview(string $path, string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        if (in_array($extension, ['xlsx', 'xls', 'csv'], true) && is_file($path)) {
            $readable = $path;
            $copied = null;
            $pathExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($pathExtension !== $extension) {
                $copied = $path.'.'.$extension;
                if (@copy($path, $copied)) {
                    $readable = $copied;
                } else {
                    $copied = null;
                }
            }

            try {
                $rows = SimpleXlsx::read($readable);
                $preview = implode("\n", array_map(
                    fn (array $row): string => implode(',', array_map(fn ($cell): string => (string) $cell, $row)),
                    array_slice($rows, 0, 40),
                ));
                if (trim($preview) !== '') {
                    return mb_substr($preview, 0, 20000);
                }
            } catch (RuntimeException) {
                // Fall through to raw text extraction.
            } finally {
                if (is_string($copied) && is_file($copied)) {
                    @unlink($copied);
                }
            }
        }

        return $this->textFromPath($path, $originalName);
    }

    private function docxText(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xml) || $xml === '') {
            return '';
        }
        $xml = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);

        return html_entity_decode(strip_tags($xml));
    }

    private function pdfText(string $path): string
    {
        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return '';
        }
        $parts = [];
        if (preg_match_all('/\(((?:\\\\.|[^\\\\)]){3,})\)/', $raw, $matches)) {
            foreach ($matches[1] as $chunk) {
                $text = stripcslashes($chunk);
                if (preg_match('/[A-Za-z]{3,}/', $text) === 1) {
                    $parts[] = $text;
                }
            }
        }

        return implode(' ', $parts);
    }
}
