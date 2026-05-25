<?php

declare(strict_types=1);

namespace PrismWorkersAi\Concerns;

use PrismWorkersAi\Cloudflare\ErrorEnvelope;

trait ExtractsErrorMessage
{
    /**
     * Surface the best error message from a Cloudflare / Workers AI error
     * response. Thin shim around ErrorEnvelope::extract so existing trait
     * users (Prism path) stay untouched while the underlying logic lives in
     * the shared Cloudflare namespace alongside the rest of the wire-shape
     * helpers.
     *
     * @param  array<string, mixed>|null  $data
     */
    protected function extractErrorMessage(?array $data): string
    {
        return ErrorEnvelope::extract($data);
    }
}
