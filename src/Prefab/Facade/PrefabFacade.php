<?php

declare(strict_types=1);

namespace App\Prefab\Facade;

use App\Prefab\Domain\Service\PrefabLoader;
use App\Prefab\Facade\Dto\PrefabDto;

final class PrefabFacade implements PrefabFacadeInterface
{
    public function __construct(
        private readonly PrefabLoader $prefabLoader
    ) {
    }

    /**
     * @return list<PrefabDto>
     */
    public function loadPrefabs(): array
    {
        return $this->prefabLoader->load();
    }
}
