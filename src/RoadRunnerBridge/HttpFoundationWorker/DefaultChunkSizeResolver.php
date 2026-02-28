<?php

declare(strict_types=1);

namespace Baldinof\RoadRunnerBundle\RoadRunnerBridge\HttpFoundationWorker;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DefaultChunkSizeResolver implements ChunkSizeResolver
{
    public function resolve(Response $response): int
    {
        return match ($response::class) {
            StreamedResponse::class, StreamedJsonResponse::class => 1024 * 16, // 16Kb
            BinaryFileResponse::class => self::resolveBinaryFileResponseChunkSize($response),
            default => 0,
        };
    }

    /**
     * @return non-negative-int
     */
    private static function resolveBinaryFileResponseChunkSize(BinaryFileResponse $response): int
    {
        $chunkSize = (new \ReflectionProperty(BinaryFileResponse::class, 'chunkSize'))->getValue($response);
        \assert(is_int($chunkSize) && $chunkSize >= 0);

        return $chunkSize;
    }
}