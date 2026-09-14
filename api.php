<?php
/**
 * سیک چت - API کامل با کانال رسمی و تبلیغات
 */

declare(strict_types=1);
require __DIR__ . '/config.php';

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {

        // ==================== احراز هویت ====================
        case 'register': {
            $in = input();
            $name = trim((string)($in['name'] ?? ''));
            $phone = normalize_phone((string)($in['phone'] ?? ''));
            $password = (string)($in['password'] ?? '');

            if (mb_strlen($name) < 2) fail('نام باید حداقل ۲ حرف باشد.');
            if (!preg_match('/^09\d{9}$/', $phone)) fail('شماره موبایل معتبر نیست.');
            if (mb_strlen($password) < 4) fail('رمز عبور باید حداقل ۴ کاراکتر باشد.');
            
            if (is_phone_banned($phone)) {
                fail('شما از پیام‌رسان سیک شدید.', 403);
            }

            $exists = db()->prepare('SELECT id FROM users WHERE phone = ?');
            $exists->execute([$phone]);
            if ($exists->fetch()) fail('این شماره قبلاً ثبت‌نام کرده است.');

            $colors = ['#0088ff', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
            $color = $colors[array_rand($colors)];

            $isAdmin = ($phone === VERIFY_ADMIN_PHONE);
            $verified = $isAdmin ? 1 : 0;
            $isOfficial = $isAdmin ? 1 : 0;

            $pdo = db();
            $pdo->beginTransaction();
            
            try {
                $stmt = $pdo->prepare('INSERT INTO users (phone, name, password_hash, color, verified, is_official, show_phone_visible) VALUES (?, ?, ?, ?, ?, ?, 0)');
                $stmt->execute([$phone, $name, password_hash($password, PASSWORD_DEFAULT), $color, $verified, $isOfficial]);
                $userId = (int)$pdo->lastInsertId();

                // اضافه کردن کاربر به کانال و گروه رسمی
                add_user_to_official_channels($pdo, $userId);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                fail('خطا در ثبت‌نام: ' . $e->getMessage(), 500);
            }

            $token = start_session_for($userId);
            $user = fetch_user($userId);
            respond([
                'ok' => true,
                'token' => $token,
                'user' => public_user($user, true, true),
                'is_verify_admin' => $isAdmin,
                'is_admin' => $isAdmin
            ]);
        }

        case 'login': {
            $in = input();
            $phone = normalize_phone((string)($in['phone'] ?? ''));
            $password = (string)($in['password'] ?? '');

            if ($phone === '' || $password === '') fail('شماره موبایل و رمز عبور را وارد کنید.');
            
            if (is_phone_banned($phone)) {
                fail('شما از پیام‌رسان سیک شدید.', 403);
            }

            $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
            $stmt->execute([$phone]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                fail('شماره موبایل یا رمز عبور اشتباه است.', 401);
            }
            
            if (is_user_suspended((int)$user['id'])) {
                fail('شما از پیام‌رسان سیک شدید.', 403);
            }

            $token = start_session_for((int)$user['id']);
            $isAdmin = ($user['phone'] === VERIFY_ADMIN_PHONE) || ((int)$user['verified'] === 1);
            
            // اطمینان از عضویت در کانال‌های رسمی
            $pdo = db();
            add_user_to_official_channels($pdo, (int)$user['id']);

            // ارسال پیوی خودکار «اعلانات اکانت» با جزئیات ورود
            send_login_notification((int)$user['id'], client_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            
            respond([
                'ok' => true,
                'token' => $token,
                'user' => public_user($user, true, true),
                'is_verify_admin' => $isAdmin,
                'is_admin' => $isAdmin
            ]);
        }

        case 'logout': {
            $token = isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : (isset($_SESSION['token']) ? $_SESSION['token'] : null);
            if ($token) {
                try {
                    $sstmt = db()->prepare('SELECT user_id FROM sessions WHERE token = ?');
                    $sstmt->execute([$token]);
                    $srow = $sstmt->fetch();
                    if ($srow) {
                        // آخرین بازدید را عقب می‌بریم تا بلافاصله بعد از خروج، وضعیت آفلاین نمایش داده شود (منتظر گذشتن ۶۰ ثانیه نمانیم)
                        db()->prepare("UPDATE users SET last_seen = datetime('now', '-2 minutes') WHERE id = ?")->execute([$srow['user_id']]);
                    }
                    db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
                } catch (Exception $e) {}
            }
            $_SESSION = [];
            respond(['ok' => true]);
        }

        case 'me': {
            $user = require_auth();
            $token = isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : (isset($_SESSION['token']) ? $_SESSION['token'] : null);
            $userData = public_user($user, true, true);
            $userData['status'] = get_user_status($user);
            $isAdmin = ($user['phone'] === VERIFY_ADMIN_PHONE) || ((int)$user['verified'] === 1);
            
            // دریافت تبلیغات فعال
            $ads = get_active_ads();
            
            respond([
                'ok' => true,
                'user' => $userData,
                'token' => $token,
                'is_verify_admin' => $isAdmin,
                'is_admin' => $isAdmin,
                'active_ads' => $ads
            ]);
        }

        // ==================== پروفایل ====================
        case 'profile_update': {
            $me = require_auth();
            $in = input();

            $fields = [];
            $params = [];

            if (isset($in['name'])) {
                $name = trim((string)$in['name']);
                if (mb_strlen($name) < 2) fail('نام باید حداقل ۲ حرف باشد.');
                $fields[] = 'name = ?';
                $params[] = $name;
            }
            if (isset($in['bio'])) {
                $bio = trim((string)$in['bio']);
                if (mb_strlen($bio) > 200) fail('بیوگرافی خیلی طولانی است.');
                $fields[] = 'bio = ?';
                $params[] = $bio;
            }
            if (isset($in['username'])) {
                $raw = (string)$in['username'];
                if (trim($raw) === '') fail('آیدی نمی‌تواند خالی باشد.');
                $username = normalize_username($raw);
                if (!valid_username_format($username)) {
                    fail('آیدی باید با حرف انگلیسی شروع شود، فقط شامل حروف/عدد/آندرلاین باشد و بین ۵ تا ۳۲ کاراکتر باشد.');
                }
                $exists = db()->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
                $exists->execute([$username, $me['id']]);
                if ($exists->fetch()) fail('این آیدی قبلاً گرفته شده.');
                $fields[] = 'username = ?';
                $params[] = $username;
            }
            if (isset($in['avatar_photo'])) {
                $photo = (string)$in['avatar_photo'];
                if ($photo !== '' && strlen($photo) > 4_000_000) fail('حجم عکس خیلی زیاد است.');
                if ($photo !== '' && !str_starts_with($photo, 'data:image/')) fail('فرمت عکس نامعتبر است.');
                $fields[] = 'avatar_photo = ?';
                $params[] = $photo === '' ? null : $photo;
            }
            if (isset($in['theme'])) {
                $theme = in_array($in['theme'], ['light', 'dark']) ? $in['theme'] : 'light';
                $fields[] = 'theme = ?';
                $params[] = $theme;
            }
            if (isset($in['premium_name'])) {
                $isEligible = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
                if (!$isEligible) fail('این قابلیت فعلاً فقط برای مدیر اصلی و ادمین‌های دارای تیک آبی فعال است.', 403);
                $premiumName = trim((string)$in['premium_name']);
                if (mb_strlen($premiumName) > 40) fail('نام نمایشی خیلی طولانی است.');
                $fields[] = 'premium_name = ?';
                $params[] = $premiumName;
            }

            if (empty($fields)) fail('هیچ تغییری ارسال نشده.');
            $params[] = $me['id'];
            db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

            $updated = fetch_user((int)$me['id']);
            respond(['ok' => true, 'user' => public_user($updated, true, true)]);
        }

        case 'password_change': {
            $me = require_auth();
            $in = input();
            $old = (string)($in['old_password'] ?? '');
            $new = (string)($in['new_password'] ?? '');

            if (!password_verify($old, $me['password_hash'])) fail('رمز فعلی اشتباه است.');
            if (mb_strlen($new) < 4) fail('رمز جدید باید حداقل ۴ کاراکتر باشد.');

            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);

            // خروج اجباری از تمام دستگاه‌های دیگر (به‌جز همینی که رمز رو الان عوض کرد)
            $currentToken = isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : (isset($_SESSION['token']) ? $_SESSION['token'] : null);
            $loggedOutOthers = 0;
            try {
                $delStmt = db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token != ?');
                $delStmt->execute([(int)$me['id'], (string)$currentToken]);
                $loggedOutOthers = $delStmt->rowCount();
            } catch (Exception $e) {}

            // اطلاع‌رسانی از طریق اعلانات اکانت
            try {
                $body = "🔒 رمز عبور حساب شما تغییر کرد.\n\n"
                      . "🕐 زمان: " . date('Y/m/d - H:i') . "\n"
                      . "📍 آی‌پی: " . client_ip() . "\n";
                if ($loggedOutOthers > 0) {
                    $body .= "\n🚪 برای امنیت بیشتر، تمام دستگاه‌های دیگر شما (" . $loggedOutOthers . " دستگاه) از حساب خارج شدند.";
                }
                send_bot_message((int)$me['id'], $body);
            } catch (Exception $e) {}

            respond(['ok' => true, 'logged_out_others' => $loggedOutOthers]);
        }

        case 'account_delete': {
            $me = require_auth();
            $in = input();
            $password = (string)($in['password'] ?? '');

            if (!password_verify($password, $me['password_hash'])) fail('رمز عبور اشتباه است.');
            if ($me['phone'] === VERIFY_ADMIN_PHONE) fail('ادمین اصلی نمی‌تواند حساب خود را حذف کند.', 403);
            if ((int)$me['is_official'] === 1) fail('حساب‌های رسمی قابل حذف نیستند.', 403);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                // گروه‌ها/کانال‌هایی که این کاربر مالک آن‌هاست را قبل از حذف حساب مدیریت می‌کنیم
                $gstmt = $pdo->prepare('SELECT * FROM groups_ WHERE owner_id = ?');
                $gstmt->execute([$me['id']]);
                $ownedGroups = $gstmt->fetchAll();

                foreach ($ownedGroups as $group) {
                    if ((int)$group['is_official'] === 1) {
                        throw new Exception('تا زمانی که مالک یک گروه/کانال رسمی هستی نمی‌توانی حساب را حذف کنی؛ ابتدا مالکیت آن را منتقل کن.');
                    }
                    $mstmt = $pdo->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND user_id != ? ORDER BY (role = 'admin') DESC, joined_at ASC LIMIT 1");
                    $mstmt->execute([$group['id'], $me['id']]);
                    $newOwnerId = $mstmt->fetchColumn();
                    if ($newOwnerId) {
                        $pdo->prepare('UPDATE groups_ SET owner_id = ? WHERE id = ?')->execute([$newOwnerId, $group['id']]);
                        $pdo->prepare("UPDATE group_members SET role = 'owner' WHERE group_id = ? AND user_id = ?")->execute([$group['id'], $newOwnerId]);
                    } else {
                        $pdo->prepare('DELETE FROM groups_ WHERE id = ?')->execute([$group['id']]);
                    }
                }

                // انتقال رکوردهای تعلیقی که این کاربر ثبت کرده به ادمین اصلی، تا با حذف حساب لغو نشوند
                $adminStmt = $pdo->prepare('SELECT id FROM users WHERE phone = ?');
                $adminStmt->execute([VERIFY_ADMIN_PHONE]);
                $adminId = $adminStmt->fetchColumn();
                if ($adminId) {
                    $pdo->prepare('UPDATE suspensions SET suspended_by = ? WHERE suspended_by = ? AND user_id != ?')
                        ->execute([$adminId, $me['id'], $me['id']]);
                }

                // پاکسازی جدول‌هایی که بدون کلید خارجی به کاربر ارجاع می‌دهند
                $pdo->prepare('DELETE FROM dm_pins WHERE user_a = ? OR user_b = ? OR pinned_by = ?')
                    ->execute([$me['id'], $me['id'], $me['id']]);
                $pdo->prepare('DELETE FROM message_views WHERE user_id = ?')->execute([$me['id']]);

                // حذف نهایی حساب کاربری (بقیه‌ی جدول‌ها با ON DELETE CASCADE پاک می‌شوند)
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$me['id']]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                fail($e->getMessage() ?: 'خطا در حذف حساب رخ داد.', 500);
            }

            $_SESSION = [];
            respond(['ok' => true]);
        }

        case 'privacy_update': {
            $me = require_auth();
            $in = input();
            $fields = [];
            $params = [];
            if (array_key_exists('last_seen_visible', $in)) {
                $fields[] = 'last_seen_visible = ?';
                $params[] = !empty($in['last_seen_visible']) ? 1 : 0;
            }
            if (array_key_exists('show_phone_visible', $in)) {
                $fields[] = 'show_phone_visible = ?';
                $params[] = !empty($in['show_phone_visible']) ? 1 : 0;
            }
            if (empty($fields)) fail('هیچ تغییری ارسال نشده.');
            $params[] = $me['id'];
            db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
            respond(['ok' => true]);
        }

        // مدیر اصلی کل پیام‌رسان و ادمین‌های تیک‌آبی می‌توانند خودشان پرمیومِ خودکارِ خودشان را فعال/غیرفعال کنند
        case 'premium_self_toggle': {
            $me = require_auth();
            if (!can_self_toggle_premium($me['phone'] ?? null, $me['verified'] ?? 0)) {
                fail('این قابلیت فقط برای مدیر اصلی و ادمین‌های دارای تیک آبی فعال است.', 403);
            }
            $in = input();
            $enabled = !empty($in['enabled']) ? 1 : 0; // 1 یعنی پرمیوم روشن (حالت عادی)، 0 یعنی خودش خاموشش کرده
            db()->prepare('UPDATE users SET premium_self_off = ? WHERE id = ?')->execute([$enabled ? 0 : 1, $me['id']]);
            $updated = fetch_user((int)$me['id']);
            respond(['ok' => true, 'user' => public_user($updated, true, true)]);
        }

        // ==================== پوشه‌بندی چت‌ها ====================
        case 'folders_list': {
            $me = require_auth();
            respond(['ok' => true, 'folders' => get_user_folders($me)]);
        }

        case 'folder_create': {
            $me = require_auth();
            $in = input();
            $folders = get_user_folders($me);
            if (count($folders) >= MAX_CUSTOM_FOLDERS) {
                fail('حداکثر تعداد پوشه‌ها ساخته شده است.');
            }
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '') $name = 'پوشه ' . (count($folders) + 1);
            $chats = [];
            if (!empty($in['chats']) && is_array($in['chats'])) {
                foreach ($in['chats'] as $c) {
                    if (!is_array($c)) continue;
                    $kind = ($c['kind'] ?? '') === 'group' ? 'group' : 'dm';
                    $id = (int)($c['id'] ?? 0);
                    if ($id > 0) $chats[] = ['kind' => $kind, 'id' => $id];
                }
            }
            $newFolder = ['id' => generate_folder_id(), 'name' => mb_substr($name, 0, 30), 'chats' => $chats];
            $folders[] = $newFolder;
            save_user_folders((int)$me['id'], $folders);
            respond(['ok' => true, 'folder' => $newFolder, 'folders' => get_user_folders(fetch_user((int)$me['id']))]);
        }

        case 'folder_update': {
            $me = require_auth();
            $in = input();
            $id = (string)($in['id'] ?? '');
            if ($id === '') fail('پوشه نامعتبر است.');
            $folders = get_user_folders($me);
            $found = false;
            foreach ($folders as &$f) {
                if ($f['id'] !== $id) continue;
                $found = true;
                if (isset($in['name'])) {
                    $name = trim((string)$in['name']);
                    if ($name === '') fail('نام پوشه نمی‌تواند خالی باشد.');
                    $f['name'] = mb_substr($name, 0, 30);
                }
                if (isset($in['chats']) && is_array($in['chats'])) {
                    $chats = [];
                    foreach ($in['chats'] as $c) {
                        if (!is_array($c)) continue;
                        $kind = ($c['kind'] ?? '') === 'group' ? 'group' : 'dm';
                        $cid = (int)($c['id'] ?? 0);
                        if ($cid > 0) $chats[] = ['kind' => $kind, 'id' => $cid];
                    }
                    $f['chats'] = $chats;
                }
                break;
            }
            unset($f);
            if (!$found) fail('پوشه پیدا نشد.', 404);
            save_user_folders((int)$me['id'], $folders);
            respond(['ok' => true, 'folders' => get_user_folders(fetch_user((int)$me['id']))]);
        }

        case 'folder_reorder': {
            $me = require_auth();
            $in = input();
            $order = is_array($in['order'] ?? null) ? $in['order'] : [];
            $folders = get_user_folders($me);
            $byId = [];
            foreach ($folders as $f) { $byId[$f['id']] = $f; }
            $reordered = [];
            foreach ($order as $id) {
                $id = (string)$id;
                if (isset($byId[$id])) { $reordered[] = $byId[$id]; unset($byId[$id]); }
            }
            foreach ($byId as $rest) { $reordered[] = $rest; }
            save_user_folders((int)$me['id'], $reordered);
            respond(['ok' => true, 'folders' => get_user_folders(fetch_user((int)$me['id']))]);
        }

        case 'folder_delete': {
            $me = require_auth();
            $in = input();
            $id = (string)($in['id'] ?? '');
            if ($id === '') fail('پوشه نامعتبر است.');
            $folders = get_user_folders($me);
            $filtered = array_values(array_filter($folders, function ($f) use ($id) { return $f['id'] !== $id; }));
            if (count($filtered) === count($folders)) fail('پوشه پیدا نشد.', 404);
            save_user_folders((int)$me['id'], $filtered);
            respond(['ok' => true, 'folders' => get_user_folders(fetch_user((int)$me['id']))]);
        }

        // ==================== جستجو ====================
        case 'user_search': {
            $me = require_auth();

            $usernameQ = isset($_GET['username']) ? (string)$_GET['username'] : '';
            if ($usernameQ !== '') {
                $username = normalize_username($usernameQ);
                if (!valid_username_format($username)) fail('آیدی نامعتبر است.');
                $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                if ($user && (int)$user['id'] === (int)$me['id']) fail('این آیدی خودت است.');
                respond(['ok' => true, 'user' => $user ? public_user($user, true, false) : null]);
            }

            $phone = normalize_phone((string)(isset($_GET['phone']) ? $_GET['phone'] : ''));
            if ($phone === '') fail('شماره یا آیدی را وارد کنید.');
            $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
            $stmt->execute([$phone]);
            $user = $stmt->fetch();
            // bypassPrivacy=false: اگر طرف مقابل شماره‌اش را در تنظیمات حریم خصوصی مخفی کرده، اینجا هم نباید فاش شود
            respond(['ok' => true, 'user' => $user ? public_user($user, true, false) : null]);
        }

        case 'find_by_id': {
            $me = require_auth();
            $raw = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
            if ($raw === '') fail('یک آیدی یا شماره موبایل وارد کن.');

            $looksLikePhone = !str_starts_with($raw, '@') && (bool)preg_match('/^[0-9\s\-+()]+$/', $raw)
                && strlen(preg_replace('/\D+/', '', $raw)) >= 10;

            if ($looksLikePhone) {
                $phone = normalize_phone($raw);
                if (!preg_match('/^09\d{9}$/', $phone)) fail('شماره موبایل معتبر نیست.');
                if ($phone === $me['phone']) fail('این شماره خودت است.');
                $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
                $stmt->execute([$phone]);
                $user = $stmt->fetch();
                if (!$user) respond(['ok' => true, 'kind' => null]);
                $userData = public_user($user, true, true);
                $userData['status'] = get_user_status($user);
                respond(['ok' => true, 'kind' => 'user', 'user' => $userData]);
            }

            $username = normalize_username($raw);
            if (!valid_username_format($username)) {
                fail('آیدی باید با @ و حرف انگلیسی شروع شود (حداقل ۵ کاراکتر)، یا یک شماره موبایل معتبر باشد.');
            }

            $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user) {
                if ((int)$user['id'] === (int)$me['id']) fail('این آیدی خودت است.');
                $userData = public_user($user, true, false);
                $userData['status'] = get_user_status($user);
                respond(['ok' => true, 'kind' => 'user', 'user' => $userData]);
            }

            $stmt = db()->prepare("SELECT * FROM groups_ WHERE username = ?");
            $stmt->execute([$username]);
            $group = $stmt->fetch();
            if ($group) {
                $isChannel = ($group['type'] ?? 'group') === 'channel';
                if (is_group_banned((int)$group['id'])) fail('این ' . ($isChannel ? 'کانال' : 'گروه') . ' سیک شده است.', 403);
                $memberChk = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
                $memberChk->execute([$group['id'], $me['id']]);
                $channel = public_group($group);
                $channel['is_member'] = (bool)$memberChk->fetch();
                $channel['is_official'] = (int)($group['is_official'] ?? 0) === 1;
                respond(['ok' => true, 'kind' => $isChannel ? 'channel' : 'group', 'channel' => $channel]);
            }

            respond(['ok' => true, 'kind' => null]);
        }

        case 'user_profile': {
            $me = require_auth();
            $userId = (int)(isset($_GET['id']) ? $_GET['id'] : (isset($_GET['user']) ? $_GET['user'] : 0));
            if ($userId <= 0) fail('کاربر نامعتبر است.');

            $user = fetch_user($userId);
            if (!$user) fail('کاربر پیدا نشد.', 404);

            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            // فقط ادمین اصلی پیام‌رسان می‌تواند شماره‌ی مخفی‌شده را ببیند؛ کاربران تیک‌آبی‌دار عادی نباید
            // بتوانند حریم خصوصی «نمایش شماره به دیگران» را دور بزنند.
            $canBypassPhonePrivacy = ($me['phone'] === VERIFY_ADMIN_PHONE);
            $out = public_user($user, true, $canBypassPhonePrivacy);
            $status = get_user_status($user);
            $out['status'] = $status;
            $out['is_official'] = (int)($user['is_official'] ?? 0) === 1;
            
            if ($isAdmin) {
                $out['banned'] = $status['banned'];
                $out['suspended'] = $status['suspended'];
                $out['sik_reason'] = $status['sik_reason'];
            }
            
            respond(['ok' => true, 'user' => $out]);
        }

        // ==================== مدیریت تیک آبی ====================
        case 'verify_set': {
            $me = require_auth();
            if ($me['phone'] !== VERIFY_ADMIN_PHONE) fail('فقط ادمین اصلی می‌تواند تیک آبی بدهد یا ادمین تعیین کند.', 403);

            $in = input();
            $targetId = (int)($in['id'] ?? 0);
            $verified = !empty($in['verified']) ? 1 : 0;
            if ($targetId <= 0) fail('کاربر نامعتبر است.');

            $target = fetch_user($targetId);
            if (!$target) fail('کاربر پیدا نشد.', 404);

            db()->prepare('UPDATE users SET verified = ? WHERE id = ?')->execute([$verified, $targetId]);
            $target = fetch_user($targetId);

            respond(['ok' => true, 'user' => public_user($target)]);
        }

        // تیک آبی برای کانال/گروه (فقط جنبه‌ی نمایشی دارد؛ برخلاف تیک کاربر، دسترسی ادمین نمی‌دهد)
        case 'group_verify_set': {
            $me = require_auth();
            if ($me['phone'] !== VERIFY_ADMIN_PHONE) fail('فقط ادمین اصلی می‌تواند تیک آبی بدهد.', 403);

            $in = input();
            $groupId = (int)($in['id'] ?? 0);
            $verified = !empty($in['verified']) ? 1 : 0;
            if ($groupId <= 0) fail('گروه/کانال نامعتبر است.');

            $gstmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $gstmt->execute([$groupId]);
            $group = $gstmt->fetch();
            if (!$group) fail('گروه/کانال پیدا نشد.', 404);

            db()->prepare('UPDATE groups_ SET verified = ? WHERE id = ?')->execute([$verified, $groupId]);
            $gstmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $gstmt->execute([$groupId]);

            respond(['ok' => true, 'group' => public_group($gstmt->fetch())]);
        }

        // ==================== مدیریت بن ====================
        case 'phone_ban': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه‌ی این کار را نداری.', 403);

            $in = input();
            $targetId = (int)($in['id'] ?? 0);
            $reason = trim((string)($in['reason'] ?? 'تخلف از قوانین'));
            if ($targetId <= 0) fail('کاربر نامعتبر است.');
            if ($targetId === (int)$me['id']) fail('نمی‌توانی خودت را سیک کنی.');

            $target = fetch_user($targetId);
            if (!$target) fail('کاربر پیدا نشد.', 404);
            if ($target['phone'] === VERIFY_ADMIN_PHONE) fail('این شماره قابل سیک شدن نیست.');
            if ((int)($target['is_official'] ?? 0) === 1) fail('اکانت رسمی قابل سیک شدن نیست.');

            db()->prepare('INSERT OR IGNORE INTO banned_phones (phone, banned_by, reason) VALUES (?, ?, ?)')
                ->execute([$target['phone'], $me['id'], $reason]);
            db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$targetId]);

            db()->prepare('INSERT INTO block_logs (blocker_id, blocked_id, action) VALUES (?, ?, ?)')
                ->execute([$me['id'], $targetId, 'block']);

            $out = public_user($target);
            $out['status'] = get_user_status($target);
            respond(['ok' => true, 'user' => $out, 'message' => 'کاربر از پیام‌رسان سیک شد.']);
        }

        case 'phone_unban': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه‌ی این کار را نداری.', 403);

            $in = input();
            $targetId = (int)($in['id'] ?? 0);
            if ($targetId <= 0) fail('کاربر نامعتبر است.');

            $target = fetch_user($targetId);
            if (!$target) fail('کاربر پیدا نشد.', 404);

            db()->prepare('DELETE FROM banned_phones WHERE phone = ?')->execute([$target['phone']]);

            db()->prepare('INSERT INTO block_logs (blocker_id, blocked_id, action) VALUES (?, ?, ?)')
                ->execute([$me['id'], $targetId, 'unblock']);

            $out = public_user($target);
            $out['status'] = get_user_status($target);
            respond(['ok' => true, 'user' => $out, 'message' => 'سیک کاربر برداشته شد.']);
        }

        case 'admin_suspend_user': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $userId = (int)($in['id'] ?? 0);
            $reason = trim((string)($in['reason'] ?? 'تخلف از قوانین'));
            if ($userId <= 0) fail('کاربر نامعتبر است.');
            if ($userId === (int)$me['id']) fail('نمی‌توانی خودت را سیک کنی.');

            $target = fetch_user($userId);
            if (!$target) fail('کاربر پیدا نشد.', 404);
            if ($target['phone'] === VERIFY_ADMIN_PHONE) fail('نمی‌توان ادمین را سیک کرد.');
            if ((int)($target['is_official'] ?? 0) === 1) fail('اکانت رسمی قابل سیک شدن نیست.');

            db()->prepare('INSERT OR REPLACE INTO suspensions (user_id, reason, suspended_by) VALUES (?, ?, ?)')
                ->execute([$userId, $reason, $me['id']]);
            db()->prepare('UPDATE users SET suspended = 1 WHERE id = ?')->execute([$userId]);
            db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);

            db()->prepare('INSERT INTO block_logs (blocker_id, blocked_id, action) VALUES (?, ?, ?)')
                ->execute([$me['id'], $userId, 'block']);

            $out = public_user($target);
            $out['status'] = get_user_status($target);
            respond(['ok' => true, 'user' => $out, 'message' => 'اکانت کاربر از پیام‌رسان سیک شد.']);
        }

        case 'admin_unsuspend_user': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $userId = (int)($in['id'] ?? 0);
            if ($userId <= 0) fail('کاربر نامعتبر است.');

            db()->prepare('DELETE FROM suspensions WHERE user_id = ?')->execute([$userId]);
            db()->prepare('UPDATE users SET suspended = 0 WHERE id = ?')->execute([$userId]);

            db()->prepare('INSERT INTO block_logs (blocker_id, blocked_id, action) VALUES (?, ?, ?)')
                ->execute([$me['id'], $userId, 'unblock']);

            $target = fetch_user($userId);
            $out = public_user($target);
            $out['status'] = get_user_status($target);
            respond(['ok' => true, 'user' => $out, 'message' => 'سیک کاربر برداشته شد.']);
        }

        // ==================== تغییر رمز عبور کاربر توسط ادمین اصلی (مثلاً در صورت هک شدن اکانت) ====================
        case 'admin_change_password': {
            $me = require_auth();
            // این عملیات فقط برای ادمین اصلی کل پیام‌رسان مجاز است، نه کاربران تیک‌آبی
            if ($me['phone'] !== VERIFY_ADMIN_PHONE) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $userId = (int)($in['id'] ?? 0);
            $newPassword = (string)($in['new_password'] ?? '');
            if ($userId <= 0) fail('کاربر نامعتبر است.');
            if (mb_strlen($newPassword) < 4) fail('رمز جدید باید حداقل ۴ کاراکتر باشد.');

            $target = fetch_user($userId);
            if (!$target) fail('کاربر پیدا نشد.', 404);
            if ($target['phone'] === VERIFY_ADMIN_PHONE) fail('رمز ادمین اصلی از این بخش قابل تغییر نیست.');

            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

            // خروج اجباری از تمام دستگاه‌های کاربر (مهم برای مواقع هک شدن اکانت)
            $loggedOutSessions = 0;
            try {
                $delStmt = db()->prepare('DELETE FROM sessions WHERE user_id = ?');
                $delStmt->execute([$userId]);
                $loggedOutSessions = $delStmt->rowCount();
            } catch (Exception $e) {}

            // اطلاع‌رسانی به کاربر از طریق اعلانات اکانت
            try {
                $body = "🔒 رمز عبور حساب شما توسط ادمین تغییر کرد.\n\n"
                      . "🕐 زمان: " . date('Y/m/d - H:i') . "\n"
                      . "🚪 برای امنیت حساب، تمام دستگاه‌های شما از حساب خارج شدند.\n"
                      . "در صورتی که این تغییر توسط خودتان درخواست نشده، با پشتیبانی تماس بگیرید.";
                send_bot_message($userId, $body);
            } catch (Exception $e) {}

            $out = public_user($target);
            $out['status'] = get_user_status($target);
            respond(['ok' => true, 'user' => $out, 'logged_out_sessions' => $loggedOutSessions, 'message' => 'رمز عبور کاربر با موفقیت تغییر کرد.']);
        }

        // ==================== بلاک کردن کاربر در پیوی ====================
        case 'user_block': {
            $me = require_auth();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0 || $id === (int)$me['id']) fail('کاربر نامعتبر است.');

            $target = fetch_user($id);
            if (!$target) fail('کاربر پیدا نشد.', 404);
            if ((int)($target['is_official'] ?? 0) === 1) fail('اکانت رسمی قابل سیک شدن نیست.');

            db()->prepare('INSERT OR IGNORE INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)')
                ->execute([$me['id'], $id]);
            
            db()->prepare('INSERT INTO block_logs (blocker_id, blocked_id, action) VALUES (?, ?, ?)')
                ->execute([$me['id'], $id, 'block']);

            respond(['ok' => true, 'message' => 'کاربر سیک شد.', 'blocked' => true]);
        }

        case 'user_unblock': {
            $me = require_auth();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) fail('کاربر نامعتبر است.');

            db()->prepare('DELETE FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?')
                ->execute([$me['id'], $id]);
            
            db()->prepare('INSERT INTO block_logs (blocker_id, blocked_id, action) VALUES (?, ?, ?)')
                ->execute([$me['id'], $id, 'unblock']);

            respond(['ok' => true, 'message' => 'سیک کاربر برداشته شد.', 'blocked' => false]);
        }

        case 'user_block_status': {
            $me = require_auth();
            $id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
            if ($id <= 0) fail('کاربر نامعتبر است.');

            $stmt = db()->prepare('SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?');
            $stmt->execute([$me['id'], $id]);
            respond(['ok' => true, 'blocked' => (bool)$stmt->fetch()]);
        }

        // ==================== کانال ====================
        case 'channel_join': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            if ($groupId <= 0) fail('گروه/کانال نامعتبر است.');

            $stmt = db()->prepare("SELECT * FROM groups_ WHERE id = ?");
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            if (!$group) fail('گروه/کانال پیدا نشد.', 404);
            if (empty($group['username'])) fail('این گروه/کانال عمومی نیست و فقط با دعوت مدیر می‌توان به آن پیوست.', 403);
            if (is_group_banned((int)$group['id'])) fail('این گروه/کانال سیک شده است.', 403);

            // گروه/کانال رسمی قابل ترک نیست، ولی همه‌ی کاربران از قبل عضو آن هستند
            db()->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")
                ->execute([$groupId, $me['id']]);

            respond(['ok' => true, 'group' => public_group($group)]);
        }

        // ==================== گروه ====================
        case 'group_create': {
            $me = require_auth();
            $in = input();
            $name = trim((string)($in['name'] ?? ''));
            $type = (isset($in['type']) && $in['type'] === 'channel') ? 'channel' : 'group';
            $memberIds = is_array($in['members'] ?? null) ? $in['members'] : [];
            $memberIds = array_values(array_unique(array_map('intval', $memberIds)));

            if (mb_strlen($name) < 2) fail('نام باید حداقل ۲ حرف باشد.');

            $usernameIn = trim((string)($in['username'] ?? ''));
            if ($usernameIn === '') {
                fail('تعیین آیدی برای گروه/کانال اجباری است.');
            }
            $username = normalize_username($usernameIn);
            if (!valid_username_format($username)) {
                fail('آیدی باید با حرف انگلیسی شروع شود، فقط شامل حروف/عدد/آندرلاین باشد و بین ۵ تا ۳۲ کاراکتر باشد.');
            }
            if (username_taken($username)) fail('این آیدی قبلاً گرفته شده.');

            // ==================== سقف تعداد گروه/کانالِ ساخته‌شده ====================
            // کاربر پرمیوم: حداکثر ۱۰۰۰ گروه/کانال، کاربر عادی: حداکثر ۱۰۰ تا.
            // بعد از رسیدن به سقف، باید یکی از گروه/کانال‌های قبلی را حذف کند تا بتواند مورد جدید بسازد.
            $isPremiumCreator = is_user_premium($me);
            $ownedLimit = $isPremiumCreator ? 1000 : 100;
            $ownedCountStmt = db()->prepare('SELECT COUNT(*) AS c FROM groups_ WHERE owner_id = ?');
            $ownedCountStmt->execute([$me['id']]);
            $ownedCount = (int)$ownedCountStmt->fetch()['c'];
            if ($ownedCount >= $ownedLimit) {
                fail(
                    $isPremiumCreator
                        ? 'به سقف مجاز ۱۰۰۰ گروه/کانال رسیده‌ای. برای ساخت گروه/کانال جدید، ابتدا یکی از موارد قبلی را حذف کن.'
                        : 'کاربران عادی حداکثر ۱۰۰ گروه/کانال می‌توانند بسازند و به این سقف رسیده‌ای. یا یکی از گروه/کانال‌های قبلی را حذف کن، یا با ارتقا به پرمیوم تا ۱۰۰۰ تا بساز.',
                    403
                );
            }

            $colors = ['#16a34a', '#f59e0b', '#8b5cf6', '#0ea5e9', '#ef4444'];
            $color = $colors[array_rand($colors)];

            $photo = (string)($in['photo'] ?? '');
            if ($photo !== '') {
                if (strlen($photo) > 4_000_000) fail('حجم عکس خیلی زیاد است.');
                if (!str_starts_with($photo, 'data:image/')) fail('فرمت عکس نامعتبر است.');
            } else {
                $photo = null;
            }

            $pdo = db();
            $pdo->prepare('INSERT INTO groups_ (name, type, color, owner_id, username, photo) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $type, $color, $me['id'], $username, $photo]);
            $groupId = (int)$pdo->lastInsertId();

            $addMember = $pdo->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)");
            $addMember->execute([$groupId, $me['id'], 'owner']);
            foreach ($memberIds as $uid) {
                if ($uid > 0 && $uid !== (int)$me['id']) {
                    $addMember->execute([$groupId, $uid, 'member']);
                }
            }

            $g = $pdo->prepare('SELECT * FROM groups_ WHERE id = ?');
            $g->execute([$groupId]);
            respond(['ok' => true, 'group' => public_group($g->fetch())]);
        }

        case 'group_update': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['id'] ?? ($in['group'] ?? 0));
            if ($groupId <= 0) fail('گروه نامعتبر است.');

            $stmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            if (!$group) fail('گروه پیدا نشد.', 404);
            if ((int)$group['owner_id'] !== (int)$me['id']) fail('فقط سازنده‌ی گروه/کانال می‌تواند این مورد را تغییر دهد.', 403);
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);
            if ((int)($group['is_official'] ?? 0) === 1) fail('گروه/کانال رسمی قابل تغییر نیست.', 403);

            if (!isset($in['photo']) && !isset($in['username'])) fail('هیچ تغییری ارسال نشده.');

            $fields = [];
            $params = [];

            if (isset($in['photo'])) {
                $photo = (string)$in['photo'];
                if ($photo !== '') {
                    if (strlen($photo) > 4_000_000) fail('حجم عکس خیلی زیاد است.');
                    if (!str_starts_with($photo, 'data:image/')) fail('فرمت عکس نامعتبر است.');
                }
                $fields[] = 'photo = ?';
                $params[] = $photo === '' ? null : $photo;
            }

            if (isset($in['username'])) {
                $usernameIn = trim((string)$in['username']);
                if ($usernameIn === '') fail('آیدی گروه/کانال نمی‌تواند خالی باشد.');
                $username = normalize_username($usernameIn);
                if (!valid_username_format($username)) {
                    fail('آیدی باید با حرف انگلیسی شروع شود، فقط شامل حروف/عدد/آندرلاین باشد و بین ۵ تا ۳۲ کاراکتر باشد.');
                }
                if ($username !== ($group['username'] ?? '') && username_taken($username, null, $groupId)) {
                    fail('این آیدی قبلاً گرفته شده.');
                }
                $fields[] = 'username = ?';
                $params[] = $username;
            }

            $params[] = $groupId;
            db()->prepare('UPDATE groups_ SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

            $stmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $stmt->execute([$groupId]);
            respond(['ok' => true, 'group' => public_group($stmt->fetch())]);
        }

        case 'group_members_get': {
            $me = require_auth();
            $groupId = (int)(isset($_GET['group']) ? $_GET['group'] : 0);
            if ($groupId <= 0) fail('گروه نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            $isOfficial = is_official_channel($groupId);
            if ($isOfficial) {
                // کانال رسمی: همه می‌توانند اعضا را ببینند
                $myRole = member_role($groupId, (int)$me['id']);
                if (!$myRole) {
                    // اگر عضو نیست، اضافه کن
                    db()->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")
                        ->execute([$groupId, $me['id']]);
                }
            } else {
                assert_group_member($groupId, (int)$me['id']);
            }
            
            $myRole = member_role($groupId, (int)$me['id']);

            $stmt = db()->prepare("
                SELECT u.id, u.name, u.username, u.color, u.avatar_photo, u.verified, u.is_official, u.phone, u.premium_name, gm.role, gm.joined_at
                FROM group_members gm JOIN users u ON u.id = gm.user_id
                WHERE gm.group_id = ?
                ORDER BY CASE gm.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, gm.joined_at ASC
            ");
            $stmt->execute([$groupId]);
            $rows = $stmt->fetchAll();

            $members = array_map(function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'name' => $r['name'],
                    'username' => $r['username'],
                    'color' => $r['color'],
                    'avatar_photo' => $r['avatar_photo'],
                    'verified' => (int)$r['verified'] === 1,
                    'is_official' => (int)$r['is_official'] === 1,
                    'is_main_admin' => is_admin_phone($r['phone'] ?? null),
                    'premium_name' => gated_premium_name($r['premium_name'] ?? null, $r['phone'] ?? null, $r['verified'] ?? 0),
                    'role' => $r['role'],
                ];
            }, $rows);

            respond(['ok' => true, 'members' => $members, 'my_role' => $myRole]);
        }

        case 'group_member_add': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $userId = (int)($in['user'] ?? 0);
            if ($groupId <= 0 || $userId <= 0) fail('اطلاعات نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            $stmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            if (!$group) fail('گروه پیدا نشد.', 404);

            $myRole = member_role($groupId, (int)$me['id']);
            if (!in_array($myRole, ['owner', 'admin'], true)) fail('فقط مالک یا مدیر می‌تواند عضو اضافه کند.', 403);

            $target = fetch_user($userId);
            if (!$target) fail('کاربر پیدا نشد.', 404);

            db()->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")
                ->execute([$groupId, $userId]);

            respond(['ok' => true]);
        }

        case 'group_member_remove': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $userId = (int)($in['user'] ?? 0);
            if ($groupId <= 0 || $userId <= 0) fail('اطلاعات نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            $stmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            if (!$group) fail('گروه پیدا نشد.', 404);

            $myRole = member_role($groupId, (int)$me['id']);
            if (!in_array($myRole, ['owner', 'admin'], true)) fail('فقط مالک یا مدیر می‌تواند عضو را حذف کند.', 403);
            if ($userId === (int)$me['id']) fail('برای خارج شدن از گزینه‌ی «حذف گفتگو» استفاده کن.');

            $targetRole = member_role($groupId, $userId);
            if (!$targetRole) fail('این کاربر عضو گروه/کانال نیست.', 404);
            if ($targetRole === 'owner') fail('مالک گروه/کانال قابل حذف نیست.');
            if ($targetRole === 'admin' && $myRole !== 'owner') fail('فقط مالک می‌تواند مدیر را حذف کند.', 403);

            $target = fetch_user($userId);
            $targetName = $target ? $target['name'] : 'کاربر';

            $pdo = db();
            $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $userId]);
            $pdo->prepare("UPDATE groups_ SET pinned_message_id = NULL WHERE id = ? AND pinned_message_id IN (SELECT id FROM group_messages WHERE group_id = ? AND sender_id = ?)")
                ->execute([$groupId, $groupId, $userId]);

            $sysText = $me['name'] . ' کاربر ' . $targetName . ' را از گروه سیک زد.';
            $pdo->prepare("INSERT INTO group_messages (group_id, sender_id, type, body) VALUES (?, ?, 'system', ?)")
                ->execute([$groupId, $me['id'], $sysText]);

            respond(['ok' => true, 'message' => "کاربر توسط مدیر از گروه سیک شد."]);
        }

        case 'group_delete': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? ($in['id'] ?? 0));
            if ($groupId <= 0) fail('گروه نامعتبر است.');

            $stmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            if (!$group) fail('گروه پیدا نشد.', 404);
            if ((int)$group['owner_id'] !== (int)$me['id']) fail('فقط مالک می‌تواند گروه/کانال را کامل حذف کند.', 403);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM reactions WHERE msg_kind = 'group' AND msg_id IN (SELECT id FROM group_messages WHERE group_id = ?)")
                    ->execute([$groupId]);
                $pdo->prepare('DELETE FROM groups_ WHERE id = ?')->execute([$groupId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                fail('خطا در حذف گروه/کانال رخ داد.', 500);
            }

            respond(['ok' => true]);
        }

        case 'group_member_set_admin': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $userId = (int)($in['user'] ?? 0);
            $makeAdmin = !empty($in['admin']);
            if ($groupId <= 0 || $userId <= 0) fail('اطلاعات نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            $stmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            if (!$group) fail('گروه پیدا نشد.', 404);
            if ((int)$group['owner_id'] !== (int)$me['id']) fail('فقط مالک می‌تواند مدیر تعیین کند.', 403);
            if ($userId === (int)$me['id']) fail('نمی‌توانی نقش خودت را تغییر بدهی.');

            $targetRole = member_role($groupId, $userId);
            if (!$targetRole) fail('این کاربر عضو گروه/کانال نیست.', 404);
            if ($targetRole === 'owner') fail('نقش مالک قابل تغییر با این عملیات نیست.');

            db()->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?')
                ->execute([$makeAdmin ? 'admin' : 'member', $groupId, $userId]);

            respond(['ok' => true]);
        }

        case 'group_owner_transfer': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $userId = (int)($in['user'] ?? 0);
            if ($groupId <= 0 || $userId <= 0) fail('اطلاعات نامعتبر است.');
            if ($userId === (int)$me['id']) fail('این کاربر همین حالا مالک است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            $stmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            if (!$group) fail('گروه پیدا نشد.', 404);
            if ((int)$group['owner_id'] !== (int)$me['id']) fail('فقط مالک می‌تواند مالکیت را منتقل کند.', 403);

            $targetRole = member_role($groupId, $userId);
            if (!$targetRole) fail('این کاربر عضو گروه/کانال نیست.', 404);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE groups_ SET owner_id = ? WHERE id = ?')->execute([$userId, $groupId]);
                $pdo->prepare("UPDATE group_members SET role = 'admin' WHERE group_id = ? AND user_id = ?")
                    ->execute([$groupId, $me['id']]);
                $pdo->prepare("UPDATE group_members SET role = 'owner' WHERE group_id = ? AND user_id = ?")
                    ->execute([$groupId, $userId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                fail('خطا در انتقال مالکیت رخ داد.', 500);
            }

            $g = $pdo->prepare('SELECT * FROM groups_ WHERE id = ?');
            $g->execute([$groupId]);
            respond(['ok' => true, 'group' => public_group($g->fetch())]);
        }

        // ==================== لیست چت‌ها ====================
        case 'chats_list': {
            $me = require_auth();
            $meId = (int)$me['id'];
            $pdo = db();

            // سنجاق‌های خودِ گفتگو (برای مرتب‌سازی چت‌های سنجاق‌شده درست زیر رسمی‌ها)
            $chatPinMap = [];
            try {
                $cpStmt = $pdo->prepare('SELECT kind, target_id, created_at FROM chat_pins WHERE user_id = ?');
                $cpStmt->execute([$meId]);
                foreach ($cpStmt->fetchAll() as $cp) {
                    $chatPinMap[$cp['kind'] . '_' . $cp['target_id']] = $cp['created_at'];
                }
            } catch (Exception $e) {}

            $dmSql = "
                SELECT u.*, m.id AS last_id, m.body AS last_body, m.type AS last_type, m.created_at AS last_at,
                       dh.hidden_before_id AS hidden_before_id,
                       (SELECT COUNT(*) FROM messages
                         WHERE sender_id = u.id AND receiver_id = :me1 AND is_read = 0) AS unread,
                       (SELECT 1 FROM blocked_users WHERE blocker_id = :me5 AND blocked_id = u.id) AS i_blocked
                FROM users u
                JOIN messages m ON (
                    m.id = (
                        SELECT id FROM messages
                        WHERE (sender_id = u.id AND receiver_id = :me2)
                           OR (sender_id = :me3 AND receiver_id = u.id)
                        ORDER BY id DESC LIMIT 1
                    )
                )
                LEFT JOIN dm_hidden dh ON dh.user_id = :me6 AND dh.other_id = u.id
                WHERE u.id != :me4
            ";
            $stmt = $pdo->prepare($dmSql);
            $stmt->execute(['me1' => $meId, 'me2' => $meId, 'me3' => $meId, 'me4' => $meId, 'me5' => $meId, 'me6' => $meId]);
            $dmRows = $stmt->fetchAll();

            $chats = [];
            foreach ($dmRows as $r) {
                if ($r['hidden_before_id'] !== null && (int)$r['last_id'] <= (int)$r['hidden_before_id']) continue;
                $userStatus = get_user_status($r);
                $isOfficialUser = (int)($r['is_official'] ?? 0) === 1;
                $pinKey = 'dm_' . (int)$r['id'];
                $chats[] = [
                    'kind' => 'dm',
                    'id' => (int)$r['id'],
                    'title' => $r['name'],
                    'subtitle' => $r['username'] ? ('@' . $r['username']) : null,
                    'color' => $r['color'],
                    'avatar_photo' => $r['avatar_photo'],
                    'verified' => (int)$r['verified'] === 1,
                    'is_official' => $isOfficialUser,
                    'is_main_admin' => is_admin_phone($r['phone'] ?? null),
                    'premium_name' => gated_premium_name($r['premium_name'] ?? null, $r['phone'] ?? null, $r['verified'] ?? 0),
                    'is_premium' => is_user_premium($r),
                    'is_saved' => false,
                    'last_message' => message_preview($r['last_type'], $r['last_body']),
                    'last_at' => $r['last_at'],
                    'unread' => (int)$r['unread'],
                    'blocked' => $r['i_blocked'] !== null,
                    'last_seen_text' => $userStatus['last_seen_text'] ?? 'آخرین بازدید مخفی است',
                    'status_text' => $userStatus['status_text'] ?? '',
                    'status_class' => $userStatus['status_class'] ?? '',
                    'is_suspended' => $userStatus['suspended'] ?? false,
                    'is_banned' => $userStatus['banned'] ?? false,
                    'is_sik' => $userStatus['is_sik'] ?? false,
                    'is_pinned' => isset($chatPinMap[$pinKey]),
                    'pinned_at' => $chatPinMap[$pinKey] ?? null,
                ];
            }

            $selfStmt = $pdo->prepare('
                SELECT body, type, created_at FROM messages
                WHERE sender_id = :me1 AND receiver_id = :me2
                ORDER BY id DESC LIMIT 1
            ');
            $selfStmt->execute(['me1' => $meId, 'me2' => $meId]);
            $selfLast = $selfStmt->fetch();

            $selfUnreadStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = :me1 AND receiver_id = :me2 AND is_read = 0');
            $selfUnreadStmt->execute(['me1' => $meId, 'me2' => $meId]);
            $selfUnread = (int)$selfUnreadStmt->fetchColumn();

            $chats[] = [
                'kind' => 'dm',
                'id' => $meId,
                'title' => '💾 فضای شخصی',
                'subtitle' => $me['phone'],
                'color' => $me['color'],
                'avatar_photo' => $me['avatar_photo'] ?? null,
                'verified' => false,
                'is_official' => false,
                'is_main_admin' => false,
                'is_saved' => true,
                'last_message' => $selfLast ? message_preview($selfLast['type'], $selfLast['body']) : 'یادداشت‌ها، فایل‌ها و پیام‌های شخصی‌ات را همین‌جا نگه دار.',
                'last_at' => $selfLast ? $selfLast['created_at'] : $me['created_at'],
                'unread' => $selfUnread,
                'blocked' => false,
                'last_seen_text' => '',
                'status_text' => '',
                'status_class' => '',
                'is_suspended' => false,
                'is_banned' => false,
                'is_sik' => false,
                'is_pinned' => isset($chatPinMap['dm_' . $meId]),
                'pinned_at' => $chatPinMap['dm_' . $meId] ?? null,
            ];

            // گروه‌ها و کانال‌ها (شامل رسمی)
            $groupSql = "
                SELECT g.*, gm.role AS my_role,
                       (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS member_count,
                       (SELECT body FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_body,
                       (SELECT type FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_type,
                       (SELECT created_at FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_at,
                       (SELECT COUNT(*) FROM group_messages
                         WHERE group_id = g.id AND sender_id != :me7
                           AND id > COALESCE((SELECT last_read_id FROM group_reads WHERE group_id = g.id AND user_id = :me8), 0)
                       ) AS unread
                FROM groups_ g
                JOIN group_members gm ON gm.group_id = g.id
                WHERE gm.user_id = :me AND g.banned = 0
                ORDER BY g.is_official DESC, g.created_at ASC
            ";
            $stmt = $pdo->prepare($groupSql);
            $stmt->execute(['me' => $meId, 'me7' => $meId, 'me8' => $meId]);
            $groupRows = $stmt->fetchAll();

            foreach ($groupRows as $g) {
                $memberCount = (int)$g['member_count'];
                $isOfficial = (int)($g['is_official'] ?? 0) === 1;
                $officialBadge = $isOfficial ? ' ✓' : '';
                $subtitle = $g['type'] === 'channel'
                    ? ('کانال' . (!empty($g['username']) ? ' · @' . $g['username'] : '') . ' · ' . $memberCount . ' عضو' . $officialBadge)
                    : ('گروه · ' . $memberCount . ' عضو' . $officialBadge);
                $groupPinKey = 'group_' . (int)$g['id'];
                $chats[] = [
                    'kind' => 'group',
                    'id' => (int)$g['id'],
                    'title' => $g['name'],
                    'subtitle' => $subtitle,
                    'color' => $g['color'],
                    'avatar_photo' => $g['photo'] ?? null,
                    'owner_id' => (int)$g['owner_id'],
                    'my_role' => $g['my_role'] ?? 'member',
                    'member_count' => $memberCount,
                    'group_type' => $g['type'],
                    'username' => $g['username'] ?? null,
                    'last_message' => $g['last_body'] !== null ? message_preview($g['last_type'], $g['last_body']) : 'گروه ایجاد شد.',
                    'last_at' => $g['last_at'] ?? $g['created_at'],
                    'unread' => (int)$g['unread'],
                    'is_banned' => (int)($g['banned'] ?? 0) === 1,
                    'is_official' => $isOfficial,
                    'verified' => (int)($g['verified'] ?? 0) === 1,
                    'is_pinned' => isset($chatPinMap[$groupPinKey]),
                    'pinned_at' => $chatPinMap[$groupPinKey] ?? null,
                ];
            }

            usort($chats, function ($a, $b) {
                // کانال‌های رسمی همیشه اول
                if ($a['is_official'] && !$b['is_official']) return -1;
                if (!$a['is_official'] && $b['is_official']) return 1;
                if ($a['is_official'] && $b['is_official']) return 0;
                // بعد از رسمی‌ها، چت‌های سنجاق‌شده (جدیدترین سنجاق در بالاترین جایگاه)
                if ($a['is_pinned'] && !$b['is_pinned']) return -1;
                if (!$a['is_pinned'] && $b['is_pinned']) return 1;
                if ($a['is_pinned'] && $b['is_pinned']) {
                    return strcmp((string)$b['pinned_at'], (string)$a['pinned_at']);
                }
                return strcmp((string)$b['last_at'], (string)$a['last_at']);
            });

            respond(['ok' => true, 'chats' => $chats]);
        }

        // ==================== پیام‌های خصوصی ====================
        case 'messages_get': {
            $me = require_auth();
            $withId = (int)(isset($_GET['with']) ? $_GET['with'] : 0);
            $sinceId = (int)(isset($_GET['since']) ? $_GET['since'] : 0);
            $sinceTs = (string)(isset($_GET['since_ts']) ? $_GET['since_ts'] : '');
            if ($withId <= 0) fail('کاربر مقصد نامعتبر است.');

            $stmt = db()->prepare('
                SELECT * FROM messages
                WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
                  AND (id > ? OR (updated_at IS NOT NULL AND updated_at > ?) OR (reacted_at IS NOT NULL AND reacted_at > ?) OR (replied_at IS NOT NULL AND replied_at > ?) OR (read_at IS NOT NULL AND read_at > ?))
                ORDER BY id ASC
                LIMIT 300
            ');
            $stmt->execute([$me['id'], $withId, $withId, $me['id'], $sinceId, $sinceTs, $sinceTs, $sinceTs, $sinceTs]);
            $rows = $stmt->fetchAll();

            // تیک دوم: علامت‌گذاری پیام‌های طرف مقابل به‌عنوان خونده‌شده (فقط پیام‌های تازه‌خونده‌شده تا read_at درست ثبت شود)
            // بهینه‌سازی: چون این endpoint با پولینگ مداوم صدا زده می‌شود، قبل از UPDATE (که همیشه
            // یک تراکنش نوشتنی روی دیتابیس باز می‌کند حتی وقتی چیزی برای آپدیت نیست) با یک SELECT
            // سبک بررسی می‌کنیم که واقعاً پیام خوانده‌نشده‌ای هست یا نه؛ این کار حجم نوشتن‌های
            // بی‌مورد روی دیتابیس را در بیشتر پولینگ‌ها (که خبری نیست) به‌شدت کاهش می‌دهد.
            $hasUnreadStmt = db()->prepare('SELECT 1 FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 0 LIMIT 1');
            $hasUnreadStmt->execute([$withId, $me['id']]);
            if ($hasUnreadStmt->fetch()) {
                db()->prepare('UPDATE messages SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE sender_id = ? AND receiver_id = ? AND is_read = 0')
                    ->execute([$withId, $me['id']]);
            }

            $messages = array_map(function ($m) use ($me) { return map_message_row($m, $me, 'dm'); }, $rows);

            respond(['ok' => true, 'messages' => $messages, 'server_time' => date('Y-m-d H:i:s')]);
        }

        case 'messages_send': {
            $me = require_auth();
            $in = input();
            $toId = (int)($in['to'] ?? 0);
            $typeIn = (string)($in['type'] ?? 'text');
            $bodyIn = (string)($in['body'] ?? '');
            $replyToId = (int)($in['reply_to'] ?? 0);
            $body = ($typeIn === 'text' || $typeIn === 'emoji' || $typeIn === 'anim_emoji') ? trim($bodyIn) : $bodyIn;
            $type = validate_message($typeIn, $body, is_user_premium($me));
            if ($type === 'anim_emoji' && !is_user_premium($me)) fail('شما پرمیوم نیستید.', 403);
            $caption = in_array($type, ['photo', 'video', 'voice', 'file'], true) ? trim((string)($in['caption'] ?? '')) : '';
            if (mb_strlen($caption) > 1024) fail('توضیحات خیلی طولانی است.');
            $fileName = in_array($type, ['photo', 'video', 'voice', 'file'], true) ? mb_substr(trim((string)($in['file_name'] ?? '')), 0, 255) : '';
            $fileSize = in_array($type, ['photo', 'video', 'voice', 'file'], true) ? max(0, (int)($in['file_size'] ?? 0)) : 0;
            [$forwardKind, $forwardChatId, $forwardName, $forwardMsgId] = parse_forward_from($in);

            if ($toId <= 0) fail('گیرنده نامعتبر است.');

            $target = fetch_user($toId);
            if (!$target) fail('کاربر مقصد یافت نشد.', 404);
            
            $targetStatus = get_user_status($target);
            if ($targetStatus['banned'] || $targetStatus['suspended']) {
                fail('امکان ارسال پیام به این کاربر وجود ندارد؛ کاربر سیک شده است.', 403);
            }

            $blockChk = db()->prepare('
                SELECT 1 FROM blocked_users
                WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)
            ');
            $blockChk->execute([$me['id'], $toId, $toId, $me['id']]);
            if ($blockChk->fetch()) fail('امکان ارسال پیام وجود ندارد؛ یکی از طرفین دیگری را سیک کرده است.', 403);

            if ($replyToId > 0) {
                $replyCheck = db()->prepare('SELECT id FROM messages WHERE id = ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))');
                $replyCheck->execute([$replyToId, $me['id'], $toId, $toId, $me['id']]);
                if (!$replyCheck->fetch()) $replyToId = 0;
            }

            $stmt = db()->prepare('INSERT INTO messages (sender_id, receiver_id, type, body, caption, file_name, file_size, reply_to_id, forward_kind, forward_chat_id, forward_name, forward_msg_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$me['id'], $toId, $type, $body, $caption, $fileName, $fileSize, $replyToId ?: null, $forwardKind, $forwardChatId, $forwardName, $forwardMsgId]);
            $id = (int)db()->lastInsertId();
            
            if ($replyToId > 0) {
                db()->prepare('UPDATE messages SET replied_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$replyToId]);
            }

            respond(['ok' => true, 'message' => [
                'id' => $id, 'mine' => true, 'type' => $type, 'body' => $body, 'caption' => $caption,
                'file_name' => $fileName, 'file_size' => $fileSize,
                'created_at' => date('Y-m-d H:i:s'), 'edited' => false, 'deleted' => false,
                'reactions' => [], 'reply_to' => $replyToId > 0 ? get_reply_preview('dm', $replyToId) : null,
                'forward' => $forwardKind ? ['kind' => $forwardKind, 'id' => $forwardChatId, 'name' => $forwardName, 'msg_id' => $forwardMsgId] : null,
                'is_read' => false,
            ]]);
        }

        case 'message_edit': {
            $me = require_auth();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) fail('شناسه پیام نامعتبر است.');

            $stmt = db()->prepare('SELECT * FROM messages WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) fail('پیام یافت نشد.', 404);
            if ((int)$row['sender_id'] !== (int)$me['id']) fail('فقط پیام‌های خودت را می‌توانی ویرایش کنی.', 403);
            if ((int)$row['deleted'] === 1) fail('پیام حذف‌شده قابل ویرایش نیست.');

            if (in_array($row['type'], ['text', 'emoji'], true)) {
                $body = trim((string)($in['body'] ?? ''));
                if ($body === '' || mb_strlen($body) > 4000) fail('متن پیام نامعتبر است.');
                db()->prepare('UPDATE messages SET body = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$body, $id]);
            } elseif (in_array($row['type'], ['photo', 'video', 'voice', 'file'], true)) {
                $caption = trim((string)($in['caption'] ?? ''));
                if (mb_strlen($caption) > 1024) fail('توضیحات خیلی طولانی است.');
                db()->prepare('UPDATE messages SET caption = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$caption, $id]);
            } else {
                fail('این نوع پیام قابل ویرایش نیست.');
            }

            $stmt = db()->prepare('SELECT * FROM messages WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            respond(['ok' => true, 'message' => map_message_row($row, $me, 'dm')]);
        }

        case 'message_delete': {
            $me = require_auth();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) fail('شناسه پیام نامعتبر است.');

            $stmt = db()->prepare('SELECT * FROM messages WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) fail('پیام یافت نشد.', 404);
            if ((int)$row['sender_id'] !== (int)$me['id']) fail('فقط پیام‌های خودت را می‌توانی حذف کنی.', 403);

            db()->prepare("UPDATE messages SET deleted = 1, body = '', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$id]);
            db()->prepare('DELETE FROM dm_pins WHERE message_id = ?')->execute([$id]);

            $stmt = db()->prepare('SELECT * FROM messages WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            respond(['ok' => true, 'message' => map_message_row($row, $me, 'dm')]);
        }

        // ==================== پیام‌های گروهی ====================
        case 'group_messages_get': {
            $me = require_auth();
            $groupId = (int)(isset($_GET['group']) ? $_GET['group'] : 0);
            $sinceId = (int)(isset($_GET['since']) ? $_GET['since'] : 0);
            $sinceTs = (string)(isset($_GET['since_ts']) ? $_GET['since_ts'] : '');
            if ($groupId <= 0) fail('گروه نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            assert_group_member($groupId, (int)$me['id']);

            $gTypeStmt = db()->prepare('SELECT type FROM groups_ WHERE id = ?');
            $gTypeStmt->execute([$groupId]);
            $gTypeRow = $gTypeStmt->fetch();
            $isChannelGroup = $gTypeRow && $gTypeRow['type'] === 'channel';

            $stmt = db()->prepare('
                SELECT gm.*, u.id AS sender_uid, u.name AS sender_name, u.color AS sender_color, u.verified AS sender_verified, u.is_official AS sender_official, u.phone AS sender_phone, u.avatar_photo AS sender_avatar, u.premium_name AS sender_premium_name, u.premium_until AS sender_premium_until
                FROM group_messages gm JOIN users u ON u.id = gm.sender_id
                WHERE gm.group_id = ? AND (gm.id > ? OR (gm.updated_at IS NOT NULL AND gm.updated_at > ?) OR (gm.reacted_at IS NOT NULL AND gm.reacted_at > ?) OR (gm.replied_at IS NOT NULL AND gm.replied_at > ?) OR (gm.views_updated_at IS NOT NULL AND gm.views_updated_at > ?))
                ORDER BY gm.id ASC LIMIT 300
            ');
            $stmt->execute([$groupId, $sinceId, $sinceTs, $sinceTs, $sinceTs, $sinceTs]);
            $rows = $stmt->fetchAll();

            $maxIdStmt = db()->prepare('SELECT MAX(id) AS mx FROM group_messages WHERE group_id = ?');
            $maxIdStmt->execute([$groupId]);
            $maxId = (int)($maxIdStmt->fetch()['mx'] ?? 0);
            if ($maxId > 0) {
                // بهینه‌سازی: قبل از نوشتن، last_read_id فعلی را می‌خوانیم (خواندن سبک است) و فقط
                // وقتی واقعاً پیام جدیدتری خوانده شده، UPSERT (که یک نوشتن است) را اجرا می‌کنیم؛
                // در غیر این‌صورت هر پولینگِ بدون پیام تازه هم یک نوشتن اضافه روی دیتابیس ایجاد می‌کرد.
                $curReadStmt = db()->prepare('SELECT last_read_id FROM group_reads WHERE group_id = ? AND user_id = ?');
                $curReadStmt->execute([$groupId, $me['id']]);
                $curReadRow = $curReadStmt->fetch();
                $curReadId = $curReadRow ? (int)$curReadRow['last_read_id'] : 0;
                if ($maxId > $curReadId) {
                    db()->prepare('
                        INSERT INTO group_reads (group_id, user_id, last_read_id) VALUES (?, ?, ?)
                        ON CONFLICT(group_id, user_id) DO UPDATE SET last_read_id = MAX(last_read_id, excluded.last_read_id)
                    ')->execute([$groupId, $me['id'], $maxId]);
                }
            }

            // چشم سین: ثبت بازدید پیام‌های کانال (به‌جز پیام‌های خود فرستنده) و افزایش شمارنده
            if ($isChannelGroup && !empty($rows)) {
                $viewStmt = db()->prepare('INSERT OR IGNORE INTO message_views (message_id, user_id) VALUES (?, ?)');
                $incStmt = db()->prepare('UPDATE group_messages SET views_count = views_count + 1, views_updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                foreach ($rows as &$r) {
                    if ((int)$r['sender_id'] === (int)$me['id']) continue;
                    $viewStmt->execute([(int)$r['id'], (int)$me['id']]);
                    if ($viewStmt->rowCount() > 0) {
                        $incStmt->execute([(int)$r['id']]);
                        $r['views_count'] = (int)($r['views_count'] ?? 0) + 1;
                    }
                }
                unset($r);
            }

            $messages = array_map(function ($m) use ($me, $isChannelGroup) {
                $row = map_message_row($m, $me, 'group');
                $row['sender_id'] = (int)$m['sender_uid'];
                $row['sender_name'] = $m['sender_name'];
                $row['sender_color'] = $m['sender_color'];
                $row['sender_verified'] = (int)($m['sender_verified'] ?? 0) === 1;
                $row['sender_official'] = (int)($m['sender_official'] ?? 0) === 1;
                $row['sender_is_main_admin'] = is_admin_phone($m['sender_phone'] ?? null);
                $row['sender_avatar'] = $m['sender_avatar'] ?? null;
                $row['sender_premium_name'] = gated_premium_name($m['sender_premium_name'] ?? null, $m['sender_phone'] ?? null, $m['sender_verified'] ?? 0);
                $row['sender_premium'] = sender_is_premium($m['sender_premium_until'] ?? null, $m['sender_phone'] ?? null, $m['sender_verified'] ?? 0);
                if ($isChannelGroup) $row['views'] = (int)($m['views_count'] ?? 0);
                return $row;
            }, $rows);

            respond(['ok' => true, 'messages' => $messages, 'server_time' => date('Y-m-d H:i:s')]);
        }

        case 'group_messages_send': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $typeIn = (string)($in['type'] ?? 'text');
            $bodyIn = (string)($in['body'] ?? '');
            $replyToId = (int)($in['reply_to'] ?? 0);
            $body = ($typeIn === 'text' || $typeIn === 'emoji' || $typeIn === 'anim_emoji') ? trim($bodyIn) : $bodyIn;
            $type = validate_message($typeIn, $body, is_user_premium($me));
            if ($type === 'anim_emoji' && !is_user_premium($me)) fail('شما پرمیوم نیستید.', 403);
            $caption = in_array($type, ['photo', 'video', 'voice', 'file'], true) ? trim((string)($in['caption'] ?? '')) : '';
            if (mb_strlen($caption) > 1024) fail('توضیحات خیلی طولانی است.');
            $fileName = in_array($type, ['photo', 'video', 'voice', 'file'], true) ? mb_substr(trim((string)($in['file_name'] ?? '')), 0, 255) : '';
            $fileSize = in_array($type, ['photo', 'video', 'voice', 'file'], true) ? max(0, (int)($in['file_size'] ?? 0)) : 0;
            [$forwardKind, $forwardChatId, $forwardName, $forwardMsgId] = parse_forward_from($in);

            if ($groupId <= 0) fail('گروه نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            $gstmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
            $gstmt->execute([$groupId]);
            $groupRow = $gstmt->fetch();
            if (!$groupRow) fail('گروه پیدا نشد.', 404);
            assert_group_can_post($groupRow, (int)$me['id']);

            if ($replyToId > 0) {
                $replyCheck = db()->prepare('SELECT id FROM group_messages WHERE id = ? AND group_id = ?');
                $replyCheck->execute([$replyToId, $groupId]);
                if (!$replyCheck->fetch()) $replyToId = 0;
            }

            db()->prepare('INSERT INTO group_messages (group_id, sender_id, type, body, caption, file_name, file_size, reply_to_id, forward_kind, forward_chat_id, forward_name, forward_msg_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$groupId, $me['id'], $type, $body, $caption, $fileName, $fileSize, $replyToId ?: null, $forwardKind, $forwardChatId, $forwardName, $forwardMsgId]);
            $id = (int)db()->lastInsertId();
            
            if ($replyToId > 0) {
                db()->prepare('UPDATE group_messages SET replied_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$replyToId]);
            }

            respond(['ok' => true, 'message' => [
                'id' => $id, 'mine' => true, 'type' => $type, 'body' => $body, 'caption' => $caption,
                'file_name' => $fileName, 'file_size' => $fileSize,
                'sender_id' => (int)$me['id'], 'sender_name' => $me['name'], 'sender_color' => $me['color'],
                'sender_verified' => (int)($me['verified'] ?? 0) === 1, 'sender_official' => (int)($me['is_official'] ?? 0) === 1, 'sender_is_main_admin' => is_admin_phone($me['phone'] ?? null), 'sender_avatar' => $me['avatar_photo'] ?? null,
                'sender_premium_name' => gated_premium_name($me['premium_name'] ?? null, $me['phone'] ?? null, $me['verified'] ?? 0),
                'sender_premium' => sender_is_premium($me['premium_until'] ?? null, $me['phone'] ?? null, $me['verified'] ?? 0),
                'created_at' => date('Y-m-d H:i:s'), 'edited' => false, 'deleted' => false,
                'reactions' => [], 'reply_to' => $replyToId > 0 ? get_reply_preview('group', $replyToId) : null,
                'forward' => $forwardKind ? ['kind' => $forwardKind, 'id' => $forwardChatId, 'name' => $forwardName, 'msg_id' => $forwardMsgId] : null,
                'views' => $groupRow['type'] === 'channel' ? 0 : null,
            ]]);
        }

        case 'group_message_edit': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $id = (int)($in['id'] ?? 0);
            if ($groupId <= 0 || $id <= 0) fail('اطلاعات نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            assert_group_member($groupId, (int)$me['id']);

            $stmt = db()->prepare('SELECT * FROM group_messages WHERE id = ? AND group_id = ?');
            $stmt->execute([$id, $groupId]);
            $row = $stmt->fetch();
            if (!$row) fail('پیام یافت نشد.', 404);
            if ((int)$row['sender_id'] !== (int)$me['id']) fail('فقط پیام‌های خودت را می‌توانی ویرایش کنی.', 403);
            if ((int)$row['deleted'] === 1) fail('پیام حذف‌شده قابل ویرایش نیست.');
            if (!in_array($row['type'], ['text', 'emoji', 'photo', 'video', 'voice', 'file'], true)) fail('این نوع پیام قابل ویرایش نیست.');
            if (is_official_readonly_channel($groupId)) {
                $myRole = member_role($groupId, (int)$me['id']);
                $isSiteAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
                if (!in_array($myRole, ['owner', 'admin'], true) && !$isSiteAdmin) {
                    fail('در کانال رسمی، اعضای عادی نمی‌توانند پیام را ویرایش کنند.', 403);
                }
            }

            if (in_array($row['type'], ['text', 'emoji'], true)) {
                $body = trim((string)($in['body'] ?? ''));
                if ($body === '' || mb_strlen($body) > 4000) fail('متن پیام نامعتبر است.');
                db()->prepare('UPDATE group_messages SET body = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$body, $id]);
            } else {
                $caption = trim((string)($in['caption'] ?? ''));
                if (mb_strlen($caption) > 1024) fail('توضیحات خیلی طولانی است.');
                db()->prepare('UPDATE group_messages SET caption = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$caption, $id]);
            }

            $stmt = db()->prepare('
                SELECT gm.*, u.id AS sender_uid, u.name AS sender_name, u.color AS sender_color, u.verified AS sender_verified, u.is_official AS sender_official, u.phone AS sender_phone, u.avatar_photo AS sender_avatar, u.premium_name AS sender_premium_name, u.premium_until AS sender_premium_until
                FROM group_messages gm JOIN users u ON u.id = gm.sender_id WHERE gm.id = ?
            ');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            $out = map_message_row($row, $me, 'group');
            $out['sender_id'] = (int)$row['sender_uid'];
            $out['sender_name'] = $row['sender_name'];
            $out['sender_color'] = $row['sender_color'];
            $out['sender_verified'] = (int)($row['sender_verified'] ?? 0) === 1;
            $out['sender_official'] = (int)($row['sender_official'] ?? 0) === 1;
            $out['sender_is_main_admin'] = is_admin_phone($row['sender_phone'] ?? null);
            $out['sender_avatar'] = $row['sender_avatar'] ?? null;
            $out['sender_premium_name'] = gated_premium_name($row['sender_premium_name'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);
            $out['sender_premium'] = sender_is_premium($row['sender_premium_until'] ?? null, $row['sender_phone'] ?? null, $row['sender_verified'] ?? 0);

            respond(['ok' => true, 'message' => $out]);
        }

        case 'group_message_delete': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $id = (int)($in['id'] ?? 0);
            if ($groupId <= 0 || $id <= 0) fail('اطلاعات نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            assert_group_member($groupId, (int)$me['id']);

            $stmt = db()->prepare('SELECT * FROM group_messages WHERE id = ? AND group_id = ?');
            $stmt->execute([$id, $groupId]);
            $row = $stmt->fetch();
            if (!$row) fail('پیام یافت نشد.', 404);

            $myRole = member_role($groupId, (int)$me['id']);
            $isSiteAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            $canModerate = in_array($myRole, ['owner', 'admin'], true) || $isSiteAdmin;
            if (is_official_readonly_channel($groupId) && !$canModerate) {
                fail('در کانال رسمی، اعضای عادی نمی‌توانند پیام حذف کنند.', 403);
            }
            if ((int)$row['sender_id'] !== (int)$me['id'] && !$canModerate) {
                fail('فقط پیام‌های خودت را می‌توانی حذف کنی.', 403);
            }

            db()->prepare("UPDATE group_messages SET deleted = 1, body = '', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$id]);
            db()->prepare('UPDATE groups_ SET pinned_message_id = NULL WHERE id = ? AND pinned_message_id = ?')
                ->execute([$groupId, $id]);

            $stmt = db()->prepare('
                SELECT gm.*, u.name AS sender_name, u.color AS sender_color
                FROM group_messages gm JOIN users u ON u.id = gm.sender_id WHERE gm.id = ?
            ');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            $out = map_message_row($row, $me, 'group');
            $out['sender_name'] = $row['sender_name'];
            $out['sender_color'] = $row['sender_color'];

            respond(['ok' => true, 'message' => $out]);
        }

        // ==================== ری‌اکشن ====================
        case 'message_react': {
            $me = require_auth();
            $in = input();
            $kind = (isset($in['kind']) && in_array($in['kind'], ['dm', 'group'], true)) ? $in['kind'] : '';
            $id = (int)($in['id'] ?? 0);
            $emoji = (string)($in['emoji'] ?? '');
            if ($kind === '' || $id <= 0) fail('اطلاعات نامعتبر است.');
            if (!in_array($emoji, REACTION_EMOJIS, true)) fail('این ری‌اکشن مجاز نیست.');

            if ($kind === 'dm') {
                $stmt = db()->prepare('SELECT * FROM messages WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) fail('پیام یافت نشد.', 404);
                if ((int)$row['deleted'] === 1) fail('نمی‌توان روی پیام حذف‌شده ری‌اکشن گذاشت.');
                if ((int)$row['sender_id'] !== (int)$me['id'] && (int)$row['receiver_id'] !== (int)$me['id']) {
                    fail('اجازه‌ی دسترسی به این پیام را نداری.', 403);
                }
            } else {
                $stmt = db()->prepare('SELECT * FROM group_messages WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) fail('پیام یافت نشد.', 404);
                if ((int)$row['deleted'] === 1) fail('نمی‌توان روی پیام حذف‌شده ری‌اکشن گذاشت.');
                if (is_group_banned((int)$row['group_id'])) fail('این گروه/کانال سیک شده است.', 403);
                assert_group_member((int)$row['group_id'], (int)$me['id']);
            }

            $existing = db()->prepare('SELECT emoji FROM reactions WHERE msg_kind = ? AND msg_id = ? AND user_id = ?');
            $existing->execute([$kind, $id, $me['id']]);
            $current = $existing->fetch();

            if ($current && $current['emoji'] === $emoji) {
                db()->prepare('DELETE FROM reactions WHERE msg_kind = ? AND msg_id = ? AND user_id = ?')
                    ->execute([$kind, $id, $me['id']]);
            } else {
                db()->prepare('
                    INSERT INTO reactions (msg_kind, msg_id, user_id, emoji) VALUES (?, ?, ?, ?)
                    ON CONFLICT(msg_kind, msg_id, user_id) DO UPDATE SET emoji = excluded.emoji, created_at = CURRENT_TIMESTAMP
                ')->execute([$kind, $id, $me['id'], $emoji]);
            }

            $reactedTable = $kind === 'dm' ? 'messages' : 'group_messages';
            db()->prepare("UPDATE {$reactedTable} SET reacted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);

            respond(['ok' => true, 'reactions' => get_reactions_for($kind, $id, (int)$me['id'])]);
        }

        // ==================== گزارش ====================
        // گزارش به‌صورت کامل بر روی خودِ کاربر/گروه/کانال ثبت می‌شود (نه یک پیام خاص)
        // تا آیدی و شناسه‌ی کانال/گروه همیشه همراه گزارش برای بررسی ادمین موجود باشد.
        case 'message_report': {
            $me = require_auth();
            $in = input();
            $kind = isset($in['kind']) && in_array($in['kind'], ['dm', 'group', 'channel', 'story', 'short'], true) ? $in['kind'] : '';
            $targetId = (int)($in['id'] ?? 0);
            $reason = trim((string)($in['reason'] ?? ''));

            // ادمین قابل گزارش نیست
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if ($isAdmin) {
                fail('اکانت ادمین قابل گزارش نیست.', 403);
            }

            if ($kind === '' || $targetId <= 0 || $reason === '') {
                fail('اطلاعات گزارش کامل نیست.');
            }
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
                fail('دلیل باید بین ۳ تا ۵۰۰ کاراکتر باشد.');
            }

            $targetKind = $kind;
            if ($kind === 'dm') {
                if ($targetId === (int)$me['id']) fail('نمی‌توانی خودت را گزارش کنی.');
                $targetUser = fetch_user($targetId);
                if (!$targetUser) fail('کاربر پیدا نشد.', 404);
                if ((int)$targetUser['verified'] === 1 || $targetUser['phone'] === VERIFY_ADMIN_PHONE || (int)($targetUser['is_official'] ?? 0) === 1) {
                    fail('امکان گزارش ادمین وجود ندارد.', 403);
                }
            } elseif ($kind === 'story') {
                $sstmt = db()->prepare('SELECT * FROM stories WHERE id = ?');
                $sstmt->execute([$targetId]);
                $storyRow = $sstmt->fetch();
                if (!$storyRow) fail('استوری پیدا نشد.', 404);
                if ((int)$storyRow['user_id'] === (int)$me['id']) fail('نمی‌توانی استوری خودت را گزارش کنی.');
                $storyOwner = fetch_user((int)$storyRow['user_id']);
                if ($storyOwner && ((int)$storyOwner['verified'] === 1 || $storyOwner['phone'] === VERIFY_ADMIN_PHONE)) {
                    fail('امکان گزارش استوری ادمین وجود ندارد.', 403);
                }
            } elseif ($kind === 'short') {
                $shstmt = db()->prepare('SELECT * FROM shorts WHERE id = ?');
                $shstmt->execute([$targetId]);
                $shortRow = $shstmt->fetch();
                if (!$shortRow) fail('شورت پیدا نشد.', 404);
                if ((int)$shortRow['created_by'] === (int)$me['id']) fail('نمی‌توانی شورت خودت را گزارش کنی.');
                $shortOwner = fetch_user((int)$shortRow['created_by']);
                if ($shortOwner && ((int)$shortOwner['verified'] === 1 || $shortOwner['phone'] === VERIFY_ADMIN_PHONE)) {
                    fail('امکان گزارش شورت ادمین وجود ندارد.', 403);
                }
            } else {
                $gstmt = db()->prepare('SELECT * FROM groups_ WHERE id = ?');
                $gstmt->execute([$targetId]);
                $groupRow = $gstmt->fetch();
                if (!$groupRow) fail('گروه/کانال پیدا نشد.', 404);
                $targetKind = 'group';

                // بررسی اینکه گروه رسمی نباشد
                if (is_official_channel($targetId)) {
                    fail('امکان گزارش کانال رسمی وجود ندارد.', 403);
                }
            }

            db()->prepare('INSERT INTO reports (reporter_id, target_kind, target_id, message_id, reason, status) VALUES (?, ?, ?, NULL, ?, ?)')
                ->execute([$me['id'], $targetKind, $targetId, $reason, 'pending']);

            respond(['ok' => true, 'message' => 'گزارش شما همراه با آیدی مورد نظر برای بررسی ثبت شد.']);
        }

        // ==================== سنجاق ====================
        case 'group_message_pin': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $id = (int)($in['id'] ?? 0);
            if ($groupId <= 0 || $id <= 0) fail('اطلاعات نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            $role = member_role($groupId, (int)$me['id']);
            if (!in_array($role, ['owner', 'admin'], true)) fail('فقط مالک یا مدیر می‌تواند پیام را سنجاق کند.', 403);

            $stmt = db()->prepare('SELECT * FROM group_messages WHERE id = ? AND group_id = ?');
            $stmt->execute([$id, $groupId]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['deleted'] === 1) fail('پیام یافت نشد.', 404);

            db()->prepare('
                INSERT INTO group_message_pins (group_id, message_id, pinned_by) VALUES (?, ?, ?)
                ON CONFLICT(group_id, message_id) DO UPDATE SET created_at = CURRENT_TIMESTAMP, pinned_by = excluded.pinned_by
            ')->execute([$groupId, $id, $me['id']]);

            $list = group_pinned_messages_payload($groupId, $me);
            respond(['ok' => true, 'pinned' => $list[0] ?? null, 'pinned_list' => $list]);
        }

        case 'group_message_unpin': {
            $me = require_auth();
            $in = input();
            $groupId = (int)($in['group'] ?? 0);
            $id = (int)($in['id'] ?? 0);
            if ($groupId <= 0) fail('گروه نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            $role = member_role($groupId, (int)$me['id']);
            if (!in_array($role, ['owner', 'admin'], true)) fail('فقط مالک یا مدیر می‌تواند سنجاق را بردارد.', 403);

            if ($id > 0) {
                db()->prepare('DELETE FROM group_message_pins WHERE group_id = ? AND message_id = ?')->execute([$groupId, $id]);
            } else {
                db()->prepare('DELETE FROM group_message_pins WHERE group_id = ?')->execute([$groupId]);
            }
            // سازگاری با ستون قدیمی
            db()->prepare('UPDATE groups_ SET pinned_message_id = NULL WHERE id = ?')->execute([$groupId]);

            $list = group_pinned_messages_payload($groupId, $me);
            respond(['ok' => true, 'pinned' => $list[0] ?? null, 'pinned_list' => $list]);
        }

        case 'group_pinned_get': {
            $me = require_auth();
            $groupId = (int)(isset($_GET['group']) ? $_GET['group'] : 0);
            if ($groupId <= 0) fail('گروه نامعتبر است.');
            if (is_group_banned($groupId)) fail('این گروه/کانال سیک شده است.', 403);

            assert_group_member($groupId, (int)$me['id']);

            $list = group_pinned_messages_payload($groupId, $me);
            respond(['ok' => true, 'pinned' => $list[0] ?? null, 'pinned_list' => $list, 'my_role' => member_role($groupId, (int)$me['id'])]);
        }

        // ==================== سنجاق در پیوی ====================
        case 'dm_message_pin': {
            $me = require_auth();
            $in = input();
            $peerId = (int)($in['with'] ?? 0);
            $id = (int)($in['id'] ?? 0);
            if ($peerId <= 0 || $id <= 0) fail('اطلاعات نامعتبر است.');

            $target = fetch_user($peerId);
            if (!$target && $peerId !== (int)$me['id']) fail('کاربر پیدا نشد.', 404);

            $stmt = db()->prepare('SELECT id, deleted FROM messages WHERE id = ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))');
            $stmt->execute([$id, $me['id'], $peerId, $peerId, $me['id']]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['deleted'] === 1) fail('پیام یافت نشد.', 404);

            $lo = min((int)$me['id'], $peerId);
            $hi = max((int)$me['id'], $peerId);
            db()->prepare('
                INSERT INTO dm_message_pins (user_a, user_b, message_id, pinned_by) VALUES (?, ?, ?, ?)
                ON CONFLICT(user_a, user_b, message_id) DO UPDATE SET created_at = CURRENT_TIMESTAMP, pinned_by = excluded.pinned_by
            ')->execute([$lo, $hi, $id, $me['id']]);

            $list = dm_pinned_messages_payload($lo, $hi, $me);
            respond(['ok' => true, 'pinned' => $list[0] ?? null, 'pinned_list' => $list]);
        }

        case 'dm_message_unpin': {
            $me = require_auth();
            $in = input();
            $peerId = (int)($in['with'] ?? 0);
            $id = (int)($in['id'] ?? 0);
            if ($peerId <= 0) fail('کاربر نامعتبر است.');

            $lo = min((int)$me['id'], $peerId);
            $hi = max((int)$me['id'], $peerId);
            if ($id > 0) {
                db()->prepare('DELETE FROM dm_message_pins WHERE user_a = ? AND user_b = ? AND message_id = ?')->execute([$lo, $hi, $id]);
            } else {
                db()->prepare('DELETE FROM dm_message_pins WHERE user_a = ? AND user_b = ?')->execute([$lo, $hi]);
            }
            // سازگاری با جدول قدیمی
            db()->prepare('DELETE FROM dm_pins WHERE user_a = ? AND user_b = ?')->execute([$lo, $hi]);

            $list = dm_pinned_messages_payload($lo, $hi, $me);
            respond(['ok' => true, 'pinned' => $list[0] ?? null, 'pinned_list' => $list]);
        }

        case 'dm_pinned_get': {
            $me = require_auth();
            $peerId = (int)(isset($_GET['with']) ? $_GET['with'] : 0);
            if ($peerId <= 0) fail('کاربر نامعتبر است.');

            $lo = min((int)$me['id'], $peerId);
            $hi = max((int)$me['id'], $peerId);

            $list = dm_pinned_messages_payload($lo, $hi, $me);
            respond(['ok' => true, 'pinned' => $list[0] ?? null, 'pinned_list' => $list]);
        }

        // ==================== سنجاق خودِ گفتگو در لیست چت‌ها ====================
        case 'chat_pin': {
            $me = require_auth();
            $in = input();
            $kind = (($in['kind'] ?? '') === 'group') ? 'group' : 'dm';
            $targetId = (int)($in['id'] ?? 0);
            if ($targetId <= 0) fail('اطلاعات نامعتبر است.');
            if ($kind === 'group') {
                assert_group_member($targetId, (int)$me['id']);
            } elseif ($targetId !== (int)$me['id']) {
                $target = fetch_user($targetId);
                if (!$target) fail('کاربر پیدا نشد.', 404);
            }
            db()->prepare('
                INSERT INTO chat_pins (user_id, kind, target_id) VALUES (?, ?, ?)
                ON CONFLICT(user_id, kind, target_id) DO UPDATE SET created_at = CURRENT_TIMESTAMP
            ')->execute([(int)$me['id'], $kind, $targetId]);
            respond(['ok' => true]);
        }

        case 'chat_unpin': {
            $me = require_auth();
            $in = input();
            $kind = (($in['kind'] ?? '') === 'group') ? 'group' : 'dm';
            $targetId = (int)($in['id'] ?? 0);
            if ($targetId <= 0) fail('اطلاعات نامعتبر است.');
            db()->prepare('DELETE FROM chat_pins WHERE user_id = ? AND kind = ? AND target_id = ?')
                ->execute([(int)$me['id'], $kind, $targetId]);
            respond(['ok' => true]);
        }

        // ==================== حذف گفتگو ====================
        case 'chat_delete': {
            $me = require_auth();
            $in = input();
            $kind = isset($in['kind']) ? $in['kind'] : '';
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) fail('شناسه نامعتبر است.');

            if ($kind === 'dm') {
                $lastId = db()->prepare('
                    SELECT MAX(id) AS mx FROM messages
                    WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                ');
                $lastId->execute([$me['id'], $id, $id, $me['id']]);
                $mx = (int)($lastId->fetch()['mx'] ?? 0);
                db()->prepare('
                    INSERT INTO dm_hidden (user_id, other_id, hidden_before_id) VALUES (?, ?, ?)
                    ON CONFLICT(user_id, other_id) DO UPDATE SET hidden_before_id = excluded.hidden_before_id
                ')->execute([$me['id'], $id, $mx]);
            } elseif ($kind === 'group') {
                // بررسی اینکه گروه رسمی نباشد
                $stmt = db()->prepare('SELECT is_official FROM groups_ WHERE id = ?');
                $stmt->execute([$id]);
                $group = $stmt->fetch();
                if ($group && (int)($group['is_official'] ?? 0) === 1) {
                    fail('نمی‌توان از گروه/کانال رسمی خارج شد.', 403);
                }
                db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')
                    ->execute([$id, $me['id']]);
            } else {
                fail('نوع گفتگو نامعتبر است.');
            }

            respond(['ok' => true]);
        }

        // ==================== پنل ادمین ====================
        case 'admin_dashboard': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $pdo = db();
            $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $totalGroups = (int)$pdo->query('SELECT COUNT(*) FROM groups_')->fetchColumn();
            $todayReports = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE DATE(created_at) = DATE('now')")->fetchColumn();
            $totalReports = (int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn();
            $pendingReports = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();
            $bannedUsers = (int)$pdo->query('SELECT COUNT(*) FROM banned_phones')->fetchColumn();
            $suspendedUsers = (int)$pdo->query('SELECT COUNT(*) FROM suspensions')->fetchColumn();
            $verifiedUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE verified = 1')->fetchColumn();
            $openTickets = (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();

            respond(['ok' => true, 'stats' => [
                'total_users' => $totalUsers,
                'total_groups' => $totalGroups,
                'total_reports' => $totalReports,
                'pending_reports' => $pendingReports,
                'today_reports' => $todayReports,
                'banned_users' => $bannedUsers,
                'suspended_users' => $suspendedUsers,
                'verified_users' => $verifiedUsers,
                'open_tickets' => $openTickets,
            ]]);
        }

        case 'admin_reports_detailed': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $stmt = db()->prepare('
                SELECT 
                    r.*,
                    u.name AS reporter_name,
                    u.phone AS reporter_phone,
                    u.username AS reporter_username,
                    u.avatar_photo AS reporter_avatar,
                    u.verified AS reporter_verified,
                    u.premium_until AS reporter_premium_until,
                    u.premium_self_off AS reporter_premium_self_off,
                    CASE 
                        WHEN r.target_kind = \'dm\' THEN (
                            SELECT name FROM users WHERE id = r.target_id
                        )
                        WHEN r.target_kind = \'story\' THEN (
                            SELECT us.name FROM stories st JOIN users us ON us.id = st.user_id WHERE st.id = r.target_id
                        )
                        WHEN r.target_kind = \'short\' THEN (
                            SELECT us.name FROM shorts sh JOIN users us ON us.id = sh.created_by WHERE sh.id = r.target_id
                        )
                        ELSE (
                            SELECT name FROM groups_ WHERE id = r.target_id
                        )
                    END AS target_name,
                    CASE 
                        WHEN r.target_kind = \'dm\' THEN (
                            SELECT phone FROM users WHERE id = r.target_id
                        )
                        WHEN r.target_kind = \'story\' THEN (
                            SELECT us.phone FROM stories st JOIN users us ON us.id = st.user_id WHERE st.id = r.target_id
                        )
                        WHEN r.target_kind = \'short\' THEN (
                            SELECT us.phone FROM shorts sh JOIN users us ON us.id = sh.created_by WHERE sh.id = r.target_id
                        )
                        ELSE NULL
                    END AS target_phone,
                    CASE 
                        WHEN r.target_kind = \'dm\' THEN (
                            SELECT username FROM users WHERE id = r.target_id
                        )
                        WHEN r.target_kind = \'story\' THEN (
                            SELECT us.username FROM stories st JOIN users us ON us.id = st.user_id WHERE st.id = r.target_id
                        )
                        WHEN r.target_kind = \'short\' THEN (
                            SELECT us.username FROM shorts sh JOIN users us ON us.id = sh.created_by WHERE sh.id = r.target_id
                        )
                        ELSE (
                            SELECT username FROM groups_ WHERE id = r.target_id
                        )
                    END AS target_username,
                    CASE 
                        WHEN r.target_kind = \'story\' THEN (
                            SELECT avatar_photo FROM stories st JOIN users us ON us.id = st.user_id WHERE st.id = r.target_id
                        )
                        WHEN r.target_kind = \'short\' THEN (
                            SELECT avatar_photo FROM shorts sh JOIN users us ON us.id = sh.created_by WHERE sh.id = r.target_id
                        )
                        ELSE NULL
                    END AS target_owner_avatar,
                    CASE 
                        WHEN r.target_kind = \'story\' THEN (SELECT media FROM stories WHERE id = r.target_id)
                        ELSE NULL
                    END AS target_media,
                    CASE 
                        WHEN r.target_kind = \'story\' THEN (SELECT type FROM stories WHERE id = r.target_id)
                        ELSE NULL
                    END AS target_media_type,
                    CASE 
                        WHEN r.target_kind = \'story\' THEN (SELECT caption FROM stories WHERE id = r.target_id)
                        WHEN r.target_kind = \'short\' THEN (SELECT caption FROM shorts WHERE id = r.target_id)
                        ELSE NULL
                    END AS target_caption,
                    CASE 
                        WHEN r.target_kind = \'short\' THEN (SELECT url FROM shorts WHERE id = r.target_id)
                        ELSE NULL
                    END AS target_url,
                    CASE 
                        WHEN r.target_kind = \'story\' THEN (SELECT 1 FROM stories WHERE id = r.target_id)
                        WHEN r.target_kind = \'short\' THEN (SELECT 1 FROM shorts WHERE id = r.target_id)
                        ELSE NULL
                    END AS target_exists,
                    CASE 
                        WHEN r.message_id IS NOT NULL AND r.target_kind = \'dm\' THEN (
                            SELECT body FROM messages WHERE id = r.message_id
                        )
                        WHEN r.message_id IS NOT NULL AND r.target_kind IN (\'group\', \'channel\') THEN (
                            SELECT body FROM group_messages WHERE id = r.message_id
                        )
                        ELSE NULL
                    END AS message_body,
                    CASE 
                        WHEN r.message_id IS NOT NULL AND r.target_kind = \'dm\' THEN (
                            SELECT type FROM messages WHERE id = r.message_id
                        )
                        WHEN r.message_id IS NOT NULL AND r.target_kind IN (\'group\', \'channel\') THEN (
                            SELECT type FROM group_messages WHERE id = r.message_id
                        )
                        ELSE NULL
                    END AS message_type,
                    CASE 
                        WHEN r.target_kind = \'dm\' THEN r.target_id
                        WHEN r.target_kind = \'story\' THEN (
                            SELECT user_id FROM stories WHERE id = r.target_id
                        )
                        WHEN r.target_kind = \'short\' THEN (
                            SELECT created_by FROM shorts WHERE id = r.target_id
                        )
                        WHEN r.message_id IS NOT NULL THEN (
                            SELECT sender_id FROM group_messages WHERE id = r.message_id
                        )
                        ELSE NULL
                    END AS reported_user_id
                FROM reports r
                JOIN users u ON u.id = r.reporter_id
                ORDER BY r.created_at DESC
                LIMIT 500
            ');
            $stmt->execute();
            $reports = $stmt->fetchAll();

            foreach ($reports as &$rep) {
                $rep['reporter_is_premium'] = is_user_premium([
                    'phone' => $rep['reporter_phone'] ?? null,
                    'verified' => $rep['reporter_verified'] ?? 0,
                    'premium_until' => $rep['reporter_premium_until'] ?? null,
                    'premium_self_off' => $rep['reporter_premium_self_off'] ?? 0,
                ]);
            }
            unset($rep);

            respond(['ok' => true, 'reports' => $reports]);
        }

        case 'admin_report_chat': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $reportId = (int)($in['id'] ?? 0);
            if ($reportId <= 0) fail('گزارش نامعتبر است.');

            $stmt = db()->prepare('SELECT * FROM reports WHERE id = ?');
            $stmt->execute([$reportId]);
            $report = $stmt->fetch();
            if (!$report) fail('گزارش پیدا نشد.', 404);

            $kind = $report['target_kind'];
            $targetId = (int)$report['target_id'];
            $reporterId = (int)$report['reporter_id'];
            $messages = [];
            $chatInfo = [];

            if ($kind === 'dm') {
                $stmt = db()->prepare("
                    SELECT m.*, us.name AS sender_name
                    FROM messages m JOIN users us ON us.id = m.sender_id
                    WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
                    ORDER BY m.id DESC LIMIT 60
                ");
                $stmt->execute([$reporterId, $targetId, $targetId, $reporterId]);
                $messages = array_reverse($stmt->fetchAll());
                $u1 = fetch_user($reporterId);
                $u2 = fetch_user($targetId);
                $chatInfo = ['kind' => 'dm', 'title' => ($u1['name'] ?? '؟') . ' و ' . ($u2['name'] ?? '؟')];
            } else {
                $stmt = db()->prepare("
                    SELECT gm.*, us.name AS sender_name
                    FROM group_messages gm JOIN users us ON us.id = gm.sender_id
                    WHERE gm.group_id = ?
                    ORDER BY gm.id DESC LIMIT 60
                ");
                $stmt->execute([$targetId]);
                $messages = array_reverse($stmt->fetchAll());
                $gstmt = db()->prepare('SELECT name FROM groups_ WHERE id = ?');
                $gstmt->execute([$targetId]);
                $g = $gstmt->fetch();
                $chatInfo = ['kind' => $kind, 'title' => $g['name'] ?? 'گروه/کانال'];
            }

            $out = array_map(function ($m) {
                return [
                    'id' => (int)$m['id'],
                    'sender_name' => $m['sender_name'],
                    'type' => $m['type'],
                    'body' => (int)($m['deleted'] ?? 0) === 1 ? '(حذف‌شده)' : $m['body'],
                    'created_at' => $m['created_at'],
                ];
            }, $messages);

            respond(['ok' => true, 'chat' => $chatInfo, 'messages' => $out, 'highlight_id' => (int)($report['message_id'] ?? 0)]);
        }

        case 'admin_update_report_status': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $reportId = (int)($in['id'] ?? 0);
            $status = isset($in['status']) && in_array($in['status'], ['pending', 'resolved', 'dismissed']) ? $in['status'] : '';
            if ($reportId <= 0 || $status === '') fail('اطلاعات نامعتبر است.');

            db()->prepare('UPDATE reports SET status = ? WHERE id = ?')->execute([$status, $reportId]);

            respond(['ok' => true]);
        }

        case 'admin_delete_report': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $reportId = (int)($in['id'] ?? 0);
            if ($reportId <= 0) fail('گزارش نامعتبر است.');

            db()->prepare('DELETE FROM reports WHERE id = ?')->execute([$reportId]);

            respond(['ok' => true]);
        }

        case 'admin_inspect_user': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $userId = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
            if ($userId <= 0) fail('کاربر نامعتبر است.');

            $target = fetch_user($userId);
            if (!$target) fail('کاربر پیدا نشد.', 404);

            $status = get_user_status($target);
            $userOut = public_user($target, true, true);
            $userOut['status'] = $status;
            $userOut['is_official'] = (int)($target['is_official'] ?? 0) === 1;
            $userOut['can_ban'] = $target['phone'] !== VERIFY_ADMIN_PHONE
                && (int)($target['is_official'] ?? 0) !== 1
                && (int)$target['id'] !== (int)$me['id'];

            $pdo = db();

            // آخرین پیام‌های خصوصی کاربر (ارسالی و دریافتی) برای بررسی گزارش
            $stmt = $pdo->prepare("
                SELECT m.id, m.type, m.body, m.deleted, m.created_at,
                       (m.sender_id = ?) AS is_outgoing,
                       u.name AS other_name, u.username AS other_username
                FROM messages m
                JOIN users u ON u.id = (CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END)
                WHERE m.sender_id = ? OR m.receiver_id = ?
                ORDER BY m.id DESC LIMIT 40
            ");
            $stmt->execute([$userId, $userId, $userId, $userId]);
            $dmMessages = $stmt->fetchAll();

            // آخرین پیام‌های ارسالی کاربر در گروه‌ها/کانال‌ها
            $stmt2 = $pdo->prepare("
                SELECT gm.id, gm.type, gm.body, gm.deleted, gm.created_at,
                       g.name AS group_name, g.type AS group_type
                FROM group_messages gm
                JOIN groups_ g ON g.id = gm.group_id
                WHERE gm.sender_id = ?
                ORDER BY gm.id DESC LIMIT 40
            ");
            $stmt2->execute([$userId]);
            $groupMessages = $stmt2->fetchAll();

            // سابقه‌ی گزارش‌های ثبت‌شده علیه این کاربر
            $stmt3 = $pdo->prepare("
                SELECT r.id, r.reason, r.status, r.created_at, u.name AS reporter_name
                FROM reports r
                JOIN users u ON u.id = r.reporter_id
                WHERE (r.target_kind = 'dm' AND r.target_id = ?)
                   OR (r.message_id IS NOT NULL AND r.message_id IN (
                        SELECT id FROM group_messages WHERE sender_id = ?
                   ))
                ORDER BY r.created_at DESC LIMIT 20
            ");
            $stmt3->execute([$userId, $userId]);
            $reportsAgainst = $stmt3->fetchAll();

            respond([
                'ok' => true,
                'user' => $userOut,
                'dm_messages' => $dmMessages,
                'group_messages' => $groupMessages,
                'reports_against' => $reportsAgainst,
            ]);
        }

        // ==================== مدیریت تبلیغات ====================
        case 'admin_create_ad': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $title = trim((string)($in['title'] ?? ''));
            $body = trim((string)($in['body'] ?? ''));
            $link = trim((string)($in['link'] ?? ''));
            $image = trim((string)($in['image'] ?? ''));

            if (mb_strlen($title) < 3) fail('عنوان تبلیغ حداقل ۳ کاراکتر باید باشد.');
            if (mb_strlen($body) < 5) fail('متن تبلیغ حداقل ۵ کاراکتر باید باشد.');
            if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) {
                fail('لینک وارد شده معتبر نیست.');
            }
            if ($image !== '' && !str_starts_with($image, 'data:image/')) {
                fail('فرمت عکس نامعتبر است.');
            }

            $stmt = db()->prepare('INSERT INTO ads (title, body, link, image, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$title, $body, $link, $image ?: null, $me['id']]);

            respond(['ok' => true, 'message' => 'تبلیغ با موفقیت ارسال شد.']);
        }

        case 'admin_get_ads': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $stmt = db()->prepare('
                SELECT a.*, u.name AS creator_name
                FROM ads a
                JOIN users u ON u.id = a.created_by
                ORDER BY a.created_at DESC
                LIMIT 50
            ');
            $stmt->execute();
            $ads = $stmt->fetchAll();

            respond(['ok' => true, 'ads' => $ads]);
        }

        case 'admin_delete_ad': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $adId = (int)($in['id'] ?? 0);
            if ($adId <= 0) fail('تبلیغ نامعتبر است.');

            db()->prepare('DELETE FROM ads WHERE id = ?')->execute([$adId]);

            respond(['ok' => true]);
        }

        case 'admin_toggle_ad': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $adId = (int)($in['id'] ?? 0);
            $active = !empty($in['active']) ? 1 : 0;
            if ($adId <= 0) fail('تبلیغ نامعتبر است.');

            db()->prepare('UPDATE ads SET is_active = ? WHERE id = ?')->execute([$active, $adId]);

            respond(['ok' => true]);
        }

        // ==================== دریافت تبلیغات فعال ====================
        case 'get_active_ad':
        case 'get_active_ads': {
            $me = require_auth();
            $ads = get_active_ads();
            respond(['ok' => true, 'ads' => $ads, 'ad' => $ads[0] ?? null]);
        }

        // ==================== کاربران ====================
        case 'admin_users': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $stmt = db()->prepare('
                SELECT id, phone, name, username, verified, suspended, is_official, avatar_photo, theme, premium_until,
                       (SELECT 1 FROM banned_phones WHERE phone = users.phone) AS banned
                FROM users ORDER BY id DESC LIMIT 500
            ');
            $stmt->execute();
            $users = $stmt->fetchAll();
            $users = array_map(function ($u) {
                $u['is_main_admin'] = is_admin_phone($u['phone'] ?? null);
                $u['is_premium'] = is_user_premium($u);
                return $u;
            }, $users);

            respond(['ok' => true, 'users' => $users]);
        }

        case 'admin_users_search': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $q = isset($_GET['q']) ? trim($_GET['q']) : '';
            $sql = '
                SELECT id, phone, name, username, verified, suspended, is_official, avatar_photo, theme, premium_until,
                       (SELECT 1 FROM banned_phones WHERE phone = users.phone) AS banned
                FROM users
            ';
            $params = [];
            if ($q !== '') {
                $sql .= ' WHERE phone LIKE ? OR name LIKE ? OR username LIKE ?';
                $like = '%' . $q . '%';
                $params = [$like, $like, $like];
            }
            $sql .= ' ORDER BY id DESC LIMIT 200';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            $users = array_map(function ($u) {
                $u['is_main_admin'] = is_admin_phone($u['phone'] ?? null);
                $u['is_premium'] = is_user_premium($u);
                return $u;
            }, $users);

            respond(['ok' => true, 'users' => $users]);
        }

        // ==================== گزارش کاربران پرمیوم (فقط لیست کاربرانی که پرمیوم‌اند) ====================
        case 'admin_premium_report': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $stmt = db()->prepare('
                SELECT id, phone, name, username, verified, is_official, avatar_photo, premium_until, premium_name, premium_self_off,
                       (SELECT COUNT(*) FROM groups_ WHERE owner_id = users.id) AS groups_owned
                FROM users
                WHERE is_official = 0
                ORDER BY id DESC
                LIMIT 2000
            ');
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $users = [];
            foreach ($rows as $u) {
                if (!is_user_premium($u)) continue;
                $users[] = [
                    'id' => (int)$u['id'],
                    'phone' => $u['phone'],
                    'name' => $u['name'],
                    'username' => $u['username'],
                    'is_main_admin' => is_admin_phone($u['phone'] ?? null),
                    'verified' => (int)($u['verified'] ?? 0) === 1,
                    'premium_until' => $u['premium_until'],
                    'premium_name' => $u['premium_name'],
                    'groups_owned' => (int)$u['groups_owned'],
                    // منبع پرمیوم: اعطای دستی ادمین، یا تیک‌آبی/مدیر اصلی که به‌صورت خودکار پرمیوم است
                    'premium_source' => (is_admin_phone($u['phone'] ?? null) || (int)($u['verified'] ?? 0) === 1) ? 'auto' : 'granted',
                ];
            }
            // پرمیوم‌های خریداری/اعطاشده اول، بعد پرمیوم‌های خودکار (ادمین/تیک‌آبی)
            usort($users, function ($a, $b) {
                if ($a['premium_source'] !== $b['premium_source']) return $a['premium_source'] === 'granted' ? -1 : 1;
                return $b['id'] <=> $a['id'];
            });

            respond(['ok' => true, 'users' => $users, 'total' => count($users)]);
        }

        // ==================== ثبت پرمیوم برای کاربر (تعداد روز دلخواه) ====================
        case 'admin_premium_set': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $targetId = (int)($in['id'] ?? 0);
            $days = (int)($in['days'] ?? 0);
            if ($targetId <= 0) fail('کاربر نامعتبر است.');
            if ($days <= 0 || $days > 3650) fail('تعداد روز باید بین ۱ تا ۳۶۵۰ باشد.');

            $target = fetch_user($targetId);
            if (!$target) fail('کاربر یافت نشد.', 404);
            if ((int)($target['is_official'] ?? 0) === 1) fail('اکانت رسمی نیازی به پرمیوم ندارد.');

            $until = date('Y-m-d H:i:s', time() + ($days * 86400));
            db()->prepare('UPDATE users SET premium_until = ? WHERE id = ?')->execute([$until, $targetId]);

            $updated = fetch_user($targetId);
            respond(['ok' => true, 'premium_until' => $until, 'is_premium' => is_user_premium($updated)]);
        }

        // ==================== لغو فوری پرمیوم کاربر (برای مواقع اضطراری) ====================
        case 'admin_premium_cancel': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $targetId = (int)($in['id'] ?? 0);
            if ($targetId <= 0) fail('کاربر نامعتبر است.');

            $target = fetch_user($targetId);
            if (!$target) fail('کاربر یافت نشد.', 404);
            if ((int)($target['is_official'] ?? 0) === 1) fail('اکانت رسمی نیازی به پرمیوم ندارد.');

            db()->prepare('UPDATE users SET premium_until = NULL WHERE id = ?')->execute([$targetId]);

            $updated = fetch_user($targetId);
            respond(['ok' => true, 'is_premium' => is_user_premium($updated)]);
        }

        case 'admin_groups': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $typeFilter = (string)(isset($_GET['type']) ? $_GET['type'] : '');
            $q = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));

            $where = [];
            $params = [];
            if ($typeFilter === 'group' || $typeFilter === 'channel') {
                $where[] = 'g.type = ?';
                $params[] = $typeFilter;
            }
            if ($q !== '') {
                $qNorm = ltrim($q, '@');
                $where[] = '(g.username LIKE ? OR g.name LIKE ?)';
                $params[] = '%' . $qNorm . '%';
                $params[] = '%' . $qNorm . '%';
            }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt = db()->prepare("
                SELECT g.*, u.name AS owner_name,
                       (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS member_count
                FROM groups_ g
                JOIN users u ON u.id = g.owner_id
                $whereSql
                ORDER BY g.id DESC LIMIT 500
            ");
            $stmt->execute($params);
            $groups = $stmt->fetchAll();

            respond(['ok' => true, 'groups' => $groups]);
        }

        case 'admin_ban_user': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $userId = (int)($in['id'] ?? 0);
            $reason = trim((string)($in['reason'] ?? 'تخلف از قوانین'));
            if ($userId <= 0) fail('کاربر نامعتبر است.');
            if ($userId === (int)$me['id']) fail('نمی‌توانی خودت را سیک کنی.');

            $target = fetch_user($userId);
            if (!$target) fail('کاربر پیدا نشد.', 404);
            if ($target['phone'] === VERIFY_ADMIN_PHONE) fail('نمی‌توان ادمین را سیک کرد.');
            if ((int)($target['is_official'] ?? 0) === 1) fail('اکانت رسمی قابل سیک شدن نیست.');

            db()->prepare('INSERT OR IGNORE INTO banned_phones (phone, banned_by, reason) VALUES (?, ?, ?)')
                ->execute([$target['phone'], $me['id'], $reason]);
            db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);

            respond(['ok' => true, 'message' => 'کاربر از پیام‌رسان سیک شد.']);
        }

        case 'admin_unban_user': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $userId = (int)($in['id'] ?? 0);
            if ($userId <= 0) fail('کاربر نامعتبر است.');

            $target = fetch_user($userId);
            if (!$target) fail('کاربر پیدا نشد.', 404);

            db()->prepare('DELETE FROM banned_phones WHERE phone = ?')->execute([$target['phone']]);

            respond(['ok' => true, 'message' => 'سیک کاربر برداشته شد.']);
        }

        case 'admin_ban_group': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $groupId = (int)($in['id'] ?? 0);
            if ($groupId <= 0) fail('گروه نامعتبر است.');

            db()->prepare('UPDATE groups_ SET banned = 1 WHERE id = ?')->execute([$groupId]);

            respond(['ok' => true, 'message' => 'گروه/کانال سیک شد.']);
        }

        case 'admin_unban_group': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $groupId = (int)($in['id'] ?? 0);
            if ($groupId <= 0) fail('گروه نامعتبر است.');

            db()->prepare('UPDATE groups_ SET banned = 0 WHERE id = ?')->execute([$groupId]);

            respond(['ok' => true, 'message' => 'سیک گروه/کانال برداشته شد.']);
        }

        case 'admin_delete_group': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین اصلی مجاز است.', 403);

            $in = input();
            $groupId = (int)($in['id'] ?? 0);
            if ($groupId <= 0) fail('گروه نامعتبر است.');

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM reactions WHERE msg_kind = 'group' AND msg_id IN (SELECT id FROM group_messages WHERE group_id = ?)")->execute([$groupId]);
                $pdo->prepare('DELETE FROM groups_ WHERE id = ?')->execute([$groupId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                fail('خطا در حذف گروه.', 500);
            }

            respond(['ok' => true]);
        }

        // ==================== تیکت‌های پشتیبانی ====================
        case 'ticket_create': {
            $me = require_auth();
            $in = input();
            $message = trim((string)($in['message'] ?? ''));
            $subject = trim((string)($in['subject'] ?? ''));
            if (mb_strlen($message) < 3) fail('متن تیکت خیلی کوتاه است.');
            if (mb_strlen($message) > 2000) fail('متن تیکت خیلی طولانی است.');

            db()->prepare('INSERT INTO tickets (user_id, user_name, user_phone, user_username, subject, message) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    (int)$me['id'],
                    $me['name'],
                    $me['phone'],
                    $me['username'] ?? null,
                    $subject !== '' ? $subject : 'سوال از دستیار پشتیبانی',
                    $message,
                ]);
            $ticketId = (int)db()->lastInsertId();

            respond(['ok' => true, 'ticket_id' => $ticketId]);
        }

        case 'admin_tickets_list': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $stmt = db()->query('SELECT * FROM tickets ORDER BY id DESC LIMIT 300');
            $tickets = $stmt->fetchAll();
            respond(['ok' => true, 'tickets' => $tickets]);
        }

        case 'admin_ticket_reply': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $ticketId = (int)($in['id'] ?? 0);
            $reply = trim((string)($in['reply'] ?? ''));
            if ($ticketId <= 0) fail('تیکت نامعتبر است.');
            if (mb_strlen($reply) < 1) fail('متن پاسخ را بنویس.');

            $stmt = db()->prepare('SELECT * FROM tickets WHERE id = ?');
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch();
            if (!$ticket) fail('تیکت پیدا نشد.', 404);

            $body = "✅ پاسخ تیکت پشتیبانی شما\n\n"
                  . "📝 سوال شما: " . $ticket['message'] . "\n\n"
                  . "💬 پاسخ پشتیبانی:\n" . $reply;
            send_bot_message((int)$ticket['user_id'], $body);

            // پس از ارسال پاسخ، تیکت به‌صورت خودکار حذف می‌شود
            db()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);

            respond(['ok' => true]);
        }

        case 'admin_ticket_delete': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $ticketId = (int)($in['id'] ?? 0);
            if ($ticketId <= 0) fail('تیکت نامعتبر است.');
            db()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
            respond(['ok' => true]);
        }

        // ==================== شورت‌ها (Shorts) ====================

        case 'admin_short_add': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('این عملیات فقط برای ادمین‌ها مجاز است.', 403);

            $in = input();
            $url = trim((string)($in['url'] ?? ''));
            $caption = mb_substr(trim((string)($in['caption'] ?? '')), 0, 500);
            $type = (($in['type'] ?? 'video') === 'ad') ? 'ad' : 'video';

            if ($type === 'ad') {
                $adTitle = mb_substr(trim((string)($in['ad_title'] ?? '')), 0, 80);
                $link = trim((string)($in['url'] ?? ''));
                $image = trim((string)($in['image'] ?? ''));

                if (mb_strlen($adTitle) < 2) fail('عنوان تبلیغ حداقل ۲ کاراکتر باید باشد.');
                if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL)) fail('لینک سایت تبلیغ‌دهنده معتبر نیست.');
                if ($image === '' || !str_starts_with($image, 'data:image/')) fail('عکس تبلیغ الزامی است.');

                $stmt = db()->prepare('INSERT INTO shorts (platform, url, embed_url, caption, thumbnail, type, ad_title, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute(['ad', $link, '', $caption, $image, 'ad', $adTitle, $me['id']]);
                $id = (int)db()->lastInsertId();

                respond(['ok' => true, 'message' => 'تبلیغ شورتی با موفقیت اضافه شد.', 'id' => $id, 'platform' => 'ad']);
            }

            if ($url === '') fail('لینک شورت را وارد کن.');
            $parsed = parse_short_url($url);
            if (empty($parsed)) fail('لینک باید از یوتیوب باشد.');

            $stmt = db()->prepare("INSERT INTO shorts (platform, url, embed_url, caption, created_by, source, is_active) VALUES (?, ?, ?, ?, ?, 'admin', 1)");
            $stmt->execute([$parsed['platform'], $url, $parsed['embed_url'], $caption, $me['id']]);
            $id = (int)db()->lastInsertId();

            respond(['ok' => true, 'message' => 'شورت با موفقیت اضافه شد.', 'id' => $id, 'platform' => $parsed['platform']]);
        }

        // کاربر عادی یک لینک یوتیوب رو که به نظرش باحال اومده پیشنهاد می‌ده.
        // دیگه نیازی به تأیید دستی ادمین نیست؛ لینک بلافاصله فعال ثبت می‌شه و خودکار
        // (چند ثانیه بعد، با رفرش‌شدنِ فید شورت‌ها توی سمت کاربر) توی لیست شورت‌ها نمایش داده می‌شه.
        case 'short_submit': {
            $me = require_auth();

            $in = input();
            $url = trim((string)($in['url'] ?? ''));
            $caption = mb_substr(trim((string)($in['caption'] ?? '')), 0, 500);

            if ($url === '') fail('لینک ویدیوی یوتیوب را وارد کن.');
            $parsed = parse_short_url($url);
            if (empty($parsed)) fail('فقط لینک یوتیوب پذیرفته می‌شود.');

            // برای جلوگیری از اسپم، سقف روزانه‌ی تعداد لینک‌های ارسالی هر کاربر همچنان برقرار است
            $dailyStmt = db()->prepare("SELECT COUNT(*) AS c FROM shorts WHERE created_by = ? AND source = 'user' AND created_at >= datetime('now', '-1 day')");
            $dailyStmt->execute([$me['id']]);
            if ((int)$dailyStmt->fetch()['c'] >= 10) {
                fail('امروز به اندازه کافی لینک پیشنهاد دادی، فردا دوباره امتحان کن.');
            }

            $stmt = db()->prepare("INSERT INTO shorts (platform, url, embed_url, caption, created_by, source, is_active) VALUES (?, ?, ?, ?, ?, 'user', 1)");
            $stmt->execute([$parsed['platform'], $url, $parsed['embed_url'], $caption, $me['id']]);
            $id = (int)db()->lastInsertId();

            respond(['ok' => true, 'message' => 'لینکت ثبت شد و به‌زودی توی شورت‌ها نمایش داده می‌شه.', 'id' => $id]);
        }

        case 'admin_shorts_list': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $stmt = db()->prepare('
                SELECT s.*, u.name AS creator_name
                FROM shorts s
                JOIN users u ON u.id = s.created_by
                ORDER BY s.id DESC
                LIMIT 200
            ');
            $stmt->execute();
            respond(['ok' => true, 'shorts' => $stmt->fetchAll()]);
        }

        case 'admin_short_delete': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $shortId = (int)($in['id'] ?? 0);
            if ($shortId <= 0) fail('شورت نامعتبر است.');

            db()->prepare('DELETE FROM shorts WHERE id = ?')->execute([$shortId]);
            respond(['ok' => true]);
        }

        case 'admin_short_toggle': {
            $me = require_auth();
            $isAdmin = ($me['phone'] === VERIFY_ADMIN_PHONE) || ((int)$me['verified'] === 1);
            if (!$isAdmin) fail('اجازه ندارید.', 403);

            $in = input();
            $shortId = (int)($in['id'] ?? 0);
            $active = !empty($in['active']) ? 1 : 0;
            if ($shortId <= 0) fail('شورت نامعتبر است.');

            db()->prepare('UPDATE shorts SET is_active = ? WHERE id = ?')->execute([$active, $shortId]);
            respond(['ok' => true]);
        }

        case 'shorts_feed': {
            $me = require_auth();

            $stmt = db()->prepare('SELECT id, name, username, avatar_photo, verified, is_official FROM users WHERE phone = ? LIMIT 1');
            $stmt->execute([VERIFY_ADMIN_PHONE]);
            $mainAdmin = $stmt->fetch();
            $adminPoster = $mainAdmin ? [
                'id' => (int)$mainAdmin['id'],
                'name' => $mainAdmin['name'],
                'username' => $mainAdmin['username'],
                'avatar_photo' => $mainAdmin['avatar_photo'],
                'verified' => true,
                'is_official' => true,
                'is_main_admin' => true,
            ] : [
                'id' => 0, 'name' => APP_NAME, 'username' => null, 'avatar_photo' => null,
                'verified' => true, 'is_official' => true, 'is_main_admin' => true,
            ];

            $rows = db()->prepare('
                SELECT s.*, u.name AS creator_name, u.username AS creator_username,
                       u.avatar_photo AS creator_avatar, u.verified AS creator_verified,
                       u.is_official AS creator_is_official
                FROM shorts s
                JOIN users u ON u.id = s.created_by
                WHERE s.is_active = 1
                ORDER BY s.id DESC
                LIMIT 300
            ');
            $rows->execute();
            $shorts = $rows->fetchAll();

            $likedIds = [];
            if ($shorts) {
                $ids = array_column($shorts, 'id');
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $lk = db()->prepare("SELECT short_id FROM short_likes WHERE user_id = ? AND short_id IN ($ph)");
                $lk->execute(array_merge([$me['id']], $ids));
                foreach ($lk->fetchAll() as $r) $likedIds[(int)$r['short_id']] = true;
            }

            $out = [];
            foreach ($shorts as $s) {
                $isUserShared = ($s['type'] ?? 'video') === 'video' && ($s['source'] ?? 'admin') === 'user';
                $poster = $isUserShared ? [
                    'id' => (int)$s['created_by'],
                    'name' => $s['creator_name'],
                    'username' => $s['creator_username'],
                    'avatar_photo' => $s['creator_avatar'],
                    'verified' => (bool)$s['creator_verified'],
                    'is_official' => (bool)$s['creator_is_official'],
                    'is_main_admin' => false,
                ] : $adminPoster;

                $out[] = [
                    'id' => (int)$s['id'],
                    'type' => $s['type'] ?? 'video',
                    'platform' => $s['platform'],
                    'url' => $s['url'],
                    'embed_url' => $s['embed_url'],
                    'caption' => $s['caption'],
                    'ad_title' => $s['ad_title'] ?? null,
                    'image' => $s['thumbnail'] ?? null,
                    'created_at' => $s['created_at'],
                    'views_count' => (int)$s['views_count'],
                    'likes_count' => (int)$s['likes_count'],
                    'comments_count' => (int)$s['comments_count'],
                    'liked_by_me' => isset($likedIds[(int)$s['id']]),
                    'is_user_shared' => $isUserShared,
                    'poster' => $poster,
                ];
            }

            respond(['ok' => true, 'shorts' => $out]);
        }

        // یک شورتِ مشخص را با آیدی برمی‌گرداند؛ برای وقتی که شورت به‌خاطر سقف تعداد
        // (LIMIT فید) یا تازه‌ساخته‌بودن، هنوز در لیست فید کاربر لود نشده باشد
        // (مثلاً وقتی کسی لینک یک شورت را در چت برای دیگری می‌فرستد)
        case 'short_get': {
            $me = require_auth();
            $in = input();
            $shortId = (int)($in['id'] ?? 0);
            if ($shortId <= 0) fail('شورت نامعتبر است.');

            $stmt = db()->prepare('
                SELECT s.*, u.name AS creator_name, u.username AS creator_username,
                       u.avatar_photo AS creator_avatar, u.verified AS creator_verified,
                       u.is_official AS creator_is_official
                FROM shorts s
                JOIN users u ON u.id = s.created_by
                WHERE s.id = ? AND s.is_active = 1
            ');
            $stmt->execute([$shortId]);
            $s = $stmt->fetch();
            if (!$s) fail('این شورت دیگر در دسترس نیست.', 404);

            $astmt = db()->prepare('SELECT id, name, username, avatar_photo, verified, is_official FROM users WHERE phone = ? LIMIT 1');
            $astmt->execute([VERIFY_ADMIN_PHONE]);
            $mainAdmin = $astmt->fetch();
            $adminPoster = $mainAdmin ? [
                'id' => (int)$mainAdmin['id'],
                'name' => $mainAdmin['name'],
                'username' => $mainAdmin['username'],
                'avatar_photo' => $mainAdmin['avatar_photo'],
                'verified' => true,
                'is_official' => true,
                'is_main_admin' => true,
            ] : [
                'id' => 0, 'name' => APP_NAME, 'username' => null, 'avatar_photo' => null,
                'verified' => true, 'is_official' => true, 'is_main_admin' => true,
            ];

            $isUserShared = ($s['type'] ?? 'video') === 'video' && ($s['source'] ?? 'admin') === 'user';
            $poster = $isUserShared ? [
                'id' => (int)$s['created_by'],
                'name' => $s['creator_name'],
                'username' => $s['creator_username'],
                'avatar_photo' => $s['creator_avatar'],
                'verified' => (bool)$s['creator_verified'],
                'is_official' => (bool)$s['creator_is_official'],
                'is_main_admin' => false,
            ] : $adminPoster;

            $lk = db()->prepare('SELECT id FROM short_likes WHERE short_id = ? AND user_id = ?');
            $lk->execute([$shortId, $me['id']]);

            respond(['ok' => true, 'short' => [
                'id' => (int)$s['id'],
                'type' => $s['type'] ?? 'video',
                'platform' => $s['platform'],
                'url' => $s['url'],
                'embed_url' => $s['embed_url'],
                'caption' => $s['caption'],
                'ad_title' => $s['ad_title'] ?? null,
                'image' => $s['thumbnail'] ?? null,
                'created_at' => $s['created_at'],
                'views_count' => (int)$s['views_count'],
                'likes_count' => (int)$s['likes_count'],
                'comments_count' => (int)$s['comments_count'],
                'liked_by_me' => (bool)$lk->fetch(),
                'is_user_shared' => $isUserShared,
                'poster' => $poster,
            ]]);
        }

        case 'short_like_toggle': {
            $me = require_auth();
            $in = input();
            $shortId = (int)($in['id'] ?? 0);
            if ($shortId <= 0) fail('شورت نامعتبر است.');

            $exists = db()->prepare('SELECT id FROM shorts WHERE id = ? AND is_active = 1');
            $exists->execute([$shortId]);
            if (!$exists->fetch()) fail('شورت پیدا نشد.', 404);

            $chk = db()->prepare('SELECT id FROM short_likes WHERE short_id = ? AND user_id = ?');
            $chk->execute([$shortId, $me['id']]);
            $already = $chk->fetch();

            if ($already) {
                db()->prepare('DELETE FROM short_likes WHERE id = ?')->execute([$already['id']]);
                db()->prepare('UPDATE shorts SET likes_count = MAX(0, likes_count - 1) WHERE id = ?')->execute([$shortId]);
                $liked = false;
            } else {
                db()->prepare('INSERT INTO short_likes (short_id, user_id) VALUES (?, ?)')->execute([$shortId, $me['id']]);
                db()->prepare('UPDATE shorts SET likes_count = likes_count + 1 WHERE id = ?')->execute([$shortId]);
                $liked = true;
            }

            $cnt = db()->prepare('SELECT likes_count FROM shorts WHERE id = ?');
            $cnt->execute([$shortId]);
            $row = $cnt->fetch();

            respond(['ok' => true, 'liked' => $liked, 'likes_count' => (int)($row['likes_count'] ?? 0)]);
        }

        case 'short_view_ping': {
            $me = require_auth();
            $in = input();
            $shortId = (int)($in['id'] ?? 0);
            if ($shortId <= 0) fail('شورت نامعتبر است.');

            try {
                $ins = db()->prepare('INSERT OR IGNORE INTO short_views (short_id, user_id) VALUES (?, ?)');
                $ins->execute([$shortId, $me['id']]);
                if ($ins->rowCount() > 0) {
                    db()->prepare('UPDATE shorts SET views_count = views_count + 1 WHERE id = ?')->execute([$shortId]);
                }
            } catch (Exception $e) {}

            $cnt = db()->prepare('SELECT views_count FROM shorts WHERE id = ?');
            $cnt->execute([$shortId]);
            $row = $cnt->fetch();

            respond(['ok' => true, 'views_count' => (int)($row['views_count'] ?? 0)]);
        }

        case 'short_comments_get': {
            $me = require_auth();
            $shortId = (int)($_GET['id'] ?? 0);
            if ($shortId <= 0) fail('شورت نامعتبر است.');

            $stmt = db()->prepare('
                SELECT c.id, c.parent_id, c.body, c.created_at, c.user_id, c.likes_count,
                       u.name AS user_name, u.username AS user_username, u.avatar_photo AS user_avatar,
                       u.verified AS user_verified, u.is_official AS user_is_official
                FROM short_comments c
                JOIN users u ON u.id = c.user_id
                WHERE c.short_id = ? AND c.deleted = 0
                ORDER BY c.id ASC
                LIMIT 500
            ');
            $stmt->execute([$shortId]);
            $comments = $stmt->fetchAll();

            $likedIds = [];
            if ($comments) {
                $ids = array_column($comments, 'id');
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $lk = db()->prepare("SELECT comment_id FROM short_comment_likes WHERE user_id = ? AND comment_id IN ($ph)");
                $lk->execute(array_merge([$me['id']], $ids));
                foreach ($lk->fetchAll() as $r) $likedIds[(int)$r['comment_id']] = true;
            }
            foreach ($comments as &$c) {
                $c['likes_count'] = (int)($c['likes_count'] ?? 0);
                $c['liked_by_me'] = isset($likedIds[(int)$c['id']]);
            }
            unset($c);

            respond(['ok' => true, 'comments' => $comments]);
        }

        case 'short_comment_like_toggle': {
            $me = require_auth();
            $in = input();
            $commentId = (int)($in['id'] ?? 0);
            if ($commentId <= 0) fail('کامنت نامعتبر است.');

            $exists = db()->prepare('SELECT id FROM short_comments WHERE id = ? AND deleted = 0');
            $exists->execute([$commentId]);
            if (!$exists->fetch()) fail('کامنت پیدا نشد.', 404);

            $chk = db()->prepare('SELECT id FROM short_comment_likes WHERE comment_id = ? AND user_id = ?');
            $chk->execute([$commentId, $me['id']]);
            $already = $chk->fetch();

            if ($already) {
                db()->prepare('DELETE FROM short_comment_likes WHERE id = ?')->execute([$already['id']]);
                db()->prepare('UPDATE short_comments SET likes_count = MAX(0, likes_count - 1) WHERE id = ?')->execute([$commentId]);
                $liked = false;
            } else {
                db()->prepare('INSERT INTO short_comment_likes (comment_id, user_id) VALUES (?, ?)')->execute([$commentId, $me['id']]);
                db()->prepare('UPDATE short_comments SET likes_count = likes_count + 1 WHERE id = ?')->execute([$commentId]);
                $liked = true;
            }

            $cnt = db()->prepare('SELECT likes_count FROM short_comments WHERE id = ?');
            $cnt->execute([$commentId]);
            $row = $cnt->fetch();

            respond(['ok' => true, 'liked' => $liked, 'likes_count' => (int)($row['likes_count'] ?? 0)]);
        }

        case 'short_comment_add': {
            $me = require_auth();
            $in = input();
            $shortId = (int)($in['id'] ?? 0);
            $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 500);
            $parentId = (int)($in['parent_id'] ?? 0);

            if ($shortId <= 0) fail('شورت نامعتبر است.');
            if (mb_strlen($body) < 1) fail('متن کامنت خالی است.');

            $exists = db()->prepare('SELECT id FROM shorts WHERE id = ? AND is_active = 1');
            $exists->execute([$shortId]);
            if (!$exists->fetch()) fail('شورت پیدا نشد.', 404);

            if ($parentId > 0) {
                $pchk = db()->prepare('SELECT id FROM short_comments WHERE id = ? AND short_id = ?');
                $pchk->execute([$parentId, $shortId]);
                if (!$pchk->fetch()) $parentId = 0;
            }

            $stmt = db()->prepare('INSERT INTO short_comments (short_id, user_id, parent_id, body) VALUES (?, ?, ?, ?)');
            $stmt->execute([$shortId, $me['id'], $parentId ?: null, $body]);
            $id = (int)db()->lastInsertId();
            db()->prepare('UPDATE shorts SET comments_count = comments_count + 1 WHERE id = ?')->execute([$shortId]);

            respond(['ok' => true, 'comment' => [
                'id' => $id, 'parent_id' => $parentId ?: null, 'body' => $body,
                'created_at' => date('Y-m-d H:i:s'), 'user_id' => $me['id'],
                'user_name' => $me['name'], 'user_username' => $me['username'],
                'user_avatar' => $me['avatar_photo'], 'user_verified' => $me['verified'],
                'user_is_official' => $me['is_official'],
            ]]);
        }

        // ==================== استوری‌ها (Stories) ====================

        case 'story_can_post': {
            $me = require_auth();
            $r = can_post_story($me);
            respond(['ok' => true, 'can_post' => $r['ok'], 'is_admin' => $r['is_admin'], 'reason' => $r['reason'] ?? null]);
        }

        case 'story_create': {
            $me = require_auth();
            $perm = can_post_story($me);
            if (!$perm['ok']) fail($perm['reason'] ?? 'اجازه ندارید.', 403);

            $in = input();
            $type = ($in['type'] ?? '') === 'video' ? 'video' : 'photo';
            $media = (string)($in['media'] ?? '');
            $caption = mb_substr(trim((string)($in['caption'] ?? '')), 0, 300);

            if ($media === '') fail('محتوایی برای استوری انتخاب نشده.');
            if (strlen($media) > STORY_MEDIA_MAX_LEN[$type]) fail('حجم فایل خیلی زیاد است.');
            if (!str_starts_with($media, STORY_MEDIA_PREFIX[$type])) fail('فرمت فایل ارسالی نامعتبر است.');

            if ($type === 'video') {
                $duration = (float)($in['duration'] ?? 0);
                if ($duration <= 0 || $duration > STORY_MAX_DURATION_SECONDS) {
                    fail('ویدیوی استوری باید کمتر از ۱ دقیقه باشد.');
                }
            }

            $isAdmin = (bool)$perm['is_admin'];
            $isMainAdmin = is_admin_phone($me['phone'] ?? null);

            $stmt = db()->prepare("INSERT INTO stories (user_id, type, media, caption, is_main_admin_story, is_admin_story, expires_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now', '+1 day'))");
            $stmt->execute([$me['id'], $type, $media, $caption, $isMainAdmin ? 1 : 0, $isAdmin ? 1 : 0]);
            $id = (int)db()->lastInsertId();

            // برای مدیر اصلی و ادمین‌های تیک‌آبی، حداکثر تعداد استوری فعال هم‌زمان محدود است؛ قدیمی‌ترین‌ها حذف می‌شوند
            if ($isAdmin) {
                $activeStmt = db()->prepare("SELECT id FROM stories WHERE user_id = ? AND expires_at > datetime('now') ORDER BY id ASC");
                $activeStmt->execute([$me['id']]);
                $activeIds = array_column($activeStmt->fetchAll(), 'id');
                $extra = count($activeIds) - STORY_ADMIN_MAX_ACTIVE;
                if ($extra > 0) {
                    $toDelete = array_slice($activeIds, 0, $extra);
                    $ph = implode(',', array_fill(0, count($toDelete), '?'));
                    db()->prepare("DELETE FROM stories WHERE id IN ($ph)")->execute($toDelete);
                }
            }

            respond(['ok' => true, 'id' => $id, 'message' => 'استوری با موفقیت اضافه شد.']);
        }

        case 'story_feed': {
            $me = require_auth();

            // پاک‌سازی تنبل (lazy cleanup): استوری‌های منقضی‌شده (که رسانه‌شان به‌صورت کامل
            // base64 داخل ردیف ذخیره شده و حجیم است) برای همیشه در دیتابیس نمی‌مانند، وگرنه
            // با گذشت زمان فایل دیتابیس بی‌رویه بزرگ و همه‌ی کوئری‌ها کندتر می‌شوند. چون این
            // endpoint خیلی پرتکرار صدا زده می‌شود، این کار را فقط با احتمال کم اجرا می‌کنیم تا
            // خودش باعث نوشتن اضافه روی هر درخواست نشود.
            if (mt_rand(1, 50) === 1) {
                try { db()->exec("DELETE FROM stories WHERE expires_at <= datetime('now', '-1 day')"); } catch (Exception $e) {}
            }

            $blocked = [];
            $bStmt = db()->prepare('SELECT blocker_id, blocked_id FROM blocked_users WHERE blocker_id = ? OR blocked_id = ?');
            $bStmt->execute([$me['id'], $me['id']]);
            foreach ($bStmt->fetchAll() as $b) {
                $other = (int)$b['blocker_id'] === (int)$me['id'] ? (int)$b['blocked_id'] : (int)$b['blocker_id'];
                $blocked[$other] = true;
            }

            $rows = db()->query("
                SELECT s.*, u.name AS owner_name, u.username AS owner_username, u.avatar_photo AS owner_avatar,
                       u.verified AS owner_verified, u.is_official AS owner_is_official, u.phone AS owner_phone
                FROM stories s
                JOIN users u ON u.id = s.user_id
                WHERE s.expires_at > datetime('now')
                ORDER BY s.user_id ASC, s.id ASC
            ")->fetchAll();

            $likedIds = [];
            if ($rows) {
                $ids = array_column($rows, 'id');
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $lk = db()->prepare("SELECT story_id FROM story_likes WHERE user_id = ? AND story_id IN ($ph)");
                $lk->execute(array_merge([$me['id']], $ids));
                foreach ($lk->fetchAll() as $r) $likedIds[(int)$r['story_id']] = true;

                $vw = db()->prepare("SELECT story_id FROM story_views WHERE user_id = ? AND story_id IN ($ph)");
                $vw->execute(array_merge([$me['id']], $ids));
                foreach ($vw->fetchAll() as $r) $viewedIds[(int)$r['story_id']] = true;
            }
            $viewedIds = $viewedIds ?? [];

            $groups = [];
            foreach ($rows as $r) {
                if (isset($blocked[(int)$r['user_id']])) continue;
                $uid = (int)$r['user_id'];
                if (!isset($groups[$uid])) {
                    $groups[$uid] = [
                        'user' => [
                            'id' => $uid,
                            'name' => $r['owner_name'],
                            'username' => $r['owner_username'],
                            'avatar_photo' => $r['owner_avatar'],
                            'verified' => (bool)$r['owner_verified'],
                            'is_official' => (bool)$r['owner_is_official'],
                            'is_main_admin' => is_admin_phone($r['owner_phone']),
                        ],
                        'stories' => [],
                        'total_views' => 0,
                        'latest_created_at' => $r['created_at'],
                        'all_seen' => true,
                    ];
                }
                $groups[$uid]['stories'][] = [
                    'id' => (int)$r['id'],
                    'type' => $r['type'],
                    'media' => $r['media'],
                    'caption' => $r['caption'],
                    'created_at' => $r['created_at'],
                    'expires_at' => $r['expires_at'],
                    'views_count' => (int)$r['views_count'],
                    'likes_count' => (int)$r['likes_count'],
                    'liked_by_me' => isset($likedIds[(int)$r['id']]),
                    'seen_by_me' => isset($viewedIds[(int)$r['id']]) || $uid === (int)$me['id'],
                ];
                $groups[$uid]['total_views'] += (int)$r['views_count'];
                $groups[$uid]['latest_created_at'] = max($groups[$uid]['latest_created_at'], $r['created_at']);
                if (!isset($viewedIds[(int)$r['id']]) && $uid !== (int)$me['id']) $groups[$uid]['all_seen'] = false;
            }

            $mine = null; $mainAdmin = null; $rest = [];
            foreach ($groups as $uid => $g) {
                if ($uid === (int)$me['id']) { $mine = $g; continue; }
                if ($g['user']['is_main_admin']) { $mainAdmin = $g; continue; }
                $rest[] = $g;
            }
            usort($rest, function ($a, $b) {
                if ($a['total_views'] !== $b['total_views']) return $b['total_views'] <=> $a['total_views'];
                return strcmp($b['latest_created_at'], $a['latest_created_at']);
            });

            $ordered = [];
            if ($mine) $ordered[] = $mine;
            if ($mainAdmin) $ordered[] = $mainAdmin;
            foreach ($rest as $g) $ordered[] = $g;

            $canPost = can_post_story($me);
            respond(['ok' => true, 'groups' => array_values($ordered), 'can_post' => $canPost['ok'], 'is_admin' => $canPost['is_admin']]);
        }

        case 'story_view': {
            $me = require_auth();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) fail('استوری نامعتبر است.');

            $exists = db()->prepare("SELECT user_id FROM stories WHERE id = ? AND expires_at > datetime('now')");
            $exists->execute([$id]);
            $story = $exists->fetch();
            if (!$story) fail('استوری پیدا نشد یا منقضی شده.', 404);

            if ((int)$story['user_id'] !== (int)$me['id']) {
                try {
                    $ins = db()->prepare('INSERT OR IGNORE INTO story_views (story_id, user_id) VALUES (?, ?)');
                    $ins->execute([$id, $me['id']]);
                    if ($ins->rowCount() > 0) {
                        db()->prepare('UPDATE stories SET views_count = views_count + 1 WHERE id = ?')->execute([$id]);
                    }
                } catch (Exception $e) {}
            }

            $cnt = db()->prepare('SELECT views_count FROM stories WHERE id = ?');
            $cnt->execute([$id]);
            $row = $cnt->fetch();
            respond(['ok' => true, 'views_count' => (int)($row['views_count'] ?? 0)]);
        }

        case 'story_like_toggle': {
            $me = require_auth();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) fail('استوری نامعتبر است.');

            $exists = db()->prepare("SELECT id FROM stories WHERE id = ? AND expires_at > datetime('now')");
            $exists->execute([$id]);
            if (!$exists->fetch()) fail('استوری پیدا نشد یا منقضی شده.', 404);

            $chk = db()->prepare('SELECT id FROM story_likes WHERE story_id = ? AND user_id = ?');
            $chk->execute([$id, $me['id']]);
            $already = $chk->fetch();

            if ($already) {
                db()->prepare('DELETE FROM story_likes WHERE id = ?')->execute([$already['id']]);
                db()->prepare('UPDATE stories SET likes_count = MAX(0, likes_count - 1) WHERE id = ?')->execute([$id]);
                $liked = false;
            } else {
                db()->prepare('INSERT INTO story_likes (story_id, user_id) VALUES (?, ?)')->execute([$id, $me['id']]);
                db()->prepare('UPDATE stories SET likes_count = likes_count + 1 WHERE id = ?')->execute([$id]);
                $liked = true;
            }

            $cnt = db()->prepare('SELECT likes_count FROM stories WHERE id = ?');
            $cnt->execute([$id]);
            $row = $cnt->fetch();
            respond(['ok' => true, 'liked' => $liked, 'likes_count' => (int)($row['likes_count'] ?? 0)]);
        }

        case 'story_viewers': {
            $me = require_auth();
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) fail('استوری نامعتبر است.');

            $stmt = db()->prepare('SELECT user_id, views_count FROM stories WHERE id = ?');
            $stmt->execute([$id]);
            $story = $stmt->fetch();
            if (!$story) fail('استوری پیدا نشد.', 404);

            $isOwner = (int)$story['user_id'] === (int)$me['id'];
            $isMainAdmin = is_admin_phone($me['phone'] ?? null);
            if (!$isOwner && !$isMainAdmin) fail('اجازه ندارید.', 403);

            $rows = db()->prepare('
                SELECT u.id, u.name, u.username, u.avatar_photo, u.verified, u.is_official, sv.created_at AS viewed_at
                FROM story_views sv
                JOIN users u ON u.id = sv.user_id
                WHERE sv.story_id = ?
                ORDER BY sv.created_at DESC
            ');
            $rows->execute([$id]);
            $viewers = array_map(function ($r) {
                return [
                    'id' => (int)$r['id'], 'name' => $r['name'], 'username' => $r['username'],
                    'avatar_photo' => $r['avatar_photo'], 'verified' => (bool)$r['verified'],
                    'is_official' => (bool)$r['is_official'], 'viewed_at' => $r['viewed_at'],
                ];
            }, $rows->fetchAll());

            respond(['ok' => true, 'viewers' => $viewers, 'views_count' => (int)$story['views_count']]);
        }

        case 'story_delete': {
            $me = require_auth();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) fail('استوری نامعتبر است.');

            $stmt = db()->prepare('SELECT user_id FROM stories WHERE id = ?');
            $stmt->execute([$id]);
            $story = $stmt->fetch();
            if (!$story) fail('استوری پیدا نشد.', 404);

            $isOwner = (int)$story['user_id'] === (int)$me['id'];
            $isMainAdmin = is_admin_phone($me['phone'] ?? null);
            if (!$isOwner && !$isMainAdmin) fail('اجازه ندارید.', 403);

            db()->prepare('DELETE FROM stories WHERE id = ?')->execute([$id]);
            respond(['ok' => true]);
        }

        case 'story_reply_send': {
            $me = require_auth();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            $body = trim((string)($in['body'] ?? ''));
            if ($id <= 0) fail('استوری نامعتبر است.');
            if ($body === '' || mb_strlen($body) > 2000) fail('متن پاسخ نامعتبر است.');

            $stmt = db()->prepare("SELECT user_id FROM stories WHERE id = ? AND expires_at > datetime('now')");
            $stmt->execute([$id]);
            $story = $stmt->fetch();
            if (!$story) fail('استوری پیدا نشد یا منقضی شده.', 404);

            $ownerId = (int)$story['user_id'];
            if ($ownerId === (int)$me['id']) fail('نمی‌تونی به استوری خودت پاسخ بدی.');

            $target = fetch_user($ownerId);
            if (!$target) fail('کاربر مقصد یافت نشد.', 404);
            $targetStatus = get_user_status($target);
            if ($targetStatus['banned'] || $targetStatus['suspended']) {
                fail('امکان ارسال پیام به این کاربر وجود ندارد.', 403);
            }

            $blockChk = db()->prepare('
                SELECT 1 FROM blocked_users
                WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)
            ');
            $blockChk->execute([$me['id'], $ownerId, $ownerId, $me['id']]);
            if ($blockChk->fetch()) fail('امکان ارسال پیام وجود ندارد.', 403);

            $stmt = db()->prepare('INSERT INTO messages (sender_id, receiver_id, type, body, story_reply_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$me['id'], $ownerId, 'text', $body, $id]);
            $msgId = (int)db()->lastInsertId();

            respond(['ok' => true, 'message' => [
                'id' => $msgId, 'mine' => true, 'type' => 'text', 'body' => $body,
                'created_at' => date('Y-m-d H:i:s'), 'edited' => false, 'deleted' => false,
                'reactions' => [], 'story_reply' => get_story_reply_preview($id), 'is_read' => false,
            ]]);
        }

        case 'story_search': {
            $me = require_auth();
            $raw = trim((string)($_GET['q'] ?? ''));
            if ($raw === '') fail('یک آیدی یا شماره موبایل وارد کن.');

            $looksLikePhone = !str_starts_with($raw, '@') && (bool)preg_match('/^[0-9\s\-+()]+$/', $raw)
                && strlen(preg_replace('/\D+/', '', $raw)) >= 10;

            if ($looksLikePhone) {
                $phone = normalize_phone($raw);
                if (!preg_match('/^09\d{9}$/', $phone)) fail('شماره موبایل معتبر نیست.');
                $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
                $stmt->execute([$phone]);
                $user = $stmt->fetch();
            } else {
                $username = normalize_username($raw);
                if (!valid_username_format($username)) fail('آیدی نامعتبر است.');
                $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
                $stmt->execute([$username]);
                $user = $stmt->fetch();
            }

            if (!$user) respond(['ok' => true, 'user' => null]);

            $blockChk = db()->prepare('SELECT 1 FROM blocked_users WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)');
            $blockChk->execute([$me['id'], $user['id'], $user['id'], $me['id']]);
            if ($blockChk->fetch()) respond(['ok' => true, 'user' => null]);

            $cntStmt = db()->prepare("SELECT COUNT(*) AS c FROM stories WHERE user_id = ? AND expires_at > datetime('now')");
            $cntStmt->execute([$user['id']]);
            $storiesCount = (int)$cntStmt->fetch()['c'];

            respond(['ok' => true, 'user' => [
                'id' => (int)$user['id'], 'name' => $user['name'], 'username' => $user['username'],
                'avatar_photo' => $user['avatar_photo'], 'verified' => (bool)$user['verified'],
                'is_official' => (bool)$user['is_official'], 'is_main_admin' => is_admin_phone($user['phone']),
                'has_stories' => $storiesCount > 0, 'stories_count' => $storiesCount,
            ]]);
        }

        default:
            fail('عملیات نامعتبر است.', 404);
    }
} catch (Throwable $e) {
    error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    respond(['ok' => false, 'error' => 'خطای داخلی سرور رخ داد.'], 500);
}

