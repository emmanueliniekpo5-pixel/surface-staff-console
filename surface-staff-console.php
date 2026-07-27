<?php
/**
 * Plugin Name: Surface Operations Console
 * Description: Internal Surface Internet operations, staff access, hierarchy, tasks and audit foundation.
 * Version: 1.2.0
 * Author: KX
 */

if (!defined('ABSPATH')) exit;

final class Surface_Operations_Console {

    const VERSION = '1.2.0';
    const ROLE = 'surface_staff';
    const LOGIN_SLUG = 'staff-login';
    const CONSOLE_SLUG = 'surface-staff-console';
    const OTP_TTL = 600;
    const OTP_RESEND_WAIT = 60;
    const OTP_MAX_ATTEMPTS = 5;

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_admin_actions']);
        add_action('admin_init', [__CLASS__, 'guard_staff_admin']);
        add_action('template_redirect', [__CLASS__, 'handle_front_auth'], 1);
        add_action('template_redirect', [__CLASS__, 'guard_staff_frontend'], 20);

        add_shortcode('surface_staff_login', [__CLASS__, 'render_login']);
        add_shortcode('surface_operations_login', [__CLASS__, 'render_login']);
        add_shortcode('surface_operations_console', [__CLASS__, 'render_console']);
        add_shortcode('surface_staff_console', [__CLASS__, 'render_console']);

