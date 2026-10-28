<?php

namespace SchemaSketch\Laravel\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

// The SchemaSketch API calls this package makes (see docs/DESIGN.md).
// Errors come back as ApiException with a message meant for the person
// running the command; the token is never part of one.
class Client
{
    public function __construct(private string $apiUrl, private ?string $token, private int $timeout = 15) {}

    public static function fromConfig(): self
    {
        return new self(config('schemasketch.api_url'), config('schemasketch.token'), (int) config('schemasketch.timeout'));
    }

    public function hasToken(): bool
    {
        return (bool) $this->token;
    }

    /** The site's base URL, for links: https://schemasketch.dev */
    public function appUrl(): string
    {
        return preg_replace('#/api/?$#', '', rtrim($this->apiUrl, '/'));
    }

    public function diagramUrl(string $id): string
    {
        return $this->appUrl().'/app?diagram='.rawurlencode($id);
    }

    public function me(): array
    {
        return $this->send(fn ($http) => $http->get('/me'));
    }

    public function schema(string $diagramId): array
    {
        return $this->send(fn ($http) => $http->get('/diagrams/'.rawurlencode($diagramId).'/schema'));
    }

    public function create(string $name, string $dbml): array
    {
        return $this->send(fn ($http) => $http->post('/diagrams', ['name' => $name, 'dbml_source' => $dbml]));
    }

    public function update(string $diagramId, string $dbml, int $baseVersion): array
    {
        return $this->send(fn ($http) => $http->put('/diagrams/'.rawurlencode($diagramId), [
            'dbml_source' => $dbml,
            'base_version' => $baseVersion,
        ]));
    }

    private function send(callable $call): array
    {
        if (! $this->token) {
            throw new ApiException('No SchemaSketch token. Create one in SchemaSketch ("Connect AI agent") and set SCHEMASKETCH_TOKEN in your .env.');
        }

        try {
            /** @var Response $response */
            $response = $call(Http::baseUrl($this->apiUrl)->withToken($this->token)->acceptJson()->timeout($this->timeout));
        } catch (ConnectionException $e) {
            throw new ApiException("Couldn't reach SchemaSketch at {$this->apiUrl}. Check your connection and try again.");
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $data = $response->json() ?? [];
        $message = match (true) {
            $response->status() === 401 => 'Your SchemaSketch token is invalid or has expired. Create a new one in "Connect AI agent" and update SCHEMASKETCH_TOKEN.',
            $response->status() === 409 => 'The diagram changed in SchemaSketch while this ran. Run the command again to include those changes.',
            $response->status() === 429 => 'Too many requests to SchemaSketch. Wait a minute and try again.',
            $response->status() >= 500 => "SchemaSketch had a problem (HTTP {$response->status()}). Try again in a minute.",
            default => $data['message'] ?? "SchemaSketch returned HTTP {$response->status()}.",
        };

        throw new ApiException($message, $response->status(), $data);
    }
}
