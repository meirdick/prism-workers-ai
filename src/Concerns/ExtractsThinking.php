<?php

declare(strict_types=1);

namespace PrismWorkersAi\Concerns;

use Prism\Prism\Text\Request;

trait ExtractsThinking
{
    /**
     * Extract thinking/reasoning content from a streaming delta.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractThinking(array $data, Request $request): string
    {
        $reasoning = data_get($data, 'choices.0.delta.reasoning_content') ?? '';

        if ($reasoning !== '') {
            return $reasoning;
        }

        $reasoning = data_get($data, 'choices.0.delta.reasoning') ?? '';

        if ($reasoning !== '') {
            return $reasoning;
        }

        return data_get($data, 'choices.0.delta.thinking') ?? '';
    }

    /**
     * Extract thinking/reasoning content from a non-streaming response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractThinkingFromMessage(array $data): string
    {
        $reasoning = data_get($data, 'choices.0.message.reasoning_content');

        if (is_string($reasoning) && $reasoning !== '') {
            return $reasoning;
        }

        $reasoning = data_get($data, 'choices.0.message.reasoning');

        if (is_string($reasoning) && $reasoning !== '') {
            return $reasoning;
        }

        return '';
    }
}
