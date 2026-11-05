<?php

use Illuminate\Support\Str;

it('echoes the caller X-Request-Id on the response', function () {
    $this->getJson(route('health'), ['X-Request-Id' => 'abc-123'])
        ->assertHeader('X-Request-Id', 'abc-123');
});

it('generates a UUID request ID when the caller sends none', function () {
    $requestId = $this->getJson(route('health'))->headers->get('X-Request-Id');

    expect(Str::isUuid($requestId))->toBeTrue();
});

it('replaces a caller request ID that contains unsafe characters', function () {
    $requestId = $this->getJson(route('health'), ['X-Request-Id' => "bad id\n{json}"])->headers->get('X-Request-Id');

    expect(Str::isUuid($requestId))->toBeTrue();
});
