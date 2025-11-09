# Wallet Top-Up Transaction Approval Feature

## Overview
This feature provides a dedicated admin interface for managing wallet top-up transaction approvals. Super-admins and admins can now review, approve, or reject wallet deposit requests through a centralized interface.

## Features

### 1. Dedicated Approval Interface
- **Location**: Admin Panel → مدیریت مالی (Financial Management) → تاییدیه شارژ کیف پول (Wallet Top-Up Approvals)
- **Navigation Badge**: Shows count of pending approvals (yellow when > 0, green when 0)
- **Access**: Super-admin and admin roles only

### 2. Transaction Management
- **View All Deposits**: See all wallet top-up transactions (deposits)
- **Filter by Status**: 
  - در انتظار تایید (Pending)
  - تایید شده (Completed)
  - رد شده (Failed/Rejected)
- **Transaction Details**: User, amount, description, creation date

### 3. Approval Actions
#### Approve Transaction
- Updates transaction status to 'completed'
- Credits the appropriate wallet (user balance or reseller wallet)
- Sends Telegram notification to user with new balance
- Auto-reactivates suspended wallet-based resellers if balance exceeds threshold

#### Reject Transaction
- Updates transaction status to 'failed'
- Does NOT credit any wallet
- Shows notification of rejection

## User Flow

### For Customers
1. Customer creates a wallet top-up order
2. Customer uploads payment receipt/proof
3. Waits for admin approval

### For Admins
#### Step 1: Order Approval
1. Navigate to سفارشات (Orders)
2. Find the pending wallet top-up order
3. Click "تایید و اجرا" (Approve and Execute)
4. System creates a pending transaction

#### Step 2: Transaction Approval
1. Navigate to تاییدیه شارژ کیف پول (Wallet Top-Up Approvals)
2. See the pending transaction with badge notification
3. Review transaction details
4. Choose action:
   - **Approve**: Credits wallet and sends notification
   - **Reject**: Marks as failed without crediting

## Technical Details

### New Components
1. **WalletTopUpTransactionResource**: Filament resource for transaction management
2. **WalletTopUpTransactionPolicy**: Access control policy
3. **WalletTopUpPermissionsSeeder**: Permissions seeder
4. **Resource Pages**:
   - ListWalletTopUpTransactions
   - ViewWalletTopUpTransaction

### Modified Components
1. **OrderResource**: Now creates pending transactions instead of completing them immediately
2. **AppServiceProvider**: Registered new policy

### Permissions
The following permissions are created and assigned to super-admin and admin roles:
- `view_any_wallet::top::up::transaction`
- `view_wallet::top::up::transaction`
- `create_wallet::top::up::transaction`
- `update_wallet::top::up::transaction`
- `delete_wallet::top::up::transaction`
- And other standard CRUD permissions

### Database Changes
No new migrations required. Uses existing `transactions` table with:
- `type = 'deposit'` for wallet top-ups
- `status` can be: 'pending', 'completed', 'failed'

## Installation

### 1. Run Permission Seeder
```bash
php artisan db:seed --class=WalletTopUpPermissionsSeeder
```

### 2. Clear Caches
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

### 3. Verify
1. Log in as super-admin
2. Check that "تاییدیه شارژ کیف پول" appears in the navigation
3. Create a test transaction and verify approval flow

## Workflow Comparison

### Before This Feature
1. User creates order → Order pending
2. Admin approves order → Wallet credited immediately + Transaction created as completed
3. Single-step approval with immediate effect

### After This Feature
1. User creates order → Order pending
2. Admin approves order → Order marked as paid + Transaction created as pending
3. Admin approves transaction → Wallet credited + Notification sent
4. Two-step approval for better control

## Benefits
- **Better Control**: Separate order validation from wallet crediting
- **Audit Trail**: Clear visibility of all wallet top-ups
- **Centralized Management**: Dedicated interface for approvals
- **Role-Based Access**: Only admins can approve
- **Notifications**: Telegram alerts when wallet is credited
- **Auto-Reactivation**: Suspended resellers automatically reactivated on approval

## Security
- Transactions are filtered to show only type='deposit'
- Access controlled via Spatie permissions
- Only super-admin and admin can view/manage
- All actions are within database transactions for consistency
- Policy gates prevent unauthorized access

## Testing
Comprehensive tests are included in `tests/Feature/WalletTopUpTransactionTest.php`:
- Permission verification for all roles
- Approval flow for users and resellers
- Rejection flow (no balance change)
- Auto-reactivation of suspended resellers
- Order to transaction creation flow

## Support
For issues or questions, please refer to:
- Code: `app/Filament/Resources/WalletTopUpTransactionResource.php`
- Tests: `tests/Feature/WalletTopUpTransactionTest.php`
- Permissions: `database/seeders/WalletTopUpPermissionsSeeder.php`
