<?php

declare(strict_types=1);

namespace PrismWorkersAi\LaravelAi;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Provider;
use PrismWorkersAi\LaravelAi\Gateway\WorkersAiGateway;

/**
 * Laravel AI provider for Cloudflare Workers AI.
 *
 * Constructor signature mirrors XaiProvider/MistralProvider et al — drops the
 * Gateway parameter that the abstract Provider expected pre-v0.6 since we
 * now own the gateway and lazy-instantiate it. This is the BC break against
 * v0.4.x: anyone subclassing this provider directly will need to drop the
 * gateway argument from their constructor. Ordinary users via `agent()` /
 * config-driven registration are unaffected.
 */
class WorkersAiProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use \Laravel\Ai\Providers\Concerns\GeneratesEmbeddings;
    use \Laravel\Ai\Providers\Concerns\GeneratesText;
    use \Laravel\Ai\Providers\Concerns\HasEmbeddingGateway;
    use \Laravel\Ai\Providers\Concerns\HasTextGateway;
    use \Laravel\Ai\Providers\Concerns\StreamsText;

    protected ?WorkersAiGateway $workersAiGateway = null;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * Shared gateway instance — text and embeddings both route through the
     * same WorkersAiGateway since Workers AI exposes both capabilities under
     * one OpenAI-compat surface.
     */
    protected function workersAiGateway(): WorkersAiGateway
    {
        return $this->workersAiGateway ??= new WorkersAiGateway($this->events);
    }

    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= $this->workersAiGateway();
    }

    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->workersAiGateway();
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? '@cf/meta/llama-3.1-8b-instruct';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? '@cf/moonshotai/kimi-k2.5';
    }

    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? '@cf/baai/bge-large-en-v1.5';
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return (int) ($this->config['models']['embeddings']['dimensions'] ?? 1024);
    }
}
