<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Models\Client;

/**
 * Add a tag to the client, or take one off.
 */
class TagClient extends Step
{
    public function __construct(private readonly bool $remove = false) {}

    public function key(): string
    {
        return $this->remove ? 'untag_client' : 'tag_client';
    }

    public function label(): string
    {
        return $this->remove ? 'Take a tag off the client' : 'Tag the client';
    }

    public function group(): string
    {
        return 'Clients';
    }

    public function fields(): array
    {
        return [self::field('tag', 'Tag', 'text', ['required' => true, 'max' => 30, 'help' => 'For example VIP or Reseller.'])];
    }

    public function summary(array $config): string
    {
        return $this->remove
            ? __('Take the tag “:tag” off the client', ['tag' => $config['tag'] ?? ''])
            : __('Tag the client “:tag”', ['tag' => $config['tag'] ?? '']);
    }

    public function preview(Context $context, array $config): string
    {
        return $this->remove
            ? __('Would take the tag “:tag” off :name', ['tag' => $config['tag'] ?? '', 'name' => $context->client()?->name ?? '—'])
            : __('Would tag :name “:tag”', ['tag' => $config['tag'] ?? '', 'name' => $context->client()?->name ?? '—']);
    }

    public function run(Context $context, array $config): string
    {
        $client = $context->client() ?? throw new StepFailed(__('There is no client.'));
        $tag = (string) $config['tag'];
        $tags = $this->remove
            ? array_values(array_filter($client->tagList(), fn (string $existing): bool => mb_strtolower($existing) !== mb_strtolower(trim($tag))))
            : Client::cleanTags([...$client->tagList(), $tag]);

        $client->forceFill(['tags' => $tags])->save();

        return $this->remove
            ? __('Took the tag “:tag” off :name', ['tag' => $tag, 'name' => $client->name])
            : __('Tagged :name “:tag”', ['tag' => $tag, 'name' => $client->name]);
    }
}
