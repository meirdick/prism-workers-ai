<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(Tests\TestCase::class)->in(__DIR__);

// Wire the WorkersAiHelpers trait into the Laravel AI *Gateway* test suite —
// makes $this->ssePayload(...), $this->chatChunk(...), etc. callable inside
// each test there.
//
// Note: tests/LaravelAiBridgeTest.php is also part of the `LaravelAi` PHPUnit
// testsuite (see phpunit.xml) but does NOT need these helpers — it only
// exercises service-provider registration, no HTTP fixtures or SSE. If you
// add a bridge test that needs the chunk helpers, either move it into
// tests/LaravelAi/Gateway/ or extend the uses() target below.
uses(Tests\LaravelAi\Gateway\WorkersAiHelpers::class)->in('LaravelAi/Gateway');

// Global helpers (workersAiTextResponse, fakeWorkersAiToolCallResponse, ...)
// for the Laravel AI gateway tests. Inlined from laravel/ai's tests/Helpers.php
// so we don't depend on laravel/ai's internal test harness.
require_once __DIR__.'/LaravelAi/Helpers.php';
