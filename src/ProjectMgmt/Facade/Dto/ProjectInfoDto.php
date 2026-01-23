<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade\Dto;

use App\ProjectMgmt\Facade\Enum\ProjectType;

final readonly class ProjectInfoDto
{
    public function __construct(
        public string      $id,
        public string      $name,
        public string      $gitUrl,
        public string      $githubToken,
        public ProjectType $projectType,
        public string      $githubUrl,
        public string      $agentImage,
    ) {
    }
}
