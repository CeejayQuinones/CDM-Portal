<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LargeDatasetCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('generated_data_records')) {
            $this->command?->info('No ownership ledger found; nothing deleted.');

            return;
        }
        DB::transaction(function (): void {
            $tables = Schema::getTableListing(schemaQualified: false);
            $owned = [];
            $registry = DB::table('generated_data_records')->whereIn('dataset_key', [LargeDatasetSeeder::DATASET_KEY, LargeDatasetSeeder::LEGACY_DATASET_KEY])->lockForUpdate()->get();
            $aliases = ['user' => 'users', 'cabinet' => 'cabinets', 'appointment_capacity' => 'appointment_date_capacities'];
            foreach ($registry->groupBy('record_type') as $type => $records) {
                $table = $aliases[$type] ?? $type;
                if (! in_array($table, $tables, true) || $table === 'generated_data_records' || ! Schema::hasColumn($table, 'created_at')) {
                    continue;
                }
                $stamps = $records->keyBy('record_id');
                foreach ($records->pluck('record_id')->chunk(500) as $ids) {
                    foreach (DB::table($table)->whereIn('id', $ids)->lockForUpdate()->get(['id', 'created_at']) as $row) {
                        if ((string) $row->created_at === (string) $stamps[$row->id]->record_created_at) {
                            $owned[$table][$row->id] = true;
                        }
                    }
                }
            }
            // Older versions recorded only the User. Its same-run identity rows
            // have the exact creation timestamp; later academic identities do not.
            foreach (['user_profiles', 'students'] as $table) {
                if (! in_array($table, $tables, true)) {
                    continue;
                }
                foreach (array_chunk(array_keys($owned['users'] ?? []), 500) as $ids) {
                    $rows = DB::table($table)->join('users', 'users.id', '=', $table.'.user_id')
                        ->whereIn('users.id', $ids)->whereColumn($table.'.created_at', 'users.created_at')
                        ->lockForUpdate()->pluck($table.'.id');
                    foreach ($rows as $id) {
                        $owned[$table][$id] = true;
                    }
                }
            }
            $found = array_map('count', $owned);
            $edges = [];
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $fk) {
                    if (isset($owned[$fk['foreign_table']]) && count($fk['columns']) === 1 && $fk['foreign_columns'] === ['id']) {
                        $edges[] = [$table, $fk['columns'][0], $fk['foreign_table'], null];
                    }
                }
            }
            // These references are polymorphic and therefore have no database FK.
            foreach (['enrollment_workflow_events' => ['subject_id', 'enrollment_applications', ['subject_type', 'application']],
                'notifications' => ['notifiable_id', 'users', ['notifiable_type', 'App\\Models\\User']],
                'personal_access_tokens' => ['tokenable_id', 'users', ['tokenable_type', 'App\\Models\\User']]] as $table => $edge) {
                if (in_array($table, $tables, true) && isset($owned[$edge[1]])) {
                    $edges[] = [$table, ...$edge];
                }
            }
            // Materialize references once, then propagate protection to ancestors.
            $references = [];
            foreach ($edges as [$child, $column, $parent, $condition]) {
                $hasId = Schema::hasColumn($child, 'id');
                foreach (array_chunk(array_keys($owned[$parent]), 500) as $ids) {
                    $query = DB::table($child)->whereIn($column, $ids);
                    if ($condition) {
                        $query->where($condition[0], $condition[1]);
                    }
                    foreach ($query->lockForUpdate()->get($hasId ? ['id', $column] : [$column]) as $row) {
                        $references[] = [$child, $hasId ? $row->id : null, $parent, $row->{$column}];
                    }
                }
            }
            $deletable = $owned;
            $reasons = [];
            do {
                $changed = false;
                foreach ($references as [$child, $childId, $parent, $parentId]) {
                    if (in_array($parent, ['users', 'user_profiles', 'students', 'enrollment_applications', 'enrollments'], true)
                        && ! isset($deletable[$parent][$parentId]) && isset($deletable[$child][$childId])) {
                        unset($deletable[$child][$childId]);
                        $reasons[$child][$childId] = "retained with protected {$parent}";
                        $changed = true;
                    }
                    if (isset($deletable[$parent][$parentId]) && ! isset($deletable[$child][$childId])) {
                        unset($deletable[$parent][$parentId]);
                        $reasons[$parent][$parentId] = "protected {$child} reference";
                        $changed = true;
                    }
                }
            } while ($changed);

            $deleted = [];
            $pending = array_filter($deletable);
            while ($pending) {
                $progress = false;
                foreach ($pending as $table => $ids) {
                    $hasChild = false;
                    foreach ($edges as [$child, , $parent]) {
                        if ($parent === $table && isset($pending[$child])) {
                            $hasChild = true;
                            break;
                        }
                    }
                    if ($hasChild) {
                        continue;
                    }
                    $deleted[$table] = 0;
                    foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                        $deleted[$table] += DB::table($table)->whereIn('id', $chunk)->delete();
                    }
                    unset($pending[$table]);
                    $progress = true;
                }
                if (! $progress) {
                    throw new \LogicException('Cyclic fixture dependencies; cleanup rolled back without disabling foreign keys.');
                }
            }
            $deletedRegistryIds = [];
            foreach ($registry as $entry) {
                $table = $aliases[$entry->record_type] ?? $entry->record_type;
                if (isset($deletable[$table][$entry->record_id])) {
                    $deletedRegistryIds[] = $entry->id;
                }
            }
            foreach (array_chunk($deletedRegistryIds, 500) as $ids) {
                DB::table('generated_data_records')->whereIn('id', $ids)->delete();
            }
            $this->command?->info('Generated rows found: '.json_encode($found));
            $this->command?->info('Rows deleted per table: '.json_encode($deleted));
            $protected = $reasons['students'] ?? [];
            $this->command?->warn('Protected Students retained: '.count($protected));
            foreach (array_count_values($protected) as $reason => $count) {
                $this->command?->line("  {$count}: {$reason}");
            }
            $this->command?->line('Protected Student IDs: '.implode(', ', array_slice(array_keys($protected), 0, 20)).(count($protected) > 20 ? ' (first 20; ownership ledger retained)' : ''));
            $this->command?->info('Remaining owned rows: '.array_sum(array_map('count', $reasons)).'. Repeated cleanup leaves protected activity intact.');
        });
    }
}
