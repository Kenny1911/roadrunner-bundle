<?php

declare(strict_types=1);

namespace Baldinof\RoadRunnerBundle\RoadRunnerBridge\HttpFoundationWorker;

use Symfony\Component\HttpFoundation\Response;

final class ChainChunkSizeResolver implements ChunkSizeResolver
{
    /**
     * @param iterable<ChunkSizeResolver> $resolvers
     */
    public function __construct(
        private iterable $resolvers,
    ) {}

    public function resolve(Response $response): int
    {
        foreach ($this->resolvers as $resolver) {
            $chunkSize = $resolver->resolve($response);

            if ($chunkSize > 0) {
                return $chunkSize;
            }
        }

        return 0;
    }
}