<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade\Enum;

/**
 * Defines the type of a project, which determines workspace setup steps.
 */
enum ProjectType: string
{
    /**
     * Default project type with standard npm-based setup.
     */
    case DEFAULT = 'default';
}
