# OV-Panel Integration Documentation

## Overview

OV-Panel is a panel type that provides OpenVPN configurations via `.ovpn` files instead of subscription links. This integration allows VpnMarket to provision, manage, and deliver OpenVPN configurations securely to resellers and end users.

## Key Differences from Other Panels

Unlike Marzban, Marzneshin, and X-UI which provide subscription URLs, OV-Panel:
- Returns `.ovpn` configuration files
- Requires secure token-based download delivery
- Supports QR code generation for easy mobile access
- Files are stored locally and served through tokenized URLs

## Architecture

### Database Schema

The `reseller_configs` table has been extended with:
- `panel_type` enum now includes `'ovpanel'`
- `ovpn_path` (string, nullable): Storage path to the `.ovpn` file
- `ovpn_token` (string, 64 chars, nullable): Secure random token for downloads
- `ovpn_token_expires_at` (timestamp, nullable): Optional token expiration

### Components

1. **OVPanelService** (`Modules/Reseller/Services/OVPanelService.php`)
   - Wraps OV-Panel API endpoints
   - Methods: `createUser()`, `enableUser()`, `disableUser()`, `deleteUser()`, `getUsage()`, `refreshOvpn()`
   - Handles authentication and API communication

2. **ResellerProvisioner** (updated)
   - Added `provisionOvpanel()` method
   - Supports ovpanel in enable/disable/delete operations
   - Stores `.ovpn` files to `storage/app/ovpn/`

3. **OVPNDownloadController** (`app/Http/Controllers/OVPNDownloadController.php`)
   - Public route: `GET /ovpn/{token}` - token-based download
   - Authenticated route: `GET /reseller/configs/{id}/ovpn` - reseller download
   - Implements audit logging for all downloads

4. **ConfigsRelationManager** (updated)
   - OV-Panel specific actions: Download, Refresh, Copy Link
   - QR code modal for easy mobile access
   - Conditional visibility based on panel type

## Setup Guide

### 1. Run Migrations

```bash
php artisan migrate
```

This adds the necessary columns to support ovpanel configurations.

### 2. Register an OV-Panel in Admin Panel

1. Go to Admin → Panels
2. Create new panel with:
   - Panel Type: `ovpanel`
   - URL: Your OV-Panel API base URL (e.g., `https://ovpanel.example.com`)
   - Username: Admin username
   - Password: Admin password

### 3. Configure Panel for Reseller Plans

Associate the OV-Panel with your plans as needed.

## Usage

### Creating a Config

When a reseller creates a config with an ovpanel type:
1. User is provisioned on the remote OV-Panel
2. `.ovpn` file is downloaded and stored in `storage/app/ovpn/`
3. A secure 64-character token is generated
4. Config is saved with path and token

### Downloading Configs

**For Resellers (Filament UI):**
1. Navigate to Reseller → Configs
2. Click "دانلود .ovpn" (Download .ovpn) button
3. Modal displays:
   - Download button
   - QR code (scan with mobile device)
   - Copy-able download link

**For End Users:**
Share the tokenized URL with end users:
```
https://your-domain.com/ovpn/{token}
```

### Refreshing .ovpn Files

If configurations change on the OV-Panel:
1. Click "بروزرسانی .ovpn" (Refresh .ovpn)
2. New file is fetched from OV-Panel
3. Old file is deleted
4. New token is generated
5. Config is updated

### Token Security

- Tokens are 64-character random hex strings
- Optional expiration can be set via `ovpn_token_expires_at`
- Tokens are rotated on refresh
- Public route returns 404 for invalid/expired tokens (no PII leaked)

## Usage Sync and Enforcement

OV-Panel configs participate in the same usage sync process:
- `SyncResellerUsageJob` fetches usage via `OVPanelService::getUsage()`
- Auto-disable when traffic limits exceeded
- Auto-disable on time expiration
- Same grace period settings apply

## Audit Logging

The following actions are logged:
- `config_ovpn_generated` - When .ovpn is first created
- `config_ovpn_refreshed` - When .ovpn is refreshed
- `config_ovpn_downloaded` - When .ovpn is downloaded

Metadata includes:
- Reseller ID
- Panel ID and type
- Filename
- Token rotation status
- Actor (for authenticated downloads)

**Note:** Token values are stored in DB but NOT logged in plaintext in application logs.

## API Reference

See `/docs/ovpanel-API-doc.md` for complete OV-Panel API documentation.

Key endpoints used:
- `POST /api/login` - Authentication
- `POST /api/user/create` - Create user
- `GET /api/user/download/ovpn/{name}` - Download .ovpn file
- `PUT /api/user/update` - Enable/disable user
- `DELETE /api/user/delete/{name}` - Delete user
- `GET /api/user/all` - Get usage (if supported)

## Troubleshooting

### .ovpn file not found
- Check that `storage/app/ovpn/` directory exists and is writable
- Verify the config has `ovpn_path` set
- Check storage disk configuration

### Token expired or invalid
- Tokens can optionally expire via `ovpn_token_expires_at`
- Refresh the .ovpn file to generate a new token
- Check that token matches exactly (case-sensitive)

### QR code not displaying
- Ensure QR code library loads from CDN
- Check browser console for JavaScript errors
- Verify internet connectivity (CDN access required)

### OV-Panel API errors
- Check panel credentials in Admin → Panels
- Verify OV-Panel URL is accessible
- Check OV-Panel logs for authentication issues
- Ensure user doesn't already exist (duplicate name error)

## Security Considerations

1. **Token Security**: 64-character random tokens provide strong security
2. **File Access**: Files stored in `storage/app/` (not publicly accessible)
3. **Download Logging**: All downloads are audited
4. **No PII Leakage**: 404 response on invalid token reveals nothing
5. **Token Rotation**: Refresh operation generates new tokens
6. **Optional Expiration**: Set token expiration for time-limited access

## Rate Limiting

Consider adding rate limiting middleware to the public download route to prevent abuse:

```php
Route::get('/ovpn/{token}', [OVPNDownloadController::class, 'downloadByToken'])
    ->middleware('throttle:10,1') // 10 requests per minute
    ->name('ovpn.download.token');
```

## Future Enhancements

Potential improvements:
- Download counter in config metadata
- Batch refresh for multiple configs
- Email delivery of download links
- Temporary token generation for one-time use
- Mobile app deep linking support
