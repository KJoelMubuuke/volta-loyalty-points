# Volta Loyalty Points

WordPress plugin that awards loyalty points to WooCommerce customers and exposes a live balance via the REST API, rendered with a React widget on the My Account dashboard.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce 8.0+

## Features

- Awards **1 point per UGX 1,000** spent when an order reaches `completed` status
- Prevents double-awarding: order meta flag `_vlp_points_awarded` ensures idempotency
- Stores balance in user meta (`vlp_loyalty_points`)
- **REST API endpoint** (`GET /wp-json/volta-loyalty/v1/points`) returns the authenticated user's balance
- **React widget** on the My Account dashboard fetches balance live and supports manual refresh
- Widget localises all user-facing strings (translation-ready)
- Graceful degradation: static loading message renders before React hydrates

## Architecture

```
volta-loyalty-points/
├── volta-loyalty-points.php   # Plugin bootstrap, points logic, widget mount + enqueue
├── includes/
│   └── rest-api.php           # REST route registration and callback
└── assets/
    ├── js/
    │   └── account-widget.js  # React widget (uses wp.element — no build step)
    └── css/
        └── account-widget.css # Widget styles, matches Volta Coffee brand tokens
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
2. Renders balance, earn-rate hint, and a Refresh button
3. Displays an accessible error message if the request fails

### Points Logic

```
points_earned = floor( order_total / 1000 )
```

Triggered by `woocommerce_order_status_completed`. Guest orders (no `user_id`) are skipped.

## Installation

1. Copy `volta-loyalty-points/` into `wp-content/plugins/`.
2. Activate in **Plugins → Installed Plugins**.
3. WooCommerce must be active — the plugin shows an admin notice and exits gracefully if it isn't.

## Checking a User's Balance (wp-admin)

**Users → Edit User → scroll to bottom** — `vlp_loyalty_points` meta is visible and editable in the user profile.

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
