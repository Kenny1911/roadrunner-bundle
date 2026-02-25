<?php

declare(strict_types=1);

namespace Baldinof\RoadRunnerBundle\RoadRunnerBridge\HttpFoundationWorker;

use Symfony\Component\HttpFoundation\Response;

interface ChunkSizeResolver
{
    /**
     * @return non-negative-int If chunk size is 0, it is ignored.
     */
    public function resolve(Response $response): int;
}
