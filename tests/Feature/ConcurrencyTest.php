<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real multi-process concurrency tests. They need a server database (MySQL/MariaDB/PostgreSQL) because
 * row locking cannot be exercised with in-memory SQLite. Run with phpunit.mysql.xml or phpunit.pgsql.xml.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Concurrency tests require MySQL/MariaDB or PostgreSQL (use phpunit.mysql.xml or phpunit.pgsql.xml).');
        }
        $this->installReferenceData();
    }

    /** @return list<array{ok:bool, code?:string}> */
    protected function runWorkers(string $action, int $productId, array $userIds, int $quantity): array
    {
        $config = config('database.connections.'.config('database.default'));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'],
            'DB_PASSWORD' => (string) $config['password'],
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);
        $startAt = microtime(true) + 1.5;
        $processes = [];
        foreach ($userIds as $i => $userId) {
            $cmd = [PHP_BINARY, base_path('tests/Concurrency/worker.php'), $action, (string) $productId, (string) $userId, (string) $quantity, (string) $startAt];
            $processes[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
        }
        $results = [];
        foreach ($processes as $i => $process) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            proc_close($process);
            $line = trim((string) collect(explode("\n", trim($out)))->last());
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "Worker produced no result. STDOUT: {$out} STDERR: {$err}");
            $results[] = $decoded;
        }

        return $results;
    }

    /** Example test 4: two employees try to sell the final tyre at the same time. */
    public function test_only_one_of_two_simultaneous_sales_gets_the_last_tyre(): void
    {
        $product = $this->makeTyre(1);
        $a = $this->makeUser(Role::SALES);
        $b = $this->makeUser(Role::SALES);

        $results = $this->runWorkers('sale', $product->id, [$a->id, $b->id], 1);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results));
        $failed = collect($results)->firstWhere('ok', false);
        $this->assertSame('insufficient_stock', $failed['code'], json_encode($results));

        $product = Product::query()->find($product->id);
        $this->assertSame(0, $product->qty_on_hand);
        $this->assertSame(1, Sale::query()->where('status', 'completed')->count());
        $this->assertSame(1, StockMovement::query()->where('type', StockMovement::SALE)->count(), 'No duplicate deduction');
        $this->assertSame(0, (int) DB::table('stock_balances')->where('qty_on_hand', '<', 0)->count());
    }

    public function test_simultaneous_reservations_cannot_over_reserve(): void
    {
        $product = $this->makeTyre(4);
        $users = [$this->makeUser(Role::SALES)->id, $this->makeUser(Role::SALES)->id, $this->makeUser(Role::SALES)->id];

        $results = $this->runWorkers('reserve', $product->id, $users, 2);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results));
        $product = Product::query()->find($product->id);
        $this->assertSame(4, $product->qty_reserved);
        $this->assertSame(4, $product->qty_on_hand);
        $this->assertSame(0, $product->availableQty());
        $this->assertSame(2, Reservation::query()->count());
    }
}
