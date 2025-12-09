# Windcave Shopware Plugin

Windcave hosted payment page HPP REST + Drop In integration for Shopware 6 using the asynchronous payment handler flow.

## Features
- Registers `Windcave Hosted Payment` (REST HPP redirect) and `Windcave Drop-in` (REST drop-in, inline) methods
- HPP flow: create REST session (`/api/v1/sessions`) → redirect to link where `rel` is `hpp` → return with `sessionId` → verify session state via REST lookup
- Drop-in flow: create REST session (`/api/v1/sessions`) → render drop-in JS with returned `links` → redirect to Shopware return URL with session id → verify session state server-side
- Configurable REST credentials, Apple Pay merchantId, Google Pay merchantId, and test mode per sales channel
- Sends basic 3DS data (email, billing/shipping address, challenge preference) on session creation
- Optional tokenization: store card tokens (cardId) on customer for rebilling; reuse tokens via storedCardIndicator on subsequent sessions
- FPRN (Fail Proof Result Notification) webhook support for reliable payment status updates
- Automatic refund processing when order transactions are marked as refunded in Shopware admin
- Void support for cancelling authorized payments

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
- `Store card token for rebilling` (enable to save cardId and reuse for credential-on-file payments)
- `Stored card indicator (initial/recurring)` (adjust if needed)

Assign one or both payment methods to the sales channel.

## Flow Overview
1. During checkout the `WindcavePaymentHandler` posts a PxPay request to Windcave and redirects the shopper to the returned `Uri`.
2. Windcave redirects to the plugin success/fail routes which append the `result` token to Shopware’s transaction return URL.
3. Shopware invokes `finalize()` where the plugin calls Windcave’s result endpoint to confirm the payment and throws an exception if the PSP reports failure.

### Drop-in flow
1. With `Windcave Drop-in` selected, the handler creates a REST session (`/api/v1/sessions`) server-side using Basic Auth and stores the returned `links`, session `id`, and fallback HPP link.
2. Shopper is redirected to `/windcave/dropin/{orderTransactionId}`, which loads Windcave’s drop-in JS from `sec.windcave.com` (or `uat` when test mode is on) and mounts the drop-in with the returned `links`.
3. On completion, the drop-in redirects to Shopware’s return URL with `sessionId=<id>`. Finalize fetches the session via REST and approves only when the session state is approved/completed. If drop-in JS fails to load, we fallback to the HPP link via iframe/redirect.

## Refunds & Voids
The plugin automatically processes refunds and voids through Windcave when order transaction states change in Shopware:

- **Paid → Refunded**: Triggers a refund request to Windcave using the stored transaction ID
- **Authorized → Cancelled**: Triggers a void request to cancel the authorization

Refund results are logged and the Windcave refund transaction ID is stored on the order transaction.

## FPRN Webhook
The plugin includes a notification endpoint at `/windcave/notification` that receives Windcave's Fail Proof Result Notification (FPRN). This ensures payment results are received even if:
- The customer closes their browser before returning to your site
- Your server is temporarily unavailable during payment completion

The FPRN endpoint automatically updates order transaction status based on the payment result.

## Drop-in Customization
The plugin provides extensive styling options for the Windcave Drop-in payment form. Configure these in **Settings → System → Plugins → Windcave Shopware Plugin**:

### Appearance Settings
- **Theme Mode**: Choose between Light, Dark, or Auto (matches browser preference)
- **Hide "Select Payment Method" title**: Toggle the header text visibility

### Button Styling
- Submit button background, text, and hover colors
- Border radius for rounded corners

### Container Styling
- Background and border colors
- Border radius and padding
- Custom styling for the payment form container

### Input Field Styling
- Input background and border colors
- Focus, valid, and invalid state border colors
- Border radius

### Text Styling
- Primary and secondary text colors
- Custom font family

### Advanced: Custom CSS
For complete control, add custom CSS rules targeting Windcave's class names:
- `.windcave-dropin-container` - Main container
- `.windcave-dropin-card-submit-button` - Submit button
- `.windcave-dropin-card-back-button` - Back button
- `.windcave-hf-input-container` - Card input fields
- `.windcave-dropin-select-payment-text` - Header text
- And more (see Windcave Drop-in documentation)

Example custom CSS:
```css
.windcave-dropin-container {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.windcave-dropin-card-submit-button {
    font-weight: bold;
    text-transform: uppercase;
}
```

## Headless / Store API Support

The plugin fully supports headless frontends (PWA, mobile apps) through Shopware's Store API. All endpoints work with both storefront and headless integrations.

### Store API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/store-api/windcave/config` | GET | Get public configuration (test mode, merchant IDs, styling) |
| `/store-api/windcave/dropin-session/{orderTransactionId}` | GET/POST | Get drop-in session data for rendering payment form |
| `/store-api/windcave/verify-session` | POST | Verify a completed payment session |
| `/store-api/windcave/notification` | GET/POST | Handle FPRN notifications (alternative to storefront endpoint) |

### Headless Integration Flow

1. **Get configuration**: Fetch `/store-api/windcave/config` for styling and merchant IDs
2. **Create order**: Use standard Shopware Store API checkout flow
3. **Get session data**: After order creation, call `/store-api/windcave/dropin-session/{orderTransactionId}`
4. **Render drop-in**: Use the returned `links` and `sessionId` to initialize Windcave's Drop-in JS
5. **Complete payment**: On payment completion, call `/store-api/windcave/verify-session` with `sessionId`
6. **Finalize**: Call Shopware's payment finalize endpoint with `sessionId` in the request body

### Example: Fetching Drop-in Session

```javascript
// After order is created, get drop-in session data
const response = await fetch('/store-api/windcave/dropin-session/' + orderTransactionId, {
    headers: {
        'sw-access-key': salesChannelAccessKey,
        'sw-context-token': contextToken
    }
});
const sessionData = await response.json();

// Initialize Windcave Drop-in
WindcavePayments.DropIn.create({
    container: 'payment-container',
    links: sessionData.links,
    darkModeConfig: sessionData.darkModeConfig,
    styles: sessionData.styles,
    // ... other options
});
```

## Notes & Next Steps
- Session endpoints: live `https://sec.windcave.com/api/v1/sessions`, test `https://uat.windcave.com/api/v1/sessions`.
- Drop-in JS is loaded directly from Windcave; ensure CSP allows the required domains per Windcave docs (connect/script/img/font/frame).
- Adjust payload fields in `WindcaveSessionRequestPayload` if you need optional REST fields (e.g., expires, 3DS data, additional callback data).
- To debug, enable test mode and monitor Shopware logs for Windcave entries.

## References
- Shopware payment plugin guide: https://developer.shopware.com/docs/guides/plugins/plugins/checkout/payment/add-payment-plugin.html
- Windcave PxPay: https://www.windcave.com/developer-e-commerce-api-rest#HPP_Walkthrough
- Square payment plugin example: https://github.com/solution25com/square-payments-shopware-6-solution25
