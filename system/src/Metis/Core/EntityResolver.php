<?php
declare(strict_types=1);

namespace Metis\Core;

use Metis\Core\Cache\CacheService;

final class EntityResolver {
    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $entity_uid): ?array {
        $entity_uid = strtoupper(trim($entity_uid));
        if ($entity_uid === '') {
            return null;
        }

        return CacheService::remember('entity_lookup.' . $entity_uid, 300, function () use ($entity_uid): ?array {
            $table = \Metis_Tables::get('entity_registry');
            $row = \metis_db()->fetchOne(
                "SELECT entity_uid, entity_type, entity_table, entity_id, module_slug, created_at
                 FROM {$table}
                 WHERE entity_uid = %s
                 LIMIT 1",
                [ $entity_uid ]
            );

            if (!is_array($row)) {
                return null;
            }

            return [
                'entity_uid' => (string) ($row['entity_uid'] ?? ''),
                'entity_type' => (string) ($row['entity_type'] ?? ''),
                'table' => (string) ($row['entity_table'] ?? ''),
                'id' => (int) ($row['entity_id'] ?? 0),
                'module_slug' => (string) ($row['module_slug'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        });
    }
}
