<?php
/**
 * سیک چت - تنظیمات و اتصال دیتابیس
 * نسخه نهایی با کانال رسمی و تبلیغات
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

if (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

function apply_security_headers(): void {
    if (headers_sent()) return;
    try {
        @header('Content-Type: text/html; charset=utf-8');
        @header('X-Content-Type-Options: nosniff');
        @header('X-Frame-Options: SAMEORIGIN');
        @header('Referrer-Policy: strict-origin-when-cross-origin');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            @header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        @header_remove('X-Powered-By');
    } catch (Exception $e) {}
}

try { apply_security_headers(); } catch (Exception $e) {}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    try {
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    } catch (Exception $e) {}
}

define('DB_FILE', __DIR__ . '/sikchat.sqlite');
define('VERIFY_ADMIN_PHONE', '09362670821');
define('APP_NAME', 'سیک چت');

// اطلاعات کانال و گروه رسمی
define('OFFICIAL_CHANNEL_NAME', '📢 کانال رسمی سیک چت');
define('OFFICIAL_CHANNEL_USERNAME', 'sikchat_official');
define('OFFICIAL_GROUP_NAME', '💬 گروه رسمی سیک چت');
define('OFFICIAL_GROUP_USERNAME', 'sikchat_group');

// حساب سیستمی «اعلانات اکانت» (پیوی خودکار با تیک آبی رسمی برای اعلان ورود و پاسخ تیکت‌ها)
define('NOTIF_BOT_PHONE', '00000000001');
define('NOTIF_BOT_USERNAME', 'account_notify');
define('NOTIF_BOT_NAME', 'اعلانات اکانت');

define('REACTION_EMOJIS', ['😂', '🤨', '😉', '❤️‍🔥', '🤍', '👍', '😔', '❤️‍🩹', '☺️', '😇', '💫', '🙏🏼', '🥶', '😭', '😈', '😕']);
define('MESSAGE_TYPES', ['text', 'emoji', 'anim_emoji', 'photo', 'video', 'voice', 'file', 'short_share']);
define('MEDIA_MAX_LEN', ['photo' => 7_000_000, 'video' => 16_000_000, 'voice' => 9_000_000, 'file' => 10_000_000]);
define('MEDIA_PREFIX', ['photo' => 'data:image/', 'video' => 'data:video/', 'voice' => 'data:audio/', 'file' => 'data:']);

// ============================================================
// سقف حجم فایل ارسالی در چت بر اساس پرمیوم بودن کاربر
// (اعداد بر حسب بایتِ فایلِ خام هستند؛ چون فایل به‌صورت base64 فرستاده می‌شود
// در validate_message حدوداً ۳۷٪ به آن اضافه می‌شود تا سقفِ متنِ base64 به دست بیاید)
// ============================================================
define('UPLOAD_MAX_BYTES_FREE', 100 * 1024 * 1024);      // کاربر عادی: تا ۱۰۰ مگابایت
define('UPLOAD_MAX_BYTES_PREMIUM', 500 * 1024 * 1024);   // کاربر پرمیوم: تا ۵۰۰ مگابایت

function upload_max_bytes(bool $isPremium): int {
    return $isPremium ? UPLOAD_MAX_BYTES_PREMIUM : UPLOAD_MAX_BYTES_FREE;
}

// حداکثر طول مجاز رشته‌ی base64 برای نوع پیام (photo/video/voice/file)، با احتساب سربار base64
function media_max_len(string $type, bool $isPremium): int {
    if (!in_array($type, ['photo', 'video', 'voice', 'file'], true)) return 0;
    $rawLimit = upload_max_bytes($isPremium);
    // سربار base64 ≈ ۴/۳ + کمی برای پیشوند data:...;base64,
    return (int)ceil($rawLimit * 4 / 3) + 1024;
}

// تلاش برای بالا بردن سقف‌های سرور برای فایل‌های بزرگِ کاربران پرمیوم (بسته به هاست ممکن است اعمال نشود)
@ini_set('memory_limit', '768M');
@ini_set('max_execution_time', '300');
@ini_set('upload_max_filesize', '600M');
@ini_set('post_max_size', '700M');

// ============================================================
// استوری‌ها (Stories)
// ============================================================
define('STORY_MEDIA_MAX_LEN', ['photo' => 8_000_000, 'video' => 22_000_000]);
define('STORY_MEDIA_PREFIX', ['photo' => 'data:image/', 'video' => 'data:video/']);
define('STORY_MAX_DURATION_SECONDS', 60); // هر استوری (ویدیو) باید زیر ۱ دقیقه باشد
define('STORY_LIFETIME_SECONDS', 86400); // هر استوری بعد از ۲۴ ساعت برای همه (از جمله ادمین) منقضی می‌شود
define('STORY_ADMIN_MAX_ACTIVE', 2); // حداکثر تعداد استوری هم‌زمانِ فعال برای مدیر اصلی و ادمین‌های تیک‌آبی

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        $dir = dirname(DB_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $isNew = !file_exists(DB_FILE);

        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        // ============================================================
        // بهینه‌سازی زیرساختی SQLite برای تحمل حجم بالای پیام/درخواست
        // ============================================================
        // journal_mode = WAL (به‌جای DELETE قدیمی): در حالت DELETE هر نوشتن،
        // کل فایل دیتابیس را قفل می‌کند و همه‌ی خواندن/نوشتن‌های هم‌زمان (که در
        // یک پیام‌رسان با پولینگ مداوم پیام‌ها بسیار زیادند) پشت هم صف می‌شوند و
        // با افزایش کاربر/پیام، دقیقاً همان چیزی رخ می‌دهد که باعث «هنگ/خواب رفتن
        // سرور» می‌شود (خطای database is locked). با WAL، نوشتن‌ها مانع خواندن‌های
        // هم‌زمان نمی‌شوند و ظرفیت هم‌زمانی به‌شدت بالاتر می‌رود.
        // نکته: WAL دو فایل کمکی -wal و -shm کنار sikchat.sqlite می‌سازد. این دو فایل
        // به‌صورت خودکار در فایل اصلی «چک‌پوینت» می‌شوند (پیش‌فرض SQLite) اما برای
        // جابه‌جایی/بکاپ کامل و بی‌خطر، هر سه فایل (sqlite / sqlite-wal / sqlite-shm)
        // باید با هم کپی شوند یا قبلش از تابع db_checkpoint() برای تخلیه‌ی کامل WAL
        // به فایل اصلی استفاده شود.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        // اگر دیتابیس توسط یک درخواست دیگر موقتاً قفل بود، به‌جای شکست فوری
        // (database is locked)، تا ۸ ثانیه صبر کن و دوباره تلاش کن.
        $pdo->exec('PRAGMA busy_timeout = 8000');
        // کش بزرگ‌تر در حافظه (منفی یعنی کیلوبایت) برای کاهش I/O دیسک روی خواندن‌های پرتکرار.
        $pdo->exec('PRAGMA cache_size = -20000');
        // جدول‌های موقت/مرتب‌سازی در RAM به‌جای دیسک.
        $pdo->exec('PRAGMA temp_store = MEMORY');
        // خواندن فایل دیتابیس از طریق memory-map برای کاهش سربار سیستم‌عامل روی I/O.
        try { $pdo->exec('PRAGMA mmap_size = 268435456'); } catch (Exception $e) {}

        if ($isNew) {
            init_schema($pdo);
        }
        migrate($pdo);

        return $pdo;
    } catch (PDOException $e) {
        throw new Exception('خطا در اتصال به دیتابیس: ' . $e->getMessage());
    }
}

// تخلیه‌ی کامل فایل‌های -wal/-shm به داخل sikchat.sqlite (برای بکاپ‌گیری امن یا قبل از جابه‌جایی سورس).
// بعد از فراخوانی این تابع، همه‌ی داده‌ها دوباره فقط در همان یک فایل sikchat.sqlite هستند.
function db_checkpoint(): bool {
    try {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function init_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone TEXT NOT NULL UNIQUE,
            username TEXT UNIQUE,
            name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            color TEXT NOT NULL DEFAULT '#0088ff',
            bio TEXT NOT NULL DEFAULT '',
            avatar_photo TEXT,
            last_seen_visible INTEGER NOT NULL DEFAULT 1,
            show_phone_visible INTEGER NOT NULL DEFAULT 0,
            verified INTEGER NOT NULL DEFAULT 0,
            suspended INTEGER NOT NULL DEFAULT 0,
            is_official INTEGER NOT NULL DEFAULT 0,
            theme TEXT NOT NULL DEFAULT 'light',
            folders_json TEXT NOT NULL DEFAULT '[]',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            receiver_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            type TEXT NOT NULL DEFAULT 'text',
            body TEXT NOT NULL,
            reply_to_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_read INTEGER NOT NULL DEFAULT 0,
            read_at TEXT,
            deleted INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT,
            reacted_at TEXT,
            replied_at TEXT,
            forward_kind TEXT,
            forward_chat_id INTEGER,
            forward_name TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_pair ON messages(sender_id, receiver_id, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_reply ON messages(reply_to_id)");
    $pdo->exec("
        CREATE TABLE sessions (
            token TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    create_group_tables($pdo);
    create_reaction_tables($pdo);
    create_moderation_tables($pdo);
    create_report_tables($pdo);
    create_block_logs($pdo);
    create_ad_tables($pdo);
    create_official_tables($pdo);
    create_pin_tables($pdo);
    create_ticket_tables($pdo);
    create_short_tables($pdo);
    create_story_tables($pdo);
}

function create_ticket_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            user_name TEXT NOT NULL DEFAULT '',
            user_phone TEXT NOT NULL DEFAULT '',
            user_username TEXT,
            subject TEXT NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tickets_user ON tickets(user_id)');
}

function create_pin_tables(PDO $pdo): void {
    // سنجاق پیام در پیوی (نسخه‌ی قدیمی - تک‌سنجاقه، فقط برای سازگاری نگه داشته شده)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dm_pins (
            user_a INTEGER NOT NULL,
            user_b INTEGER NOT NULL,
            message_id INTEGER NOT NULL,
            pinned_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_a, user_b)
        )
    ");
    // سنجاق چندگانه‌ی پیام در پیوی
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dm_message_pins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_a INTEGER NOT NULL,
            user_b INTEGER NOT NULL,
            message_id INTEGER NOT NULL,
            pinned_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_dm_msg_pins_unique ON dm_message_pins(user_a, user_b, message_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dm_msg_pins_lookup ON dm_message_pins(user_a, user_b)');

    // سنجاق چندگانه‌ی پیام در گروه/کانال
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS group_message_pins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            message_id INTEGER NOT NULL,
            pinned_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_group_msg_pins_unique ON group_message_pins(group_id, message_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_group_msg_pins_lookup ON group_message_pins(group_id)');

    // سنجاق خودِ گفتگو (چت) در لیست چت‌های کاربر — هر کاربر برای خودش می‌چیند
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_pins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_chat_pins_unique ON chat_pins(user_id, kind, target_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_pins_lookup ON chat_pins(user_id)');

    // بازدید پیام‌های کانال (چشم سین)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS message_views (
            message_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            viewed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id, user_id)
        )
    ");
}

function create_ad_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            link TEXT,
            image TEXT,
            created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_active INTEGER NOT NULL DEFAULT 1
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ads_active ON ads(is_active)');
}

function create_official_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS official_channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL REFERENCES groups_(id) ON DELETE CASCADE,
            type TEXT NOT NULL CHECK(type IN ('channel', 'group')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_official_group ON official_channels(group_id)');
}

function create_reaction_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            msg_kind TEXT NOT NULL,
            msg_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            emoji TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_reactions_unique ON reactions(msg_kind, msg_id, user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reactions_lookup ON reactions(msg_kind, msg_id)');
}

function create_group_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS groups_ (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'group',
            username TEXT,
            color TEXT NOT NULL DEFAULT '#16a34a',
            photo TEXT,
            owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            banned INTEGER NOT NULL DEFAULT 0,
            is_official INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            pinned_message_id INTEGER
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_groups_username ON groups_(username)');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS group_members (
            group_id INTEGER NOT NULL REFERENCES groups_(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role TEXT NOT NULL DEFAULT 'member',
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, user_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS group_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL REFERENCES groups_(id) ON DELETE CASCADE,
            sender_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            type TEXT NOT NULL DEFAULT 'text',
            body TEXT NOT NULL,
            reply_to_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT,
            reacted_at TEXT,
            replied_at TEXT,
            views_count INTEGER NOT NULL DEFAULT 0,
            views_updated_at TEXT,
            forward_kind TEXT,
            forward_chat_id INTEGER,
            forward_name TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_group_messages_reply ON group_messages(reply_to_id)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS group_reads (
            group_id INTEGER NOT NULL REFERENCES groups_(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            last_read_id INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (group_id, user_id)
        )
    ");
}

function create_moderation_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blocked_users (
            blocker_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            blocked_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (blocker_id, blocked_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dm_hidden (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            other_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            hidden_before_id INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, other_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS banned_phones (
            phone TEXT PRIMARY KEY,
            banned_by INTEGER,
            reason TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suspensions (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            reason TEXT NOT NULL,
            suspended_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

function create_report_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reporter_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            target_kind TEXT NOT NULL CHECK(target_kind IN ('dm', 'group', 'channel', 'story', 'short')),
            target_id INTEGER NOT NULL,
            message_id INTEGER,
            reason TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_target ON reports(target_kind, target_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status)');
}

// جدول reports از قبل با CHECK قدیمی (فقط dm/group/channel) ساخته شده باشد؛ برای پشتیبانی از
// گزارشِ استوری و شورت، جدول با CHECK جدید بازسازی می‌شود (بدون از دست رفتن هیچ گزارش قبلی).
function migrate_reports_target_kind(PDO $pdo): void {
    try {
        $row = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'reports'")->fetch();
        if (!$row || empty($row['sql'])) return;
        if (strpos($row['sql'], "'story'") !== false) return; // از قبل به‌روز است

        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec("
            CREATE TABLE reports_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reporter_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                target_kind TEXT NOT NULL CHECK(target_kind IN ('dm', 'group', 'channel', 'story', 'short')),
                target_id INTEGER NOT NULL,
                message_id INTEGER,
                reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec('INSERT INTO reports_new (id, reporter_id, target_kind, target_id, message_id, reason, status, created_at) SELECT id, reporter_id, target_kind, target_id, message_id, reason, status, created_at FROM reports');
        $pdo->exec('DROP TABLE reports');
        $pdo->exec('ALTER TABLE reports_new RENAME TO reports');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_target ON reports(target_kind, target_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status)');
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Exception $e2) {}
    }
}

function create_block_logs(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS block_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            blocker_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            blocked_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            action TEXT NOT NULL CHECK(action IN ('block', 'unblock')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_block_logs_pair ON block_logs(blocker_id, blocked_id)');
}

function create_short_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shorts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            platform TEXT NOT NULL DEFAULT 'aparat',
            url TEXT NOT NULL,
            embed_url TEXT NOT NULL,
            caption TEXT NOT NULL DEFAULT '',
            thumbnail TEXT,
            created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            views_count INTEGER NOT NULL DEFAULT 0,
            likes_count INTEGER NOT NULL DEFAULT 0,
            comments_count INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shorts_active ON shorts(is_active, id)');

    // پشتیبانی از تبلیغات لابه‌لای شورت‌ها (نوع رکورد: video یا ad)
    try {
        $shcols = $pdo->query('PRAGMA table_info(shorts)')->fetchAll();
        $shnames = array_column($shcols, 'name');
    } catch (Exception $e) { $shnames = []; }
    if (!in_array('type', $shnames, true)) {
        try { $pdo->exec("ALTER TABLE shorts ADD COLUMN type TEXT NOT NULL DEFAULT 'video'"); } catch (Exception $e) {}
    }
    if (!in_array('ad_title', $shnames, true)) {
        try { $pdo->exec('ALTER TABLE shorts ADD COLUMN ad_title TEXT'); } catch (Exception $e) {}
    }
    // منبع شورت: افزوده‌شده توسط ادمین یا پیشنهاد/اشتراک‌گذاری یک کاربر عادی
    if (!in_array('source', $shnames, true)) {
        try { $pdo->exec("ALTER TABLE shorts ADD COLUMN source TEXT NOT NULL DEFAULT 'admin'"); } catch (Exception $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS short_likes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            short_id INTEGER NOT NULL REFERENCES shorts(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_short_likes_unique ON short_likes(short_id, user_id)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS short_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            short_id INTEGER NOT NULL REFERENCES shorts(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            parent_id INTEGER,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted INTEGER NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_short_comments_short ON short_comments(short_id, id)');

    try {
        $sccols = $pdo->query('PRAGMA table_info(short_comments)')->fetchAll();
        $scnames = array_column($sccols, 'name');
    } catch (Exception $e) { $scnames = []; }
    if (!in_array('likes_count', $scnames, true)) {
        try { $pdo->exec('ALTER TABLE short_comments ADD COLUMN likes_count INTEGER NOT NULL DEFAULT 0'); } catch (Exception $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS short_comment_likes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            comment_id INTEGER NOT NULL REFERENCES short_comments(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_short_comment_likes_unique ON short_comment_likes(comment_id, user_id)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS short_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            short_id INTEGER NOT NULL REFERENCES shorts(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_short_views_unique ON short_views(short_id, user_id)');
}

function create_story_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            type TEXT NOT NULL DEFAULT 'photo' CHECK(type IN ('photo', 'video')),
            media TEXT NOT NULL,
            caption TEXT NOT NULL DEFAULT '',
            is_main_admin_story INTEGER NOT NULL DEFAULT 0,
            is_admin_story INTEGER NOT NULL DEFAULT 0,
            views_count INTEGER NOT NULL DEFAULT 0,
            likes_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL DEFAULT (datetime('now', '+1 day'))
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stories_user ON stories(user_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stories_active ON stories(expires_at)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS story_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            story_id INTEGER NOT NULL REFERENCES stories(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_story_views_unique ON story_views(story_id, user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_story_views_story ON story_views(story_id, created_at)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS story_likes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            story_id INTEGER NOT NULL REFERENCES stories(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_story_likes_unique ON story_likes(story_id, user_id)');

    // ستون پاسخ به استوری روی جدول پیام‌های پیوی (پاسخ استوری مثل یک پیام معمولی برای صاحب استوری ارسال می‌شود)
    try {
        $mcols2 = $pdo->query('PRAGMA table_info(messages)')->fetchAll();
        $mnames2 = array_column($mcols2, 'name');
    } catch (Exception $e) { $mnames2 = []; }
    if (!in_array('story_reply_id', $mnames2, true)) {
        try { $pdo->exec('ALTER TABLE messages ADD COLUMN story_reply_id INTEGER'); } catch (Exception $e) {}
    }
}

// آیا این کاربر (بر اساس شماره و تیک‌آبی) جزو «ادمین‌های استوری» است؟ یعنی مدیر اصلی پیام‌رسان یا ادمین تیک‌آبی
function is_story_admin(?string $phone, $verified): bool {
    return is_admin_phone($phone) || ((int)($verified ?? 0) === 1);
}

// آیا این کاربر اجازه دارد الان استوری بگذارد؟ (ادمین‌ها همیشه، بقیه فقط تا وقتی پرمیوم دارند)
function can_post_story(array $u): array {
    $isAdmin = is_story_admin($u['phone'] ?? null, $u['verified'] ?? 0);
    if ($isAdmin) return ['ok' => true, 'is_admin' => true];
    if (is_user_premium($u)) return ['ok' => true, 'is_admin' => false];
    return ['ok' => false, 'is_admin' => false, 'reason' => 'برای گذاشتن استوری باید اشتراک پرمیوم داشته باشی.'];
}

// خلاصه‌ی استوری برای پیش‌نمایش «پاسخ به استوری» در پیوی
function get_story_reply_preview(int $storyId): ?array {
    if ($storyId <= 0) return null;
    try {
        $stmt = db()->prepare('SELECT s.id, s.type, s.media, s.user_id, u.name AS owner_name FROM stories s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
        $stmt->execute([$storyId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return [
            'id' => (int)$row['id'],
            'type' => $row['type'],
            'media' => $row['media'],
            'owner_id' => (int)$row['user_id'],
            'owner_name' => $row['owner_name'],
        ];
    } catch (Exception $e) { return null; }
}

// ============================================================
// توابع وضعیت کاربر
// ============================================================

function is_phone_banned(string $phone): bool {
    try {
        $stmt = db()->prepare('SELECT 1 FROM banned_phones WHERE phone = ?');
        $stmt->execute([$phone]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { return false; }
}

function is_user_suspended(int $userId): bool {
    try {
        $stmt = db()->prepare('SELECT 1 FROM suspensions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { return false; }
}

function is_group_banned(int $groupId): bool {
    try {
        $stmt = db()->prepare('SELECT banned FROM groups_ WHERE id = ?');
        $stmt->execute([$groupId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['banned'] === 1 : false;
    } catch (Exception $e) { return false; }
}

function is_official_channel(int $groupId): bool {
    try {
        $stmt = db()->prepare('SELECT 1 FROM official_channels WHERE group_id = ?');
        $stmt->execute([$groupId]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { return false; }
}

// آیا این گروه/کانال رسمی، از نوع «کانال» است؟ (محدودیت ویرایش/حذف پیام فقط برای کانال رسمی اعمال می‌شود، نه گروه رسمی)
function is_official_readonly_channel(int $groupId): bool {
    try {
        $stmt = db()->prepare('SELECT type FROM official_channels WHERE group_id = ?');
        $stmt->execute([$groupId]);
        $r = $stmt->fetch();
        return $r ? ($r['type'] === 'channel') : false;
    } catch (Exception $e) { return false; }
}

// آیا کاربر در حال حاضر پرمیوم است؟ مدیر اصلی سایت همیشه پرمیوم؛ ادمین‌های تیک‌آبی تا وقتی ادمین هستند پرمیوم؛
// در غیر این صورت بر اساس تاریخ انقضای ثبت‌شده (۳۰ روزه)
// استثنا: مدیر اصلی و ادمین‌های تیک‌آبی می‌توانند با سوییچ خودشان (premium_self_off) پرمیومِ خودکارشان را موقتا خاموش کنند
function is_user_premium(array $u): bool {
    if ((int)($u['premium_self_off'] ?? 0) === 1) return false;
    if (is_admin_phone($u['phone'] ?? null)) return true;
    if ((int)($u['verified'] ?? 0) === 1) return true;
    $until = $u['premium_until'] ?? null;
    if (!$until) return false;
    try { return strtotime($until) > time(); } catch (Exception $e) { return false; }
}

// آیا این کاربر جزو کسانی است که پرمیومِ خودکار دارند و اجازه دارند خودشان آن را فعال/غیرفعال کنند؟
// (مدیر اصلی کل پیام‌رسان + ادمین‌های تیک‌آبی)
function can_self_toggle_premium(?string $phone, $verified): bool {
    return is_admin_phone($phone) || ((int)($verified ?? 0) === 1);
}

// نام نمایشی متحرک فقط زمانی نمایش داده می‌شود که صاحبش هنوز واجد شرایط باشد (مدیر اصلی یا ادمین تیک‌آبی)
function gated_premium_name(?string $premiumName, ?string $phone, $verified): string {
    $eligible = is_admin_phone($phone) || ((int)($verified ?? 0) === 1);
    return ($eligible && $premiumName) ? $premiumName : '';
}

// وضعیت پرمیوم فرستنده‌ی یک پیام، به‌صورت لحظه‌ای (برای تصمیم به متحرک نشان دادن ایموجی)
function sender_is_premium(?string $premiumUntil, ?string $phone, $verified): bool {
    return is_user_premium(['phone' => $phone, 'verified' => $verified, 'premium_until' => $premiumUntil]);
}

// آیا این شماره متعلق به مدیر اصلی سایت است؟ (تیک طلایی فقط و فقط برای همین یک نفر)
function is_admin_phone(?string $phone): bool {
    return $phone !== null && $phone === VERIFY_ADMIN_PHONE;
}

function get_sik_reason(int $userId): string {
    try {
        $stmt = db()->prepare('SELECT reason FROM suspensions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? $row['reason'] : 'تخلف از قوانین';
    } catch (Exception $e) { return 'تخلف از قوانین'; }
}

function get_user_status(array $user): array {
    $status = [
        'banned' => false,
        'suspended' => false,
        'sik_reason' => null,
        'last_seen' => $user['last_seen'] ?? null,
        'last_seen_text' => null,
        'status_text' => null,
        'status_class' => '',
        'show_status_box' => false,
        'is_sik' => false,
        'is_official' => (int)($user['is_official'] ?? 0) === 1,
    ];

    if (is_phone_banned($user['phone'])) {
        $status['banned'] = true;
        $status['is_sik'] = true;
        $status['status_text'] = '🚫 این کاربر از سیک چت سیک شده است.';
        $status['status_class'] = 'sik';
        $status['last_seen_text'] = 'آخرین بازدید خیلی وقت پیش';
        $status['show_status_box'] = true;
        $status['sik_reason'] = 'بن شده توسط ادمین';
        return $status;
    }

    if (is_user_suspended((int)$user['id'])) {
        $status['suspended'] = true;
        $status['is_sik'] = true;
        $status['status_text'] = '⚠️ اکانت این کاربر از سیک چت سیک شده است.';
        $status['status_class'] = 'sik';
        $status['last_seen_text'] = 'آخرین بازدید خیلی وقت پیش';
        $status['show_status_box'] = true;
        $status['sik_reason'] = get_sik_reason((int)$user['id']);
        return $status;
    }

    if ((int)($user['last_seen_visible'] ?? 1) === 1 && !empty($user['last_seen'])) {
        $diff = time() - strtotime($user['last_seen']);
        if ($diff < 60) {
            $status['status_text'] = '🟢 آنلاین';
            $status['status_class'] = 'online';
        } else {
            $status['last_seen_text'] = 'آخرین بازدید: ' . format_persian_time($user['last_seen']);
            $status['status_text'] = 'آفلاین';
            $status['status_class'] = 'offline';
        }
    } else {
        $status['last_seen_text'] = 'آخرین بازدید مخفی است';
        $status['status_text'] = 'آفلاین';
        $status['status_class'] = 'offline';
    }

    return $status;
}

function format_persian_time(string $datetime): string {
    try {
        $dt = new DateTime($datetime);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        if ($diff < 60) return 'لحظاتی پیش';
        if ($diff < 3600) return floor($diff / 60) . ' دقیقه پیش';
        if ($diff < 86400) return floor($diff / 3600) . ' ساعت پیش';
        if ($diff < 172800) return 'دیروز';
        if ($diff < 604800) return floor($diff / 86400) . ' روز پیش';
        return $dt->format('Y/m/d H:i');
    } catch (Exception $e) { return $datetime; }
}

function validate_message(string $type, string $body, bool $isPremium = false): string {
    $type = in_array($type, MESSAGE_TYPES, true) ? $type : 'text';
    if ($type === 'text' || $type === 'emoji' || $type === 'anim_emoji' || $type === 'short_share') {
        if ($body === '' || mb_strlen($body) > 4000) {
            respond(['ok' => false, 'error' => 'متن پیام نامعتبر است.'], 400);
            exit;
        }
        return $type;
    }
    if ($body === '') {
        respond(['ok' => false, 'error' => 'فایلی برای ارسال انتخاب نشده.'], 400);
        exit;
    }
    $maxLen = media_max_len($type, $isPremium);
    if (strlen($body) > $maxLen) {
        $maxMb = (int)round(upload_max_bytes($isPremium) / (1024 * 1024));
        respond([
            'ok' => false,
            'error' => $isPremium
                ? 'حجم فایل بیشتر از سقف مجاز کاربران پرمیوم (' . $maxMb . ' مگابایت) است.'
                : 'حجم فایل بیشتر از سقف مجاز کاربران عادی (' . $maxMb . ' مگابایت) است. با ارتقا به پرمیوم می‌توانی تا ۵۰۰ مگابایت ارسال کنی.',
            'error_code' => 'FILE_TOO_LARGE',
            'is_premium' => $isPremium,
            'max_mb' => $maxMb,
        ], 400);
        exit;
    }
    if (!str_starts_with($body, MEDIA_PREFIX[$type])) {
        respond(['ok' => false, 'error' => 'فرمت فایل ارسالی نامعتبر است.'], 400);
        exit;
    }
    return $type;
}

function message_preview(string $type, string $body): string {
    switch ($type) {
        case 'photo': return '📷 عکس';
        case 'video': return '🎥 ویدیو';
        case 'voice': return '🎙 پیام صوتی';
        case 'file': return '📎 فایل';
        case 'short_share': return '🎬 شورت';
        default: return $body;
    }
}

function respond(array $data, int $status = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400) {
    respond(['ok' => false, 'error' => $message], $status);
}

function input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > 0) {
            respond(['ok' => false, 'error' => 'حجم داده برای این سرور خیلی زیاد است.'], 413);
        }
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(['ok' => false, 'error' => 'داده‌ی ارسالی معتبر نیست.'], 400);
    }
    return $data;
}

function normalize_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '98') && strlen($digits) === 12) $digits = '0' . substr($digits, 2);
    if (strlen($digits) === 10 && $digits[0] === '9') $digits = '0' . $digits;
    return $digits;
}

function normalize_username(string $u): string {
    $u = trim($u);
    if (str_starts_with($u, '@')) $u = substr($u, 1);
    return strtolower($u);
}

function valid_username_format(string $u): bool {
    return (bool)preg_match('/^[a-z][a-z0-9_]{4,31}$/', $u);
}

/**
 * تشخیص لینک شورت یوتیوب و ساخت لینک embed مناسب برای پخش داخل اپ.
 * در صورت نامعتبر بودن لینک (یا غیر یوتیوب بودن آن)، آرایه‌ی خالی برمی‌گرداند.
 */
