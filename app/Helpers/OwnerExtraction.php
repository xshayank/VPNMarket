<?php

namespace App\Helpers;

class OwnerExtraction
{
    /**
     * Extract owner username from a user/config API response.
     * 
     * Checks multiple common fields that panel APIs might use to indicate ownership:
     * - admin / admin_username
     * - owner / owner_username
     * - created_by / created_by_username
     * - meta.owner
     * 
     * @param array $record API response record (user/config object)
     * @return string|null Owner username or null if not found
     */
    public static function ownerUsername(array $record): ?string
    {
        // Check direct admin fields (most common in Marzban/Marzneshin)
        if (isset($record['admin']) && is_string($record['admin'])) {
            return $record['admin'];
        }
        
        if (isset($record['admin_username']) && is_string($record['admin_username'])) {
            return $record['admin_username'];
        }
        
        // Check owner fields
        if (isset($record['owner']) && is_string($record['owner'])) {
            return $record['owner'];
        }
        
        if (isset($record['owner_username']) && is_string($record['owner_username'])) {
            return $record['owner_username'];
        }
        
        // Check created_by fields
        if (isset($record['created_by']) && is_string($record['created_by'])) {
            return $record['created_by'];
        }
        
        if (isset($record['created_by_username']) && is_string($record['created_by_username'])) {
            return $record['created_by_username'];
        }
        
        // Check meta object for owner field
        if (isset($record['meta']) && is_array($record['meta'])) {
            if (isset($record['meta']['owner']) && is_string($record['meta']['owner'])) {
                return $record['meta']['owner'];
            }
            
            if (isset($record['meta']['owner_username']) && is_string($record['meta']['owner_username'])) {
                return $record['meta']['owner_username'];
            }
        }
        
        return null;
    }
}
