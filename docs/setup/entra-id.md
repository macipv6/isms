# Microsoft Entra ID Setup

The ISMS Builder foundation uses Microsoft Entra ID as the only login mechanism. V1 is deliberately single-tenant and additionally requires a local allow-list entry for every consultant who may sign in.

## App registration

Create an application registration in the Microsoft Entra admin center with these settings:

```text
Supported account type: Accounts in this organizational directory only (single tenant)
Platform: Web
Local redirect URI: http://localhost:8080/auth/microsoft/callback
Production redirect URI pattern: https://<approved-host>/auth/microsoft/callback
Delegated scopes: openid, profile, email, User.Read
```

No directory-wide Microsoft Graph permissions are required for the Foundation slice.

## Record application identifiers

From the app registration Overview page record:

- **Directory (tenant) ID** → `MICROSOFT_TENANT_ID`
- **Application (client) ID** → `MICROSOFT_CLIENT_ID`

Create a client secret under **Certificates & secrets** and store the secret value only in runtime secret storage. For local development it may be placed in the untracked `.env` file. Never commit the client secret.

Configure:

```dotenv
MICROSOFT_TENANT_ID=<tenant-uuid>
MICROSOFT_CLIENT_ID=<application-uuid>
MICROSOFT_CLIENT_SECRET=<runtime-secret>
MICROSOFT_REDIRECT_URI=http://localhost:8080/auth/microsoft/callback
```

For production, use the approved HTTPS hostname and set `SESSION_SECURE_COOKIE=true`.

## Determine the initial administrator Object ID

Use the Entra admin center to open **Users → All users → <administrator>** and copy the user's **Object ID**. If Azure CLI is available and signed in as the intended administrator, the same immutable ID can be obtained with:

```bash
az ad signed-in-user show --query id -o tsv
```

The Object ID is not the email address. The application identifies a consultant by the stable pair **Tenant ID + Object ID**.

## Add the initial allow-list user

After migrations are applied, run:

```bash
php artisan isms:bootstrap-user \
  <tenant-uuid> \
  <object-uuid> \
  admin@example.test \
  "ISMS Admin" \
  --organization="ISMS Consulting" \
  --role=admin
```

Replace all example values with the real consultant Entra values. The command stores no password.

## Defense in depth

Successful Microsoft authentication alone does not grant access. The application also checks:

1. the validated ID-token `tid` equals `MICROSOFT_TENANT_ID`;
2. the validated ID-token `oid` matches the Microsoft Graph `/me` object ID;
3. the OIDC nonce matches the nonce created before redirect;
4. the `(tenant ID, object ID)` pair exists in the local `users` allow-list;
5. the local user is active.

OAuth authorization codes, access tokens, refresh tokens and raw ID tokens are not persisted by the ISMS Builder.

## Manual smoke test

1. Open `http://localhost:8080/login`.
2. Click **Mit Microsoft anmelden**.
3. Complete the Microsoft sign-in and any MFA required by Conditional Access.
4. Confirm the browser returns to `/dashboard`.
5. Confirm `audit_events` contains `auth.login_succeeded`.
6. Use **Abmelden**.
7. Confirm `/dashboard` redirects to `/login`.
8. Confirm `audit_events` contains `auth.logout`.

Do not attach or commit screenshots that expose authorization codes, tokens, client secrets, real tenant IDs, or Object IDs.
