<?php

declare(strict_types=1);

namespace Gdpd\Api;

use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\Logger;

/**
 * Ports the legacy `apicommand` dispatcher (SrvMetod.pas) and the wrapping
 * logic in `WebModule1_all_tAction` (WbMdul.pas). Every data route from
 * the old WbMdul.dfm action list goes through this single dispatcher,
 * keyed by its route name, exactly like the original.
 *
 * Preserved exactly, including behaviour that looks like a bug:
 * - The API key is read fresh from config on every request and compared
 *   to the request's APIKEY query parameter with plain string equality --
 *   no hashing, no rate limiting. (Currently empty in production, so this
 *   is effectively no auth at all -- known, tracked separately.)
 * - A request whose HTTP method is neither GET nor PUT for a given route
 *   (e.g. POST) is never rejected: the dispatcher falls through and echoes
 *   the raw request body back with HTTP 200, because the original code
 *   initializes its result variable from Request.Content and never
 *   reassigns it when no GET/PUT branch matches. Confirmed live: curl'ing
 *   the real server with an unexpected method reflects the body verbatim.
 * - A route name that has no handler registered behaves the same way
 *   (echoes the body) -- lets routes be added incrementally without
 *   behaving differently from "not wired yet" in the original.
 * - The response Content-Type is set to `application/json; charset=UTF-8`
 *   only when the result text does NOT contain the substring "Error: "
 *   anywhere -- not just as a prefix. This means e.g. the literal "401
 *   Unauthorized" response also gets a JSON content type, exactly like
 *   the original.
 * - The HTTP status code is always 200; errors and the unauthorized case
 *   are communicated only through the body text.
 * - Real dp.galladance.com code sends "GET" requests with a JSON body
 *   (curl CUSTOMREQUEST=GET + POSTFIELDS) -- PHP's request handling reads
 *   the raw body regardless of method, so this works the same way here.
 */
final class Dispatcher
{
    /** @var array<string, callable(?array): string> */
    private array $getHandlers = [];
    /** @var array<string, callable(?array): string> */
    private array $putObjectHandlers = [];
    /** @var array<string, callable(list<array<string, mixed>>): string> */
    private array $putArrayHandlers = [];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $log,
    ) {
    }

    /** @param callable(?array): string $handler */
    public function mapGet(string $routeName, callable $handler): void
    {
        $this->getHandlers[strtolower($routeName)] = $handler;
    }

    /** @param callable(?array): string $handler */
    public function mapPutObject(string $routeName, callable $handler): void
    {
        $this->putObjectHandlers[strtolower($routeName)] = $handler;
    }

    /** @param callable(list<array<string, mixed>>): string $handler */
    public function mapPutArray(string $routeName, callable $handler): void
    {
        $this->putArrayHandlers[strtolower($routeName)] = $handler;
    }

    public function handle(string $routeName): void
    {
        $body = file_get_contents('php://input');
        $body = $body === false ? '' : $body;

        try {
            $result = $this->dispatch($routeName, $body);
        } catch (\Throwable $e) {
            $result = $e->getMessage();
            $this->log->log('ERROR_APIRESTw', $e->getMessage());
        }

        $this->logApiCall($routeName, $body);

        $contentType = str_contains($result, 'Error: ')
            ? 'text/plain; charset=UTF-8'
            : 'application/json; charset=UTF-8';
        header('Content-Type: ' . $contentType);
        echo $result;
    }

    private function dispatch(string $routeName, string $body): string
    {
        $decoded = null;
        $isArray = false;
        if ($body !== '') {
            $trimmed = ltrim($body);
            $isArray = $trimmed !== '' && $trimmed[0] === '[';
            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = null;
            }
        }

        $apiKey = $_GET['APIKEY'] ?? '';
        $configuredKey = $this->config->get('db', 'apikey');
        if ($apiKey !== $configuredKey) {
            return '401 Unauthorized';
        }

        // Matches the legacy fallback: unless a branch below reassigns it,
        // the result is the raw request body (relevant for non-GET/PUT
        // methods and for routes with no handler registered yet).
        $result = $body;
        $routeKey = strtolower($routeName);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            if ($method === 'GET') {
                if (isset($this->getHandlers[$routeKey])) {
                    /** @var array<string, mixed>|null $jo */
                    $jo = $isArray ? null : $decoded;
                    $result = ($this->getHandlers[$routeKey])($jo);
                }
            } elseif ($method === 'PUT') {
                if ($routeKey === 'putdattab') {
                    if (!$isArray || $decoded === null) {
                        $result = 'Error: Json is null';
                    } elseif (isset($this->putArrayHandlers[$routeKey])) {
                        $result = ($this->putArrayHandlers[$routeKey])($decoded);
                    }
                } elseif (isset($this->putObjectHandlers[$routeKey])) {
                    if ($isArray || $decoded === null) {
                        $result = 'Error: Json is null';
                    } else {
                        $result = ($this->putObjectHandlers[$routeKey])($decoded);
                    }
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->log->log('ERROR_APIREST1', $e->getMessage());
            return $e->getMessage();
        }
    }

    private function logApiCall(string $routeName, string $body): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $tab = $_GET['tab'] ?? '';
            $description = $tab === ''
                ? " apicom: \"{$method}:{$routeName}\", apinbody:\"{$body}\""
                : " apicom: \"{$method}:{$routeName}?tab={$tab}\", apinbody:\"{$body}\"";
            $this->log->log('apicommand', $description);
        } catch (\Throwable $e) {
            $this->log->log('ERROR_APIREST2', $e->getMessage());
        }
    }
}
