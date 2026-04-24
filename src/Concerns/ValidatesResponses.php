<?php

declare(strict_types=1);

namespace PrismWorkersAi\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponses
{
    use ExtractsErrorMessage;

    protected function validateResponse(Response $response): void
    {
        $data = $response->json();

        if (! $data || data_get($data, 'error') || data_get($data, 'errors')) {
            throw PrismException::providerResponseError(vsprintf(
                'Workers AI Error: [%s] %s',
                [
                    data_get($data, 'error.type')
                        ?? data_get($data, 'errors.0.code', 'unknown'),
                    $this->extractErrorMessage($data),
                ]
            ));
        }
    }
}
