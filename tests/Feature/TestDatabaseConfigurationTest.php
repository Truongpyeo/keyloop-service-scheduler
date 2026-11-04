<?php

use Illuminate\Support\Facades\DB;

it('runs feature tests on an in-memory sqlite database', function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
});