function parse_short_url(string $url): array {
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return [];

    // یوتیوب: youtu.be/ID یا youtube.com/watch?v=ID یا /shorts/ID یا /embed/ID
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([A-Za-z0-9_-]{6,})~i', $url, $m)) {
        $vid = $m[1];
        return [
            'platform' => 'youtube',
            // controls=0/disablekb=1/fs=0/iv_load_policy=3/showinfo=0 => حداکثر حذف چیدمان و کنترل‌های اضافه‌ی یوتیوب، فقط پخش خام ویدیو
            'embed_url' => 'https://www.youtube.com/embed/' . $vid . '?autoplay=1&playsinline=1&rel=0&modestbranding=1&controls=0&disablekb=1&fs=0&iv_load_policy=3&showinfo=0',
        ];
    }

    return [];
}

function require_auth(): array {
    $token = isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : (isset($_SESSION['token']) ? $_SESSION['token'] : null);
    if (!$token) {
        fail('ابتدا وارد شوید.', 401);
        exit;
    }

    try {
        $stmt = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
    } catch (Exception $e) {
        fail('خطا در اعتبارسنجی نشست.', 500);
        exit;
    }

    if (!$user) {
        fail('نشست منقضی شده، دوباره وارد شوید.', 401);
        exit;
    }

    if (is_phone_banned($user['phone'])) {
        try { db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]); } catch (Exception $e) {}
        fail('شما از پیام‌رسان سیک شدید.', 403);
        exit;
    }

    if (is_user_suspended((int)$user['id'])) {
        try { db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]); } catch (Exception $e) {}
        fail('شما از پیام‌رسان سیک شدید.', 403);
        exit;
    }

    // بهینه‌سازی: چون این تابع روی تک‌تک درخواست‌های API (از جمله پولینگ مداوم پیام‌ها هر
    // چند ثانیه) اجرا می‌شود، آپدیت last_seen را فقط وقتی واقعاً لازم است (بیش از ۱۵ ثانیه از
    // آخرین‌بار گذشته) انجام می‌دهیم؛ در غیر این صورت با هر کاربر فعال، هر چند ثانیه یک‌بار یک
    // نوشتن اضافه روی دیتابیس می‌افتاد که با زیاد شدن کاربرها/پیام‌ها باعث قفل‌شدن و کندی سرور می‌شد.
    $lastSeenTs = !empty($user['last_seen']) ? strtotime((string)$user['last_seen']) : false;
    if ($lastSeenTs === false || (time() - $lastSeenTs) >= 15) {
        try { db()->prepare('UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = ?')->execute([$user['id']]); } catch (Exception $e) {}
    }

    return $user;
}

