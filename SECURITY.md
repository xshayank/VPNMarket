# Security Policy

## Supported Versions

We actively support security updates for the following versions of VPNMarket:

| Version | Supported          |
| ------- | ------------------ |
| Latest  | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please report it responsibly:

### How to Report

1. **Do NOT** open a public GitHub issue for security vulnerabilities
2. Send details to: **security@your-domain.com** (Configure this with your actual security contact)
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if any)

### What to Expect

- **Acknowledgment**: Within 48 hours
- **Initial Assessment**: Within 5 business days
- **Status Updates**: Every 7 days until resolved
- **Resolution Timeline**: We aim to patch critical vulnerabilities within 30 days

## Security Best Practices

### For Deployment

#### 1. Environment Configuration

**Required Security Settings in `.env`:**

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generate-with-artisan-key-generate>

# Session Security
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict

# HTTPS Enforcement
FORCE_HTTPS=true

# Database
DB_CONNECTION=mysql  # Avoid SQLite in production
```

#### 2. HTTPS Configuration

- **Always** use HTTPS in production
- Configure SSL/TLS certificates (Let's Encrypt recommended)
- Set `FORCE_HTTPS=true` in `.env`
- The SecurityHeaders middleware will add HSTS headers automatically when HTTPS is detected

#### 3. Web Server Hardening

**Nginx Example:**

```nginx
# Hide server version
server_tokens off;

# Rate limiting
limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
limit_req zone=login burst=10 nodelay;

# Security headers (Laravel middleware also adds these)
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```

#### 4. File Permissions

```bash
# Set proper ownership
chown -R www-data:www-data /path/to/vpnmarket

# Secure permissions
chmod -R 755 /path/to/vpnmarket
chmod -R 775 /path/to/vpnmarket/storage
chmod -R 775 /path/to/vpnmarket/bootstrap/cache

# Protect .env
chmod 600 /path/to/vpnmarket/.env
```

### Dependency Management

#### Regular Updates

```bash
# Update dependencies monthly
composer update --no-dev

# Check for security vulnerabilities
composer audit

# Review and address any reported vulnerabilities
```

#### Composer Audit

Run `composer audit` regularly to check for known vulnerabilities in dependencies:

```bash
# Check for security issues
composer audit

# Update specific package if vulnerable
composer update vendor/package --with-dependencies
```

### Data Protection & Encryption

#### Sensitive Credentials

- **Panel API Tokens & Passwords**: Automatically encrypted using Laravel's encryption (see `App\Models\Panel`)
- **Encryption Key**: Keep `APP_KEY` secure and never commit to version control
- **Key Rotation**: If you rotate `APP_KEY`, existing encrypted data will become unreadable

#### Database Encryption

Panel credentials (`api_token`, `password`) are automatically encrypted:

```php
// Encryption happens automatically via accessors
$panel->api_token = 'secret_token';  // Stored encrypted
$panel->save();

echo $panel->api_token;  // Returns decrypted value
```

### Input Validation & Sanitization

#### Server-Side Validation

All user inputs are validated server-side with Laravel validation rules:

- **Username/Email**: Format and length validation
- **Numeric Fields**: Type checking and range validation
- **Node IDs**: Integer normalization and whitelist checking
- **max_clients**: Integer between 1-100

#### XSS Prevention

- Use Blade's `{{ }}` syntax for output escaping (automatic)
- Avoid `{!! !!}` unless explicitly needed and data is sanitized
- All dynamic content in forms and tables is escaped

### Authorization & Access Control

#### Role-Based Access Control (RBAC)

- Powered by Spatie Laravel Permission
- Policies enforce resource-level authorization
- Middleware restricts route access by role

#### Policy Checks

```php
// Verify ownership before operations
$this->authorize('update', $config);

// Prevent privilege escalation
if ($config->reseller_id !== $reseller->id) {
    abort(403);
}
```

### Rate Limiting

Rate limiting is applied to prevent abuse:

- **Config Creation**: Limited to prevent resource exhaustion
- **User Creation**: Throttled to mitigate panel abuse
- **Login Attempts**: Laravel's built-in throttling (5 attempts per minute)

### Logging & Monitoring

#### Security Event Logging

Use `SecurityLog` helper for logging sensitive operations:

```php
use App\Helpers\SecurityLog;

// Automatically redacts passwords, tokens, keys
SecurityLog::info('User login attempt', [
    'username' => $username,
    'password' => $password,  // Will be [REDACTED]
]);
```

#### Audit Trail

- All sensitive operations logged via `AuditLog` model
- Includes: config creation, deletion, modifications
- Reseller traffic management actions

### CSRF Protection

- All state-changing forms include `@csrf` directive
- Laravel validates CSRF tokens automatically
- AJAX requests must include CSRF token in headers

### Secrets Management

#### Environment Variables

- **Never** commit `.env` file
- **Never** hardcode secrets in code
- Use `.env.example` as template
- Store production secrets securely (e.g., AWS Secrets Manager, Vault)

#### Configuration Files

```php
// ✓ Good - uses environment variable
'api_key' => env('THIRD_PARTY_API_KEY'),

// ✗ Bad - hardcoded secret
'api_key' => 'sk_live_abc123...',
```

### Session Security

#### Session Configuration

Set in `config/session.php`:

```php
'lifetime' => 120,  // 2 hours
'expire_on_close' => true,
'encrypt' => true,
'secure' => env('SESSION_SECURE_COOKIE', true),
'http_only' => true,
'same_site' => 'strict',
```

### Security Headers

The `SecurityHeaders` middleware automatically adds:

- **Content-Security-Policy**: Restricts resource loading
- **X-Frame-Options**: Prevents clickjacking
- **X-Content-Type-Options**: Prevents MIME sniffing
- **Referrer-Policy**: Controls referrer information
- **Permissions-Policy**: Restricts browser features
- **Strict-Transport-Security**: HTTPS enforcement (production only)

### Mass Assignment Protection

All models use `$fillable` arrays to whitelist mass-assignable fields:

```php
protected $fillable = [
    'name',
    'email',
    // Only safe fields listed
];
```

### API Request Hardening

- All external API requests have timeouts (30 seconds default)
- Responses validated before trusting ID fields
- Retries with exponential backoff where appropriate
- Exception handling with sanitized error logging

## Security Checklist for Administrators

- [ ] `APP_DEBUG=false` in production
- [ ] HTTPS properly configured with valid certificate
- [ ] `SESSION_SECURE_COOKIE=true` and `SESSION_HTTP_ONLY=true`
- [ ] Database backups configured and tested
- [ ] `.env` file permissions set to 600
- [ ] `composer audit` runs regularly (weekly recommended)
- [ ] Security headers verified in browser DevTools
- [ ] Rate limiting tested on critical endpoints
- [ ] Logs monitored for suspicious activity
- [ ] Panel credentials rotated periodically
- [ ] User permissions reviewed quarterly

## Vulnerability Disclosure Timeline

1. **Day 0**: Vulnerability reported
2. **Day 1-2**: Acknowledgment sent
3. **Day 3-7**: Initial triage and assessment
4. **Day 8-30**: Develop and test fix
5. **Day 31**: Release security patch
6. **Day 32**: Public disclosure (coordinated with reporter)

## Additional Resources

- [Laravel Security Best Practices](https://laravel.com/docs/security)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)

## Contact

For security concerns, contact: **security@your-domain.com** (Configure this with your actual security contact)

---

**Last Updated**: November 2025
