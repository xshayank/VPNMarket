<?php

namespace App\Console\Commands;

use App\Models\Panel;
use App\Services\EylandooService;
use Illuminate\Console\Command;

class EylandooDebugUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eylandoo:debug-usage {username} 
                            {--panel-id= : Panel ID to use for credentials}
                            {--panel-url= : Override panel URL}
                            {--api-token= : Override API token}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug Eylandoo usage API response for a specific username';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $username = $this->argument('username');
        $panelId = $this->option('panel-id');
        $panelUrl = $this->option('panel-url');
        $apiToken = $this->option('api-token');

        $this->info('=== Eylandoo Usage Debug Tool ===');
        $this->newLine();

        // Resolve credentials
        if ($panelId) {
            $panel = Panel::find($panelId);
            if (! $panel) {
                $this->error("Panel with ID {$panelId} not found");

                return 1;
            }

            if ($panel->panel_type !== 'eylandoo') {
                $this->error("Panel {$panelId} is not an Eylandoo panel (type: {$panel->panel_type})");

                return 1;
            }

            $credentials = $panel->getCredentials();
            $panelUrl = $panelUrl ?: $credentials['url'];
            $apiToken = $apiToken ?: $credentials['api_token'];

            $this->line("🔧 Using Panel: {$panel->name} (ID: {$panelId})");
        } elseif ($panelUrl && $apiToken) {
            $this->line('🔧 Using custom credentials');
        } else {
            $this->error('You must provide either --panel-id or both --panel-url and --api-token');

            return 1;
        }

        $this->line("🌐 Panel URL: {$panelUrl}");
        $this->line("👤 Username: {$username}");
        $this->newLine();

        // Create service
        $service = new EylandooService($panelUrl, $apiToken, '');

        // Fetch user data
        $this->info('📡 Calling GET /api/v1/users/'.$username);
        $this->newLine();

        try {
            $response = $service->getUser($username);

            if ($response === null) {
                $this->error('❌ Failed to fetch user data (HTTP error or user not found)');
                $this->warn('Check:');
                $this->line('  - Panel URL is correct and accessible');
                $this->line('  - API token is valid');
                $this->line('  - Username exists on the panel');

                return 1;
            }

            // Display response preview (trimmed)
            $this->info('✅ API Response received');
            $this->newLine();
            $this->line('📄 JSON Response Preview (first 500 chars):');
            $this->line(str_repeat('-', 60));
            $jsonPreview = json_encode($response, JSON_PRETTY_PRINT);
            $this->line(substr($jsonPreview, 0, 500));
            if (strlen($jsonPreview) > 500) {
                $this->line('... (truncated)');
            }
            $this->line(str_repeat('-', 60));
            $this->newLine();

            // Parse usage
            $usage = $service->getUserUsageBytes($username);

            if ($usage === null) {
                $this->error('❌ Usage parsing failed (returned null)');
                $this->warn('Possible reasons:');
                $this->line('  - API returned success:false');
                $this->line('  - Exception occurred during parsing');
                $this->line('Check logs for detailed error messages');

                return 1;
            }

            // Display usage result
            $this->info('✅ Usage parsed successfully');
            $this->newLine();
            $this->line("📊 Normalized Usage: {$usage} bytes ({$this->formatBytes($usage)})");
            $this->newLine();

            // Display wrapper keys found
            $this->line('🔍 Response Structure Analysis:');
            $this->displayResponseStructure($response);
            $this->newLine();

            $this->info('✅ Debug complete - check application logs for detailed parsing info');

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Exception occurred: '.$e->getMessage());
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Display response structure
     */
    protected function displayResponseStructure(array $response): void
    {
        $wrapperKeys = ['userInfo', 'data', 'user', 'result', 'stats'];

        foreach ($wrapperKeys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $this->line("  ✓ Wrapper '{$key}' found with keys: ".implode(', ', array_keys($response[$key])));
            }
        }

        // Show top-level keys
        $topLevelKeys = array_keys($response);
        $this->line('  ℹ Top-level keys: '.implode(', ', $topLevelKeys));
    }

    /**
     * Format bytes to human-readable format
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 bytes';
        }

        $sign = $bytes < 0 ? '-' : '';
        $abs = abs($bytes);

        if ($abs < 1024) {
            return $sign.$abs.' bytes';
        } elseif ($abs < 1048576) {
            return $sign.number_format($abs / 1024, 2).' KB';
        } elseif ($abs < 1073741824) {
            return $sign.number_format($abs / 1048576, 2).' MB';
        } else {
            return $sign.number_format($abs / 1073741824, 2).' GB';
        }
    }
}
