# Custom Login URL 
MU plugin that changes the default login URL (`/wp-login.php`) to a custom slug and protects the login page from unauthorized direct access.

## 📌 Features

- ✅ Redirects custom slug to `wp-login.php`
- ✅ Sets a short-lived HMAC-secured cookie to authorize access
- ✅ Protects `wp-login.php` with a 403 Forbidden error if accessed directly
- ✅ Preserves query parameters like `redirect_to`, `reauth`, `action`
- ✅ Supports:
  - Login
  - Logout
  - Registration
  - Lost Password
- ✅ MU-compatible: load automatically without user activation

## ⚙️ Installation

1. Copy `custom-login-url.php` to your site's `wp-content/mu-plugins/` directory.
2. (Optional) Define a custom slug in `wp-config.php`:
   ```php
   define( 'LOGIN_URL', 'your-custom-login-slug' );
   ```
3. Access the login page via: https://yourdomain.com/your-custom-login-slug

## 🔐 Security Notes
* Prevents direct access to wp-login.php unless a valid custom-login-url HMAC cookie is present.
* Cookie is valid for 5 minutes.
* Exempts action=postpass and action=logout from protection to preserve functionality.

## 🚨 Known Limitations
* This plugin only masks the login URL and adds basic protection.
* It is not a replacement for full authentication firewalls or security plugins.
* Ensure that no conflicting login redirect plugins are active.

## 👨‍💻 Developer Notes
* Built for WordPress 5.0+ and PHP 8.0+.
* Uses:
   * template_redirect to handle access via custom slug
   * login_url, logout_url, register_url, lostpassword_url filters to rewrite links
   * login_init to block unauthorized access
  
⸻

🛠 Made with care by Matchbox Design Group
