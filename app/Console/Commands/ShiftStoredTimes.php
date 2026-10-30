<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * One-off: re-express date-times that were stored while the application ran in
 * UTC, so they read correctly now that it runs in another zone.
 *
 * The database keeps no zone. A row saved as "2026-10-30 18:57:00" under UTC means
 * 02:57 the next morning in Kuala Lumpur, but once the application zone changes it
 * is read as 18:57 local, eight hours early. Nothing moves by itself; this command
 * adds the difference to every date-time column. Date-only columns (task and project
 * deadlines) are days, not instants, and are left alone.
 *
 * It refuses to run twice, and --dry-run changes nothing.
 */
class ShiftStoredTimes extends Command
{
    protected $signature = 'app:shift-stored-times
        {--from=UTC : The zone the stored times are in now}
        {--to= : The zone they should be in (default: the application timezone)}
        {--dry-run : Only report what would change}
        {--force : Run even if this has been run before}';

    protected $description = 'Shift stored date-times from one timezone to another, once, after changing APP_TIMEZONE';

    /** Tables whose date-times are not application data. */
    protected const SKIP = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches'];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: config('app.timezone'));

        try {
            $now = CarbonImmutable::now();
            $seconds = $now->setTimezone($to)->utcOffset() * 60 - $now->setTimezone($from)->utcOffset() * 60;
        } catch (\Throwable) {
            $this->error("Unknown timezone: {$from} or {$to}.");

            return self::FAILURE;
        }

        $marker = storage_path('app/private/stored-times-shifted.json');

        if (File::exists($marker) && ! $this->option('force')) {
            $this->error('The stored times were already shifted ('.trim((string) File::get($marker)).'). Running this again would move them twice. Use --force only if you are sure.');

            return self::FAILURE;
        }

        if ($seconds === 0) {
            $this->info("{$from} and {$to} have the same offset today. Nothing to shift.");

            return self::SUCCESS;
        }

        $columns = $this->columns();
        $hours = $seconds / 3600;

        $this->line(sprintf('Shifting %d column(s) by %+g hour(s) (%s to %s)%s.', count($columns), $hours, $from, $to, $this->option('dry-run') ? ', dry run' : ''));

        foreach ($columns as [$table, $column]) {
            $rows = DB::table($table)->whereNotNull($column)->count();

            $this->line("  {$table}.{$column}: {$rows} row(s)");

            if (! $this->option('dry-run') && $rows > 0) {
                $this->shift($table, $column, $seconds);
            }
        }

        if (! $this->option('dry-run')) {
            File::ensureDirectoryExists(dirname($marker));
            File::put($marker, json_encode(['from' => $from, 'to' => $to, 'seconds' => $seconds, 'at' => now()->toDateTimeString()]));
            $this->info('Done. Do not run this again.');
        }

        return self::SUCCESS;
    }

    /** @return list<array{string, string}> */
    protected function columns(): array
    {
        $found = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, self::SKIP, true)) {
                continue;
            }

            foreach (Schema::getColumns($name) as $column) {
                if (preg_match('/^(timestamp|datetime)/i', $column['type_name'] ?? $column['type'] ?? '')) {
                    $found[] = [$name, $column['name']];
                }
            }
        }

        return $found;
    }

    protected function shift(string $table, string $column, int $seconds): void
    {
        $t = DB::getQueryGrammar()->wrapTable($table);
        $c = DB::getQueryGrammar()->wrap($column);

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("UPDATE {$t} SET {$c} = datetime({$c}, ? || ' seconds') WHERE {$c} IS NOT NULL", [$seconds]);

            return;
        }

        DB::statement("UPDATE {$t} SET {$c} = DATE_ADD({$c}, INTERVAL ? SECOND) WHERE {$c} IS NOT NULL", [$seconds]);
    }
}
