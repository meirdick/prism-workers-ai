<?php

namespace PrismWorkersAi\LaravelAi\Gateway\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use PrismWorkersAi\Cloudflare\StrictAttributeGuard;
use PrismWorkersAi\Cloudflare\TextOptionsCompat;

trait BuildsTextRequests
{
    /**
     * Build the request body for the Chat Completions API from
     * `Laravel\Ai\Messages\Message[]` (initial-turn shape).
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        return $this->finalizeChatBody(
            [
                'model' => $model,
                'messages' => $this->mapMessagesToChat($messages, $instructions),
            ],
            provider: $provider,
            tools: $tools,
            schema: $schema,
            options: $options,
        );
    }

    /**
     * Append tools, response_format, sampling options, and providerOptions to
     * a body that already carries `model` and `messages`.
     *
     * Called from three places — `buildTextRequestBody` (initial turn) and the
     * tool-call follow-up bodies in `ParsesTextResponses::continueWithToolResults`
     * and `HandlesTextStreaming::handleStreamingToolCalls`. Previously each site
     * rebuilt the same shape inline, which let new options drift between paths;
     * threading them all through this helper is the single source of truth.
     *
     * @param  array<string, mixed>  $body  must already contain `model` + `messages`
     * @return array<string, mixed>
     */
    protected function finalizeChatBody(
        array $body,
        Provider $provider,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        if (filled($tools)) {
            $mappedTools = $this->mapTools($tools);

            if (filled($mappedTools)) {
                $body['tool_choice'] = 'auto';
                $body['tools'] = $mappedTools;
            }
        }

        if (filled($schema)) {
            // StrictAttributeGuard returns true on laravel/ai ^0.5 / ^0.6
            // (preserving v0.4.x always-strict behavior on lines without the
            // attribute) and honors `#[Strict]` on v0.7+.
            $body['response_format'] = $this->buildResponseFormat(
                $schema,
                StrictAttributeGuard::isAppliedTo($options?->agent),
            );
        }

        if (! is_null($options?->maxTokens)) {
            $body['max_completion_tokens'] = $options->maxTokens;
        }

        // top_p was added to TextGenerationOptions in laravel/ai v0.6.6.
        // TextOptionsCompat::topP returns null on older lines so we don't trip
        // an "Undefined property" warning. Arr::whereNotNull keeps the body
        // clean of null fields.
        $body = array_merge($body, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => TextOptionsCompat::topP($options),
        ]));

        $providerOptions = $options?->providerOptions($provider->driver());

        if (filled($providerOptions)) {
            $body = array_merge($body, Arr::except($providerOptions, ['session_affinity']));
        }

        return $body;
    }

    /**
     * Build the response format options for structured output.
     *
     * `$strict` is opt-in via the `#[Strict]` attribute on the agent (or tool,
     * for OpenAI). When true, ObjectSchema enforces all-required-properties
     * and bans additionalProperties; the request also signals `strict: true`
     * so Workers AI's /compat layer (OpenAI-compatible) treats the schema as
     * an enforceable constraint rather than a hint.
     */
    protected function buildResponseFormat(array $schema, bool $strict): array
    {
        $objectSchema = new ObjectSchema($schema, strict: $strict);

        $schemaArray = $objectSchema->toSchema();

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => Arr::except($schemaArray, ['name']),
                'strict' => $strict,
            ],
        ];
    }
}
