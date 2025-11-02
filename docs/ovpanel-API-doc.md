# OV-Panel API Reference

Backend: FastAPI  
Frontend: React (axios baseURL `/api`)  
Auth: JWT Bearer  
Interactive API docs (enabled via config): `GET /doc`  
Static assets (frontend build): `/assets`

Important notes:
- The frontend client uses `/api` as its base URL (reverse proxy typically maps `/api` to the backend).
- If you deploy the app under a subpath (config `URLPATH`), your external paths may be prefixed accordingly. The examples below assume `/api` as the base path.

## Authentication

### POST /api/login
Authenticate and obtain an access token.

- Content-Type: `application/x-www-form-urlencoded`
- Body:
  - `username`: string
  - `password`: string
- Response:
```json
{
  "access_token": "<JWT>",
  "token_type": "bearer"
}
```

All subsequent secured requests must include:
```
Authorization: Bearer <JWT>
```

Example (curl):
```bash
curl -X POST https://your-host/api/login \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "username=admin&password=secret"
```

## Response envelope

Most endpoints return a consistent envelope:
```ts
type ResponseModel<T = any> = {
  success: boolean;
  msg: string;
  data?: T;
}
```

## Models

These are the primary input/output shapes referenced by the API.

```ts
// Settings (response)
type Settings = {
  tunnel_address?: string | null;
  port: number;
  protocol?: string | null;
};

// SettingsUpdate (request)
type SettingsUpdate = {
  tunnel_address?: string | null;
  port?: number;
  protocol?: string; // e.g., "tcp" | "udp"
};

// Server information (response)
type ServerInfo = {
  cpu: number;             // %
  memory_total: number;    // bytes
  memory_used: number;     // bytes
  memory_percent: number;  // %
  disk_total: number;      // bytes
  disk_used: number;       // bytes
  disk_percent: number;    // %
  uptime: number;          // seconds
};

// Users (response)
type Users = {
  name: string;
  is_active: boolean;
  expiry_date: string; // ISO date
  owner: string;
};

// CreateUser (request)
type CreateUser = {
  name: string;        // 3-10 chars
  expiry_date: string; // ISO date
};

// UpdateUser (request)
type UpdateUser = {
  name: string;
  expiry_date?: string | null;
};

// Admins (response)
type Admins = {
  username: string;
};

// NodeCreate (request)
type NodeCreate = {
  name: string;               // max 10
  address: string;            // node host/ip
  tunnel_address?: string | null;
  protocol?: string;          // default "tcp"
  ovpn_port?: number;         // default 1194
  port: number;               // node API port
  key: string;                // 10-40 chars
  status?: boolean;           // default true
  set_new_setting?: boolean;  // default false
};
```

## Settings

All endpoints require `Authorization: Bearer <JWT>`.

### GET /api/settings/
Retrieve current panel settings.

- Response:
```json
{
  "success": true,
  "msg": "Settings retrieved successfully",
  "data": {
    "tunnel_address": "10.8.0.0",
    "port": 1194,
    "protocol": "tcp"
  }
}
```

Example:
```bash
curl -H "Authorization: Bearer $TOKEN" https://your-host/api/settings/
```

### PUT /api/settings/update
Update panel settings and apply the new configuration.

- Body (any subset of fields):
```json
{
  "tunnel_address": "10.8.0.0",
  "port": 1194,
  "protocol": "tcp"
}
```
- Success response:
```json
{ "success": true, "msg": "Settings updated successfully" }
```
- Failure to apply configuration:
```json
{ "success": false, "msg": "Failed to apply new configuration" }
```

Example:
```bash
curl -X PUT https://your-host/api/settings/update \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"tunnel_address":"10.8.0.0","port":1194,"protocol":"tcp"}'
```

### GET /api/settings/server/info
Get server resource stats where the panel runs.

- Response:
```json
{
  "success": true,
  "msg": "Server information retrieved successfully",
  "data": {
    "cpu": 7.5,
    "memory_total": 16727891968,
    "memory_used": 6239029248,
    "memory_percent": 37.3,
    "disk_total": 536870912000,
    "disk_used": 214748364800,
    "disk_percent": 40.0,
    "uptime": 123456
  }
}
```

