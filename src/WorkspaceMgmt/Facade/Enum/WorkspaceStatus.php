<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Facade\Enum;

enum WorkspaceStatus: int
{
    case AVAILABLE_FOR_SETUP        = 0;
    case IN_SETUP                   = 1;
    case AVAILABLE_FOR_CONVERSATION = 2;
    case IN_CONVERSATION            = 3;
    case IN_REVIEW                  = 4;
    case MERGED                     = 5;
    case PROBLEM                    = 6;
}
