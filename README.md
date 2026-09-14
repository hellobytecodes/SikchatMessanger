# 💬 SikChat (سیک‌چت)

**SikChat** is a full-featured, self-hosted, Telegram/Rubika-style real-time messenger built with **pure PHP + SQLite** on the backend and a **single-file HTML/CSS/JavaScript** frontend (no framework, no build step). Drop it on any PHP hosting, open `index.html`, and you have your own private messaging platform — with DMs, groups, channels, stories, short videos, an admin control panel, premium subscriptions, and more.

> ⚠️ This project uses an SQLite file database and long-polling for "real-time" updates, which makes it extremely easy to deploy on cheap shared hosting (no Node.js, no WebSocket server, no MySQL required).

---

## 📸 Screenshots

> _Add your own screenshots here. Suggested slots:_

| Chat List | Conversation | Admin Panel |
|---|---|---|
| _screenshot_ | _screenshot_ | _screenshot_ |

| Stories | Shorts (Reels) | Profile |
|---|---|---|
| _screenshot_ | _screenshot_ | _screenshot_ |

---

## ✨ Features

### 🔑 Accounts & Authentication
- Register/login with **phone number + password** (Iranian phone format normalization built-in, e.g. `09xxxxxxxxx`).
- Optional **@username** (must match `^[a-z][a-z0-9_]{4,31}$`).
- Session tokens stored in an SQLite `sessions` table (works with both cookies and an `X-Auth-Token` header — good for SPA/mobile wrapper usage).
- Automatic **login notification**: every new login sends an automated message from the "Account Notifications" system bot with IP, device type, and time.
- Change password, delete account, and full **privacy controls** (hide last-seen, hide phone number).

### 👤 Profiles & Identity
- Avatar photo, bio, display color, theme (light/dark).
- **Blue verification tick** — can be granted/revoked per user by the main admin.
- **Gold tick** — reserved exclusively for the one hard-coded "main admin" phone number (site owner).
- Custom **animated premium display name** for verified/premium users.
- Search users by name/username, or look up a specific user by numeric ID.

### 💬 Private Messaging (DMs)
- Text, emoji, animated emoji, photo, video, voice messages, and generic file attachments (all sent as Base64 data-URIs, stored directly in SQLite — no external file storage needed).
- Message **reply**, **edit**, **soft delete**, **forwarding** (with "forwarded from" attribution and jump-to-original support).
- **Emoji reactions** on messages (16 built-in reaction emojis).
- **Read receipts** (double check-mark).
- **Pin/unpin messages** (multiple pins per chat) and **pin whole chats** to the top of your chat list.
- **Block/unblock users**, with a full audit log of block/unblock actions.
- **Report** messages, DMs, groups, channels, stories, or shorts to the admin team.
- Chat **folders** — organize your chat list into up to 12 custom folders.
- **Reply to a story** directly as a DM.

### 👥 Groups & Channels
- Create **Groups** (everyone can post) or **Channels** (only owner/admins can post, like a broadcast channel).
- Roles: **owner / admin / member**, with promote, demote, and full **ownership transfer**.
- Add/remove members, member list with role badges.
- Group/channel **avatar photo, color, username** (public `@handle`), pinned messages, and message view counters ("seen by" for channel posts, like Telegram).
- Official **verified checkmark** for groups/channels, settable by the admin.
- Built-in **official channel** and **official group** are auto-created on first run (`@sikchat_official`, `@sikchat_group`) — fully customizable via `config.php`.

### 📖 Stories (24h, like Instagram/Telegram Stories)
- Photo or video stories (max 60 seconds for video), auto-expire after 24 hours for everyone, including admins.
- View counter, likes, viewer list, and reply-to-story (delivered as a DM to the story owner).
- Posting stories is restricted to **Premium users** and **story-admins** (main admin + blue-tick admins), with a configurable max number of simultaneously active admin stories.

### 🎬 Shorts (vertical short-video feed)
- A TikTok/Reels-style vertical feed that currently supports embedding **YouTube Shorts/videos** (auto-parses `youtu.be`, `/shorts/`, `/watch?v=`, `/embed/` links into a clean, chromeless embed player).
- Likes, threaded comments (with comment-likes), view counts.
- Users can **submit** their own shorts for admin approval; admins can publish shorts directly.
- **Native ads can be interleaved into the shorts feed** as a special "ad" record type.

