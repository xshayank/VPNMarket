# OV-Panel Integration - Manual Verification Guide

## Overview
This guide provides step-by-step instructions for manually verifying the OV-Panel integration.

## Prerequisites
- Access to an OV-Panel instance
- Admin access to VpnMarket application
- Reseller account for testing

## Step 1: Register OV-Panel
1. Login as admin
2. Navigate to Admin → Panels
3. Click "Create New Panel"
4. Fill in details:
   - Name: `Test OV-Panel`
   - URL: `https://your-ovpanel-instance.com`
   - Panel Type: Select `ovpanel` from dropdown
   - Username: Your OV-Panel admin username
   - Password: Your OV-Panel admin password
5. Click "Save"
6. Verify panel appears in list with "OV-Panel" badge

## Step 2: Create Reseller with OV-Panel
1. Navigate to Admin → Resellers
2. Create new reseller or select existing
3. Assign the OV-Panel created in Step 1
4. Ensure reseller has traffic quota allocated

## Step 3: Create Config with OV-Panel
1. Login as the reseller
2. Navigate to Configs
3. Click "Create Config"
4. Fill in details:
   - Username: `test_ovpn_user`
   - Traffic Limit: 10 GB
   - Expiry: 30 days from now
   - Panel Type: Should show `ovpanel`
5. Click "Create"
6. Verify config is created successfully

Expected Results:
- Config appears in list
- Status shows "Active"
- Panel type shows "ovpanel" badge
- No subscription URL column (ovpanel specific)

## Step 4: Verify OV-Panel Specific Actions
In the config actions menu, verify these buttons appear:

### Download .ovpn Button
1. Click "دانلود .ovpn" (Download .ovpn)
2. Modal should open with:
   - Download button at top
   - QR code in center
   - Download URL in text field
   - Copy button next to URL
3. Click download button
4. Verify .ovpn file downloads with filename: `test_ovpn_user.ovpn`
5. Open file and verify it contains valid OpenVPN configuration

### Refresh .ovpn Button
1. Click "بروزرسانی .ovpn" (Refresh .ovpn)
2. Confirm the action
3. Success notification should appear
4. Click "Download .ovpn" again
5. Verify new download URL is different (token rotated)

### Copy Download Link Button
1. Click "کپی لینک دانلود" (Copy download link)
2. Notification should show the copied URL
3. Open URL in browser (should be format: `/ovpn/{64-char-token}`)
4. Verify .ovpn file downloads

## Step 5: Test Token-Based Download
1. Get the download URL from Step 4
2. Test in different scenarios:
   
   **Valid Token:**
   - Access URL: `https://your-domain.com/ovpn/{valid-token}`
   - Should download .ovpn file immediately
   - HTTP 200 status
   - Content-Type: `application/x-openvpn-profile`

   **Invalid Token:**
   - Access URL: `https://your-domain.com/ovpn/invalid_token_xyz`
   - Should return 404 Not Found
   - No information about the config leaked

## Step 6: Test QR Code Scanning
1. Open "Download .ovpn" modal on desktop
2. Use mobile device to scan QR code
3. Should open download URL on mobile
4. .ovpn file should download on mobile
5. Can import directly into OpenVPN app

## Step 7: Verify Audit Logging
1. Navigate to Admin → Audit Logs
2. Filter by action type or search for config
3. Verify these log entries exist:
   - `config_ovpn_generated` - when config was created
   - `config_ovpn_downloaded` - when .ovpn was downloaded
   - `config_ovpn_refreshed` - when .ovpn was refreshed

Each log should contain:
- Timestamp
- Actor (user who performed action)
- Target (config ID)
- Metadata (panel_id, panel_type, filename)

## Step 8: Test Usage Sync
1. Use the config to generate some VPN traffic
2. Wait for usage sync job to run (or trigger manually)
3. Navigate to reseller configs
4. Verify usage bytes are updated for the config
5. If usage exceeds limit, config should auto-disable

## Step 9: Test Enable/Disable
### Disable Config
1. Click disable button on active config
2. Confirm action
3. Verify status changes to "Disabled"
4. Verify .ovpn file still downloadable (but won't work)

### Enable Config
1. Click enable button on disabled config
2. Confirm action
3. Verify status changes to "Active"
4. Verify VPN connection works

## Step 10: Test Config Deletion
1. Create a test config
2. Click delete button
3. Confirm deletion
4. Verify config is soft-deleted
5. Verify .ovpn file is no longer downloadable (token invalid)

## Step 11: Verify Security
### Token Expiration (if implemented)
1. Create config with expiring token
2. Wait for token to expire
3. Try to download via expired token
4. Should return 403 Forbidden

### Authorization
1. Login as different reseller
2. Try to access another reseller's config download URL
3. Should return 403 Unauthorized

### File Storage
1. Check `storage/app/ovpn/` directory
2. Verify .ovpn files are stored with UUID filenames
3. Verify files are not in public directory

## Step 12: Test Panel Filter
1. Navigate to Configs list
2. Use panel type filter
3. Select "OV-Panel"
4. Verify only ovpanel configs are shown

## Common Issues and Troubleshooting

### Issue: .ovpn file not found
- Check `storage/app/ovpn/` directory exists and is writable
- Verify config has `ovpn_path` set in database
- Check application logs for storage errors

### Issue: QR code not displaying
- Check browser console for JavaScript errors
- Verify CDN for qrcodejs is accessible
- Check internet connectivity

### Issue: OV-Panel API errors
- Verify OV-Panel URL is correct and accessible
- Check credentials are valid
- Review OV-Panel logs for API errors
- Verify user doesn't already exist (duplicate name)

### Issue: Token expired immediately
- Check `ovpn_token_expires_at` column in database
- Verify server time is correct
- Check token generation logic

## Expected File Structure
After successful setup, you should see:

```
storage/app/ovpn/
├── abc123...def.ovpn
├── xyz789...abc.ovpn
└── ...
```

## Database Verification
Check database tables:

```sql
-- Verify config has ovpanel fields
SELECT id, external_username, panel_type, ovpn_path, ovpn_token, ovpn_token_expires_at
FROM reseller_configs
WHERE panel_type = 'ovpanel';

-- Verify audit logs
SELECT action, actor_type, actor_id, target_type, target_id, meta, created_at
FROM audit_logs
WHERE action IN ('config_ovpn_generated', 'config_ovpn_refreshed', 'config_ovpn_downloaded')
ORDER BY created_at DESC;

-- Verify panel
SELECT id, name, panel_type, url, is_active
FROM panels
WHERE panel_type = 'ovpanel';
```

## Performance Considerations
- .ovpn files are small (~5-10 KB typically)
- Token lookups are indexed for fast retrieval
- QR codes generated client-side (no server load)
- Downloads served via Laravel Storage (streaming)

## Success Criteria
✅ Can create OV-Panel configs
✅ .ovpn files downloadable via tokenized URLs
✅ QR codes work on mobile devices
✅ Usage sync updates from OV-Panel
✅ Enable/disable operations work
✅ Audit logs capture all operations
✅ Security measures in place (token validation, authorization)
✅ No regressions in existing functionality

## Next Steps
After successful verification:
1. Test with real OV-Panel instance in production
2. Monitor audit logs for any issues
3. Collect feedback from resellers
4. Consider adding rate limiting to download routes
5. Monitor storage usage for .ovpn files
