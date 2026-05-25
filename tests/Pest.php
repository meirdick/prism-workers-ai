<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(Tests\TestCase::class)->in(__DIR__);

// Wire the WorkersAiHelpers trait into the Laravel AI gateway test suite —
// makes $this->ssePayload(...), $this->chatChunk(...), etc. callable inside
// each test there.
uses(Tests\LaravelAi\Gateway\WorkersAiHelpers::class)->in('LaravelAi/Gateway');

// Global helpers (workersAiTextResponse, fakeWorkersAiToolCallResponse, ...)
// for the Laravel AI gateway tests. Inlined from laravel/ai's tests/Helpers.php
// so we don't depend on laravel/ai's internal test harness.
require_once __DIR__.'/LaravelAi/Helpers.php';
