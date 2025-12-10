# WooCommerce Review Reply API

Simple WordPress/WooCommerce plugin that exposes REST endpoints to create, list, and delete replies to product reviews.

## Requirements
- WordPress 5.0+ and WooCommerce 5.0+ (tested up to WC 8.0).
- PHP 7.4+.
- Ability to authenticate to the WordPress REST API (e.g., Application Passwords, Basic Auth, JWT, or logged-in browser with a valid REST nonce).

## Installation (local or production)
1) Copy `wc-review-reply-api.php` into `wp-content/plugins/wc-review-reply-api/` (create the folder if it doesn’t exist).  
2) In wp-admin, go to **Plugins → Installed Plugins** and activate **WooCommerce Review Reply API**.  
3) Ensure permalinks and the WordPress REST API are enabled (default WP settings are usually enough).  
4) If activation fails, resolve any admin notices about WooCommerce version, PHP version, or REST namespace conflicts, then reactivate.

## Base URL
- All routes live under `/wp-json/wc-review-api/v1`.
- Example full URL for a site at `https://example.com`: `https://example.com/wp-json/wc-review-api/v1/test`.

## Permissions
- `POST /reviews/{id}/reply` and `DELETE /replies/{id}` require a user who can `moderate_comments` or `manage_woocommerce` (e.g., Shop Manager or Administrator).
- `GET /reviews/{id}/replies` and `GET /test` are public (no auth required).

## Endpoints

### 1) Health check
- `GET /test`
- Response: `{ success: true, message: "WooCommerce Review Reply API is working!", version: "1.0.1" }`
- Auth: none

### 2) Create a reply to a product review
- `POST /reviews/{id}/reply`
- Path params:
  - `id` (integer, required): Review (comment) ID.
- Body params (JSON or form):
  - `content` (string, required): Reply text.
  - `author_name` (string, optional): Defaults to current user display name.
  - `author_email` (string, optional): Defaults to current user email.
- Auth: required (see Permissions).
- Success response includes `reply_id` and the created reply object.

### 3) List replies for a review
- `GET /reviews/{id}/replies`
- Path params:
  - `id` (integer, required): Review (comment) ID.
- Auth: none.
- Returns `count` and an array of replies.

### 4) Delete a reply
- `DELETE /replies/{id}`
- Path params:
  - `id` (integer, required): Reply (comment) ID.
- Auth: required (see Permissions).
- Permanently deletes the reply.

## Example requests (cURL)
Replace `https://example.com` with your site, and use an Application Password or other auth mechanism when required.

Create a reply (auth required):
```bash
curl -X POST "https://example.com/wp-json/wc-review-api/v1/reviews/123/reply" \
  -u "admin:APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "content": "Thanks for your feedback!",
    "author_name": "Store Team",
    "author_email": "support@example.com"
  }'
```

List replies (public):
```bash
curl "https://example.com/wp-json/wc-review-api/v1/reviews/123/replies"
```

Delete a reply (auth required):
```bash
curl -X DELETE "https://example.com/wp-json/wc-review-api/v1/replies/456" \
  -u "admin:APP_PASSWORD"
```

Health check (public):
```bash
curl "https://example.com/wp-json/wc-review-api/v1/test"
```

## How to test end-to-end
1) Install and activate WooCommerce and this plugin.  
2) Create a product and add a product review (from a customer account). Note its comment ID.  
3) Use the **health check** endpoint to confirm the plugin is reachable.  
4) Use **POST /reviews/{id}/reply** with an authorized user to add a reply.  
5) Verify replies with **GET /reviews/{id}/replies** (should show your new reply).  
6) Optionally, delete a reply with **DELETE /replies/{id}** and confirm removal via the list endpoint.  
7) In wp-admin, refresh the product page and confirm replies appear under the review thread.

## Troubleshooting
- **401/403**: Ensure the user can `moderate_comments` or `manage_woocommerce`, and that your auth method is accepted by WordPress (Application Passwords are the simplest for cURL/Postman).  
- **404** on review: Confirm the review (comment) ID exists and belongs to a WooCommerce product.  
- **Namespace conflict**: Another plugin might already use `wc-review-api/v1`. Disable the conflicting plugin or change its routes.  
- **REST disabled**: Ensure no plugin or custom code disables the REST API (`rest_authentication_errors` filters).  

## Notes
- Replies are stored as regular WordPress comments with `comment_parent` set to the review ID and `comment_type` of `comment`.  
- The plugin declares compatibility with WooCommerce HPOS/custom order tables.  
- Version: 1.0.1.

