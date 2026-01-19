<?php

declare(strict_types=1);

namespace App\DockerIntegration\Facade;

use App\DockerIntegration\Facade\Dto\ContainerConfigDto;
use App\DockerIntegration\Facade\Dto\ContainerDto;
use App\DockerIntegration\Facade\Dto\ContainerOutputStreamDto;
use App\DockerIntegration\Facade\Dto\ContainerStatusDto;
use App\DockerIntegration\Facade\Dto\ExecutionResultDto;

interface DockerIntegrationFacadeInterface
{
    public function createContainer(ContainerConfigDto $config): ContainerDto;

    public function startContainer(string $containerId): void;

    public function stopContainer(string $containerId): void;

    public function destroyContainer(string $containerId): void;

    public function executeInContainer(string $containerId, string $command, int $timeoutSeconds): ExecutionResultDto;

    public function getContainerOutputStream(string $containerId): ?ContainerOutputStreamDto;

    public function getContainerStatus(string $containerId): ?ContainerStatusDto;
}
