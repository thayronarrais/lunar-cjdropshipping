<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Thayron\CjDropshipping\Auth\AccessToken;
use Thayron\CjDropshipping\Auth\TokenManager;
use Thayron\CjDropshipping\CjClient;

/**
 * Queues CJdropshipping API responses for the SDK client resolved from the container.
 */
final class FakeCj
{
    public const OPEN_ID = '123456789';

    private MockHandler $handler;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    public static function install(Application $app): self
    {
        $fake = new self;
        $fake->handler = new MockHandler;

        $stack = HandlerStack::create($fake->handler);
        $stack->push(Middleware::history($fake->history));

        $app->instance(ClientInterface::class, new Client(['handler' => $stack, 'http_errors' => false]));
        $app->forgetInstance(CjClient::class);

        $token = new AccessToken(
            'fake-access-token',
            new DateTimeImmutable('+10 days'),
            'fake-refresh-token',
            new DateTimeImmutable('+170 days'),
            self::OPEN_ID,
        );
        // Stored forever (not the token's own ~10 day expiry): tests that travel() the
        // clock forward must not have this cache entry expire out from under them.
        Cache::put(TokenManager::storeKeyFor((string) config('cjdropshipping.api_key')), $token->toArray(), null);

        return $fake;
    }

    public function fixture(string $name): self
    {
        $this->handler->append(new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__."/../Fixtures/{$name}.json")));

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(string $name): array
    {
        $envelope = json_decode((string) file_get_contents(__DIR__."/../Fixtures/{$name}.json"), true);

        return $envelope['data'];
    }

    /**
     * @param  array<string, mixed>  $overrides  merged into the fixture "data"
     */
    public function fixtureWith(string $name, array $overrides): self
    {
        $envelope = json_decode((string) file_get_contents(__DIR__."/../Fixtures/{$name}.json"), true);
        $envelope['data'] = array_replace_recursive($envelope['data'], $overrides);

        return $this->json($envelope);
    }

    public function success(mixed $data): self
    {
        return $this->json(['code' => 200, 'result' => true, 'message' => 'Success', 'data' => $data, 'requestId' => 'fake']);
    }

    public function error(int $code, string $message = 'Error'): self
    {
        return $this->json(['code' => $code, 'result' => false, 'message' => $message, 'data' => null, 'requestId' => 'fake']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function json(array $body): self
    {
        $this->handler->append(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)));

        return $this;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_values(array_map(fn (array $entry): RequestInterface => $entry['request'], $this->history));
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(
            fn (RequestInterface $request): string => (string) preg_replace('#^/api2\.0/v1/#', '', $request->getUri()->getPath()),
            $this->requests(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function queryAt(int $index): array
    {
        parse_str($this->requests()[$index]->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function jsonAt(int $index): array
    {
        $decoded = json_decode((string) $this->requests()[$index]->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function remaining(): int
    {
        return $this->handler->count();
    }
}
