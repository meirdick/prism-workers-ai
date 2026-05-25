<?php

declare(strict_types=1);

namespace PrismWorkersAi;

/**
 * Driver-key constants for the Workers AI provider.
 *
 * These constants used to live on `PrismWorkersAi\WorkersAi` (the Prism
 * provider class). Reading either one would force PHP to autoload that
 * class, which in turn requires Prism's `Provider` parent — so anyone
 * touching the constant indirectly required `prism-php/prism` to be
 * installed.
 *
 * Pulling the constants into a Prism-free class lets the service provider
 * register the Laravel AI bridge, look up config keys, and emit stream
 * events without ever touching Prism. That makes `prism-php/prism` a
 * genuinely optional dependency — `meirdick/prism-workers-ai` can be
 * installed alongside laravel/ai with zero Prism overhead.
 */
final class WorkersAiKey
{
    /**
     * Primary driver key. Used in config blocks, AiManager registration,
     * and the stream `provider` field on Prism stream events.
     */
    public const PRIMARY = 'workers-ai';

    /**
     * Dashless alias. Every other provider in the Laravel AI / Prism
     * ecosystem (openai, anthropic, xai, gemini, groq, mistral, deepseek)
     * is a single lowercase token, so users naturally reach for
     * "workersai" — especially inside defensive try/catch blocks that
     * would swallow a "driver not supported" error and silently degrade
     * a feature.
     */
    public const ALIAS = 'workersai';
}
