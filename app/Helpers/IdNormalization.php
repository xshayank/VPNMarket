<?php

namespace App\Helpers;

class IdNormalization
{
    /**
     * Normalize an array of IDs to integers
     * Filters out non-numeric values to prevent ID collisions
     * 
     * @param array $ids Array of IDs to normalize
     * @return array Array of integer IDs
     */
    public static function normalizeIds(array $ids): array
    {
        return array_map('intval', array_filter($ids, function ($id) {
            // Only include numeric values (integers or numeric strings)
            // This prevents non-numeric strings from becoming 0
            return $id !== null && $id !== '' && is_numeric($id);
        }));
    }

    /**
     * Normalize a single ID to integer
     * 
     * @param mixed $id ID to normalize
     * @return int Integer ID
     */
    public static function normalizeId($id): int
    {
        return (int) $id;
    }

    /**
     * Validate and normalize node IDs array
     * Ensures all values are positive integers
     * Filters out non-numeric values to prevent ID collisions
     * 
     * @param array $nodeIds Array of node IDs
     * @return array Array of positive integer node IDs
     */
    public static function normalizeNodeIds(array $nodeIds): array
    {
        return array_values(array_filter(
            array_map('intval', array_filter($nodeIds, fn($id) => is_numeric($id))),
            fn($id) => $id > 0
        ));
    }
}
