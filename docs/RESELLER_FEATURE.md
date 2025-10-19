# Reseller Feature Documentation

## Overview

The Reseller feature allows administrators to convert regular users into resellers who can either:
1. **Plan-based resellers**: Purchase plans in bulk at discounted rates
2. **Traffic-based resellers**: Create unlimited VPN configs within their allocated traffic quota

## Architecture

### Database Structure

- **resellers**: Core reseller profile table
- **reseller_allowed_plans**: Junction table for plan-based reseller permissions
- **reseller_orders**: Bulk purchase orders from plan-based resellers
- **reseller_configs**: VPN configs created by traffic-based resellers
- **reseller_config_events**: Audit log for config lifecycle events

### Module Structure

```
Modules/Reseller/
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   ├── PlanPurchaseController.php
│   │   ├── ConfigController.php
│   │   └── SyncController.php
│   └── Middleware/
│       └── EnsureUserIsReseller.php (app/Http/Middleware/)
├── Jobs/
│   ├── ProvisionResellerOrderJob.php
│   └── SyncResellerUsageJob.php
├── Services/
│   ├── ResellerPricingService.php
│   └── ResellerProvisioner.php
├── Providers/
│   ├── ResellerServiceProvider.php
│   └── RouteServiceProvider.php
└── resources/
    └── views/
        ├── dashboard.blade.php
        ├── plans/index.blade.php
        ├── orders/show.blade.php
        └── configs/
```

## Admin Features

### 1. Convert User to Reseller

Navigate to **Admin Panel > Users** and click "Convert to Reseller" action on any user.

**Options:**
- **Type**: Plan-based or Traffic-based
- **Username Prefix**: Custom prefix for generated usernames (default: "resell")
- **For Traffic-based:**
  - Traffic quota in GB
  - Window duration in days
  - Allowed Marzneshin services (optional)

### 2. Manage Resellers

Navigate to **Admin Panel > Resellers**

**Features:**
- View all resellers
- Edit reseller settings
- Suspend/activate resellers
- Configure allowed plans (for plan-based resellers)
- Set per-reseller pricing overrides

### 3. Configure Plan Visibility

Navigate to **Admin Panel > Plans** and edit any plan.

**Reseller Settings Section:**
- **Reseller Visible**: Toggle plan visibility to resellers
- **Reseller Price**: Fixed reseller price (optional)
- **Reseller Discount Percent**: Percentage discount from retail (optional)

### 4. Pricing Priority

When a reseller views a plan, pricing is calculated in this order:

1. Reseller-specific override (percent discount)
2. Reseller-specific override (fixed price)
3. Plan-level discount percent
4. Plan-level fixed reseller price
5. Plan hidden if none of the above are set

## Reseller Panel Features

### Plan-based Resellers

**Dashboard** (`/reseller`)
- View balance
- See total orders and accounts provisioned
- Recent order history

**Plans** (`/reseller/plans`)
- Browse available plans with reseller pricing
- Select quantity (max configurable via settings)
- Choose delivery mode:
  - **On-screen**: View accounts in browser
  - **Download**: Get CSV/JSON export

**Orders** (`/reseller/orders/{id}`)
- View order details
- Access provisioned accounts
- Download artifacts (usernames and subscription links)

### Traffic-based Resellers

**Dashboard** (`/reseller`)
- Traffic statistics (total, used, remaining)
- Window validity period
- Active configs count

**Configs** (`/reseller/configs`)
- List all created configs
- View usage per config
- Enable/disable configs
- Delete configs

**Create Config** (`/reseller/configs/create`)
- Select panel
- Set traffic limit
- Set expiry date (within reseller window)
- Select allowed services (Marzneshin only, if whitelisted)

**Manual Sync** 
- Button to trigger immediate usage sync

## Username Convention

All provisioned usernames follow these patterns:

- **Plan-based**: `{prefix}_{resellerId}_order_{orderId}_{index}`
  - Example: `resell_1_order_42_1`
  
- **Traffic-based**: `{prefix}_{resellerId}_cfg_{configId}`
  - Example: `resell_1_cfg_123`

## Settings

Default settings can be configured via the `settings` table:

| Key | Default | Description |
|-----|---------|-------------|
| `reseller.username_prefix` | `resell` | Default prefix for usernames |
| `reseller.bulk_max_quantity` | `50` | Max accounts per bulk order |
| `reseller.configs_max_active` | `50` | Max active configs for traffic resellers |
| `reseller.usage_sync_interval_minutes` | `5` | Minutes between usage sync jobs |

## Automated Enforcement

### Usage Sync Job

Runs every `reseller.usage_sync_interval_minutes`:

1. Fetches usage from panels for all active configs
2. Updates `usage_bytes` for each config
3. Calculates total reseller usage
4. Auto-disables configs that:
   - Exceed their individual traffic limit
   - Are past their expiry date
   - Belong to resellers whose quota/window is exhausted

### Reseller Suspension

When a reseller is suspended:
- All active configs are disabled
- Access to `/reseller` panel is blocked
- No new purchases or configs can be created

## Ticketing Integration

Tickets created from the reseller panel are automatically tagged with `source='reseller'` and can be filtered in the admin panel for isolated queue management.

## Security Considerations

- Reseller middleware ensures only authenticated resellers can access `/reseller`
- Suspended resellers are blocked at middleware level
- Policies enforce resellers only access their own orders/configs
- Rate limiting should be applied to config creation endpoints
- Balance checks prevent over-purchasing

## Testing

Run tests with:

```bash
php artisan test --filter=Reseller
```

Tests cover:
- Pricing resolution logic
- Dashboard access control
- Middleware enforcement
- Suspended reseller blocking

## Troubleshooting

**Q: Reseller can't see any plans**
- Ensure `reseller_visible` is enabled on plans
- Check that plan has pricing configured (either fixed price or discount percent)
- Verify reseller status is 'active'

**Q: Bulk order stuck in 'provisioning'**
- Check queue is running: `php artisan queue:work`
- Review job logs for errors
- Verify panel credentials are correct

**Q: Traffic sync not updating**
- Ensure sync job is scheduled
- Check panel API connectivity
- Review sync job logs

**Q: Config creation fails**
- Verify traffic quota is not exceeded
- Check expiry is within reseller window
- For Marzneshin, ensure selected services are whitelisted

## Future Enhancements

- Reseller API for external integrations
- Automated reporting and analytics
- Multi-currency support
- Webhook notifications for provisioning events
- Reseller-specific branding options
