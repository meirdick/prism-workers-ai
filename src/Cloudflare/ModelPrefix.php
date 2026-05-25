<?php

declare(strict_types=1);

namespace PrismWorkersAi\Cloudflare;

use InvalidArgumentException;

/**
 * Validates that a model ID matches the endpoint it's being sent to.
 *
 * Cloudflare exposes Workers AI under three URL shapes (direct API, AI
 * Gateway provider path, AI Gateway `/compat`). The first two accept bare
 * model IDs (`@cf/meta/llama-3.3-...`); the third requires a `workers-ai/`
 * prefix because `/compat` is a multi-provider routing endpoint that uses
 * the prefix to pick the upstream.
 *
 * Misconfigurations in either direction silently 404 from Cloudflare:
 *   - bare model on `/compat`         → "model not found"
 *   - prefixed model on `/v1` paths   → "model not found"
 *
 * Both are wire-shape mismatches with no useful error message from the
 * upstream. We pre-validate so users get a clear "you're hitting the wrong
 * endpoint for this model ID" instead of a cryptic 404.
 */
final class ModelPrefix
{
    public const PREFIX = 'workers-ai/';

    /**
     * Throws if `$model` is incompatible with the endpoint at `$url`.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $url, string $model): void
    {
        $isCompat = BaseUrl::isCompatEndpoint($url);
        $hasPrefix = str_starts_with($model, self::PREFIX);

        if ($isCompat && ! $hasPrefix) {
            throw new InvalidArgumentException(
                "Workers AI model '{$model}' is missing the `workers-ai/` prefix required by the `/compat` endpoint. "
                ."Either prefix the model name (e.g. '".self::PREFIX.$model."'), or switch to the direct API by "
                ."configuring `account_id` (and optionally `gateway`) instead of an explicit `/compat` URL — those "
                .'paths take bare model IDs.'
            );
        }

        if (! $isCompat && $hasPrefix) {
            $bare = substr($model, strlen(self::PREFIX));

            throw new InvalidArgumentException(
                "Workers AI model '{$model}' has the `workers-ai/` prefix, but the configured endpoint expects bare "
                ."model IDs. Drop the prefix (use '{$bare}'), or switch to the `/compat` endpoint by setting `url` "
                .'explicitly. Bare IDs are the right form when using `account_id` (direct API) or `account_id`+`gateway` '
                .'(AI Gateway provider path).'
            );
        }
    }

    /**
     * Add the `workers-ai/` prefix iff the URL targets `/compat` and the
     * model doesn't already have it. Used by the Prism path so user code
     * can pass bare IDs and have them auto-prefixed at request time.
     */
    public static function normalize(string $url, string $model): string
    {
        if (! BaseUrl::isCompatEndpoint($url)) {
            return $model;
        }

        return str_starts_with($model, self::PREFIX) ? $model : self::PREFIX.$model;
    }
}