// ============================================================
// توابع کمکی اضافی
// ============================================================

function add_user_to_official_channels(PDO $pdo, int $userId): void {
    try {
        // کانال رسمی
        $channelStmt = $pdo->prepare('SELECT id FROM groups_ WHERE username = ?');
        $channelStmt->execute([OFFICIAL_CHANNEL_USERNAME]);
        $channel = $channelStmt->fetch();
        if ($channel) {
            $pdo->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")
                ->execute([(int)$channel['id'], $userId]);
        }
        
        // گروه رسمی
        $groupStmt = $pdo->prepare('SELECT id FROM groups_ WHERE username = ?');
        $groupStmt->execute([OFFICIAL_GROUP_USERNAME]);
        $group = $groupStmt->fetch();
        if ($group) {
            $pdo->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")
                ->execute([(int)$group['id'], $userId]);
        }
    } catch (Exception $e) {
        // نادیده بگیر - خطا در افزودن به کانال‌های رسمی
    }
}

function get_default_avatar(string $name): string {
    $initial = mb_substr($name, 0, 1);
    $colors = ['#0088ff', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
    $color = $colors[array_rand($colors)];
    
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">';
    $svg .= '<rect width="200" height="200" fill="' . $color . '" rx="50"/>';
    $svg .= '<text x="50%" y="55%" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="80" fill="#ffffff" font-weight="bold">' . $initial . '</text>';
    $svg .= '</svg>';
    
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
