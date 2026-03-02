<?php

declare(strict_types=1);

namespace Baldinof\RoadRunnerBundle\RoadRunnerBridge;

use Baldinof\RoadRunnerBundle\RoadRunnerBridge\HttpFoundationWorker\ChunkSizeResolver;
use Baldinof\RoadRunnerBundle\RoadRunnerBridge\HttpFoundationWorker\DefaultChunkSizeResolver;
use Spiral\RoadRunner\Http\HttpWorkerInterface;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Spiral\RoadRunner\WorkerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class HttpFoundationWorker implements HttpFoundationWorkerInterface
{
    private HttpWorkerInterface $httpWorker;
    private array $originalServer;
    private ChunkSizeResolver $chunkSizeResolver;

    public function __construct(HttpWorkerInterface $httpWorker, ChunkSizeResolver $chunkSizeResolver = new DefaultChunkSizeResolver())
    {
        $this->httpWorker = $httpWorker;
        $this->originalServer = $_SERVER;
        $this->chunkSizeResolver = $chunkSizeResolver;
    }

    public function waitRequest(): ?SymfonyRequest
    {
        $rrRequest = $this->httpWorker->waitRequest();

        if ($rrRequest === null) {
            return null;
        }

        return $this->toSymfonyRequest($rrRequest);
    }

    public function respond(SymfonyResponse $response): void
    {
        $chunkSize = $this->chunkSizeResolver->resolve($response);

        ob_start(function (string $buffer, int $phase) use ($response, $chunkSize) {
            static $content = '';
            $content .= $buffer;
            static $isFirstRespond = true;

            $isLast = ($phase & PHP_OUTPUT_HANDLER_END) === PHP_OUTPUT_HANDLER_END;

            // Skip, if content size less, then chunk size
            if (strlen($content) < $chunkSize && !$isLast) {
                return '';
            }

            $this->httpWorker->respond(
                status: $response->getStatusCode(),
                body: $content,
                headers: $isFirstRespond ? $this->stringifyHeaders($response->headers->all()) : [],
                endOfStream: $isLast,
            );
            $isFirstRespond = false;
            $content = '';

            return '';
        }, $chunkSize);
        $response->sendContent();
        @ob_end_clean();
    }

    public function getWorker(): WorkerInterface
    {
        return $this->httpWorker->getWorker();
    }

    private function toSymfonyRequest(RoadRunnerRequest $rrRequest): SymfonyRequest
    {
        $_SERVER = $this->configureServer($rrRequest);

        $files = $this->wrapUploads($rrRequest->uploads);

        $request = new SymfonyRequest(
            $rrRequest->query,
            $rrRequest->getParsedBody() ?? [],
            $rrRequest->attributes,
            $rrRequest->cookies,
            $files,
            $_SERVER,
            $rrRequest->body
        );

        $request->headers->add($rrRequest->headers);

        return $request;
    }

    private function configureServer(RoadRunnerRequest $request): array
    {
        $server = $this->originalServer;

        $components = parse_url($request->uri);

        if ($components === false) {
            throw new \Exception('Failed to parse RoadRunner request URI: '.$request->uri);
        }

        if (isset($components['host'])) {
            $server['SERVER_NAME'] = $components['host'];
        }

        if (isset($components['port'])) {
            $server['SERVER_PORT'] = $components['port'];
        } elseif (isset($components['scheme'])) {
            $server['SERVER_PORT'] = $components['scheme'] === 'https' ? 443 : 80;
        }

        $server['REQUEST_URI'] = $components['path'] ?? '';
        if (isset($components['query']) && $components['query'] !== '') {
            $server['QUERY_STRING'] = $components['query'];
            $server['REQUEST_URI'] .= '?'.$components['query'];
        }

        if (isset($components['scheme']) && $components['scheme'] === 'https') {
            $server['HTTPS'] = 'on';
        }

        $server['REQUEST_TIME'] = $this->timeInt();
        $server['REQUEST_TIME_FLOAT'] = $this->timeFloat();
        $server['REMOTE_ADDR'] = $request->getRemoteAddr();
        $server['REQUEST_METHOD'] = $request->method;
        $server['SERVER_PROTOCOL'] = $request->protocol;

        $server['HTTP_USER_AGENT'] = '';
        foreach ($request->headers as $key => $value) {
            $key = strtoupper(str_replace('-', '_', $key));
            if (\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $server[$key] = implode(', ', $value);
            } else {
                $server['HTTP_'.$key] = implode(', ', $value);
            }
        }

        $authorizationHeader = $request->headers['Authorization'][0] ?? null;

        if ($authorizationHeader !== null && preg_match("/Basic\s+(.*)$/i", $authorizationHeader, $matches)) {
            $decoded = base64_decode($matches[1], true);

            if ($decoded) {
                $userPass = explode(':', $decoded, 2);

                $server['PHP_AUTH_USER'] = $userPass[0];
                $server['PHP_AUTH_PW'] = $userPass[1] ?? '';
            }
        }

        return $server;
    }

    /**
     * Wraps all uploaded files with UploadedFile.
     */
    private function wrapUploads(array $files): array
    {
        $result = [];

        foreach ($files as $index => $file) {
            if (!isset($file['name'])) {
                $result[$index] = $this->wrapUploads($file);
                continue;
            }

            $result[$index] = new UploadedFile($file['tmpName'] ?? '', $file['name'], $file['mime'], $file['error'], true);
        }

        return $result;
    }

    private function timeInt(): int
    {
        return time();
    }

    private function timeFloat(): float
    {
        return microtime(true);
    }

    /**
     * @param array<string, array<int, string|null>>|array<int, string|null> $headers
     *
     * @return array<int|string, string[]>
     */
    private function stringifyHeaders(array $headers): array
    {
        return array_map(static function ($headerValues) {
            return array_map(static fn ($val) => (string) $val, (array) $headerValues);
        }, $headers);
    }
}