### 👑 Admin Control Panel (built into the same web app)
Accessible from the account dropdown menu once logged in as the main admin/verified admin account:
- **Dashboard** — user/message/group counters and stats.
- **User management** — search, suspend/unsuspend ("سیک" = ban terminology used throughout the UI), ban/unban by phone, grant/revoke blue tick, force-change any user's password, inspect any user's profile/activity.
- **Group/Channel management** — ban/unban, verify, or fully delete any group or channel.
- **Reports queue** — review reported messages/chats/stories/shorts, update report status, delete reports, and jump straight into the reported conversation.
- **Ads management** — create, list, enable/disable, and delete in-app ads shown in the app and in the shorts feed.
- **Premium management** — grant/cancel premium subscriptions for any user, view a premium-users report.
- **Support tickets** — users can open support tickets; admins can view and reply to them from the panel.
- **Shorts management** — publish/delete shorts, approve user-submitted shorts, toggle visibility.

### 💎 Premium Subscription System
- Time-limited premium (30-day cycles) grantable by the admin, or automatically active for the main admin and all blue-tick verified accounts.
- Premium perks: **5× larger upload limit** (500 MB vs. 100 MB for free users), animated premium display name, ability to post stories.
- Blue-tick/main-admin accounts can self-toggle their automatic premium status on/off.

