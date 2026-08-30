<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class DocumentAnalysisTypeRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $types = config('document_analysis.supported_types', []);

        return is_array($types) ? $types : [];
    }

    /** @return array<string, mixed>|null */
    public static function resolve(?string $documentType): ?array
    {
        $normalized = self::normalize($documentType);

        foreach (self::all() as $key => $definition) {
            $aliases = array_merge([$key, $definition['label'] ?? ''], $definition['aliases'] ?? []);

            if (collect($aliases)->contains(
                fn (mixed $alias): bool => is_string($alias) && self::normalize($alias) === $normalized,
            )) {
                return [...$definition, 'key' => $key];
            }
        }

        return null;
    }

    public static function supports(?string $documentType): bool
    {
        return self::resolve($documentType) !== null;
    }

    public static function canonicalKey(?string $documentType): string
    {
        return self::resolve($documentType)['key'] ?? (self::normalize($documentType) ?: 'unknown');
    }

    private static function normalize(?string $value): string
    {
        return Str::snake(strtolower(Str::ascii(trim((string) $value))));
    }
}
