# Windcave Shopware Plugin

Windcave hosted payment page (PxPay) integration for Shopware 6 using the asynchronous payment handler flow.

## Features
- Registers `Windcave Hosted Payment` (REST HPP redirect) and `Windcave Drop-in` (REST drop-in, inline) methods
- HPP flow: create REST session (`/api/v1/sessions`) → redirect to link where `rel` is `hpp` → return with `sessionId` → verify session state via REST lookup
- Drop-in flow: create REST session (`/api/v1/sessions`) → render drop-in JS with returned `links` → redirect to Shopware return URL with session id → verify session state server-side
- Configurable REST credentials, Apple Pay merchantId, Google Pay merchantId, and test mode per sales channel
- Sends basic 3DS data (email, billing/shipping address, challenge preference) on session creation

## Installation
1. Place the plugin folder in your Shopware installation (e.g. `custom/plugins/WindcaveSHOPWARE`).
2. Install & activate:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate WindcaveSHOPWARE
   ```
3. Clear the cache and compile the container:
   ```bash
   bin/console cache:clear
   bin/console cache:warmup
   ```

## Configuration
In **Settings → System → Plugins → Windcave Shopware Plugin** set per sales channel:
- `REST API Username`
- `REST API Key`
- `Apple Pay merchantId (Windcave)` (optional)
- `Google Pay merchantId (Windcave/Google)` (optional)
- `Test mode` (switches between UAT and live endpoints)

Assign one or both payment methods to the sales channel.

## Flow Overview
1. During checkout the `WindcavePaymentHandler` posts a PxPay request to Windcave and redirects the shopper to the returned `Uri`.
2. Windcave redirects to the plugin success/fail routes which append the `result` token to Shopware’s transaction return URL.
3. Shopware invokes `finalize()` where the plugin calls Windcave’s result endpoint to confirm the payment and throws an exception if the PSP reports failure.

### Drop-in flow
1. With `Windcave Drop-in` selected, the handler creates a REST session (`/api/v1/sessions`) server-side using Basic Auth and stores the returned `links`, session `id`, and fallback HPP link.
2. Shopper is redirected to `/windcave/dropin/{orderTransactionId}`, which loads Windcave’s drop-in JS from `sec.windcave.com` (or `uat` when test mode is on) and mounts the drop-in with the returned `links`.
3. On completion, the drop-in redirects to Shopware’s return URL with `sessionId=<id>`. Finalize fetches the session via REST and approves only when the session state is approved/completed. If drop-in JS fails to load, we fallback to the HPP link via iframe/redirect.

## Notes & Next Steps
- Session endpoints: live `https://sec.windcave.com/api/v1/sessions`, test `https://uat.windcave.com/api/v1/sessions`.
- Drop-in JS is loaded directly from Windcave; ensure CSP allows the required domains per Windcave docs (connect/script/img/font/frame).
- Adjust payload fields in `WindcaveSessionRequestPayload` if you need optional REST fields (e.g., expires, 3DS data, additional callback data).
- To debug, enable test mode and monitor Shopware logs for Windcave entries.

## References
- Shopware payment plugin guide: https://developer.shopware.com/docs/guides/plugins/plugins/checkout/payment/add-payment-plugin.html
- Windcave PxPay: https://www.windcave.com/developer-e-commerce-api-rest#HPP_Walkthrough
- Square payment plugin example: https://github.com/solution25com/square-payments-shopware-6-solution25
