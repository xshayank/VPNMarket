# StarsEfar API — Endpoints & Usage Guide

> A concise, developer‑friendly reference to integrate with StarsEfar’s Telegram Stars purchase services.

---

## Base URL

```
https://starsefar.xyz
```

All endpoints below are relative to this base URL.

---

## Authentication

StarsEfar uses **Bearer token** authentication via an API key you obtain from their Telegram bot.

**Header**
```http
Authorization: Bearer YOUR_API_KEY
```

> Keep your API key private. Do not commit it to public repos.

---

## Endpoints

### 1) Check Order Status
**GET** `/api/check-order/{orderId}`

Retrieve the current status and details of an order.

**Path Parameters**
- `orderId` *(string, required)* — Order identifier (e.g., `ord_123456789`).

**Request Example**
```bash
curl -X GET \
  "https://starsefar.xyz/api/check-order/ord_123456789" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Successful Response (200)**
```json
{
  "success": true,
  "data": {
    "orderId": "ord_123456789",
    "fullName": "علی احمدی",
    "phone": "09123456789",
    "targetAccount": "@username",
    "stars": 100,
    "amount": 200000,
    "status": "paid",
    "paid": true,
    "date": "2025-01-15T10:30:00Z"
  }
}
```

---

### 2) Create Gift Link (API)
**POST** `/api/create-gift-link`

> **Note:** In some places this endpoint is shown as `/api/create-gift-link-api`. Treat `/api/create-gift-link` as the primary path; if integration requires, support the `-api` suffix variant as well.

Creates a **gift link** your customer can pay through. **When the payment completes, the Stars are added to the *license owner’s* balance (you), not to the destination account.** This design supports reselling Stars.

**Body Parameters (JSON)**
- `targetAccount` *(string, required)* — Destination account’s handle or phone (for invoice display).
- `stars` *(integer, optional)* — Number of Stars (valid range: 5–2500). If provided, it takes precedence.
- `amount` *(integer, optional)* — Smart field:
  - `< 2500` → treated as **Stars count** (same as `stars`).
  - `10,000 – 5,000,000` → treated as **amount in Toman**; Stars derived from amount.
  - Outside these ranges → **validation error**.
- `callbackUrl` *(string, optional)* — HTTPS URL to receive a server‑to‑server notification after successful payment.

**Request Examples**
- **Explicit Stars**
```bash
curl -X POST "https://starsefar.xyz/api/create-gift-link" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "targetAccount": "@username",
    "stars": 100,
    "callbackUrl": "https://example.com/payment-callback"
  }'
```

- **Small `amount` (< 2500) → interpreted as Stars**
```bash
curl -X POST "https://starsefar.xyz/api/create-gift-link" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "targetAccount": "@username",
    "amount": 100,
    "callbackUrl": "https://example.com/payment-callback"
  }'
```

- **Toman `amount` (10,000 – 5,000,000) → Stars derived**
```bash
curl -X POST "https://starsefar.xyz/api/create-gift-link" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "targetAccount": "@username",
    "amount": 200000,
    "callbackUrl": "https://example.com/payment-callback"
  }'
```

**Successful Response (201)**
```json
{
  "success": true,
  "link": "https://starsefar.xyz/?order_id=gift_123456789",
  "orderId": "gift_123456789",
  "licenseOwnerId": "123456789"
}
```

#### Pricing Logic Summary
- If `stars` is present → use it directly.
- If `amount < 2500` → treat `amount` as **Stars**.
- If `10,000 ≤ amount ≤ 5,000,000` → treat `amount` as **Toman** and compute Stars from it.
- Otherwise → **reject** request with validation error.

---

## Payment Callback (Webhook)
When `callbackUrl` is provided, the API POSTs a JSON payload **only after a successful payment**.

**Callback Payload**
```json
{
  "success": true,
  "orderId": "abc123",
  "targetAccount": "@username",
  "stars": 100,
  "amount": 200000,
  "status": "completed",
  "completedAt": "2025-01-15T10:30:00Z",
  "message": "پرداخت با موفقیت انجام شد"
}
```

**Integration Notes**
- Your endpoint **must** return HTTP **200 OK**.
- Request timeout is **10 seconds**.
- Only sent on **success**; if your server is unreachable, the platform logs an error (no guarantee of retries is stated).

---

## Error Handling
The API returns structured error responses with HTTP status codes.

| Code | Name                  | Meaning                                             |
|------|-----------------------|-----------------------------------------------------|
| 400  | Bad Request           | Invalid input parameters                            |
| 401  | Unauthorized          | Missing/invalid/expired API key                     |
| 403  | Forbidden             | Not allowed to access the requested resource        |
| 404  | Not Found             | Order or resource not found                         |
| 429  | Too Many Requests     | Rate limit exceeded                                 |
| 500  | Internal Server Error | Server‑side error                                   |

**Error Response Example**
```json
{
  "success": false,
  "error": "پارامترهای ورودی نامعتبر",
  "code": 400,
  "details": {
    "field": "phone",
    "message": "شماره تماس باید 11 رقم باشد"
  }
}
```

---

## Rate Limits
Default rate limits per API key:
- **100 requests / minute**
- **1000 requests / hour**
- **10,000 requests / day**

Contact support for higher limits if needed.

---

## Sandbox (Interactive Test Environment)
An interactive web sandbox lets you test all endpoints without writing code.

1. Visit `https://starsefar.xyz/sandbox`.
2. Set your API key in **Settings**.
3. Choose an endpoint, fill parameters, and send.
4. Inspect the JSON response.

> The sandbox hits the **real** backend. Protect your real API key.

---

## Support
- **Email:** [email protected]
- **Telegram:** `@starsefar_support`
- **Availability:** 24/7

---

## Quick Integration Checklist
- [ ] Securely store your API key and load it from env vars.
- [ ] Build a thin client that always sends the `Authorization: Bearer` header.
- [ ] For gift links, decide whether you pass explicit `stars` or a Toman `amount`.
- [ ] Implement an HTTPS `callbackUrl` endpoint; reply **200 OK** within 10s.
- [ ] Handle non‑200 responses with structured error parsing.
- [ ] Respect rate limits; add retries/backoff where appropriate.
- [ ] For QA, use the sandbox to validate flows end‑to‑end.

