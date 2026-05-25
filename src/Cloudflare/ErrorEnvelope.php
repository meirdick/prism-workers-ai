<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

/**
 * Extracts the best error message from a Workers AI / Cloudflare response.
 *
 * Cloudflare's AI Gateway and the underlying Workers AI service emit errors
 * in several shapes depending on origin (gateway-level vs upstream model
 * provider) and route (/compat vs /workers-ai/v1). Surfacing the right
 * message requires checking each shape in priority order rather than
 * assuming the OpenAI-style envelope.
 *
 * Supported shapes:
 *   - OpenAI-style:   { "error": { "message": "...", "type": "..." } }
 *   - AI Gateway:     { "errors": [ { "message": "...", "code": N } ] }
 *   - String error:   { "error": "plain string" }
 *   - Top-level:      { "message": "..." }
 *
 * Without this layered lookup, gateway errors collapse to "Unknown error"
 * and operators lose the actual reason (rate limit, billing, model not
 * available, malformed request, etc.).
 */
final class ErrorEnvelope
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function extract(?array $data): string
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

    /**
     * Best-effort error identifier for diagnostic surfaces and stream Error
     * events. Prefers `error.code` (e.g. "rate_limit_exceeded") since that's
     * what consumers branch on, then `error.type` (e.g. "rate_limit_error",
     * the broader category), then the AI Gateway `errors.0.code`, finally
     * "unknown" so callers always have a non-null token.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function extractType(?array $data): string
    {
        if ($data === null) {
            return 'unknown';
        }

        $type = data_get($data, 'error.code')
            ?? data_get($data, 'error.type')
            ?? data_get($data, 'errors.0.code');

        return $type !== null ? (string) $type : 'unknown';
    }

    /**
     * Whether the response body looks like an error payload, regardless of shape.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function isErrorPayload(?array $data): bool
    {
        if (! $data) {
            return true;
        }

        return data_get($data, 'error') !== null
            || data_get($data, 'errors') !== null;
    }
}
