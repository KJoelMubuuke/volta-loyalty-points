# Volta Loyalty Points

WordPress plugin that awards loyalty points to WooCommerce customers, lets them redeem points for a checkout discount, and exposes a live balance via the REST API and a React widget on the My Account dashboard.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce 8.0+

## Features

- Awards **1 point per UGX 1,000** spent when an order reaches `completed` status
- Prevents double-awarding: order meta flag `_vlp_points_awarded` ensures idempotency
- **Redeemable**: customers can apply points as a discount on the cart/checkout — **1 point = UGX 50**, capped at **50% of the cart subtotal** so points can never zero out an order
- **Refund/cancellation aware**: if an order is later refunded or cancelled, any points it awarded are clawed back and any points it consumed are restored to the customer — both idempotent, so a status flapping back and forth can't double-adjust a balance
- Balance changes go through a single atomic SQL update (`vlp_adjust_points()`), not a read-then-write — two orders completing at the same moment can't clobber each other's point award
- Every balance change is recorded in a durable ledger table (`{prefix}vlp_points_log`) and visible on **Users → Edit User**
- **REST API endpoint** (`GET /wp-json/volta-loyalty/v1/points`) returns the authenticated user's balance
- **React widget** on the My Account dashboard fetches balance live and supports manual refresh
- Widget localises all user-facing strings (translation-ready)
- Graceful degradation: static loading message renders before React hydrates
- Clean uninstall: removes all user meta, order meta, the ledger table, and plugin options

## Architecture

```
volta-loyalty-points/
├── volta-loyalty-points.php   # Bootstrap, award logic, refund/cancel clawback, widget mount + enqueue
├── includes/
│   ├── points-ledger.php      # Ledger table schema + vlp_adjust_points() atomic balance helper
│   ├── redemption.php         # Cart/checkout redemption: apply form, discount fee, order-time deduction
│   ├── admin-profile.php      # Balance + activity ledger on the Edit User screen
│   └── rest-api.php           # REST route registration and callback
├── uninstall.php              # Removes all plugin data on deletion
└── assets/
    ├── js/
    │   └── account-widget.js  # React widget (uses wp.element — no build step)
    └── css/
        ├── account-widget.css # My Account widget styles
        └── redeem-widget.css  # Cart/checkout redemption widget styles
```

### REST API

| Method | Endpoint | Auth | Response |
|--------|----------|------|----------|
| `GET` | `/wp-json/volta-loyalty/v1/points` | Cookie + `X-WP-Nonce` | `{ "points": 120, "points_per_ugx_1000": 1 }` |

The nonce is injected via `wp_localize_script` so the widget never exposes it in markup.

### React Widget

Built with `wp.element` (WordPress's bundled React) — no build toolchain required. Loaded only on `is_account_page()` pages for logged-in users, keeping the footprint minimal.

The widget:
1. On mount, fetches `/wp-json/volta-loyalty/v1/points` with the WP REST nonce
2. Renders balance, earn-rate hint, redemption-value hint, and a Refresh button
3. Displays an accessible error message if the request fails

### Earning Points

```
points_earned = floor( order_total / 1000 )
```

Triggered by `woocommerce_order_status_completed`. Guest orders (no `user_id`) are skipped. `order_total` already reflects any points redeemed on that order, so earning and redemption can't compound into an infinite loop.

### Redeeming Points

A widget on the cart and checkout pages (`includes/redemption.php`) lets a logged-in customer with a positive balance request a number of points to redeem:

1. **Apply** — a same-page form (processed on `template_redirect`, not `admin-post.php`, so `WC()->session`/`WC()->cart` are guaranteed to be initialised) stores the requested point count in the WooCommerce session.
2. **Discount** — `woocommerce_cart_calculate_fees` reads the session value, re-clamps it against the customer's *live* balance and 50%-of-subtotal cap, and adds a negative fee (`Volta Rewards (N points)`). Re-clamping on every recalculation means a stale session value, or a balance spent elsewhere in another tab, can never over-discount an order.
3. **Deduction** — on `woocommerce_checkout_order_processed`, the same clamp is re-applied one final time and the points are permanently deducted via `vlp_adjust_points()`. The amount actually deducted is stored on the order (`_vlp_points_redeemed_amount`) so a later refund/cancel knows exactly how much to restore.

### Refunds & Cancellations

`vlp_handle_order_refund_or_cancel()` runs on `woocommerce_order_status_refunded` and `woocommerce_order_status_cancelled`:

- Claws back `_vlp_points_awarded_amount` if the order had awarded points (flagged via `_vlp_points_clawed_back` so it only happens once)
- Restores `_vlp_points_redeemed_amount` if the order had consumed points (flagged via `_vlp_points_restored`)

**Known simplification:** any refund — full or partial — triggers a full reversal. Proportional clawback for partial refunds is out of scope.

### Points Ledger

Every call to `vlp_adjust_points( $user_id, $delta, $reason, $order_id )` does two things atomically:
1. `UPDATE wp_usermeta SET meta_value = GREATEST(0, meta_value + $delta) ...` — a single SQL statement, not a PHP read-modify-write, so concurrent adjustments can't race. The balance is clamped at zero.
2. Inserts a row into `{prefix}vlp_points_log` (`user_id`, `order_id`, `delta`, `balance_after`, `reason`, `created_at`).

The last 20 entries for a user are shown on **Users → Edit User**, each with the date, signed change, resulting balance, a human-readable reason, and a link to the related order.

## Installation

1. Copy `volta-loyalty-points/` into `wp-content/plugins/`.
2. Activate in **Plugins → Installed Plugins** — this also creates the points ledger table.
3. WooCommerce must be active — the plugin shows an admin notice and exits gracefully if it isn't.

## Checking a User's Balance (wp-admin)

**Users → Edit User → "Volta Rewards"** section — shows the current balance and a recent-activity ledger (date, change, balance after, reason, linked order).

## Testing the REST Endpoint

```bash
# 1. Log in to WordPress and grab a nonce
# 2. Replace <NONCE> and <SITE_URL> below
curl -s \
  -H "X-WP-Nonce: <NONCE>" \
  --cookie "wordpress_logged_in_<hash>=<cookie_value>" \
  "<SITE_URL>/wp-json/volta-loyalty/v1/points"

# Expected response:
# {"points":0,"points_per_ugx_1000":1}
```

Or visit **My Account** when logged in — the widget fetches it automatically.
