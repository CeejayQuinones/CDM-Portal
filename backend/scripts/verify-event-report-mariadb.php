<?php

// Read-only verifier for Event report queries under MariaDB/MySQL ONLY_FULL_GROUP_BY.
// Run: php scripts/verify-event-report-mariadb.php

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Services\Event\EventReportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$driver = $connection->getDriverName();
if (! in_array($driver, ['mysql', 'mariadb'], true)) {
    throw new RuntimeException('This verifier requires a MariaDB/MySQL connection.');
}

$mode = (string) $connection->selectOne('select @@session.sql_mode as mode')->mode;
if (! str_contains($mode, 'ONLY_FULL_GROUP_BY')) {
    throw new RuntimeException('ONLY_FULL_GROUP_BY must be enabled for this verification.');
}

$actor = User::query()
    ->where('status', 'active')
    ->whereHas('role', fn ($query) => $query->where('role_name', Role::ADMIN))
    ->firstOrFail();
$service = app(EventReportService::class);
$events = Event::query()->with(['audiences', 'parent.audiences'])->orderBy('id')->get();
$checked = [];

foreach ($events as $event) {
    $report = $service->report($event, ['per_page' => 1], $actor, false);
    $checked[] = [
        'event_id' => $event->id,
        'attendance_rows' => array_sum(array_map(fn ($item) => (int) $item->total, $report['breakdowns']['method']->all())),
        'method_groups' => $report['breakdowns']['method']->count(),
    ];
}

echo json_encode([
    'database' => $connection->getDatabaseName(),
    'server_version' => $connection->selectOne('select version() as version')->version,
    'sql_mode' => $mode,
    'events_checked' => count($checked),
    'reports' => $checked,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
