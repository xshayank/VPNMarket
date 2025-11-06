<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Panel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'panel_type',
        'username',
        'password',
        'api_token',
        'extra',
        'is_active',
    ];

    protected $casts = [
        'extra' => 'array',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'password',
        'api_token',
    ];

    /**
     * Encrypt password before saving
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    /**
     * Encrypt API token before saving
     */
    protected function apiToken(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    public function plans()
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Get panel credentials for API usage
     */
    public function getCredentials(): array
    {
        return [
            'url' => $this->url,
            'username' => $this->username,
            'password' => $this->password,
            'api_token' => $this->api_token,
            'extra' => $this->extra ?? [],
        ];
    }

    /**
     * Get Eylandoo nodes with caching (5 minutes)
     *
     * @return array Array of nodes with id and name
     */
    public function getCachedEylandooNodes(): array
    {
        if ($this->panel_type !== 'eylandoo') {
            return [];
        }

        $cacheKey = "panel:{$this->id}:eylandoo_nodes";
        
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () {
            try {
                $credentials = $this->getCredentials();
                
                // Validate credentials
                if (empty($credentials['url']) || empty($credentials['api_token'])) {
                    \Illuminate\Support\Facades\Log::warning("Eylandoo nodes fetch: Missing credentials for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                        'has_url' => !empty($credentials['url']),
                        'has_api_token' => !empty($credentials['api_token']),
                    ]);
                    return [];
                }
                
                $service = new \App\Services\EylandooService(
                    $credentials['url'],
                    $credentials['api_token'],
                    $credentials['extra']['node_hostname'] ?? ''
                );
                
                $nodes = $service->listNodes();
                
                if (empty($nodes)) {
                    \Illuminate\Support\Facades\Log::info("Eylandoo nodes fetch: API returned no nodes for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                        'url' => $credentials['url'],
                    ]);
                } else {
                    \Illuminate\Support\Facades\Log::debug("Eylandoo nodes fetch: Successfully retrieved " . count($nodes) . " nodes for panel {$this->id}");
                }
                
                return $nodes;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to fetch Eylandoo nodes for panel {$this->id}: " . $e->getMessage(), [
                    'panel_id' => $this->id,
                    'panel_name' => $this->name,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return [];
            }
        });
    }
}
