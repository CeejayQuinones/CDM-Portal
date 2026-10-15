<?php

namespace App\Providers;

use App\Models\StudentDocument;
use App\Observers\StudentDocumentObserver;
use App\Services\Ai\Analyzers\GeminiDocumentAnalyzer;
use App\Services\Ai\Analyzers\MockDocumentAnalyzer;
use App\Services\Ai\Contracts\DocumentAnalyzer;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DocumentAnalyzer::class, function (): DocumentAnalyzer {
            $driver = strtolower(trim((string) config('services.document_analysis.driver', 'mock')));

            return match ($driver) {
                'mock' => $this->app->make(MockDocumentAnalyzer::class),
                'gemini' => $this->geminiDocumentAnalyzer(),
                default => throw new InvalidArgumentException(
                    "Unsupported AI document analyzer [{$driver}]. Expected mock or gemini.",
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        StudentDocument::observe(StudentDocumentObserver::class);
    }

    private function geminiDocumentAnalyzer(): GeminiDocumentAnalyzer
    {
        $apiKey = trim((string) config('services.document_analysis.gemini.api_key'));
        $model = trim((string) config('services.document_analysis.gemini.model'));
        $timeout = (int) config('services.document_analysis.gemini.timeout', 60);

        if ($apiKey === '') {
            throw new InvalidArgumentException('GEMINI_API_KEY must be configured when AI_DOCUMENT_ANALYZER=gemini.');
        }

        if ($model === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $model)) {
            throw new InvalidArgumentException('GEMINI_MODEL must be a valid model identifier when AI_DOCUMENT_ANALYZER=gemini.');
        }

        if ($timeout < 1 || $timeout > 110) {
            throw new InvalidArgumentException('GEMINI_TIMEOUT must be between 1 and 110 seconds.');
        }

        return new GeminiDocumentAnalyzer($apiKey, $model, $timeout);
    }
}
