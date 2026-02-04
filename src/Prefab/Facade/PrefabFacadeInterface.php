<?php

declare(strict_types=1);

namespace App\Prefab\Facade;

use App\Prefab\Facade\Dto\PrefabDto;

/**
 * Facade for prefab configuration (prefabricated projects for new organizations).
 */
interface PrefabFacadeInterface
{
    /**
     * Load prefab definitions from prefabs.yaml at project root.
     * Returns empty list if file is missing or invalid.
     *
     * @return list<PrefabDto>
     */
    public function loadPrefabs(): array;
}
