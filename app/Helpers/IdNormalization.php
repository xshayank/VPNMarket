<?php

namespace App\Helpers;

class IdNormalization
{
    /**
     * Normalize an array of IDs to integers
     * 
     * @param array $ids Array of IDs to normalize
     * @return array Array of integer IDs
     */
    public static function normalizeIds(array $ids): array
    {
        return array_map('intval', array_filter($ids, function ($id) {
            return $id !== null && $id !== '';
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
     * 
     * @param array $nodeIds Array of node IDs
     * @return array Array of positive integer node IDs
     */
    public static function normalizeNodeIds(array $nodeIds): array
    {
        return array_values(array_filter(
            array_map('intval', $nodeIds),
            fn($id) => $id > 0
        ));
    }
}
