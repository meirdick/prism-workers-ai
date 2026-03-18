<?php

declare(strict_types=1);

namespace PrismWorkersAi\LaravelAi;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Provider;

/**
 * Laravel AI SDK provider for Workers AI.
 *
 * Implements TextProvider for agent() text generation and streaming,
 * and EmbeddingProvider for Embeddings::for()->generate().
 */
class WorkersAiProvider extends Provider implements TextProvider, EmbeddingProvider
{
    use \Laravel\Ai\Providers\Concerns\GeneratesEmbeddings;
    use \Laravel\Ai\Providers\Concerns\GeneratesText;
    use \Laravel\Ai\Providers\Concerns\HasEmbeddingGateway;
    use \Laravel\Ai\Providers\Concerns\HasTextGateway;
    use \Laravel\Ai\Providers\Concerns\StreamsText;

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'workers-ai/@cf/meta/llama-3.1-8b-instruct';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast';
    }

    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'workers-ai/@cf/baai/bge-large-en-v1.5';
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return (int) ($this->config['models']['embeddings']['dimensions'] ?? 1024);
    }
}
