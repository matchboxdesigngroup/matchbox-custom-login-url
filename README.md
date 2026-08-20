# Custom Login URL

MU plugin that serves the WordPress login screen from a custom slug and makes `wp-login.php` return a 404.

## 📌 How it works

The login screen is **rendered in place** at the custom slug — the request is never redirected to `wp-login.php`. Because of that:

- The browser address bar stays on the custom slug for the whole login flow.
- No access cookie or handshake is involved.
- There is no interstitial redirect for a page cache or CDN to cache.

Direct requests to `wp-login.php` render the theme's own 404 template, so a scanner cannot tell the file is there.

Every URL core builds from `wp-login.php` is rewritten to the custom slug by filtering `site_url()` and `network_site_url()`. That covers login, logout, registration, lost password, the login form's own `action` attribute, interim (session-expired) logins, and the reset links inside password-reset and new-user emails.

## ⚙️ Installation

1. Copy `custom-login-url.php` to your site's `wp-content/mu-plugins/` directory.
2. (Optional) Define a custom slug in `wp-config.php`:
   ```php
   define( 'LOGIN_URL', 'your-custom-login-slug' );
   ```
   The default is `web-ad`. The value is run through `sanitize_title()`.
3. Log in at `https://yourdomain.com/your-custom-login-slug/`.

### Filter

```php
add_filter( 'matchbox_custom_login_slug', fn() => 'some-other-slug' );
```

## 🔐 Behavior notes

- **Slug collisions.** New top-level posts and pages cannot claim the login slug — `wp_unique_post_slug` appends `-2` instead. If content already used the slug before the plugin was installed, an admin notice warns that the content is now unreachable.
- **Fail-safe.** If `LOGIN_URL` sanitizes to an empty string, the plugin stands down completely and leaves `wp-login.php` alone rather than locking everyone out. An admin notice reports it.
- **Locked out?** Rename or delete `wp-content/mu-plugins/custom-login-url.php` over SFTP or WP-CLI. `wp-login.php` works normally again immediately.
- **Subdirectory installs** are handled — the request path is compared relative to `home_url()`.
- **Core's convenience redirects are disabled.** WordPress hooks `wp_redirect_admin_locations()` to `template_redirect`, which turns any 404 at `/wp-login.php`, `/login`, `/admin` or `/dashboard` into a redirect to `wp_login_url()` — which would hand the custom slug to anyone who guessed one of those paths. The plugin removes that action, so all four now 404. The side effect is that `/admin` and `/dashboard` no longer shortcut to `/wp-admin/`.

## 🚨 Known limitations

- This plugin obscures the login URL. It is not an authentication firewall and does not rate-limit login attempts.
- It does **not** cover other authentication surfaces: XML-RPC (`system.multicall` credential stuffing), the REST API, or `admin-ajax.php`. Disable or restrict those separately if they are not needed.
- Serving a themed 404 for `wp-login.php` means WordPress boots and resolves a query for every scanner that probes it. On a site under heavy bot traffic, block `/wp-login.php` at the web server or CDN as well.
- Multisite `wp-signup.php` and `wp-activate.php` are not covered.
- Ensure no other login-redirect or "hide login" plugin is active.

## 👨‍💻 Developer notes

- Built for WordPress 5.0+ and PHP 7.4+.
- Everything is namespaced inside the `Matchbox_Custom_Login_URL` class; no globals are introduced.
- Hooks used:
  - `plugins_loaded` (priority 1) — classifies the request and corrects `$pagenow`
  - `wp_loaded` (priority 1) — loads `wp-login.php` in place, or renders the 404
  - `site_url`, `network_site_url`, `wp_redirect` — rewrite `wp-login.php` URLs to the slug
  - `remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 )` — stops core leaking the slug
  - `wp_unique_post_slug`, `save_post`, `admin_notices` — slug collision handling

⸻

🛠 Made with care by Matchbox Design Group
