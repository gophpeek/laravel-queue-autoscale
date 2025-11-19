<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueAutoscale\Workers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PHPeek\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Symfony\Component\Process\Process;

final readonly class WorkerSpawner
{
    /**
     * Spawn N queue:work worker processes
     *
     * @param  string  $connection  Queue connection name
     * @param  string  $queue  Queue name
     * @param  int  $count  Number of workers to spawn
     * @return Collection<int, WorkerProcess> Spawned workers
     */
    public function spawn(string $connection, string $queue, int $count): Collection
    {
        $workers = collect();

        for ($i = 0; $i < $count; $i++) {
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'queue:work',
                $connection,
                '--queue='.$queue,
                '--tries='.AutoscaleConfiguration::workerTries(),
                '--max-time='.AutoscaleConfiguration::workerTimeoutSeconds(),
                '--sleep='.AutoscaleConfiguration::workerSleepSeconds(),
            ]);

            $process->start();

            $worker = new WorkerProcess(
                process: $process,
                connection: $connection,
                queue: $queue,
                spawnedAt: now(),
            );

            $workers->push($worker);

            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Worker spawned',
                [
                    'connection' => $connection,
                    'queue' => $queue,
                    'pid' => $process->getPid(),
                ]
            );
        }

        return $workers;
    }
}
