<?php

namespace App\Helpers;

class IdNormalization
{
    /**
     * Normalize an array of IDs to integers
     * Filters out non-numeric values to prevent ID collisions
     * 
     * @param array $ids Array of IDs to normalize
     * @param bool $positiveOnly Only include positive integers (> 0)
     * @return array Array of integer IDs
     */
    public static function normalizeIds(array $ids, bool $positiveOnly = false): array
    {
        $result = [];
        foreach ($ids as $id) {
            // Only include numeric values to prevent non-numeric strings from becoming 0
            if ($id !== null && $id !== '' && is_numeric($id)) {
                $intId = (int) $id;
                if (!$positiveOnly || $intId > 0) {
                    $result[] = $intId;
                }
            }
        }
        return $result;
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
        return self::normalizeIds($nodeIds, true);
    }
}
