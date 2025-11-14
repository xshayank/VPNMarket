# Tetra98 Payment API – Create Order & Verify

## Overview

This document describes how to use the Tetra98 payment API to:

- Create a payment order.
- Redirect the user to payment pages.
- Verify the payment result.
- Handle the callback payload.

Your **API Key** is shown in your Tetra98 panel under **اطلاعات API**.  
In this document it will be referred to as:

```json
"ApiKey": "YOUR_API_KEY"
```

Replace `YOUR_API_KEY` with the key provided in your dashboard.

---

## 1. Create Order

### Endpoint

- **URL:** `https://tetra98.ir/api/create_order`
- **Method:** `POST`
- **Content-Type:** `application/json`
- **Purpose:** Create a new payment order and obtain payment URLs.

> Note: In some examples it may be shown as `create_orderRequest`, but the cURL sample uses `/api/create_order`.

### Request Body (JSON)

```json
{
  "ApiKey": "YOUR_API_KEY",
  "Hash_id": "invoice-123",
  "Amount": 1000000,
  "Description": "test order",
  "Email": "user@example.com",
  "Mobile": "09120000000",
  "CallbackURL": "https://example.com/cb"
}
```

#### Field Descriptions

- **ApiKey** (string)  
  Your API key from the panel.

- **Hash_id** (string)  
  A unique identifier for the order or invoice (e.g. order ID from your system).

- **Amount** (integer)  
  The payment amount in Rials (example: `1000000`).

- **Description** (string)  
  Text description of the order (e.g. `"test order"` or `"سفارش تست"`).

- **Email** (string)  
  Customer email address.

- **Mobile** (string)  
  Customer mobile number (e.g. Iranian mobile format).

- **CallbackURL** (string, URL)  
  Your endpoint that Tetra98 will call after the payment result is known.

---

### Successful Response (JSON)

On success, the API returns:

```json
{
  "status": "100",
  "Authority": "{authority}",
  "payment_url_bot": "https://t.me/Tetra98_bot?start=pay_{Authority}",
  "payment_url_web": "https://tetra98.ir/payment/{Authority}",
  "tracking_id": "{trackingid}"
}
```

#### Response Fields

- **status** (string)  
  `"100"` indicates a successful order creation.

- **Authority** (string)  
  Unique payment authority code. Use it to generate payment links or for verification.

- **payment_url_bot** (string, URL)  
  Payment link via the official Telegram bot.  
  Example: `https://t.me/Tetra98_bot?start=pay_{Authority}`

- **payment_url_web** (string, URL)  
  Payment page on Tetra98’s website.  
  Example: `https://tetra98.ir/payment/{Authority}`

- **tracking_id** (string)  
  Tracking ID for the transaction.

---

## 2. Verify Payment

### Endpoint

- **URL:** `https://tetra98.ir/api/verify`
- **Method:** `POST`
- **Content-Type:** `application/json`
- **Purpose:** Verify the payment using the `authority` code.

### Request Body (JSON)

```json
{
  "authority": "{authority}",
  "ApiKey": "YOUR_API_KEY"
}
```

#### Field Descriptions

- **authority** (string)  
  The `Authority` value returned from the **Create Order** response.

- **ApiKey** (string)  
  Your API key from the panel.

---

## 3. Callback Payload

After the payment process, Tetra98 will send a JSON payload to your specified `CallbackURL`.

### Example Callback Payload

```json
{
  "status": 100,
  "hashid": "{hashid}",
  "authority": "{authority}"
}
```

#### Field Descriptions

- **status** (integer)  
  `100` indicates a successful payment.

- **hashid** (string)  
  The original `Hash_id` passed when creating the order (your internal order ID).

- **authority** (string)  
  The payment authority code related to this transaction.

Use `hashid` to match the callback to your internal order, and `authority` for verification/logging.

---

## 4. Sample cURL Request (Create Order)

Below is a sample `curl` command for creating an order:

```bash
curl -X POST "https://tetra98.ir/api/create_order"   -H "Content-Type: application/json"   -d '{
    "ApiKey": "YOUR_API_KEY",
    "Hash_id": "test-1763123604",
    "Amount": 1000000,
    "Description": "سفارش تست",
    "Email": "customer@example.com",
    "Mobile": "09120000000",
    "CallbackURL": "https://example.com/callback"
  }'
```

### Steps to Use

1. Replace `"YOUR_API_KEY"` with the API key from your Tetra98 panel.
2. Set `Hash_id` to your internal order ID.
3. Adjust `Amount`, `Description`, `Email`, `Mobile`, and `CallbackURL` as needed.
4. Run the command and use the returned `payment_url_web` or `payment_url_bot` to redirect the user or show them a payment link.

---

## 5. Basic Integration Flow

1. **Create Order**  
   - Call `/api/create_order` with order details.  
   - Receive `Authority`, `payment_url_web`, `payment_url_bot`.

2. **Redirect User to Payment**  
   - Send user to `payment_url_web` (browser) or use `payment_url_bot` via Telegram.

3. **Handle Callback**  
   - Implement an endpoint at `CallbackURL` to receive `status`, `hashid`, `authority`.

4. **Verify Payment (Optional but Recommended)**  
   - Call `/api/verify` with `authority` and `ApiKey` to confirm final status.

5. **Update Order Status in Your System**  
   - Use `hashid` to map to your internal order and mark it as paid or failed based on the result.
```