function public_user(array $u, bool $revealPhone = false, bool $bypassPrivacy = false): array {
    $showPhoneSetting = (int)(isset($u['show_phone_visible']) ? $u['show_phone_visible'] : 1) === 1;
    $phoneAllowed = $revealPhone && ($bypassPrivacy || $showPhoneSetting);
    return [
        'id' => (int)$u['id'],
        'name' => $u['name'],
        'phone' => $phoneAllowed ? $u['phone'] : null,
        'username' => isset($u['username']) ? $u['username'] : null,
        'color' => $u['color'],
        'bio' => isset($u['bio']) ? $u['bio'] : '',
        'avatar_photo' => isset($u['avatar_photo']) ? $u['avatar_photo'] : null,
        'last_seen_visible' => (int)(isset($u['last_seen_visible']) ? $u['last_seen_visible'] : 1) === 1,
        'show_phone_visible' => $showPhoneSetting,
        'verified' => (int)(isset($u['verified']) ? $u['verified'] : 0) === 1,
        'suspended' => (int)(isset($u['suspended']) ? $u['suspended'] : 0) === 1,
        'is_official' => (int)(isset($u['is_official']) ? $u['is_official'] : 0) === 1,
        // تیک طلایی فقط برای مدیر اصلی سایت است؛ حساب‌های رسمی دیگر (مثل ربات اعلانات) با اینکه is_official دارند، طلایی نیستند
        'is_main_admin' => is_admin_phone($u['phone'] ?? null),
        'theme' => isset($u['theme']) ? $u['theme'] : 'light',
        'last_seen' => $u['last_seen'] ?? date('Y-m-d H:i:s'),
        'is_premium' => is_user_premium($u),
        'premium_name' => gated_premium_name($u['premium_name'] ?? null, $u['phone'] ?? null, $u['verified'] ?? 0),
        'premium_name_editable' => is_admin_phone($u['phone'] ?? null) || ((int)($u['verified'] ?? 0) === 1),
        'premium_self_off' => (int)(isset($u['premium_self_off']) ? $u['premium_self_off'] : 0) === 1,
        'premium_self_toggle_available' => can_self_toggle_premium($u['phone'] ?? null, $u['verified'] ?? 0),
    ];
}

