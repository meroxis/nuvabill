<?php

namespace Tests\Fakes;

use Anthropic\Client;
use App\Ai\ClientFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The real Anthropic client with a fake transport: each request gets the next queued answer, and
 * every request body is kept so tests can check what was sent. Nothing reaches Anthropic.
 */
class FakeClaude extends ClientFactory implements ClientInterface
{
    /**
     * @var list<array{status: int, body: array<string, mixed>}>
     */
    private array $queue = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $requests = [];

    /**
     * @var list<string>
     */
    public array $keys = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(ClientFactory::class, $fake);

        return $fake;
    }

    public function make(string $key): Client
    {
        $this->keys[] = $key;

        return new Client(apiKey: $key, authToken: '', baseUrl: 'https://api.anthropic.com', requestOptions: ['transporter' => $this, 'maxRetries' => 0]);
    }

    /**
     * Queue a normal answer. Pass an array to answer with JSON text, as structured outputs do.
     *
     * @param  string|array<string, mixed>  $text
     */
    public function answer(string|array $text, int $inputTokens = 1000, int $outputTokens = 200, string $model = 'claude-haiku-4-5', string $stopReason = 'end_turn'): self
    {
        $this->queue[] = ['status' => 200, 'body' => [
            'id' => 'msg_'.count($this->queue),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [['type' => 'text', 'text' => is_array($text) ? json_encode($text) : $text]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ]];

        return $this;
    }

    public function error(int $status, string $type, string $message): self
    {
        $this->queue[] = ['status' => $status, 'body' => ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = json_decode((string) $request->getBody(), true) + ['_headers' => $request->getHeaders()];

        $next = array_shift($this->queue) ?? throw new RuntimeException('FakeClaude got a request with no queued answer.');

        return new Response($next['status'], ['Content-Type' => 'application/json'], (string) json_encode($next['body']));
    }

    /**
     * Everything the last request sent to the AI, as one string, to check what was left out.
     */
    public function lastSentText(): string
    {
        return (string) json_encode(end($this->requests), JSON_UNESCAPED_UNICODE);
    }
}