### 🛠️ Technical Highlights
- **Zero external dependencies** on the backend — plain PHP + PDO/SQLite.
- **WAL mode SQLite** tuning (`journal_mode=WAL`, `synchronous=NORMAL`, `busy_timeout`, larger cache, memory temp store, mmap) so the app can handle many concurrent polling clients without "database is locked" errors.
- Automatic, idempotent **schema migrations** on every request (`migrate()` in `config.php`) — safe to redeploy the code over an existing database at any time; new columns/tables are added automatically without wiping data.
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `HSTS` on HTTPS, hidden `X-Powered-By`).
- Per-file-type upload size limits with Base64-overhead-aware validation.
- Single JSON-based REST API (`api.php`) with an `action` parameter dispatching to 90+ endpoints.
- Fully **RTL, Persian-language UI** out of the box (easy to translate — see [Customization](#-customization--personalization)).

---

## 🧱 Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ (uses `declare(strict_types=1)`, PDO, typed functions) |
| Database | SQLite 3 (single file, WAL mode) |
| Frontend | Vanilla HTML5 + CSS3 + JavaScript (no framework, no bundler) |
| API | Single-endpoint JSON REST API (`api.php?action=...`) |
| Realtime updates | Client-side long-polling against `api.php` |

---

## 📂 Project Structure

```
sikchat/
├── index.html     # The entire frontend: markup + CSS + JS (single-page app)
├── api.php        # The entire backend REST API (90+ actions, one big switch/case dispatcher)
├── config.php     # DB connection, schema, migrations, constants, and all shared helper functions
├── .user.ini       # PHP runtime overrides (upload size / memory / execution time limits)
└── sikchat.sqlite  # Auto-created SQLite database file (created on first request — not in the repo)
```

There is no `composer.json`, no `node_modules`, no build pipeline. You literally just upload the files.

---

## 🚀 Installation & Deployment

### Requirements
- PHP **8.0+** with the **PDO SQLite** extension enabled (`pdo_sqlite`).
- Any web server that can run PHP: Apache, Nginx + PHP-FPM, LiteSpeed, or a typical shared cPanel host.
- Write permission on the project folder (SQLite needs to create `sikchat.sqlite`, `sikchat.sqlite-wal`, and `sikchat.sqlite-shm`).
- HTTPS is strongly recommended (required for camera/mic access used by voice messages and stories, and for the HSTS header to take effect).

### Steps
1. **Upload** `index.html`, `api.php`, `config.php`, and `.user.ini` to your web server (e.g. `public_html/`).
2. Make sure the folder is **writable** by PHP:
   ```bash
   chmod 755 .
   ```
3. Open your domain in the browser (e.g. `https://yourdomain.com/index.html`). The database and all tables are created automatically on the very first API request — no manual SQL import needed.
4. **Register the first account using the admin phone number** you configured (see [Changing the Admin Account](#-changing-the-admin-account--how-to-become-the-owner) below) — this account automatically becomes the site owner ("main admin") with the gold tick and full admin panel access.
5. Done. Share the link with your users.

### Local development
Since it's plain PHP, you can run it locally with PHP's built-in server:
```bash
php -S localhost:8000
```
Then open `http://localhost:8000/index.html`.

---

## ⚙️ Customization & Personalization

All of the core branding and behavior is controlled by **constants at the top of `config.php`** — you don't need to touch the database or the frontend logic to rebrand the app.

### 1. App name
```php
define('APP_NAME', 'سیک چت');
```
Change `'سیک چت'` to your own app name. Also update the `<title>` tag near the top of `index.html`:
```html
<title>سیک چت</title>
```

### 2. Official Channel & Group
On first run, SikChat auto-creates an official channel and an official group owned by the admin account. Rename or rebrand them by editing:
```php
define('OFFICIAL_CHANNEL_NAME', '📢 کانال رسمی سیک چت');
define('OFFICIAL_CHANNEL_USERNAME', 'sikchat_official');
define('OFFICIAL_GROUP_NAME', '💬 گروه رسمی سیک چت');
define('OFFICIAL_GROUP_USERNAME', 'sikchat_group');
```
> ⚠️ These are only used to auto-create the channel/group **the first time** the database is initialized. If you change them *after* the database already exists, you must either delete `sikchat.sqlite` (⚠️ wipes all data) or manually update the existing rows in the `groups_` table via a SQLite editor.

### 3. Notification Bot ("Account Notifications")
The automated system account that sends login alerts:
```php
define('NOTIF_BOT_PHONE', '00000000001');
define('NOTIF_BOT_USERNAME', 'account_notify');
define('NOTIF_BOT_NAME', 'اعلانات اکانت');
```
Change the name/username to match your brand (e.g. `"YourApp Security"`).

### 4. Upload / File Size Limits
```php
define('UPLOAD_MAX_BYTES_FREE', 100 * 1024 * 1024);     // Free users: 100 MB
define('UPLOAD_MAX_BYTES_PREMIUM', 500 * 1024 * 1024);  // Premium users: 500 MB
```
Also mirror your changes in `.user.ini` so PHP itself accepts the larger request bodies:
```ini
upload_max_filesize = 600M
post_max_size = 700M
memory_limit = 768M
max_execution_time = 300
max_input_time = 300
```

### 5. Stories settings
```php
define('STORY_MAX_DURATION_SECONDS', 60);   // Max video story length
define('STORY_LIFETIME_SECONDS', 86400);    // Story lifetime (24h)
define('STORY_ADMIN_MAX_ACTIVE', 2);        // Max concurrent admin stories
```

### 6. Reaction emojis
```php
define('REACTION_EMOJIS', ['😂', '🤨', '😉', '❤️‍🔥', '🤍', '👍', '😔', '❤️‍🩹', '☺️', '😇', '💫', '🙏🏼', '🥶', '😭', '😈', '😕']);
```
Add, remove, or reorder emojis freely — the frontend reads this list dynamically wherever it's exposed via the API.

### 7. Colors, theme & RTL/translation
- The default brand color (`#0088ff`) and accent colors appear both in `config.php` (for default avatars/official badges) and as CSS variables at the top of `index.html`'s `<style>` block — search for `--main`, `--bg`, etc. and adjust the palette there.
- The entire UI text lives inline in `index.html`'s JavaScript (Persian strings). To translate the app to another language, search-and-replace the Persian strings in the JS templates and `api.php`'s `error`/`respond()` messages. There's no i18n abstraction layer — this is a straightforward find-and-replace job since everything lives in a small number of files.
- The UI is RTL (`dir="rtl"`) by default; switch to `ltr` in the `<html>` tag and adjust CSS `direction`/`text-align` rules if you translate to a LTR language.

---

## 🔐 Changing the Admin Account / How to Become the Owner

SikChat has **one hard-coded "main admin" phone number** defined in `config.php`:

```php
define('VERIFY_ADMIN_PHONE', '09362670821');
```

Whoever registers (or already exists) with **exactly this phone number** automatically becomes the site's main administrator:
- Gets the **gold verification tick** (`is_main_admin`) — this is reserved *only* for this one phone number, it cannot be granted to anyone else.
- Automatically has **permanent premium** status.
- Automatically owns the auto-created official channel/group.
- Sees the **"Admin Panel"** entry in the account dropdown menu and gets access to every admin action in `api.php` (user bans, verification, ads, tickets, premium grants, group/channel moderation, etc.).

### To make yourself the admin/owner of your own deployment:

1. Open `config.php` and change the line:
   ```php
   define('VERIFY_ADMIN_PHONE', '09362670821');
   ```
   to your own phone number, for example:
   ```php
   define('VERIFY_ADMIN_PHONE', '09121234567');
   ```
2. Deploy/upload the updated `config.php`.
3. Go to the app and **register a new account using that exact phone number** (or log into an existing account that already has it) — normalized the same way the app normalizes numbers (`09XXXXXXXXX` format).
4. That account instantly becomes the main admin — no extra database editing required. The migration logic in `config.php` will also automatically (re)create/assign the official channel and group to this account if they don't already exist.

> 🔒 **Security tip:** Since admin rights are tied 1:1 to a single phone number defined in source code, make sure `config.php` is never publicly downloadable (it shouldn't be, since PHP files are executed, not served as text — but double check your server config doesn't accidentally expose `.php` source, e.g. via a misconfigured static file handler).

### Granting/revoking the **blue tick** (secondary admin / verified badge) to other users
Once logged in as the main admin, open any user's profile and use the **"Grant blue tick" / "Revoke blue tick"** button (also callable via the `verify_set` API action). Blue-tick users get:
- Automatic premium status.
- Ability to post Stories as a "story admin."
- Ability to self-toggle their own automatic premium on/off.
- A custom animated premium display name (editable by themselves).

They do **not** get the gold tick or admin-panel access — that remains exclusive to the `VERIFY_ADMIN_PHONE` account.

---

## 🌐 REST API Overview

All API calls go to `api.php` with an `action` field (JSON body or query string) and, for authenticated actions, either a session cookie or an `X-Auth-Token` header. The API returns JSON: `{"ok": true, ...}` or `{"ok": false, "error": "..."}`.

Some of the 90+ available actions, grouped by area:

| Area | Example actions |
|---|---|
| Auth & Account | `register`, `login`, `logout`, `me`, `profile_update`, `password_change`, `account_delete`, `privacy_update` |
| Users & Search | `user_search`, `find_by_id`, `user_profile`, `user_block`, `user_unblock` |
| Direct Messages | `messages_get`, `messages_send`, `message_edit`, `message_delete`, `message_react`, `message_report` |
| Pins & Folders | `dm_message_pin`, `group_message_pin`, `chat_pin`, `folder_create`, `folder_update`, `folder_reorder` |
| Groups/Channels | `group_create`, `group_update`, `group_member_add`, `group_member_set_admin`, `group_owner_transfer`, `group_delete` |
| Stories | `story_can_post`, `story_create`, `story_feed`, `story_view`, `story_like_toggle`, `story_reply_send` |
| Shorts | `shorts_feed`, `short_get`, `short_submit`, `short_like_toggle`, `short_comment_add` |
| Admin | `admin_dashboard`, `admin_users`, `admin_ban_user`, `admin_suspend_user`, `admin_change_password`, `verify_set`, `admin_premium_set`, `admin_create_ad`, `admin_tickets_list`, `admin_ticket_reply`, `admin_short_add` |
| Support | `ticket_create` |

> A full endpoint-by-endpoint reference can be generated by reading the `case '...':` blocks in `api.php` — each one is self-contained and documented inline in Persian comments.

---

## 🗄️ Database Schema (auto-managed)

You never need to write SQL by hand — `init_schema()` and `migrate()` in `config.php` create and evolve the schema automatically. Key tables include:

- `users`, `sessions`
- `messages` (DMs), `group_messages`, `groups_`, `group_members`, `group_reads`
- `reactions`, `reports`, `block_logs`, `blocked_users`, `suspensions`, `banned_phones`
- `dm_message_pins`, `group_message_pins`, `chat_pins`, `message_views`
- `ads`, `official_channels`, `tickets`
- `shorts`, `short_likes`, `short_comments`, `short_comment_likes`, `short_views`
- `stories`, `story_views`, `story_likes`

The database is a **single SQLite file** (`sikchat.sqlite`), run in **WAL mode** — for backups, either copy all three files together (`sikchat.sqlite`, `-wal`, `-shm`) or call the built-in `db_checkpoint()` helper first to flush everything into the main file before copying just one file.

---

## 🛡️ Security Notes

- Passwords are hashed with PHP's `password_hash()` (bcrypt/argon, depending on PHP build) — never stored in plain text.
- Sessions use random 32-byte tokens (`random_bytes`), stored server-side, not JWTs.
- Cookies are set `HttpOnly`, `SameSite=Lax`.
- The one and only "main admin" role is bound to a single phone number in code, not a database flag that could be tampered with through a bug in a lower-trust code path.
- Always deploy behind HTTPS in production.

---

## 📄 License

License MIT Open Source

---

## 🙌 Credits

Built with plain PHP + SQLite + vanilla JS — no frameworks, no build tools, just a fast, self-hostable messenger you fully own and control.
