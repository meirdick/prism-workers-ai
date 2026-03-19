<?php

declare(strict_types=1);

use Prism\Prism\Enums\ToolChoice;
use PrismWorkersAi\Maps\ToolChoiceMap;

it('maps Auto to auto string', function () {
    expect(ToolChoiceMap::map(ToolChoice::Auto))->toBe('auto');
});

it('maps Any to required string', function () {
    expect(ToolChoiceMap::map(ToolChoice::Any))->toBe('required');
});

it('maps null to null', function () {
    expect(ToolChoiceMap::map(null))->toBeNull();
});

it('maps string tool name to function format', function () {
    $result = ToolChoiceMap::map('get_weather');

    expect($result)->toBe([
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
        ],
    ]);
});

it('throws InvalidArgumentException for None', function () {
    ToolChoiceMap::map(ToolChoice::None);
})->throws(InvalidArgumentException::class, 'Invalid tool choice');
