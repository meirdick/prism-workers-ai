<?php

declare(strict_types=1);

namespace PrismWorkersAi\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponses
{
    protected function validateResponse(Response $response): void
    {
        $data = $response->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(vsprintf(
                'Workers AI Error: [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ]
            ));
        }
    }
}
