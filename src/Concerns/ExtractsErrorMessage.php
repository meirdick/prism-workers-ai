<?php

declare(strict_types=1);

namespace PrismWorkersAi\Concerns;

trait ExtractsErrorMessage
{
    /**
     * Extract the best error message from a Cloudflare / Workers AI error
     * response. Handles multiple payload shapes:
     *
     *   - OpenAI-style:  { error: { message: "...", type: "..." } }
     *   - AI Gateway:    { errors: [ { message: "...", code: N } ] }
     *   - String error:  { error: "plain string" }
     *   - Top-level:     { message: "..." }
     *
     * @param  array<string, mixed>|null  $data
     */
    protected function extractErrorMessage(?array $data): string
    {
        if ($data === null) {
            return 'Unknown error';
        }

        return data_get($data, 'error.message')
            ?? data_get($data, 'errors.0.message')
            ?? (is_string(data_get($data, 'error')) ? $data['error'] : null)
            ?? data_get($data, 'message')
            ?? 'Unknown error';
    }
}