function username_taken(string $username, ?int $excludeUserId = null, ?int $excludeGroupId = null): bool {
    $u = db()->prepare('SELECT id FROM users WHERE username = ?' . ($excludeUserId ? ' AND id != ' . (int)$excludeUserId : ''));
    $u->execute([$username]);
    if ($u->fetch()) return true;
    $g = db()->prepare('SELECT id FROM groups_ WHERE username = ?' . ($excludeGroupId ? ' AND id != ' . (int)$excludeGroupId : ''));
    $g->execute([$username]);
    if ($g->fetch()) return true;
    return false;
}

function get_reactions_for(string $kind, int $msgId, int $meId): array {
    try {
        $stmt = db()->prepare("
            SELECT emoji, COUNT(*) AS c, MAX(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS mine, MIN(id) AS fid
            FROM reactions
            WHERE msg_kind = ? AND msg_id = ?
            GROUP BY emoji
            ORDER BY fid ASC
        ");
        $stmt->execute([$meId, $kind, $msgId]);
        $rows = $stmt->fetchAll();
        return array_map(function ($r) {
            return [
                'emoji' => $r['emoji'],
                'count' => (int)$r['c'],
                'mine' => (int)$r['mine'] === 1,
            ];
        }, $rows);
    } catch (Exception $e) { return []; }
}

function public_group(array $g): array {
    try {
        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM group_members WHERE group_id = ?');
        $countStmt->execute([(int)$g['id']]);
        $memberCount = (int)$countStmt->fetch()['c'];
    } catch (Exception $e) { $memberCount = 0; }

    return [
        'id' => (int)$g['id'],
        'name' => $g['name'],
        'type' => $g['type'],
        'username' => isset($g['username']) ? $g['username'] : null,
        'color' => $g['color'],
        'photo' => isset($g['photo']) ? $g['photo'] : null,
        'owner_id' => (int)$g['owner_id'],
        'member_count' => $memberCount,
        'banned' => (int)($g['banned'] ?? 0) === 1,
        'is_official' => (int)($g['is_official'] ?? 0) === 1,
        'verified' => (int)($g['verified'] ?? 0) === 1,
        'pinned_message_id' => isset($g['pinned_message_id']) ? (int)$g['pinned_message_id'] : null,
    ];
}

function get_reply_preview(string $kind, int $replyToId): ?array {
    if ($replyToId <= 0) return null;
    
    try {
        if ($kind === 'dm') {
            $stmt = db()->prepare('SELECT id, sender_id, type, body, deleted FROM messages WHERE id = ?');
            $stmt->execute([$replyToId]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['deleted'] === 1) return null;
            
            $sender = fetch_user((int)$row['sender_id']);
            return [
                'id' => (int)$row['id'],
                'sender_name' => $sender ? $sender['name'] : 'نامشخص',
                'type' => $row['type'],
                'body' => $row['type'] === 'text' || $row['type'] === 'emoji' ? $row['body'] : message_preview($row['type'], $row['body']),
            ];
        } else {
            $stmt = db()->prepare('
                SELECT gm.id, gm.sender_id, gm.type, gm.body, gm.deleted, u.name AS sender_name
                FROM group_messages gm
                JOIN users u ON u.id = gm.sender_id
                WHERE gm.id = ?
            ');
            $stmt->execute([$replyToId]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['deleted'] === 1) return null;
            
            return [
                'id' => (int)$row['id'],
                'sender_name' => $row['sender_name'],
                'type' => $row['type'],
                'body' => $row['type'] === 'text' || $row['type'] === 'emoji' ? $row['body'] : message_preview($row['type'], $row['body']),
            ];
        }
    } catch (Exception $e) { return null; }
}

// اطلاعات منبع فوروارد را از ورودی کلاینت اعتبارسنجی می‌کند (برای برچسب «فوروارد شده از...»)
function parse_forward_from($in): array {
    $f = is_array($in) ? ($in['forward_from'] ?? null) : null;
    if (!is_array($f)) return [null, null, null, null];

    $kind = (string)($f['kind'] ?? '');
    if (!in_array($kind, ['dm', 'group'], true)) return [null, null, null, null];

    $id = (int)($f['id'] ?? 0);
    if ($id <= 0) return [null, null, null, null];

    $name = trim((string)($f['name'] ?? ''));
    if ($name === '') return [null, null, null, null];
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

    // شناسه‌ی خودِ پیام مبدا در همان چت (برای رفتن مستقیم به همان پیام، مثل روبیکا)
    $msgId = (int)($f['msg_id'] ?? 0);
    if ($msgId <= 0) $msgId = null;

    return [$kind, $id, $name, $msgId];
}

function fetch_user(int $id) {
    try {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        return $u ?: false;
    } catch (Exception $e) { return false; }
}

function generate_token(): string {
    if (function_exists('random_bytes')) {
        try {
            return bin2hex(random_bytes(32));
        } catch (Exception $e) {}
    }
    return md5(uniqid(mt_rand(), true)) . md5(microtime(true));
}

function start_session_for(int $userId): string {
    try {
        $token = generate_token();
        db()->prepare('INSERT INTO sessions (token, user_id) VALUES (?, ?)')->execute([$token, $userId]);
        $_SESSION['token'] = $token;
        $_SESSION['user_id'] = $userId;
        return $token;
    } catch (Exception $e) {
        fail('خطا در ایجاد نشست.', 500);
        exit;
    }
}

function pinned_message_payload(int $groupId, array $me) {
    try {
        $gstmt = db()->prepare('SELECT pinned_message_id FROM groups_ WHERE id = ?');
        $gstmt->execute([$groupId]);
        $g = $gstmt->fetch();
        $pinnedId = $g && $g['pinned_message_id'] !== null ? (int)$g['pinned_message_id'] : 0;
        if ($pinnedId <= 0) return null;

        $stmt = db()->prepare('
            SELECT gm.*, u.id AS sender_uid, u.name AS sender_name, u.color AS sender_color, u.verified AS sender_verified, u.is_official AS sender_official, u.phone AS sender_phone, u.premium_name AS sender_premium_name, u.premium_until AS sender_premium_until
            FROM group_messages gm JOIN users u ON u.id = gm.sender_id
            WHERE gm.id = ? AND gm.group_id = ?
        ');
        $stmt->execute([$pinnedId, $groupId]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['deleted'] === 1) {
            try { db()->prepare('UPDATE groups_ SET pinned_message_id = NULL WHERE id = ?')->execute([$groupId]); } catch (Exception $e) {}
            return null;
        }

        $out = map_message_row($row, $me, 'group');
        $out['sender_id'] = (int)$row['sender_uid'];
        $out['sender_name'] = $row['sender_name'];
        $out['sender_color'] = $row['sender_color'];
        $out['sender_verified'] = (int)($row['sender_verified'] ?? 0) === 1;
        $out['sender_official'] = (int)($row['sender_official'] ?? 0) === 1;
        $out['sender_is_main_admin'] = is_admin_phone($row['sender_phone'] ?? null);
        $out['sender_premium_name'] = gated_premium_name($row['sender_premium_name'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
        $out['sender_premium'] = sender_is_premium($row['sender_premium_until'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
        return $out;
    } catch (Exception $e) { return null; }
}

function dm_pinned_message_payload(int $userA, int $userB, array $me) {
    try {
        $lo = min($userA, $userB);
        $hi = max($userA, $userB);
        $pstmt = db()->prepare('SELECT message_id FROM dm_pins WHERE user_a = ? AND user_b = ?');
        $pstmt->execute([$lo, $hi]);
        $p = $pstmt->fetch();
        $pinnedId = $p ? (int)$p['message_id'] : 0;
        if ($pinnedId <= 0) return null;

        $stmt = db()->prepare('
            SELECT m.*, u.id AS sender_uid, u.name AS sender_name, u.color AS sender_color, u.verified AS sender_verified, u.is_official AS sender_official, u.phone AS sender_phone, u.premium_name AS sender_premium_name, u.premium_until AS sender_premium_until
            FROM messages m JOIN users u ON u.id = m.sender_id
            WHERE m.id = ? AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
        ');
        $stmt->execute([$pinnedId, $lo, $hi, $hi, $lo]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['deleted'] === 1) {
            try { db()->prepare('DELETE FROM dm_pins WHERE user_a = ? AND user_b = ?')->execute([$lo, $hi]); } catch (Exception $e) {}
            return null;
        }

        $out = map_message_row($row, $me, 'dm');
        $out['sender_id'] = (int)$row['sender_uid'];
        $out['sender_name'] = $row['sender_name'];
        $out['sender_color'] = $row['sender_color'];
        $out['sender_verified'] = (int)($row['sender_verified'] ?? 0) === 1;
        $out['sender_official'] = (int)($row['sender_official'] ?? 0) === 1;
        $out['sender_is_main_admin'] = is_admin_phone($row['sender_phone'] ?? null);
        $out['sender_premium_name'] = gated_premium_name($row['sender_premium_name'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
        $out['sender_premium'] = sender_is_premium($row['sender_premium_until'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
        return $out;
    } catch (Exception $e) { return null; }
}

// سنجاق چندگانه‌ی پیام در گروه/کانال — همه‌ی پیام‌های سنجاق‌شده را برمی‌گرداند (جدیدترین سنجاق اول)
function group_pinned_messages_payload(int $groupId, array $me): array {
    try {
        $pstmt = db()->prepare('SELECT message_id FROM group_message_pins WHERE group_id = ? ORDER BY created_at DESC, id DESC');
        $pstmt->execute([$groupId]);
        $ids = array_map(fn($r) => (int)$r['message_id'], $pstmt->fetchAll());
        if (!$ids) return [];

        $out = [];
        foreach ($ids as $pinnedId) {
            $stmt = db()->prepare('
                SELECT gm.*, u.id AS sender_uid, u.name AS sender_name, u.color AS sender_color, u.verified AS sender_verified, u.is_official AS sender_official, u.phone AS sender_phone, u.premium_name AS sender_premium_name, u.premium_until AS sender_premium_until
                FROM group_messages gm JOIN users u ON u.id = gm.sender_id
                WHERE gm.id = ? AND gm.group_id = ?
            ');
            $stmt->execute([$pinnedId, $groupId]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['deleted'] === 1) {
                try { db()->prepare('DELETE FROM group_message_pins WHERE group_id = ? AND message_id = ?')->execute([$groupId, $pinnedId]); } catch (Exception $e) {}
                continue;
            }
            $item = map_message_row($row, $me, 'group');
            $item['sender_id'] = (int)$row['sender_uid'];
            $item['sender_name'] = $row['sender_name'];
            $item['sender_color'] = $row['sender_color'];
            $item['sender_verified'] = (int)($row['sender_verified'] ?? 0) === 1;
            $item['sender_official'] = (int)($row['sender_official'] ?? 0) === 1;
            $item['sender_is_main_admin'] = is_admin_phone($row['sender_phone'] ?? null);
            $item['sender_premium_name'] = gated_premium_name($row['sender_premium_name'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
            $item['sender_premium'] = sender_is_premium($row['sender_premium_until'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
            $out[] = $item;
        }
        return $out;
    } catch (Exception $e) { return []; }
}

// سنجاق چندگانه‌ی پیام در پیوی — همه‌ی پیام‌های سنجاق‌شده را برمی‌گرداند (جدیدترین سنجاق اول)
function dm_pinned_messages_payload(int $userA, int $userB, array $me): array {
    try {
        $lo = min($userA, $userB);
        $hi = max($userA, $userB);
        $pstmt = db()->prepare('SELECT message_id FROM dm_message_pins WHERE user_a = ? AND user_b = ? ORDER BY created_at DESC, id DESC');
        $pstmt->execute([$lo, $hi]);
        $ids = array_map(fn($r) => (int)$r['message_id'], $pstmt->fetchAll());
        if (!$ids) return [];

        $out = [];
        foreach ($ids as $pinnedId) {
            $stmt = db()->prepare('
                SELECT m.*, u.id AS sender_uid, u.name AS sender_name, u.color AS sender_color, u.verified AS sender_verified, u.is_official AS sender_official, u.phone AS sender_phone, u.premium_name AS sender_premium_name, u.premium_until AS sender_premium_until
                FROM messages m JOIN users u ON u.id = m.sender_id
                WHERE m.id = ? AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
            ');
            $stmt->execute([$pinnedId, $lo, $hi, $hi, $lo]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['deleted'] === 1) {
                try { db()->prepare('DELETE FROM dm_message_pins WHERE user_a = ? AND user_b = ? AND message_id = ?')->execute([$lo, $hi, $pinnedId]); } catch (Exception $e) {}
                continue;
            }
            $item = map_message_row($row, $me, 'dm');
            $item['sender_id'] = (int)$row['sender_uid'];
            $item['sender_name'] = $row['sender_name'];
            $item['sender_color'] = $row['sender_color'];
            $item['sender_verified'] = (int)($row['sender_verified'] ?? 0) === 1;
            $item['sender_official'] = (int)($row['sender_official'] ?? 0) === 1;
            $item['sender_is_main_admin'] = is_admin_phone($row['sender_phone'] ?? null);
            $item['sender_premium_name'] = gated_premium_name($row['sender_premium_name'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
            $item['sender_premium'] = sender_is_premium($row['sender_premium_until'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
            $out[] = $item;
        }
        return $out;
    } catch (Exception $e) { return []; }
}

function assert_group_member(int $groupId, int $userId): void {
    try {
        $stmt = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $stmt->execute([$groupId, $userId]);
        if (!$stmt->fetch()) {
            fail('عضو این گروه/کانال نیستید.', 403);
            exit;
        }
    } catch (Exception $e) {
        fail('خطا در بررسی عضویت.', 500);
        exit;
    }
}

function member_role(int $groupId, int $userId): ?string {
    try {
        $stmt = db()->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
        $stmt->execute([$groupId, $userId]);
        $row = $stmt->fetch();
        return $row ? $row['role'] : null;
    } catch (Exception $e) { return null; }
}

function assert_group_can_post(array $group, int $userId): void {
    $role = member_role((int)$group['id'], $userId);
    if (!$role) {
        fail('عضو این گروه/کانال نیستید.', 403);
        exit;
    }
    if ($group['type'] === 'channel' && !in_array($role, ['owner', 'admin'], true)) {
        fail('در این کانال فقط مالک یا مدیر می‌تواند پیام بدهد.', 403);
        exit;
    }
}

function map_message_row(array $m, array $me, string $kind = 'dm'): array {
    $deleted = (int)($m['deleted'] ?? 0) === 1;
    $replyToId = isset($m['reply_to_id']) ? (int)$m['reply_to_id'] : 0;

    $out = [
        'id' => (int)$m['id'],
        'mine' => (int)$m['sender_id'] === (int)$me['id'],
        'type' => $m['type'],
        'body' => $deleted ? '' : $m['body'],
        'caption' => $deleted ? '' : (string)($m['caption'] ?? ''),
        'file_name' => $deleted ? '' : (string)($m['file_name'] ?? ''),
        'file_size' => $deleted ? 0 : (int)($m['file_size'] ?? 0),
        'created_at' => $m['created_at'],
        'edited' => !$deleted && !empty($m['updated_at']),
        'deleted' => $deleted,
        'reactions' => $deleted ? [] : get_reactions_for($kind, (int)$m['id'], (int)$me['id']),
        'reply_to' => ($replyToId > 0 && !$deleted) ? get_reply_preview($kind, $replyToId) : null,
        'forward' => (!$deleted && !empty($m['forward_kind']) && !empty($m['forward_chat_id'])) ? [
            'kind' => $m['forward_kind'],
            'id' => (int)$m['forward_chat_id'],
            'name' => $m['forward_name'],
            'msg_id' => !empty($m['forward_msg_id']) ? (int)$m['forward_msg_id'] : null,
        ] : null,
        'story_reply' => (!$deleted && !empty($m['story_reply_id'])) ? get_story_reply_preview((int)$m['story_reply_id']) : null,
    ];

    if ($kind === 'dm') {
        // تیک دوم: پیام توسط طرف مقابل خونده شده یا نه
        $out['is_read'] = (int)($m['is_read'] ?? 0) === 1;
    } elseif ($kind === 'group' && array_key_exists('views_count', $m)) {
        // چشم سین: تعداد بازدید پیام‌های کانال
        $out['views'] = (int)($m['views_count'] ?? 0);
    }

    return $out;
}

function migrate(PDO $pdo): void {
    try {
        $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll();
        $names = array_column($cols, 'name');
    } catch (Exception $e) { $names = []; }

    $columns = [
        'avatar_photo' => 'ALTER TABLE users ADD COLUMN avatar_photo TEXT',
        'last_seen_visible' => 'ALTER TABLE users ADD COLUMN last_seen_visible INTEGER NOT NULL DEFAULT 1',
        'show_phone_visible' => 'ALTER TABLE users ADD COLUMN show_phone_visible INTEGER NOT NULL DEFAULT 1',
        'bio' => "ALTER TABLE users ADD COLUMN bio TEXT NOT NULL DEFAULT ''",
        'verified' => 'ALTER TABLE users ADD COLUMN verified INTEGER NOT NULL DEFAULT 0',
        'suspended' => 'ALTER TABLE users ADD COLUMN suspended INTEGER NOT NULL DEFAULT 0',
        'is_official' => 'ALTER TABLE users ADD COLUMN is_official INTEGER NOT NULL DEFAULT 0',
        'theme' => "ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'light'",
        'folders_json' => "ALTER TABLE users ADD COLUMN folders_json TEXT NOT NULL DEFAULT '[]'",
        'premium_until' => 'ALTER TABLE users ADD COLUMN premium_until TEXT',
        'premium_name' => "ALTER TABLE users ADD COLUMN premium_name TEXT NOT NULL DEFAULT ''",
        'premium_self_off' => 'ALTER TABLE users ADD COLUMN premium_self_off INTEGER NOT NULL DEFAULT 0',
    ];

    foreach ($columns as $col => $sql) {
        if (!in_array($col, $names, true)) { 
            try { $pdo->exec($sql); } catch (Exception $e) {} 
        }
    }

    if (!in_array('username', $names, true)) {
        try { 
            $pdo->exec('ALTER TABLE users ADD COLUMN username TEXT'); 
        } catch (Exception $e) {}
        try { 
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username)'); 
        } catch (Exception $e) {}
    }

    create_group_tables($pdo);
    create_moderation_tables($pdo);
    create_reaction_tables($pdo);
    create_report_tables($pdo);
    create_block_logs($pdo);
    create_ad_tables($pdo);
    create_official_tables($pdo);
    create_pin_tables($pdo);
    create_ticket_tables($pdo);
    create_short_tables($pdo);
    create_story_tables($pdo);
    migrate_reports_target_kind($pdo);

    // ستون is_official در groups_
    try {
        $gcols = $pdo->query('PRAGMA table_info(groups_)')->fetchAll();
        $gnames = array_column($gcols, 'name');
    } catch (Exception $e) { $gnames = []; }

    if (!in_array('is_official', $gnames, true)) {
        try { $pdo->exec('ALTER TABLE groups_ ADD COLUMN is_official INTEGER NOT NULL DEFAULT 0'); } catch (Exception $e) {}
    }

    $gColumns = [
        'username' => 'ALTER TABLE groups_ ADD COLUMN username TEXT',
        'photo' => 'ALTER TABLE groups_ ADD COLUMN photo TEXT',
        'pinned_message_id' => 'ALTER TABLE groups_ ADD COLUMN pinned_message_id INTEGER',
        'banned' => 'ALTER TABLE groups_ ADD COLUMN banned INTEGER NOT NULL DEFAULT 0',
        'verified' => 'ALTER TABLE groups_ ADD COLUMN verified INTEGER NOT NULL DEFAULT 0',
    ];

    foreach ($gColumns as $col => $sql) {
        if (!in_array($col, $gnames, true)) { 
            try { $pdo->exec($sql); } catch (Exception $e) {} 
        }
    }

    try {
        $mcols = $pdo->query('PRAGMA table_info(messages)')->fetchAll();
        $mnames = array_column($mcols, 'name');
    } catch (Exception $e) { $mnames = []; }

    $mColumns = [
        'deleted' => 'ALTER TABLE messages ADD COLUMN deleted INTEGER NOT NULL DEFAULT 0',
        'updated_at' => 'ALTER TABLE messages ADD COLUMN updated_at TEXT',
        'reacted_at' => 'ALTER TABLE messages ADD COLUMN reacted_at TEXT',
        'reply_to_id' => 'ALTER TABLE messages ADD COLUMN reply_to_id INTEGER',
        'replied_at' => 'ALTER TABLE messages ADD COLUMN replied_at TEXT',
        'read_at' => 'ALTER TABLE messages ADD COLUMN read_at TEXT',
        'forward_kind' => 'ALTER TABLE messages ADD COLUMN forward_kind TEXT',
        'forward_chat_id' => 'ALTER TABLE messages ADD COLUMN forward_chat_id INTEGER',
        'forward_name' => 'ALTER TABLE messages ADD COLUMN forward_name TEXT',
        'forward_msg_id' => 'ALTER TABLE messages ADD COLUMN forward_msg_id INTEGER',
        'caption' => "ALTER TABLE messages ADD COLUMN caption TEXT NOT NULL DEFAULT ''",
        'file_name' => "ALTER TABLE messages ADD COLUMN file_name TEXT NOT NULL DEFAULT ''",
        'file_size' => 'ALTER TABLE messages ADD COLUMN file_size INTEGER NOT NULL DEFAULT 0',
    ];

    foreach ($mColumns as $col => $sql) {
        if (!in_array($col, $mnames, true)) { 
            try { $pdo->exec($sql); } catch (Exception $e) {} 
        }
    }

    try {
        $gmcols = $pdo->query('PRAGMA table_info(group_messages)')->fetchAll();
        $gmnames = array_column($gmcols, 'name');
    } catch (Exception $e) { $gmnames = []; }

    $gmColumns = [
        'deleted' => 'ALTER TABLE group_messages ADD COLUMN deleted INTEGER NOT NULL DEFAULT 0',
        'updated_at' => 'ALTER TABLE group_messages ADD COLUMN updated_at TEXT',
        'reacted_at' => 'ALTER TABLE group_messages ADD COLUMN reacted_at TEXT',
        'reply_to_id' => 'ALTER TABLE group_messages ADD COLUMN reply_to_id INTEGER',
        'replied_at' => 'ALTER TABLE group_messages ADD COLUMN replied_at TEXT',
        'views_count' => 'ALTER TABLE group_messages ADD COLUMN views_count INTEGER NOT NULL DEFAULT 0',
        'views_updated_at' => 'ALTER TABLE group_messages ADD COLUMN views_updated_at TEXT',
        'forward_kind' => 'ALTER TABLE group_messages ADD COLUMN forward_kind TEXT',
        'forward_chat_id' => 'ALTER TABLE group_messages ADD COLUMN forward_chat_id INTEGER',
        'forward_name' => 'ALTER TABLE group_messages ADD COLUMN forward_name TEXT',
        'forward_msg_id' => 'ALTER TABLE group_messages ADD COLUMN forward_msg_id INTEGER',
        'caption' => "ALTER TABLE group_messages ADD COLUMN caption TEXT NOT NULL DEFAULT ''",
        'file_name' => "ALTER TABLE group_messages ADD COLUMN file_name TEXT NOT NULL DEFAULT ''",
        'file_size' => 'ALTER TABLE group_messages ADD COLUMN file_size INTEGER NOT NULL DEFAULT 0',
    ];

    foreach ($gmColumns as $col => $sql) {
        if (!in_array($col, $gmnames, true)) { 
            try { $pdo->exec($sql); } catch (Exception $e) {} 
        }
    }

    try {
        $memcols = $pdo->query('PRAGMA table_info(group_members)')->fetchAll();
        $memnames = array_column($memcols, 'name');
    } catch (Exception $e) { $memnames = []; }

    if (!in_array('role', $memnames, true)) {
        try {
            $pdo->exec("ALTER TABLE group_members ADD COLUMN role TEXT NOT NULL DEFAULT 'member'");
            $pdo->exec("UPDATE group_members SET role = 'owner' WHERE user_id = (SELECT owner_id FROM groups_ WHERE groups_.id = group_members.group_id)");
        } catch (Exception $e) {}
    }

    // ایجاد کانال و گروه رسمی اگر وجود ندارند
    create_official_groups($pdo);

    // پاکسازی آواتارهای پیش‌فرض قدیمی (حرف اول ثابتِ ساخته‌شده در ثبت‌نام)
    // تا آواتار حرفی از این پس به‌صورت زنده و بر اساس نام فعلی در کلاینت ساخته شود
    cleanup_legacy_default_avatars($pdo);
}

function cleanup_legacy_default_avatars(PDO $pdo): void {
    try {
        $stmt = $pdo->prepare("SELECT id, avatar_photo FROM users WHERE avatar_photo LIKE 'data:image/svg+xml;base64,%' AND phone != ?");
        $stmt->execute([NOTIF_BOT_PHONE]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $b64 = substr($row['avatar_photo'], strlen('data:image/svg+xml;base64,'));
            $decoded = base64_decode($b64, true);
            if ($decoded === false) continue;
            // امضای مشخص خروجی get_default_avatar قدیمی: حرف بولد بدون هیچ ایموجی (برخلاف آواتار ربات)
            if (strpos($decoded, 'font-weight="bold"') !== false && strpos($decoded, 'rx="50"') !== false && mb_strpos($decoded, '🔔') === false) {
                $pdo->prepare('UPDATE users SET avatar_photo = NULL WHERE id = ?')->execute([$row['id']]);
            }
        }
    } catch (Exception $e) {}
}

function create_official_groups(PDO $pdo): void {
    // پیدا کردن ادمین اصلی
    $adminStmt = $pdo->prepare('SELECT id FROM users WHERE phone = ?');
    $adminStmt->execute([VERIFY_ADMIN_PHONE]);
    $admin = $adminStmt->fetch();
    
    if (!$admin) return;
    $adminId = (int)$admin['id'];
    
    // رنگ‌های رسمی
    $channelColor = '#0088ff';
    $groupColor = '#16a34a';
    
    // 1. ایجاد کانال رسمی
    $channelStmt = $pdo->prepare('SELECT id FROM groups_ WHERE username = ?');
    $channelStmt->execute([OFFICIAL_CHANNEL_USERNAME]);
    $channel = $channelStmt->fetch();
    
    if (!$channel) {
        $pdo->prepare('INSERT INTO groups_ (name, type, username, color, owner_id, is_official) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([OFFICIAL_CHANNEL_NAME, 'channel', OFFICIAL_CHANNEL_USERNAME, $channelColor, $adminId, 1]);
        $channelId = (int)$pdo->lastInsertId();
        
        // ثبت در جدول کانال‌های رسمی
        $pdo->prepare('INSERT INTO official_channels (group_id, type) VALUES (?, ?)')
            ->execute([$channelId, 'channel']);
        
        // اضافه کردن ادمین به عنوان عضو و مالک
        $pdo->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$channelId, $adminId]);
    } else {
        $channelId = (int)$channel['id'];
    }
    
    // 2. ایجاد گروه رسمی
    $groupStmt = $pdo->prepare('SELECT id FROM groups_ WHERE username = ?');
    $groupStmt->execute([OFFICIAL_GROUP_USERNAME]);
    $group = $groupStmt->fetch();
    
    if (!$group) {
        $pdo->prepare('INSERT INTO groups_ (name, type, username, color, owner_id, is_official) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([OFFICIAL_GROUP_NAME, 'group', OFFICIAL_GROUP_USERNAME, $groupColor, $adminId, 1]);
        $groupId = (int)$pdo->lastInsertId();
        
        // ثبت در جدول کانال‌های رسمی
        $pdo->prepare('INSERT INTO official_channels (group_id, type) VALUES (?, ?)')
            ->execute([$groupId, 'group']);
        
        // اضافه کردن ادمین به عنوان عضو و مالک
        $pdo->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$groupId, $adminId]);
    } else {
        $groupId = (int)$group['id'];
    }
}

// ============================================================
// حساب سیستمی «اعلانات اکانت» + ارسال پیام از طرف ربات
// ============================================================

function ensure_notif_bot(PDO $pdo): int {
    static $cached = null;
    if ($cached !== null) return $cached;

    $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = ?');
    $stmt->execute([NOTIF_BOT_PHONE]);
    $row = $stmt->fetch();
    if ($row) {
        $cached = (int)$row['id'];
        return $cached;
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">'
         . '<rect width="200" height="200" fill="#0088ff" rx="50"/>'
         . '<text x="50%" y="58%" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="90" fill="#ffffff">🔔</text>'
         . '</svg>';
    $avatar = 'data:image/svg+xml;base64,' . base64_encode($svg);

    try {
        $pdo->prepare('INSERT INTO users (phone, username, name, password_hash, color, bio, avatar_photo, verified, is_official, show_phone_visible, last_seen_visible) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 0, 0)')
            ->execute([
                NOTIF_BOT_PHONE,
                NOTIF_BOT_USERNAME,
                NOTIF_BOT_NAME,
                password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                '#0088ff',
                'اعلان‌های امنیتی و پیام‌های خودکار حساب شما',
                $avatar,
            ]);
        $cached = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        // در صورت وجود همزمان (race)، دوباره بخوان
        $stmt->execute([NOTIF_BOT_PHONE]);
        $row = $stmt->fetch();
        $cached = $row ? (int)$row['id'] : 0;
    }
    return $cached;
}

function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $val = $_SERVER[$key];
            if (strpos($val, ',') !== false) {
                $parts = explode(',', $val);
                $val = trim($parts[0]);
            }
            if ($val !== '') return $val;
        }
    }
    return 'نامشخص';
}

function send_bot_message(int $userId, string $body): void {
    $pdo = db();
    $botId = ensure_notif_bot($pdo);
    if ($botId <= 0 || $botId === $userId) return;
    $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, type, body) VALUES (?, ?, ?, ?)')
        ->execute([$botId, $userId, 'text', $body]);
}

function send_login_notification(int $userId, string $ip, string $userAgent): void {
    try {
        $device = 'دستگاه نامشخص';
        $ua = mb_strtolower($userAgent);
        if (strpos($ua, 'android') !== false) $device = 'اندروید';
        elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) $device = 'آیفون/آیپد';
        elseif (strpos($ua, 'windows') !== false) $device = 'ویندوز';
        elseif (strpos($ua, 'mac os') !== false) $device = 'مک';
        elseif (strpos($ua, 'linux') !== false) $device = 'لینوکس';

        $time = date('Y/m/d - H:i');
        $body = "🟢 ورود جدید به حساب شما\n\n"
              . "📍 آی‌پی: " . $ip . "\n"
              . "📱 دستگاه: " . $device . "\n"
              . "🕐 زمان: " . $time . "\n\n"
              . "اگر خودتان وارد شدید نیازی به کاری نیست 🙌\n"
              . "اگر این ورود از طرف شما نبوده، همین حالا رمز عبورتان را از بخش «حریم خصوصی و امنیت» تغییر دهید.";
        send_bot_message($userId, $body);
    } catch (Exception $e) {}
}

// ============================================================
// تابع دریافت تبلیغ فعال
// ============================================================

// ============================================================
// پوشه‌بندی چت‌ها (فولدرها)
// ============================================================

define('MAX_CUSTOM_FOLDERS', 12);

function get_user_folders(array $user): array {
    $raw = $user['folders_json'] ?? '[]';
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $f) {
        if (!is_array($f) || empty($f['id'])) continue;
        $chats = [];
        if (!empty($f['chats']) && is_array($f['chats'])) {
            foreach ($f['chats'] as $c) {
                if (!is_array($c)) continue;
                $kind = ($c['kind'] ?? '') === 'group' ? 'group' : 'dm';
                $id = (int)($c['id'] ?? 0);
                if ($id > 0) $chats[] = ['kind' => $kind, 'id' => $id];
            }
        }
        $out[] = [
            'id' => (string)$f['id'],
            'name' => mb_substr(trim((string)($f['name'] ?? 'پوشه')), 0, 30),
            'chats' => $chats,
        ];
    }
    return $out;
}

function save_user_folders(int $userId, array $folders): void {
    $clean = [];
    foreach (array_slice($folders, 0, MAX_CUSTOM_FOLDERS) as $f) {
        $chats = [];
        if (!empty($f['chats']) && is_array($f['chats'])) {
            foreach (array_slice($f['chats'], 0, 300) as $c) {
                if (!is_array($c)) continue;
                $kind = ($c['kind'] ?? '') === 'group' ? 'group' : 'dm';
                $id = (int)($c['id'] ?? 0);
                if ($id > 0) $chats[] = ['kind' => $kind, 'id' => $id];
            }
        }
        $clean[] = [
            'id' => (string)$f['id'],
            'name' => mb_substr(trim((string)($f['name'] ?? 'پوشه')), 0, 30),
            'chats' => $chats,
        ];
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    db()->prepare('UPDATE users SET folders_json = ? WHERE id = ?')->execute([$json, $userId]);
}

function generate_folder_id(): string {
    return 'f_' . bin2hex(random_bytes(6));
}

function get_active_ad(): ?array {
    $ads = get_active_ads(1);
    return $ads[0] ?? null;
}

function get_active_ads(int $limit = 20): array {
    try {
        $stmt = db()->prepare('SELECT * FROM ads WHERE is_active = 1 ORDER BY created_at ASC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Exception $e) {
        return [];
    }
}