        add_filter('show_admin_bar', [__CLASS__, 'hide_staff_admin_bar']);
        add_filter('login_redirect', [__CLASS__, 'filter_login_redirect'], 10, 3);
    }

    public static function activate() {
        add_role(self::ROLE, 'Surface Operations Staff', ['read' => true]);

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $tasks = $wpdb->prefix . 'surface_operations_tasks';
        $audit = $wpdb->prefix . 'surface_operations_audit';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$tasks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(190) NOT NULL,
            description LONGTEXT NULL,
            module VARCHAR(80) NOT NULL DEFAULT 'general',
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            assigned_team VARCHAR(80) NULL,
            assigned_user_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            due_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY assigned_user_id (assigned_user_id),
            KEY status (status),
            KEY module (module)
        ) {$charset};");

        dbDelta("CREATE TABLE {$audit} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action_key VARCHAR(120) NOT NULL,
            object_type VARCHAR(80) NULL,
            object_id VARCHAR(120) NULL,
            summary TEXT NOT NULL,
            context LONGTEXT NULL,
            ip_address VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY actor_user_id (actor_user_id),
            KEY action_key (action_key),
            KEY object_type (object_type)
        ) {$charset};");
    }

    private static function is_staff($user = null) {
        if (!$user) $user = wp_get_current_user();
        return $user && in_array(self::ROLE, (array) $user->roles, true);
    }

    private static function is_admin_user($user = null) {
        if (!$user) $user = wp_get_current_user();
        return $user && in_array('administrator', (array) $user->roles, true);
    }

    private static function user_team($user_id) {
        return sanitize_text_field((string) get_user_meta($user_id, 'surface_operations_team', true));
    }

    private static function user_level($user_id) {
        $level = sanitize_key((string) get_user_meta($user_id, 'surface_operations_level', true));
        return $level ?: 'operations_officer';
    }

    private static function level_label($level) {
        $levels = self::levels();
        return isset($levels[$level]) ? $levels[$level] : 'Operations Officer';
    }

    private static function levels() {
        return [
            'operations_director' => 'Operations Director',
            'operations_manager'  => 'Operations Manager',
            'team_lead'           => 'Team Lead',
            'operations_officer'  => 'Operations Officer',
            'finance_officer'     => 'Finance Officer',
            'compliance_officer'  => 'Compliance Officer',
            'support_officer'     => 'Support Officer',
            'auditor'             => 'Read-only Auditor',
        ];
    }

    private static function teams() {
        return [
            'Operations', 'Surface Identity', 'SurfaceTeeth', 'Advocacy',
            'Campaigns', 'Wallet', 'Bundles', 'Support', 'Compliance',
            'Finance', 'Analytics', 'Engineering'
        ];
    }

    private static function role_permissions() {
        return [
            'operations_director' => ['dashboard','tasks','partners','surfaceteeth','advocates','campaigns','wallet','bundles','support','reports','teams','staff','audit'],
            'operations_manager'  => ['dashboard','tasks','partners','surfaceteeth','advocates','campaigns','wallet','bundles','support','reports','teams','staff'],
            'team_lead'           => ['dashboard','tasks','partners','surfaceteeth','advocates','campaigns','wallet','bundles','support','reports','teams'],
            'operations_officer'  => ['dashboard','tasks','partners','surfaceteeth','advocates','campaigns','support'],
            'finance_officer'     => ['dashboard','tasks','wallet','bundles','reports'],
            'compliance_officer'  => ['dashboard','tasks','partners','surfaceteeth','advocates','campaigns','audit'],
            'support_officer'     => ['dashboard','tasks','partners','support'],
            'auditor'             => ['dashboard','reports','audit'],
        ];
    }

    private static function can_access($section, $user_id = 0) {
        if (!$user_id) $user_id = get_current_user_id();
        $map = self::role_permissions();
        $level = self::user_level($user_id);
        return in_array($section, $map[$level] ?? ['dashboard'], true);
    }

    private static function staff_status($user_id) {
        $status = sanitize_key((string) get_user_meta($user_id, 'surface_operations_status', true));
        return $status ?: 'active';
    }

    public static function register_admin_menu() {
        add_menu_page(
            'Surface Operations',
            'Surface Operations',
            'manage_options',
            'surface-operations',
            [__CLASS__, 'render_admin_page'],
            'dashicons-shield-alt',
            56
        );
    }

    public static function handle_admin_actions() {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['surface_create_staff'])) {
            check_admin_referer('surface_create_staff_action', 'surface_create_staff_nonce');
            $name  = sanitize_text_field(wp_unslash($_POST['surface_staff_name'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['surface_staff_email'] ?? ''));
            $team  = sanitize_text_field(wp_unslash($_POST['surface_staff_team'] ?? 'Operations'));
            $level = sanitize_key(wp_unslash($_POST['surface_staff_level'] ?? 'operations_officer'));
            $manager_id = absint($_POST['surface_staff_manager'] ?? 0);

            if (!$name || !is_email($email)) {
                add_settings_error('surface_operations', 'invalid_staff', 'Enter a valid staff name and email.', 'error');
            } elseif (email_exists($email)) {
                add_settings_error('surface_operations', 'staff_exists', 'That email already belongs to an account.', 'error');
            } else {
                $user_id = wp_create_user($email, wp_generate_password(32, true, true), $email);
                if (is_wp_error($user_id)) {
                    add_settings_error('surface_operations', 'staff_failed', $user_id->get_error_message(), 'error');
                } else {
                    wp_update_user(['ID' => $user_id, 'display_name' => $name]);
                    $user = new WP_User($user_id);
                    $user->set_role(self::ROLE);
                    update_user_meta($user_id, 'surface_operations_team', $team);
                    update_user_meta($user_id, 'surface_operations_level', $level);
                    update_user_meta($user_id, 'surface_operations_manager_id', $manager_id);
                    update_user_meta($user_id, 'surface_operations_status', 'active');
                    update_user_meta($user_id, 'surface_operations_created_at', current_time('mysql'));
                    wp_mail($email, 'Your Surface Operations Access', "Hello {$name},\n\nYou now have access to Surface Operations.\n\nTeam: {$team}\nRole: " . self::level_label($level) . "\n\nSign in: " . home_url('/' . self::LOGIN_SLUG . '/') . "\n\nSurface Internet");
                    self::audit('staff.created', 'staff', (string) $user_id, "Created staff access for {$name}");
                    add_settings_error('surface_operations', 'staff_created', 'Staff access created and emailed.', 'success');
                }
            }
        }

        if (isset($_POST['surface_update_staff'])) {
            check_admin_referer('surface_update_staff_action', 'surface_update_staff_nonce');
            $user_id = absint($_POST['surface_staff_id'] ?? 0);
            $user = get_user_by('id', $user_id);
            if ($user && self::is_staff($user)) {
                $name = sanitize_text_field(wp_unslash($_POST['surface_staff_name'] ?? $user->display_name));
                $team = sanitize_text_field(wp_unslash($_POST['surface_staff_team'] ?? 'Operations'));
                $level = sanitize_key(wp_unslash($_POST['surface_staff_level'] ?? 'operations_officer'));
                $manager_id = absint($_POST['surface_staff_manager'] ?? 0);
                wp_update_user(['ID' => $user_id, 'display_name' => $name]);
                update_user_meta($user_id, 'surface_operations_team', $team);
                update_user_meta($user_id, 'surface_operations_level', $level);
                update_user_meta($user_id, 'surface_operations_manager_id', $manager_id);
                self::audit('staff.updated', 'staff', (string) $user_id, "Updated staff profile for {$name}");
                add_settings_error('surface_operations', 'staff_updated', 'Staff profile updated.', 'success');
            }
        }

        if (isset($_POST['surface_toggle_staff'])) {
            check_admin_referer('surface_toggle_staff_action', 'surface_toggle_staff_nonce');
            $user_id = absint($_POST['surface_staff_id'] ?? 0);
            $user = get_user_by('id', $user_id);
            if ($user && self::is_staff($user)) {
                $new_status = self::staff_status($user_id) === 'suspended' ? 'active' : 'suspended';
                update_user_meta($user_id, 'surface_operations_status', $new_status);
                delete_transient(self::otp_key($user->user_email));
                self::audit('staff.' . $new_status, 'staff', (string) $user_id, ucfirst($new_status) . " staff access for {$user->display_name}");
                add_settings_error('surface_operations', 'staff_status', 'Staff status updated.', 'success');
            }
        }

        if (isset($_POST['surface_delete_staff'])) {
            check_admin_referer('surface_delete_staff_action', 'surface_delete_staff_nonce');
            $user_id = absint($_POST['surface_staff_id'] ?? 0);
            $user = get_user_by('id', $user_id);
            if ($user && self::is_staff($user)) {
                $name = $user->display_name;
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($user_id);
                self::audit('staff.deleted', 'staff', (string) $user_id, "Deleted staff account for {$name}");
                add_settings_error('surface_operations', 'staff_deleted', 'Staff account deleted.', 'success');
            }
        }
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        $search = sanitize_text_field(wp_unslash($_GET['staff_search'] ?? ''));
        $args = ['role' => self::ROLE, 'orderby' => 'display_name', 'order' => 'ASC'];
        if ($search) $args['search'] = '*' . $search . '*';
        $staff = get_users($args);
        $all_staff = get_users(['role' => self::ROLE, 'orderby' => 'display_name', 'order' => 'ASC']);
        $edit_id = absint($_GET['edit_staff'] ?? 0);
        $edit_user = $edit_id ? get_user_by('id', $edit_id) : null;
        if ($edit_user && !self::is_staff($edit_user)) $edit_user = null;
        $active = 0; $suspended = 0;
        foreach ($all_staff as $member) self::staff_status($member->ID) === 'suspended' ? $suspended++ : $active++;
        settings_errors('surface_operations');
        ?>
        <div class="wrap">
            <h1>Surface Operations</h1>
            <p>Manage staff access, hierarchy, teams and operational responsibility.</p>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;max-width:900px;margin:18px 0 24px;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;"><small>Total Staff</small><strong style="display:block;font-size:28px;margin-top:6px;"><?php echo esc_html(count($all_staff)); ?></strong></div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;"><small>Active</small><strong style="display:block;font-size:28px;margin-top:6px;"><?php echo esc_html($active); ?></strong></div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;"><small>Suspended</small><strong style="display:block;font-size:28px;margin-top:6px;"><?php echo esc_html($suspended); ?></strong></div>
            </div>

            <div style="display:grid;grid-template-columns:minmax(340px,520px) minmax(560px,1fr);gap:26px;align-items:start;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;">
                    <h2 style="margin-top:0;"><?php echo $edit_user ? 'Edit Staff Member' : 'Create Staff Access'; ?></h2>
                    <form method="post">
                        <?php if ($edit_user): wp_nonce_field('surface_update_staff_action', 'surface_update_staff_nonce'); ?>
                            <input type="hidden" name="surface_staff_id" value="<?php echo esc_attr($edit_user->ID); ?>">
                        <?php else: wp_nonce_field('surface_create_staff_action', 'surface_create_staff_nonce'); endif; ?>
                        <p><label><strong>Full Name</strong><br><input type="text" name="surface_staff_name" class="regular-text" value="<?php echo esc_attr($edit_user ? $edit_user->display_name : ''); ?>" required></label></p>
                        <p><label><strong>Email</strong><br><input type="email" name="surface_staff_email" class="regular-text" value="<?php echo esc_attr($edit_user ? $edit_user->user_email : ''); ?>" <?php echo $edit_user ? 'readonly' : 'required'; ?>></label></p>
                        <p><label><strong>Team</strong><br><select name="surface_staff_team" class="regular-text">
                            <?php $current_team = $edit_user ? self::user_team($edit_user->ID) : 'Operations'; foreach (self::teams() as $team): ?><option value="<?php echo esc_attr($team); ?>" <?php selected($current_team, $team); ?>><?php echo esc_html($team); ?></option><?php endforeach; ?>
                        </select></label></p>
                        <p><label><strong>Role</strong><br><select name="surface_staff_level" class="regular-text">
                            <?php $current_level = $edit_user ? self::user_level($edit_user->ID) : 'operations_officer'; foreach (self::levels() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($current_level, $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                        </select></label></p>
                        <p><label><strong>Reports To</strong><br><select name="surface_staff_manager" class="regular-text"><option value="0">No manager assigned</option>
                            <?php $current_manager = $edit_user ? absint(get_user_meta($edit_user->ID, 'surface_operations_manager_id', true)) : 0; foreach ($all_staff as $manager): if ($edit_user && $manager->ID === $edit_user->ID) continue; ?><option value="<?php echo esc_attr($manager->ID); ?>" <?php selected($current_manager, $manager->ID); ?>><?php echo esc_html($manager->display_name); ?></option><?php endforeach; ?>
                        </select></label></p>
                        <p><button type="submit" name="<?php echo $edit_user ? 'surface_update_staff' : 'surface_create_staff'; ?>" class="button button-primary"><?php echo $edit_user ? 'Save Staff Changes' : 'Create Staff Access'; ?></button><?php if ($edit_user): ?> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=surface-operations')); ?>">Cancel</a><?php endif; ?></p>
                    </form>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;overflow:auto;">
                    <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:14px;"><h2 style="margin:0;">Staff Centre</h2><form method="get"><input type="hidden" name="page" value="surface-operations"><input type="search" name="staff_search" value="<?php echo esc_attr($search); ?>" placeholder="Search staff"><button class="button">Search</button></form></div>
                    <table class="widefat striped">
                        <thead><tr><th>Staff</th><th>Team</th><th>Role</th><th>Manager</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$staff): ?><tr><td colspan="7">No matching staff found.</td></tr><?php endif; ?>
                        <?php foreach ($staff as $member): $manager_id = absint(get_user_meta($member->ID,'surface_operations_manager_id',true)); $manager = $manager_id ? get_user_by('id',$manager_id) : null; $status = self::staff_status($member->ID); ?>
                            <tr>
                                <td><strong><?php echo esc_html($member->display_name); ?></strong><br><small><?php echo esc_html($member->user_email); ?></small></td>
                                <td><?php echo esc_html(self::user_team($member->ID) ?: 'Operations'); ?></td>
                                <td><?php echo esc_html(self::level_label(self::user_level($member->ID))); ?></td>
                                <td><?php echo esc_html($manager ? $manager->display_name : '—'); ?></td>
                                <td><span style="font-weight:700;color:<?php echo $status === 'suspended' ? '#b91c1c' : '#047857'; ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                <td><?php echo esc_html((string) get_user_meta($member->ID, 'surface_operations_last_login', true) ?: 'Never'); ?></td>
                                <td style="white-space:nowrap;"><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=surface-operations&edit_staff=' . $member->ID)); ?>">Edit</a>
                                    <form method="post" style="display:inline"><?php wp_nonce_field('surface_toggle_staff_action', 'surface_toggle_staff_nonce'); ?><input type="hidden" name="surface_staff_id" value="<?php echo esc_attr($member->ID); ?>"><button class="button button-small" name="surface_toggle_staff"><?php echo $status === 'suspended' ? 'Reactivate' : 'Suspend'; ?></button></form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this staff account?');"><?php wp_nonce_field('surface_delete_staff_action', 'surface_delete_staff_nonce'); ?><input type="hidden" name="surface_staff_id" value="<?php echo esc_attr($member->ID); ?>"><button class="button button-small" name="surface_delete_staff">Delete</button></form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    private static function otp_key($email) {
        return 'surface_soc_otp_' . md5(strtolower(trim($email)));
    }

    private static function rate_key($email) {
        return 'surface_soc_rate_' . md5(strtolower(trim($email)));
    }

    private static function send_otp($email) {
        $user = get_user_by('email', $email);
        if (!$user || !self::is_staff($user)) {
            return new WP_Error('invalid_staff', 'We could not verify that staff email.');
        }

        $status = get_user_meta($user->ID, 'surface_operations_status', true);
        if ($status === 'suspended') {
            return new WP_Error('staff_suspended', 'This staff access is currently suspended.');
        }

        $last_sent = (int) get_transient(self::rate_key($email));
        if ($last_sent && (time() - $last_sent) < self::OTP_RESEND_WAIT) {
            return new WP_Error('otp_wait', 'Please wait before requesting another code.');
        }

        $code = (string) random_int(100000, 999999);
        set_transient(self::otp_key($email), [
            'hash' => wp_hash_password($code),
            'attempts' => 0,
            'user_id' => (int) $user->ID,
        ], self::OTP_TTL);
        set_transient(self::rate_key($email), time(), self::OTP_RESEND_WAIT);

        $subject = 'Your Surface Operations verification code';
        $message  = "Your Surface Operations verification code is:\n\n{$code}\n\n";
        $message .= "This code expires in 10 minutes and can be used once.\n\n";
        $message .= "If you did not request this code, you can ignore this email.\n\n";
        $message .= "Surface Internet";

        if (!wp_mail($email, $subject, $message)) {
            delete_transient(self::otp_key($email));
            return new WP_Error('mail_failed', 'The verification email could not be sent.');
        }

        self::audit('auth.otp_requested', 'staff', (string) $user->ID, 'OTP requested');
        return true;
    }

    private static function verify_otp($email, $code) {
        $payload = get_transient(self::otp_key($email));
        if (!is_array($payload) || empty($payload['hash']) || empty($payload['user_id'])) {
            return new WP_Error('otp_expired', 'The code has expired. Request a new one.');
        }

        $attempts = (int) ($payload['attempts'] ?? 0);
        if ($attempts >= self::OTP_MAX_ATTEMPTS) {
            delete_transient(self::otp_key($email));
            return new WP_Error('otp_locked', 'Too many incorrect attempts. Request a new code.');
        }

        if (!wp_check_password($code, $payload['hash'])) {
            $payload['attempts'] = $attempts + 1;
            set_transient(self::otp_key($email), $payload, self::OTP_TTL);
            return new WP_Error('otp_invalid', 'That verification code is incorrect.');
        }

        $user = get_user_by('id', (int) $payload['user_id']);
        if (!$user || !self::is_staff($user) || strcasecmp($user->user_email, $email) !== 0) {
            delete_transient(self::otp_key($email));
            return new WP_Error('otp_user_invalid', 'This staff access could not be verified.');
        }
        if (self::staff_status($user->ID) === 'suspended') {
            delete_transient(self::otp_key($email));
            return new WP_Error('staff_suspended', 'This staff access is currently suspended.');
        }

        delete_transient(self::otp_key($email));
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true, is_ssl());
        update_user_meta($user->ID, 'surface_operations_last_login', current_time('mysql'));
        self::audit('auth.login', 'staff', (string) $user->ID, 'Staff signed in using email OTP');
        return $user;
    }


    public static function handle_front_auth() {
        if (is_admin()) return;

        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        if ($path === self::LOGIN_SLUG && is_user_logged_in() && self::is_staff()) {
            wp_safe_redirect(home_url('/' . self::CONSOLE_SLUG . '/'));
            exit;
        }

        if ($path !== self::LOGIN_SLUG || !isset($_POST['surface_operations_verify_otp'])) {
            return;
        }

        $nonce = isset($_POST['surface_operations_login_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['surface_operations_login_nonce']))
            : '';

        if (!$nonce || !wp_verify_nonce($nonce, 'surface_operations_login')) {
            return;
        }

        $email = sanitize_email(wp_unslash($_POST['surface_operations_email'] ?? ''));
        $code  = preg_replace('/\D+/', '', (string) wp_unslash($_POST['surface_operations_code'] ?? ''));

        if (!is_email($email) || strlen($code) !== 6) {
            return;
        }

        $verified = self::verify_otp($email, $code);
        if (is_wp_error($verified)) {
            return;
        }

        wp_safe_redirect(home_url('/' . self::CONSOLE_SLUG . '/'));
        exit;
    }

    public static function render_login() {
        if (is_user_logged_in() && self::is_staff()) {
            wp_safe_redirect(home_url('/' . self::CONSOLE_SLUG . '/'));
            exit;
        }

        $message = '';
        $error = '';
        $email = sanitize_email(wp_unslash($_POST['surface_operations_email'] ?? ''));
        $stage = isset($_POST['surface_operations_stage']) ? sanitize_key(wp_unslash($_POST['surface_operations_stage'])) : 'email';

        if (isset($_POST['surface_operations_send_otp'])) {
            if (!isset($_POST['surface_operations_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['surface_operations_login_nonce'])), 'surface_operations_login')) {
                $error = 'Your session expired. Refresh the page and try again.';
            } elseif (!is_email($email)) {
                $error = 'Enter a valid staff email.';
            } else {
                $sent = self::send_otp($email);
                if (is_wp_error($sent)) $error = $sent->get_error_message();
                else {
                    $stage = 'code';
                    $message = 'A six-digit verification code has been sent to your email.';
                }
            }
        }

        if (isset($_POST['surface_operations_verify_otp'])) {
            $stage = 'code';
            $code = preg_replace('/\D+/', '', (string) wp_unslash($_POST['surface_operations_code'] ?? ''));
            if (!isset($_POST['surface_operations_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['surface_operations_login_nonce'])), 'surface_operations_login')) {
                $error = 'Your session expired. Refresh the page and try again.';
            } elseif (!is_email($email) || strlen($code) !== 6) {
                $error = 'Enter the six-digit code sent to your email.';
            } else {
                $verified = self::verify_otp($email, $code);
                if (is_wp_error($verified)) $error = $verified->get_error_message();
                else {
                    wp_safe_redirect(home_url('/' . self::CONSOLE_SLUG . '/'));
                    exit;
                }
            }
        }

        ob_start();
        ?>
        <style>
            .soc-auth-shell{min-height:72vh;display:grid;place-items:center;padding:32px 16px;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827}
            .soc-auth-card{width:min(100%,460px);background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:32px;box-shadow:0 24px 70px rgba(17,24,39,.10)}
            .soc-mark{width:46px;height:46px;border-radius:14px;background:#111827;color:#fff;display:grid;place-items:center;font-weight:800;margin-bottom:22px}
            .soc-auth-card h1{font-size:28px;line-height:1.15;margin:0 0 8px}.soc-auth-card p{color:#6b7280;margin:0 0 24px}
            .soc-field{margin:0 0 16px}.soc-field label{display:block;font-size:13px;font-weight:700;margin:0 0 7px}.soc-field input{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:12px;padding:14px 15px;font-size:16px;outline:none}.soc-field input:focus{border-color:#111827;box-shadow:0 0 0 3px rgba(17,24,39,.08)}
            .soc-button{width:100%;border:0;border-radius:12px;background:#111827;color:#fff;padding:14px 18px;font-size:15px;font-weight:750;cursor:pointer}.soc-note{font-size:12px!important;text-align:center;margin:16px 0 0!important}.soc-alert{border-radius:10px;padding:11px 13px;margin:0 0 16px;font-size:14px}.soc-alert-error{background:#fef2f2;color:#991b1b}.soc-alert-success{background:#ecfdf5;color:#065f46}.soc-code{letter-spacing:.35em;text-align:center;font-size:24px!important;font-weight:750}
        </style>
        <div class="soc-auth-shell">
            <div class="soc-auth-card">
                <div class="soc-mark">S</div>
                <h1>Surface Operations</h1>
                <p>Secure staff access to Surface Internet operations.</p>
                <?php if ($error): ?><div class="soc-alert soc-alert-error"><?php echo esc_html($error); ?></div><?php endif; ?>
                <?php if ($message): ?><div class="soc-alert soc-alert-success"><?php echo esc_html($message); ?></div><?php endif; ?>
                <form method="post" autocomplete="off">
                    <?php wp_nonce_field('surface_operations_login', 'surface_operations_login_nonce'); ?>
                    <input type="hidden" name="surface_operations_stage" value="<?php echo esc_attr($stage); ?>">
                    <div class="soc-field">
                        <label>Staff Email</label>
                        <input type="email" name="surface_operations_email" value="<?php echo esc_attr($email); ?>" required <?php echo $stage === 'code' ? 'readonly' : ''; ?>>
                    </div>
                    <?php if ($stage === 'code'): ?>
                        <div class="soc-field">
                            <label>Verification Code</label>
                            <input class="soc-code" type="text" inputmode="numeric" maxlength="6" name="surface_operations_code" pattern="[0-9]{6}" required autofocus>
                        </div>
                        <button class="soc-button" type="submit" name="surface_operations_verify_otp">Verify &amp; Continue</button>
                        <p class="soc-note">The code expires after 10 minutes.</p>
                    <?php else: ?>
                        <button class="soc-button" type="submit" name="surface_operations_send_otp">Send Verification Code</button>
                        <p class="soc-note">No password is required.</p>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_console() {
        if (!is_user_logged_in() || !self::is_staff()) {
            return '<script>window.location.href=' . wp_json_encode(home_url('/' . self::LOGIN_SLUG . '/')) . ';</script>';
        }

        $user = wp_get_current_user();
        $team = self::user_team($user->ID) ?: 'Operations';
        $level = self::level_label(self::user_level($user->ID));
        $task_counts = self::task_counts($user->ID, $team);
        $all_staff = get_users(['role' => self::ROLE, 'fields' => 'ID']);
        $active_staff = 0; $suspended_staff = 0;
        foreach ($all_staff as $staff_id) self::staff_status($staff_id) === 'suspended' ? $suspended_staff++ : $active_staff++;
        $recent_tasks = self::recent_tasks($user->ID, $team, 6);
        $recent_audit = self::recent_audit(6);
        $logout_url = wp_logout_url(home_url('/' . self::LOGIN_SLUG . '/'));

        ob_start();
        ?>
        <style>
            body{background:#f4f6f8!important}.soc-app{min-height:100vh;display:grid;grid-template-columns:250px 1fr;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827}.soc-sidebar{background:#111827;color:#fff;padding:26px 18px;position:sticky;top:0;height:100vh;box-sizing:border-box}.soc-brand{font-size:19px;font-weight:800;padding:0 10px 24px}.soc-brand small{display:block;color:#9ca3af;font-size:11px;font-weight:600;margin-top:4px}.soc-nav a{display:block;color:#cbd5e1;text-decoration:none;padding:11px 12px;border-radius:10px;margin:3px 0;font-size:14px}.soc-nav a:hover,.soc-nav a.active{background:#1f2937;color:#fff}.soc-sidebar-foot{position:absolute;left:18px;right:18px;bottom:22px;border-top:1px solid #374151;padding-top:16px}.soc-sidebar-foot strong,.soc-sidebar-foot span{display:block}.soc-sidebar-foot span{font-size:12px;color:#9ca3af;margin:3px 0 10px}.soc-sidebar-foot a{color:#cbd5e1;font-size:13px}.soc-main{padding:30px}.soc-top{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:25px}.soc-top h1{font-size:29px;margin:0 0 4px}.soc-top p{margin:0;color:#6b7280}.soc-search{width:min(360px,40vw);border:1px solid #d1d5db;border-radius:12px;padding:12px 14px;background:#fff}.soc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.soc-stat,.soc-panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px}.soc-stat{padding:20px}.soc-stat span{display:block;color:#6b7280;font-size:13px}.soc-stat strong{display:block;font-size:30px;margin-top:7px}.soc-columns{display:grid;grid-template-columns:1.3fr .9fr;gap:18px;margin-top:18px}.soc-panel{padding:21px}.soc-panel h2{font-size:17px;margin:0 0 16px}.soc-row{display:flex;justify-content:space-between;gap:14px;padding:13px 0;border-top:1px solid #f0f1f3}.soc-row:first-of-type{border-top:0}.soc-row-title{font-weight:700;font-size:14px}.soc-meta{font-size:12px;color:#6b7280;margin-top:4px}.soc-badge{height:max-content;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:750;background:#f3f4f6}.soc-empty{color:#6b7280;font-size:14px;padding:8px 0}.soc-module-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.soc-module{border:1px solid #e5e7eb;border-radius:12px;padding:14px}.soc-module strong{display:block;font-size:13px}.soc-module span{font-size:11px;color:#6b7280}.soc-coming{opacity:.65}@media(max-width:900px){.soc-app{grid-template-columns:1fr}.soc-sidebar{height:auto;position:relative}.soc-sidebar-foot{position:static;margin-top:20px}.soc-main{padding:20px}.soc-grid{grid-template-columns:repeat(2,1fr)}.soc-columns{grid-template-columns:1fr}.soc-search{display:none}}@media(max-width:520px){.soc-grid,.soc-module-grid{grid-template-columns:1fr}}
        </style>
        <div class="soc-app">
            <aside class="soc-sidebar">
                <div class="soc-brand">Surface Operations<small>Operating the Surface Internet</small></div>
                <nav class="soc-nav">
                    <?php
                    $nav = ['dashboard'=>'Dashboard','tasks'=>'Tasks','partners'=>'Partners','surfaceteeth'=>'SurfaceTeeth™','advocates'=>'Advocates','campaigns'=>'Campaigns','wallet'=>'Wallet','bundles'=>'Bundles','support'=>'Support','reports'=>'Reports','teams'=>'Teams','staff'=>'Staff','audit'=>'Audit'];
                    foreach ($nav as $key => $label) {
                        if (!self::can_access($key, $user->ID)) continue;
                        echo '<a class="' . ($key === 'dashboard' ? 'active' : '') . '" href="#' . esc_attr($key) . '">' . esc_html($label) . '</a>';
                    }
                    ?>
                </nav>
                <div class="soc-sidebar-foot"><strong><?php echo esc_html($user->display_name); ?></strong><span><?php echo esc_html($level . ' · ' . $team); ?></span><a href="<?php echo esc_url($logout_url); ?>">Sign out</a></div>
            </aside>
            <main class="soc-main">
                <div class="soc-top"><div><h1><?php echo esc_html(self::greeting() . ', ' . self::first_name($user->display_name)); ?></h1><p>Here is what needs attention across your operations.</p></div><input class="soc-search" type="search" placeholder="Search operations"></div>

                <section class="soc-grid">
                    <div class="soc-stat"><span>My Open Tasks</span><strong><?php echo esc_html($task_counts['mine']); ?></strong></div>
                    <div class="soc-stat"><span>Team Queue</span><strong><?php echo esc_html($task_counts['team']); ?></strong></div>
                    <div class="soc-stat"><span>Active Staff</span><strong><?php echo esc_html($active_staff); ?></strong></div>
                    <div class="soc-stat"><span>Suspended</span><strong><?php echo esc_html($suspended_staff); ?></strong></div>
                </section>

                <section class="soc-columns">
                    <div class="soc-panel" id="tasks"><h2>My To-do List</h2>
                        <?php if (!$recent_tasks): ?><div class="soc-empty">No tasks have been assigned yet.</div><?php endif; ?>
                        <?php foreach ($recent_tasks as $task): ?><div class="soc-row"><div><div class="soc-row-title"><?php echo esc_html($task->title); ?></div><div class="soc-meta"><?php echo esc_html(ucfirst($task->module) . ($task->due_at ? ' · Due ' . mysql2date('M j, g:i a', $task->due_at) : '')); ?></div></div><span class="soc-badge"><?php echo esc_html(ucfirst($task->priority)); ?></span></div><?php endforeach; ?>
                    </div>
                    <div class="soc-panel"><h2>Recent Operations Activity</h2>
                        <?php if (!$recent_audit): ?><div class="soc-empty">Activity will appear here as operations begin.</div><?php endif; ?>
                        <?php foreach ($recent_audit as $entry): ?><div class="soc-row"><div><div class="soc-row-title"><?php echo esc_html($entry->summary); ?></div><div class="soc-meta"><?php echo esc_html(mysql2date('M j, g:i a', $entry->created_at)); ?></div></div></div><?php endforeach; ?>
                    </div>
                </section>

                <section class="soc-panel" style="margin-top:18px"><h2>Operations Centres</h2><div class="soc-module-grid">
                    <?php foreach (['Partners','SurfaceTeeth™','Advocates','Campaigns','Wallet','Bundles','Support','Reports','Teams'] as $module): ?><div class="soc-module soc-coming"><strong><?php echo esc_html($module); ?></strong><span>Integration follows in the next phase</span></div><?php endforeach; ?>
                </div></section>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function task_counts($user_id, $team) {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_tasks';
        $today_start = current_time('Y-m-d 00:00:00');
        $today_end = current_time('Y-m-d 23:59:59');
        $now = current_time('mysql');

        return [
            'mine' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE assigned_user_id=%d AND status IN ('open','in_progress')", $user_id)),
            'team' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE assigned_team=%s AND (assigned_user_id IS NULL OR assigned_user_id=0) AND status IN ('open','in_progress')", $team)),
            'due_today' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE (assigned_user_id=%d OR assigned_team=%s) AND status IN ('open','in_progress') AND due_at BETWEEN %s AND %s", $user_id, $team, $today_start, $today_end)),
            'overdue' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE (assigned_user_id=%d OR assigned_team=%s) AND status IN ('open','in_progress') AND due_at IS NOT NULL AND due_at < %s", $user_id, $team, $now)),
        ];
    }

    private static function recent_tasks($user_id, $team, $limit = 6) {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_tasks';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE (assigned_user_id=%d OR (assigned_team=%s AND (assigned_user_id IS NULL OR assigned_user_id=0))) AND status IN ('open','in_progress') ORDER BY CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, due_at IS NULL, due_at ASC, id DESC LIMIT %d", $user_id, $team, $limit));
    }

    private static function recent_audit($limit = 6) {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_audit';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit));
    }

    private static function audit($action, $object_type, $object_id, $summary, $context = []) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'surface_operations_audit', [
            'actor_user_id' => get_current_user_id(),
            'action_key' => sanitize_key($action),
            'object_type' => sanitize_key($object_type),
            'object_id' => sanitize_text_field($object_id),
            'summary' => sanitize_text_field($summary),
            'context' => wp_json_encode($context),
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'created_at' => current_time('mysql'),
        ], ['%d','%s','%s','%s','%s','%s','%s','%s']);
    }

    private static function greeting() {
        $hour = (int) current_time('G');
        if ($hour < 12) return 'Good morning';
        if ($hour < 17) return 'Good afternoon';
        return 'Good evening';
    }

    private static function first_name($name) {
        $parts = preg_split('/\s+/', trim((string) $name));
        return $parts[0] ?: 'there';
    }

    public static function hide_staff_admin_bar($show) {
        if (is_user_logged_in() && self::is_staff() && !self::is_admin_user()) return false;
        return $show;
    }

    public static function guard_staff_admin() {
        if (!is_admin() || wp_doing_ajax() || !is_user_logged_in()) return;
        if (!self::is_staff() || self::is_admin_user()) return;
        wp_safe_redirect(home_url('/' . self::CONSOLE_SLUG . '/'));
        exit;
    }

    public static function guard_staff_frontend() {
        if (!is_user_logged_in() || is_admin() || !self::is_staff() || self::is_admin_user()) return;
        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $allowed = [self::CONSOLE_SLUG, self::LOGIN_SLUG];
        if (in_array($path, $allowed, true)) return;
        wp_safe_redirect(home_url('/' . self::CONSOLE_SLUG . '/'));
        exit;
    }

    public static function filter_login_redirect($redirect_to, $request, $user) {
        if ($user instanceof WP_User && self::is_staff($user)) return home_url('/' . self::CONSOLE_SLUG . '/');
        return $redirect_to;
    }
}

register_activation_hook(__FILE__, ['Surface_Operations_Console', 'activate']);
add_action('plugins_loaded', ['Surface_Operations_Console', 'boot']);