## Users

All endpoints require `Authorization: Bearer <JWT>`.

### GET /api/user/all
List all users.

- Response:
```json
{
  "success": true,
  "msg": "Users retrieved successfully",
  "data": [
    {
      "name": "alice",
      "is_active": true,
      "expiry_date": "2025-12-31",
      "owner": "owner"
    }
  ]
}
```

### GET /api/user/download/ovpn/{name}
Download an OpenVPN client profile for a user.

- Responses:
  - On success: returns `.ovpn` file (media type `application/x-openvpn-profile`)
  - On failure:
    ```json
    { "success": false, "msg": "OVPN file not found" }
    ```

Example:
```bash
curl -L -H "Authorization: Bearer $TOKEN" \
  -o alice.ovpn https://your-host/api/user/download/ovpn/alice
```

### POST /api/user/create
Create a new user across server and nodes.

- Body:
```json
{
  "name": "alice",
  "expiry_date": "2025-12-31"
}
```
- Response:
```json
{ "success": true, "msg": "User created successfully", "data": "alice" }
```
- Error if name exists:
```json
{ "success": false, "msg": "User with this name already exists" }
```
- Error if server operation fails:
```json
{ "success": false, "msg": "Server error while creating user" }
```

### PUT /api/user/update
Update an existing user.

- Body:
```json
{
  "name": "alice",
  "expiry_date": "2026-01-31"
}
```
- Response (example):
```json
{ "success": true, "msg": "User updated successfully", "data": { /* implementation-defined */ } }
```

### DELETE /api/user/delete/{name}
Delete a user from server and nodes.

- Responses:
  - Not found on server:
    ```json
    { "success": false, "msg": "User not found on server" }
    ```
  - Success:
    ```json
    { "success": true, "msg": "User deleted successfully", "data": true }
    ```

## Nodes

All endpoints require `Authorization: Bearer <JWT>`.

### POST /api/node/add
Add a node.

- Body:
```json
{
  "name": "node1",
  "address": "10.0.0.2",
  "tunnel_address": "10.8.0.0",
  "protocol": "tcp",
  "ovpn_port": 1194,
  "port": 8080,
  "key": "your-node-api-key",
  "status": true,
  "set_new_setting": false
}
```
- Response (success or failure message):
```json
{ "success": true, "msg": "Node added successfully" }
```

### PUT /api/node/update/{address}
Update an existing node (identified by its `address`).

- Body: same shape as `NodeCreate`
- Response:
```json
{ "success": true, "msg": "Node updated successfully" }
```

### GET /api/node/status/{address}
Get health/status info for a node.

- Response:
```json
{
  "success": true,
  "msg": "Node status retrieved successfully",
  "data": { /* implementation-defined status object */ }
}
```

### GET /api/node/list
List all nodes.

- Response:
```json
{
  "success": true,
  "msg": "Nodes retrieved successfully",
  "data": [
    /* nodes list */
  ]
}
```

### GET /api/node/download/ovpn/{address}/{name}
Download a user's OVPN profile from a specific node.

- Responses:
  - On success: returns `.ovpn` file (media type `application/x-openvpn-profile`)
  - On failure:
    ```json
    { "success": false, "msg": "OVPN file not found" }
    ```

### DELETE /api/node/delete/{address}
Delete a node.

- Response:
```json
{ "success": true, "msg": "Node deleted successfully" }
```

## Admins

All endpoints require `Authorization: Bearer <JWT>`.

### GET /api/admin/all
List admins.

- Response:
```json
{
  "success": true,
  "msg": "Admins retrieved successfully",
  "data": [
    { "username": "admin1" }
  ]
}
```

## Interactive API docs

- If enabled in configuration, the OpenAPI UI is available at:
  - `GET /doc`

## Source references

- Settings router: backend/routers/setting.py
- Users router: backend/routers/users.py
- Nodes router: backend/routers/node.py
- Admins router: backend/routers/admins.py
- Auth (login): backend/auth/auth.py
