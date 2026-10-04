<?php

namespace Tests\Unit;

use App\Enums\SyncStatus;
use PHPUnit\Framework\TestCase;

/**
 * The frontend mirrors SyncStatus as a string union in frontend/src/lib/api/types.ts.
 * Nothing links the two, so this test fails when they drift apart.
 */
class SyncStatusContractTest extends TestCase
{
    public function test_frontend_sync_status_type_matches_the_enum(): void
    {
        $path = dirname(__DIR__, 3).'/frontend/src/lib/api/types.ts';

        // The backend container only mounts ./backend.
        if (! is_file($path)) {
            $this->markTestSkipped('frontend/src/lib/api/types.ts is not available.');
        }

        $this->assertSame(1, preg_match('/export type SyncStatus\s*=([^;]+);/', file_get_contents($path), $match));
        preg_match_all('/"([^"]+)"/', $match[1], $values);

        $expected = array_map(fn (SyncStatus $status) => $status->value, SyncStatus::cases());
        sort($expected);
        $actual = $values[1];
        sort($actual);

        $this->assertSame($expected, $actual);
    }
}
