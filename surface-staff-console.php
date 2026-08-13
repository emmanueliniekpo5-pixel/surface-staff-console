<?php
/**
 * Plugin Name: Surface Operations Console
 * Description: Internal Surface Internet operations, staff access, hierarchy, tasks and audit foundation.
 * Version: 1.5.6
 * Author: KX
 */

if (!defined('ABSPATH')) exit;

final class Surface_Operations_Console {

    const VERSION = '1.5.5';
    const ROLE = 'surface_staff';
    const LOGIN_SLUG = 'staff-login';
    const CONSOLE_SLUG = 'surface-staff-console';
    const OTP_TTL = 600;
    const OTP_RESEND_WAIT = 60;
    const OTP_MAX_ATTEMPTS = 5;

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_admin_actions']);
        add_action('admin_init', [__CLASS__, 'handle_admin_task_actions']);
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade'], 5);
        add_action('admin_init', [__CLASS__, 'guard_staff_admin']);
        add_action('template_redirect', [__CLASS__, 'handle_front_auth'], 1);
        add_action('template_redirect', [__CLASS__, 'handle_analytics_export'], 4);
        add_action('template_redirect', [__CLASS__, 'handle_task_actions'], 5);
        add_action('template_redirect', [__CLASS__, 'handle_registry_actions'], 5);
        add_action('template_redirect', [__CLASS__, 'handle_protected_sii_actions'], 5);
        add_action('template_redirect', [__CLASS__, 'handle_partner_actions'], 6);
        add_action('template_redirect', [__CLASS__, 'handle_surfacetooth_actions'], 7);
        add_action('template_redirect', [__CLASS__, 'handle_campaign_actions'], 8);
        add_action('template_redirect', [__CLASS__, 'handle_question_bank_actions'], 8);
        add_action('template_redirect', [__CLASS__, 'handle_wallet_actions'], 9);
        add_action('template_redirect', [__CLASS__, 'handle_bundle_actions'], 10);
        add_action('template_redirect', [__CLASS__, 'handle_advocate_actions'], 11);
        add_action('template_redirect', [__CLASS__, 'handle_support_actions'], 12);
        add_action('template_redirect', [__CLASS__, 'handle_escalation_actions'], 13);
        add_action('template_redirect', [__CLASS__, 'handle_notification_actions'], 14);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'capture_resolve_request'], 10, 3);
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

        $comments = $wpdb->prefix . 'surface_operations_task_comments';

        dbDelta("CREATE TABLE {$comments} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            comment_text LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY task_id (task_id),
            KEY user_id (user_id)
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
        $wallet_reviews = $wpdb->prefix . 'surface_operations_wallet_reviews';
        dbDelta("CREATE TABLE {$wallet_reviews} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ledger_id BIGINT UNSIGNED NOT NULL,
            reviewed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reviewed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ledger_id (ledger_id),
            KEY reviewed_by (reviewed_by)
        ) {$charset};");

        $resolver_logs = $wpdb->prefix . 'surface_operations_resolver_logs';
        dbDelta("CREATE TABLE {$resolver_logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            resolve_id VARCHAR(80) NOT NULL,
            requested_sii VARCHAR(190) NULL,
            resolved_sii VARCHAR(190) NULL,
            partner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            channel VARCHAR(40) NULL,
            result VARCHAR(40) NOT NULL DEFAULT 'unknown',
            device VARCHAR(190) NULL,
            phone VARCHAR(80) NULL,
            request_meta LONGTEXT NULL,
            response_summary LONGTEXT NULL,
            processing_time_ms DECIMAL(12,2) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'unknown',
            linked_surfacetooth VARCHAR(190) NULL,
            linked_campaign VARCHAR(190) NULL,
            linked_wallet VARCHAR(190) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY resolve_id (resolve_id),
            KEY requested_sii (requested_sii),
            KEY partner_user_id (partner_user_id),
            KEY channel (channel),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");
        $support_cases = $wpdb->prefix . 'surface_operations_support_cases';
        dbDelta("CREATE TABLE {$support_cases} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_code VARCHAR(40) NOT NULL,
            subject VARCHAR(190) NOT NULL,
            description LONGTEXT NULL,
            reporter_name VARCHAR(190) NULL,
            reporter_email VARCHAR(190) NULL,
            reporter_phone VARCHAR(80) NULL,
            partner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            related_surfacetooth_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            related_campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            related_wallet_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(40) NOT NULL DEFAULT 'open',
            assigned_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY case_code (case_code),
            KEY status (status),
            KEY priority (priority),
            KEY assigned_user_id (assigned_user_id),
            KEY partner_user_id (partner_user_id)
        ) {$charset};");

        $support_notes = $wpdb->prefix . 'surface_operations_support_notes';
        dbDelta("CREATE TABLE {$support_notes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            note_text LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY case_id (case_id),
            KEY user_id (user_id)
        ) {$charset};");
        $escalations = $wpdb->prefix . 'surface_operations_escalations';
        dbDelta("CREATE TABLE {$escalations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_code VARCHAR(40) NOT NULL,
            object_type VARCHAR(80) NOT NULL,
            object_id VARCHAR(120) NOT NULL,
            object_label VARCHAR(190) NULL,
            requested_action VARCHAR(80) NOT NULL DEFAULT 'suspend',
            reason VARCHAR(80) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'normal',
            notes LONGTEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            current_level VARCHAR(40) NOT NULL DEFAULT 'manager',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            assigned_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            decision_notes LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY case_code (case_code),
            KEY object_lookup (object_type, object_id),
            KEY status (status),
            KEY current_level (current_level),
            KEY created_by (created_by)
        ) {$charset};");


        $escalation_events = $wpdb->prefix . 'surface_operations_escalation_events';
        dbDelta("CREATE TABLE {$escalation_events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            escalation_id BIGINT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_key VARCHAR(80) NOT NULL,
            event_note LONGTEXT NULL,
            from_level VARCHAR(40) NULL,
            to_level VARCHAR(40) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY escalation_id (escalation_id),
            KEY actor_user_id (actor_user_id),
            KEY event_key (event_key)
        ) {$charset};");

        // Repair records created while the workflow owner was stored as 0/blank.
        $wpdb->query("UPDATE {$escalations} SET current_level='manager' WHERE current_level IS NULL OR current_level='' OR current_level='0'");
        $wpdb->query("UPDATE {$escalations} SET current_level='manager' WHERE current_level='operations_manager'");
        $wpdb->query("UPDATE {$escalations} SET current_level='team_lead' WHERE current_level='teamlead'");
        $wpdb->query("UPDATE {$escalations} SET current_level='operations_director' WHERE current_level='director'");


        $notifications = $wpdb->prefix . 'surface_operations_notifications';
        dbDelta("CREATE TABLE {$notifications} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type_key VARCHAR(80) NOT NULL,
            module VARCHAR(80) NOT NULL DEFAULT 'general',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            title VARCHAR(190) NOT NULL,
            summary TEXT NULL,
            object_type VARCHAR(80) NULL,
            object_id VARCHAR(120) NULL,
            target_url TEXT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id_read (user_id, is_read),
            KEY type_key (type_key),
            KEY created_at (created_at)
        ) {$charset};");


        $registry_assignments = $wpdb->prefix . 'surface_operations_registry_assignments';
        dbDelta("CREATE TABLE {$registry_assignments} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registry_id BIGINT UNSIGNED NOT NULL,
            assigned_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            assigned_team VARCHAR(80) NOT NULL DEFAULT 'Surface Identity',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            internal_notes LONGTEXT NULL,
            assigned_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            assigned_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY registry_id (registry_id),
            KEY assigned_user_id (assigned_user_id),
            KEY assigned_team (assigned_team),
            KEY priority (priority)
        ) {$charset};");

        update_option('surface_operations_console_version', self::VERSION, false);
    }

    public static function maybe_upgrade() {
        $installed = (string) get_option('surface_operations_console_version', '');
        if ($installed !== self::VERSION) {
            self::activate();
            update_option('surface_operations_console_version', self::VERSION, false);
        }
    }


    private static function ensure_task_tables() {
        global $wpdb;

        $tasks = $wpdb->prefix . 'surface_operations_tasks';
        $comments = $wpdb->prefix . 'surface_operations_task_comments';

        $tasks_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tasks));
        $comments_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $comments));

        if ($tasks_exists === $tasks && $comments_exists === $comments) {
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

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

        dbDelta("CREATE TABLE {$comments} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            comment_text LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY task_id (task_id),
            KEY user_id (user_id)
        ) {$charset};");

        $tasks_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tasks));
        $comments_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $comments));

        return $tasks_exists === $tasks && $comments_exists === $comments;
    }

    private static function ensure_audit_table() {
        global $wpdb;

        $audit = $wpdb->prefix . 'surface_operations_audit';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audit));
        if ($exists === $audit) return true;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

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

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audit));
        return $exists === $audit;
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
            'operations_director' => ['dashboard','notifications','tasks','registry','partners','surfaceteeth','advocates','campaigns','questionbank','wallet','bundles','resolver','support','escalations','analytics','reports','teams','staff','audit'],
            'operations_manager'  => ['dashboard','notifications','tasks','registry','partners','surfaceteeth','advocates','campaigns','questionbank','wallet','bundles','resolver','support','escalations','analytics','reports','teams','staff'],
            'team_lead'           => ['dashboard','notifications','tasks','registry','partners','surfaceteeth','advocates','campaigns','questionbank','wallet','bundles','resolver','support','escalations','analytics','reports','teams'],
            'operations_officer'  => ['dashboard','notifications','tasks','registry','partners','surfaceteeth','advocates','campaigns','questionbank','resolver','support','escalations'],
            'finance_officer'     => ['dashboard','notifications','tasks','wallet','bundles','escalations','reports'],
            'compliance_officer'  => ['dashboard','notifications','tasks','registry','partners','surfaceteeth','advocates','campaigns','questionbank','resolver','escalations','audit'],
            'support_officer'     => ['dashboard','notifications','tasks','partners','support','escalations'],
            'auditor'             => ['dashboard','notifications','resolver','analytics','reports','audit'],
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
        add_submenu_page(
            'surface-operations',
            'Staff Management',
            'Staff',
            'manage_options',
            'surface-operations',
            [__CLASS__, 'render_admin_page']
        );
        add_submenu_page(
            'surface-operations',
            'Operations Tasks',
            'Tasks',
            'manage_options',
            'surface-operations-tasks',
            [__CLASS__, 'render_admin_tasks_page']
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

    public static function handle_admin_task_actions() {
        if (!current_user_can('manage_options') || empty($_POST['surface_admin_task_action'])) return;
        check_admin_referer('surface_admin_task_action', 'surface_admin_task_nonce');

        global $wpdb;
        if (!self::ensure_task_tables()) {
            add_settings_error('surface_operations_tasks', 'task_table_failed', 'The task table could not be prepared. No task was saved.', 'error');
            return;
        }
        $table = $wpdb->prefix . 'surface_operations_tasks';
        $action = sanitize_key(wp_unslash($_POST['surface_admin_task_action']));

        if ($action === 'create') {
            $title = sanitize_text_field(wp_unslash($_POST['task_title'] ?? ''));
            $description = sanitize_textarea_field(wp_unslash($_POST['task_description'] ?? ''));
            $module = sanitize_key(wp_unslash($_POST['task_module'] ?? 'general'));
            $priority = sanitize_key(wp_unslash($_POST['task_priority'] ?? 'normal'));
            $team = sanitize_text_field(wp_unslash($_POST['task_team'] ?? ''));
            $user_id = absint($_POST['task_user_id'] ?? 0);
            $due_raw = sanitize_text_field(wp_unslash($_POST['task_due_at'] ?? ''));
            $due_at = $due_raw ? gmdate('Y-m-d H:i:s', strtotime($due_raw)) : null;

            if (!$title) {
                add_settings_error('surface_operations_tasks', 'task_title', 'Enter a task title.', 'error');
                return;
            }
            if ($user_id && !$team) $team = self::user_team($user_id);
            $now = current_time('mysql');
            $task_data = [
                'title'           => $title,
                'description'     => $description,
                'module'          => $module,
                'status'          => 'open',
                'priority'        => $priority,
                'assigned_team'   => $team,
                'created_by'      => get_current_user_id(),
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
            $task_formats = ['%s','%s','%s','%s','%s','%s','%d','%s','%s'];

            if ($user_id) {
                $task_data['assigned_user_id'] = $user_id;
                $task_formats[] = '%d';
            }
            if ($due_at) {
                $task_data['due_at'] = $due_at;
                $task_formats[] = '%s';
            }

            $inserted = $wpdb->insert($table, $task_data, $task_formats);
            if ($inserted === false) {
                add_settings_error('surface_operations_tasks', 'task_failed', 'Task was not saved. Database error: ' . $wpdb->last_error, 'error');
                return;
            }

            $task_id = (int) $wpdb->insert_id;
            self::audit('task.created','task',(string)$task_id,'Created task: '.$title);
            add_settings_error('surface_operations_tasks', 'task_created', 'Task created.', 'success');
        }

        if ($action === 'status') {
            $task_id = absint($_POST['task_id'] ?? 0);
            $status = sanitize_key(wp_unslash($_POST['task_status'] ?? 'open'));
            if (!in_array($status, ['open','in_progress','completed'], true)) $status = 'open';
            $data = ['status'=>$status,'updated_at'=>current_time('mysql')];
            if ($status === 'completed') $data['completed_at'] = current_time('mysql');
            else $data['completed_at'] = null;
            $wpdb->update($table, $data, ['id'=>$task_id]);
            self::audit('task.status','task',(string)$task_id,'Administrator changed task status to '.str_replace('_',' ',$status));
            add_settings_error('surface_operations_tasks', 'task_updated', 'Task status updated.', 'success');
        }

        if ($action === 'reassign') {
            $task_id = absint($_POST['task_id'] ?? 0);
            $user_id = absint($_POST['task_user_id'] ?? 0);
            $team = sanitize_text_field(wp_unslash($_POST['task_team'] ?? ''));
            $task = $task_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $task_id)) : null;

            if (!$task) {
                add_settings_error('surface_operations_tasks', 'task_missing', 'Task could not be found.', 'error');
                return;
            }

            if ($user_id) {
                $assigned_user = get_user_by('id', $user_id);
                if (!$assigned_user || !self::is_staff($assigned_user) || self::staff_status($user_id) === 'suspended') {
                    add_settings_error('surface_operations_tasks', 'task_assignee', 'Select an active staff member.', 'error');
                    return;
                }
                $team = self::user_team($user_id);
            }

            $wpdb->update(
                $table,
                [
                    'assigned_user_id' => $user_id ?: null,
                    'assigned_team'    => $team,
                    'updated_at'       => current_time('mysql'),
                ],
                ['id' => $task_id],
                ['%d','%s','%s'],
                ['%d']
            );
            self::audit('task.reassigned','task',(string)$task_id,'Administrator reassigned task: '.$task->title);
            add_settings_error('surface_operations_tasks', 'task_reassigned', 'Task assignment updated.', 'success');
        }

        if ($action === 'delete') {
            $task_id = absint($_POST['task_id'] ?? 0);
            $wpdb->delete($table, ['id'=>$task_id], ['%d']);
            $wpdb->delete($wpdb->prefix.'surface_operations_task_comments', ['task_id'=>$task_id], ['%d']);
            self::audit('task.deleted','task',(string)$task_id,'Administrator deleted a task');
            add_settings_error('surface_operations_tasks', 'task_deleted', 'Task deleted.', 'success');
        }
    }

    public static function render_admin_tasks_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $task_tables_ready = self::ensure_task_tables();
        $table = $wpdb->prefix . 'surface_operations_tasks';
        $filter = sanitize_key(wp_unslash($_GET['task_status'] ?? 'all'));
        $filter_priority = sanitize_key(wp_unslash($_GET['task_priority'] ?? 'all'));
        $filter_team = sanitize_text_field(wp_unslash($_GET['task_team'] ?? ''));
        $filter_user = absint($_GET['task_user_id'] ?? 0);
        $conditions = [];
        $query_args = [];
        if (in_array($filter, ['open','in_progress','completed'], true)) {
            $conditions[] = 'status=%s';
            $query_args[] = $filter;
        }
        if (in_array($filter_priority, ['low','normal','high','urgent'], true)) {
            $conditions[] = 'priority=%s';
            $query_args[] = $filter_priority;
        }
        if ($filter_team !== '') {
            $conditions[] = 'assigned_team=%s';
            $query_args[] = $filter_team;
        }
        if ($filter_user) {
            $conditions[] = 'assigned_user_id=%d';
            $query_args[] = $filter_user;
        }
        $where = $conditions ? ' WHERE '.implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM {$table}{$where} ORDER BY CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END, CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, id DESC LIMIT 200";
        if ($query_args) $sql = $wpdb->prepare($sql, $query_args);
        $tasks = $task_tables_ready ? $wpdb->get_results($sql) : [];
        $staff = get_users(['role'=>self::ROLE,'orderby'=>'display_name','order'=>'ASC']);
        settings_errors('surface_operations_tasks');
        ?>
        <div class="wrap">
            <h1>Operations Tasks</h1>
            <p>Create, assign and monitor work across Surface Operations.</p>
            <?php if (!$task_tables_ready): ?><div class="notice notice-error inline"><p>The task table could not be prepared. No task will be saved until this is resolved.</p></div><?php endif; ?>
            <div style="display:grid;grid-template-columns:minmax(300px,380px) 1fr;gap:20px;align-items:start;margin-top:20px;">
                <div class="postbox" style="padding:20px;">
                    <h2 style="margin-top:0;">Assign Task</h2>
                    <form method="post">
                        <?php wp_nonce_field('surface_admin_task_action','surface_admin_task_nonce'); ?>
                        <input type="hidden" name="surface_admin_task_action" value="create">
                        <p><label><strong>Task</strong><br><input class="widefat" name="task_title" required></label></p>
                        <p><label><strong>Description</strong><br><textarea class="widefat" rows="4" name="task_description"></textarea></label></p>
                        <p><label><strong>Module</strong><br><select class="widefat" name="task_module"><?php foreach(['general'=>'General','registry'=>'Surface Identity','partners'=>'Partners','surfaceteeth'=>'SurfaceTeeth','advocacy'=>'Advocacy','campaigns'=>'Campaigns','questionbank'=>'Question Bank','wallet'=>'Wallet','bundles'=>'Bundles','resolver'=>'Resolver','support'=>'Support'] as $k=>$v) echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>'; ?></select></label></p>
                        <p><label><strong>Priority</strong><br><select class="widefat" name="task_priority"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label></p>
                        <p><label><strong>Assign to staff</strong><br><select class="widefat" name="task_user_id"><option value="0">Team queue</option><?php foreach($staff as $member){ if(self::staff_status($member->ID)==='suspended') continue; echo '<option value="'.esc_attr($member->ID).'">'.esc_html($member->display_name.' · '.self::user_team($member->ID)).'</option>'; } ?></select></label></p>
                        <p><label><strong>Team</strong><br><select class="widefat" name="task_team"><option value="">Select team</option><?php foreach(self::teams() as $team) echo '<option value="'.esc_attr($team).'">'.esc_html($team).'</option>'; ?></select></label></p>
                        <p><label><strong>Due date</strong><br><input class="widefat" type="datetime-local" name="task_due_at"></label></p>
                        <p><button class="button button-primary" type="submit">Assign Task</button></p>
                    </form>
                </div>
                <div>
                    <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px;background:#fff;border:1px solid #dcdcde;padding:12px;">
                        <input type="hidden" name="page" value="surface-operations-tasks">
                        <label><strong>Status</strong><br><select name="task_status"><option value="all">All</option><option value="open" <?php selected($filter,'open'); ?>>Open</option><option value="in_progress" <?php selected($filter,'in_progress'); ?>>In Progress</option><option value="completed" <?php selected($filter,'completed'); ?>>Completed</option></select></label>
                        <label><strong>Priority</strong><br><select name="task_priority"><option value="all">All</option><?php foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($filter_priority,$k,false).'>'.esc_html($v).'</option>'; ?></select></label>
                        <label><strong>Team</strong><br><select name="task_team"><option value="">All teams</option><?php foreach(self::teams() as $team) echo '<option value="'.esc_attr($team).'" '.selected($filter_team,$team,false).'>'.esc_html($team).'</option>'; ?></select></label>
                        <label><strong>Staff</strong><br><select name="task_user_id"><option value="0">All staff</option><?php foreach($staff as $member) echo '<option value="'.esc_attr($member->ID).'" '.selected($filter_user,$member->ID,false).'>'.esc_html($member->display_name).'</option>'; ?></select></label>
                        <button class="button button-primary" type="submit">Filter</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=surface-operations-tasks')); ?>">Reset</a>
                    </form>
                    <?php if(!$tasks): ?><div class="notice notice-info inline"><p>No tasks found.</p></div><?php endif; ?>
                    <?php foreach($tasks as $task): ?>
                        <div class="postbox" style="padding:16px;margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;gap:12px;align-items:start;">
                                <div><h2 style="margin:0 0 5px;"><?php echo esc_html($task->title); ?></h2><div style="color:#646970;"><?php echo esc_html(ucfirst($task->module).' · '.ucfirst($task->priority).' · '.ucwords(str_replace('_',' ',$task->status))); ?><?php if($task->due_at) echo esc_html(' · Due '.mysql2date('M j, Y g:i a',$task->due_at)); ?></div></div>
                                <strong><?php echo esc_html($task->assigned_user_id ? self::staff_name($task->assigned_user_id) : ($task->assigned_team ?: 'Unassigned')); ?></strong>
                            </div>
                            <?php if($task->description): ?><p><?php echo nl2br(esc_html($task->description)); ?></p><?php endif; ?>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <?php wp_nonce_field('surface_admin_task_action','surface_admin_task_nonce'); ?><input type="hidden" name="surface_admin_task_action" value="reassign"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>">
                                    <select name="task_user_id"><option value="0">Team queue</option><?php foreach($staff as $member){ if(self::staff_status($member->ID)==='suspended') continue; echo '<option value="'.esc_attr($member->ID).'" '.selected((int)$task->assigned_user_id,$member->ID,false).'>'.esc_html($member->display_name).'</option>'; } ?></select>
                                    <select name="task_team"><option value="">No team</option><?php foreach(self::teams() as $team) echo '<option value="'.esc_attr($team).'" '.selected((string)$task->assigned_team,$team,false).'>'.esc_html($team).'</option>'; ?></select><button class="button" type="submit">Reassign</button>
                                </form>
                                <form method="post" style="display:flex;gap:8px;align-items:center;">
                                    <?php wp_nonce_field('surface_admin_task_action','surface_admin_task_nonce'); ?><input type="hidden" name="surface_admin_task_action" value="status"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>">
                                    <select name="task_status"><option value="open" <?php selected($task->status,'open'); ?>>Open</option><option value="in_progress" <?php selected($task->status,'in_progress'); ?>>In Progress</option><option value="completed" <?php selected($task->status,'completed'); ?>>Completed</option></select><button class="button" type="submit">Update</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Delete this task?');">
                                    <?php wp_nonce_field('surface_admin_task_action','surface_admin_task_nonce'); ?><input type="hidden" name="surface_admin_task_action" value="delete"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><button class="button button-link-delete" type="submit">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
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

    public static function handle_task_actions() {
        if (is_admin() || !is_user_logged_in() || !self::is_staff()) return;
        if (empty($_POST['surface_operations_task_action'])) return;
        if (!isset($_POST['surface_operations_task_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['surface_operations_task_nonce'])), 'surface_operations_task')) return;

        global $wpdb;
        $tasks = $wpdb->prefix . 'surface_operations_tasks';
        $comments = $wpdb->prefix . 'surface_operations_task_comments';
        $user = wp_get_current_user();
        $team = self::user_team($user->ID) ?: 'Operations';
        $action = sanitize_key(wp_unslash($_POST['surface_operations_task_action']));
        $redirect = add_query_arg('soc_section', 'tasks', home_url('/' . self::CONSOLE_SLUG . '/'));

        if ($action === 'create' && self::can_manage_tasks($user->ID)) {
            $title = sanitize_text_field(wp_unslash($_POST['task_title'] ?? ''));
            $description = sanitize_textarea_field(wp_unslash($_POST['task_description'] ?? ''));
            $module = sanitize_key(wp_unslash($_POST['task_module'] ?? 'general'));
            $priority = sanitize_key(wp_unslash($_POST['task_priority'] ?? 'normal'));
            $assigned_team = sanitize_text_field(wp_unslash($_POST['task_team'] ?? ''));
            $assigned_user_id = absint($_POST['task_user_id'] ?? 0);
            $due_raw = sanitize_text_field(wp_unslash($_POST['task_due_at'] ?? ''));
            $due_at = $due_raw ? date('Y-m-d H:i:s', strtotime($due_raw)) : null;
            if ($assigned_user_id) {
                $assigned_user = get_user_by('id', $assigned_user_id);
                if (!$assigned_user || !self::is_staff($assigned_user)) $assigned_user_id = 0;
                else $assigned_team = self::user_team($assigned_user_id);
            }
            if ($title) {
                $now = current_time('mysql');
                $task_data = [
                    'title'         => $title,
                    'description'   => $description,
                    'module'        => $module,
                    'status'        => 'open',
                    'priority'      => $priority,
                    'assigned_team' => $assigned_team,
                    'created_by'    => $user->ID,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
                $task_formats = ['%s','%s','%s','%s','%s','%s','%d','%s','%s'];

                if ($assigned_user_id) {
                    $task_data['assigned_user_id'] = $assigned_user_id;
                    $task_formats[] = '%d';
                }
                if ($due_at) {
                    $task_data['due_at'] = $due_at;
                    $task_formats[] = '%s';
                }

                $inserted = $wpdb->insert($tasks, $task_data, $task_formats);
                if ($inserted !== false) {
                    $task_id = (int) $wpdb->insert_id;
                    self::audit('task.created','task',(string)$task_id,'Created task: ' . $title);
                    if($assigned_user_id) self::notify_user($assigned_user_id,'task_assigned','tasks',$priority,'Task Assigned',$title,'task',$task_id,add_query_arg('soc_section','tasks',home_url('/'.self::CONSOLE_SLUG.'/')));
                    $redirect = add_query_arg('task_notice','created',$redirect);
                } else {
                    $redirect = add_query_arg('task_notice','failed',$redirect);
                }
            }
        }

        if (in_array($action, ['status','claim','comment','reassign'], true)) {
            $task_id = absint($_POST['task_id'] ?? 0);
            $task = $task_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tasks} WHERE id=%d", $task_id)) : null;
            if ($task && self::can_work_task($task, $user->ID, $team)) {
                if ($action === 'claim' && empty($task->assigned_user_id)) {
                    $wpdb->update($tasks, ['assigned_user_id'=>$user->ID,'assigned_team'=>$team,'status'=>'in_progress','updated_at'=>current_time('mysql')], ['id'=>$task_id], ['%d','%s','%s','%s'], ['%d']);
                    self::audit('task.claimed','task',(string)$task_id,'Claimed task: ' . $task->title);
                    $redirect = add_query_arg('task_notice','claimed',$redirect);
                }
                if ($action === 'status') {
                    $status = sanitize_key(wp_unslash($_POST['task_status'] ?? 'open'));
                    if (in_array($status, ['open','in_progress','completed'], true)) {
                        $data = ['status'=>$status,'updated_at'=>current_time('mysql'),'completed_at'=>($status==='completed'?current_time('mysql'):null)];
                        $wpdb->update($tasks,$data,['id'=>$task_id],['%s','%s','%s'],['%d']);
                        self::audit('task.status','task',(string)$task_id,'Changed task status to ' . str_replace('_',' ',$status) . ': ' . $task->title);
                        $redirect = add_query_arg('task_notice','updated',$redirect);
                    }
                }
                if ($action === 'reassign' && self::can_manage_tasks($user->ID)) {
                    $assigned_user_id = absint($_POST['task_user_id'] ?? 0);
                    $assigned_team = sanitize_text_field(wp_unslash($_POST['task_team'] ?? ''));
                    if ($assigned_user_id) {
                        $assigned_user = get_user_by('id', $assigned_user_id);
                        if (!$assigned_user || !self::is_staff($assigned_user) || self::staff_status($assigned_user_id) === 'suspended') {
                            $assigned_user_id = 0;
                        } else {
                            $assigned_team = self::user_team($assigned_user_id);
                        }
                    }
                    $wpdb->update($tasks,[
                        'assigned_user_id'=>$assigned_user_id ?: null,
                        'assigned_team'=>$assigned_team,
                        'updated_at'=>current_time('mysql')
                    ],['id'=>$task_id],['%d','%s','%s'],['%d']);
                    self::audit('task.reassigned','task',(string)$task_id,'Reassigned task: '.$task->title);
                    if($assigned_user_id) self::notify_user($assigned_user_id,'task_assigned','tasks',$task->priority,'Task Assigned',$task->title,'task',$task_id,add_query_arg('soc_section','tasks',home_url('/'.self::CONSOLE_SLUG.'/')));
                    $redirect = add_query_arg('task_notice','reassigned',$redirect);
                }
                if ($action === 'comment') {
                    $comment = sanitize_textarea_field(wp_unslash($_POST['task_comment'] ?? ''));
                    if ($comment) {
                        $wpdb->insert($comments,['task_id'=>$task_id,'user_id'=>$user->ID,'comment_text'=>$comment,'created_at'=>current_time('mysql')],['%d','%d','%s','%s']);
                        self::audit('task.comment','task',(string)$task_id,'Added a task comment: ' . $task->title);
                        $redirect = add_query_arg('task_notice','commented',$redirect);
                    }
                }
            }
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_partner_actions() {
        if (!is_user_logged_in() || !self::is_staff() || empty($_POST['surface_operations_partner_action'])) return;

        $user = wp_get_current_user();
        if (!self::can_access('partners', $user->ID)) return;

        check_admin_referer('surface_operations_partner', 'surface_operations_partner_nonce');

        $partner_id = absint($_POST['partner_id'] ?? 0);
        $partner = $partner_id ? get_user_by('id', $partner_id) : false;
        if (!$partner || !self::is_surface_partner($partner)) return;

        $action = sanitize_key(wp_unslash($_POST['surface_operations_partner_action']));
        if ($action === 'suspend' || $action === 'reactivate') {
            $new_status = $action === 'suspend' ? 'suspended' : 'active';
            update_user_meta($partner_id, 'surface_operations_partner_status', $new_status);
            self::audit(
                'partner.' . $new_status,
                'partner',
                (string) $partner_id,
                ucfirst($new_status) . ' partner /' . self::partner_sii($partner_id),
                ['partner_email' => $partner->user_email]
            );
        }

        $redirect = add_query_arg([
            'soc_section' => 'partners',
            'partner_notice' => $action === 'suspend' ? 'suspended' : 'reactivated',
        ], home_url('/' . self::CONSOLE_SLUG . '/'));
        wp_safe_redirect($redirect);
        exit;
    }

    private static function is_surface_partner($user) {
        if (!$user) return false;
        return in_array('surface_partner', (array) $user->roles, true)
            || (string) get_user_meta($user->ID, 'surface_name', true) !== '';
    }

    private static function partner_sii($user_id) {
        return trim((string) get_user_meta($user_id, 'surface_name', true));
    }

    private static function partner_status($user_id) {
        $status = sanitize_key((string) get_user_meta($user_id, 'surface_operations_partner_status', true));
        if (in_array($status, ['active', 'pending', 'suspended'], true)) return $status;
        return get_user_meta($user_id, 'surface_onboarding_saved', true) ? 'active' : 'pending';
    }

    private static function partner_surfaceteeth_count($user_id) {
        global $wpdb;
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_surface_partner_user_id' WHERE p.post_status NOT IN ('trash','auto-draft') AND (p.post_author=%d OR pm.meta_value=%s)",
            $user_id,
            (string) $user_id
        ));
        return count($post_ids);
    }

    private static function partner_bundle_summary($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_bundles';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) return 'Not available';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total, COALESCE(SUM(remaining_mb),0) remaining FROM {$table} WHERE user_id=%d AND status='active'",
            $user_id
        ));
        if (!$row || !(int) $row->total) return 'No active bundle';
        return (int) $row->total . ' active · ' . number_format_i18n((float) $row->remaining, 0) . ' MB remaining';
    }

    private static function partner_wallet_balance($user_id) {
        $keys = ['surface_wallet_balance', 'kx_wallet_balance', 'surface_kx_balance', 'kx_unit_balance'];
        foreach ($keys as $key) {
            $value = get_user_meta($user_id, $key, true);
            if ($value !== '' && is_numeric($value)) return number_format_i18n((float) $value, 2);
        }
        return 'Not available';
    }

    private static function surface_partners($search = '') {
        $users = get_users([
            'role__in' => ['surface_partner'],
            'orderby' => 'registered',
            'order' => 'DESC',
            'number' => -1,
        ]);
        $by_id = [];
        foreach ($users as $partner) $by_id[$partner->ID] = $partner;

        $with_sii = get_users([
            'meta_key' => 'surface_name',
            'meta_compare' => 'EXISTS',
            'orderby' => 'registered',
            'order' => 'DESC',
            'number' => -1,
        ]);
        foreach ($with_sii as $partner) {
            if (self::partner_sii($partner->ID) !== '') $by_id[$partner->ID] = $partner;
        }

        $partners = array_values($by_id);
        usort($partners, function($a, $b) { return strcmp($b->user_registered, $a->user_registered); });

        $search = trim((string) $search);
        if ($search !== '') {
            $needle = strtolower($search);
            $partners = array_values(array_filter($partners, function($partner) use ($needle) {
                $store = (string) get_user_meta($partner->ID, 'surface_store', true);
                $sii = self::partner_sii($partner->ID);
                $haystack = strtolower($partner->display_name . ' ' . $store . ' ' . $sii . ' ' . $partner->user_email);
                return strpos($haystack, $needle) !== false;
            }));
        }
        return $partners;
    }

    private static function can_enforce($user_id = 0) {
        $user_id = $user_id ?: get_current_user_id();
        return self::user_level($user_id) === 'operations_director' || self::is_admin_user(get_user_by('id', $user_id));
    }

    private static function next_escalation_level($user_id) {
        $level = self::user_level($user_id);
        if ($level === 'operations_manager') return 'team_lead';
        if ($level === 'team_lead') return 'operations_director';
        if ($level === 'operations_director') return 'operations_director';
        // All officer roles raise first to the Operations Manager.
        return 'manager';
    }

    private static function escalation_reasons() {
        return ['fraud'=>'Fraud','policy_violation'=>'Policy violation','copyright'=>'Copyright','scam'=>'Scam','offensive_content'=>'Offensive content','spam'=>'Spam','security'=>'Security concern','other'=>'Other'];
    }

    private static function ensure_escalation_tables() {
        global $wpdb;
        $main = $wpdb->prefix . 'surface_operations_escalations';
        $events = $wpdb->prefix . 'surface_operations_escalation_events';
        if (self::table_exists($main) && self::table_exists($events)) return true;
        self::activate();
        return self::table_exists($main) && self::table_exists($events);
    }

    private static function escalation_event($escalation_id, $event_key, $note = '', $from_level = '', $to_level = '') {
        global $wpdb;
        if (!self::ensure_escalation_tables()) return false;
        return $wpdb->insert($wpdb->prefix . 'surface_operations_escalation_events', [
            'escalation_id' => absint($escalation_id),
            'actor_user_id' => get_current_user_id(),
            'event_key' => sanitize_key($event_key),
            'event_note' => sanitize_textarea_field($note),
            'from_level' => sanitize_key($from_level),
            'to_level' => sanitize_key($to_level),
            'created_at' => current_time('mysql'),
        ], ['%d','%d','%s','%s','%s','%s','%s']);
    }

    private static function escalation_events($escalation_id) {
        global $wpdb;
        if (!self::ensure_escalation_tables()) return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}surface_operations_escalation_events WHERE escalation_id=%d ORDER BY created_at ASC,id ASC",
            absint($escalation_id)
        ));
    }

    private static function ensure_notification_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_notifications';
        if (self::table_exists($table)) return true;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type_key VARCHAR(80) NOT NULL,
            module VARCHAR(80) NOT NULL DEFAULT 'general',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            title VARCHAR(190) NOT NULL,
            summary TEXT NULL,
            object_type VARCHAR(80) NULL,
            object_id VARCHAR(120) NULL,
            target_url TEXT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id_read (user_id, is_read),
            KEY type_key (type_key),
            KEY created_at (created_at)
        ) {$charset};");
        return self::table_exists($table);
    }

    private static function notification_recipients_for_level($level) {
        $level = self::normalized_escalation_level($level);
        $meta_level = ['manager'=>'operations_manager','team_lead'=>'team_lead','operations_director'=>'operations_director'][$level] ?? '';
        if (!$meta_level) return [];
        $users = get_users(['role'=>self::ROLE,'meta_key'=>'surface_operations_level','meta_value'=>$meta_level,'fields'=>'ID']);
        return array_values(array_filter(array_map('absint',$users), function($id){ return self::staff_status($id) === 'active'; }));
    }

    private static function notify_user($user_id, $type_key, $module, $priority, $title, $summary, $object_type='', $object_id='', $target_url='') {
        if (!$user_id || !self::ensure_notification_table()) return 0;
        global $wpdb; $table=$wpdb->prefix.'surface_operations_notifications';
        $wpdb->insert($table,['user_id'=>absint($user_id),'type_key'=>sanitize_key($type_key),'module'=>sanitize_key($module),'priority'=>sanitize_key($priority),'title'=>sanitize_text_field($title),'summary'=>sanitize_textarea_field($summary),'object_type'=>sanitize_key($object_type),'object_id'=>sanitize_text_field((string)$object_id),'target_url'=>esc_url_raw($target_url),'is_read'=>0,'created_at'=>current_time('mysql')],['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s']);
        return (int)$wpdb->insert_id;
    }

    private static function notify_users($user_ids, $type_key, $module, $priority, $title, $summary, $object_type='', $object_id='', $target_url='') {
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)$user_ids))));
        foreach($ids as $uid) self::notify_user($uid,$type_key,$module,$priority,$title,$summary,$object_type,$object_id,$target_url);
        if($ids) self::audit('notification.generated',$object_type ?: 'notification',(string)$object_id,'Generated notification: '.$title,['type'=>$type_key,'recipients'=>$ids]);
    }

    private static function notification_count($user_id) {
        if (!self::ensure_notification_table()) return 0;
        global $wpdb; $table=$wpdb->prefix.'surface_operations_notifications';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND is_read=0",$user_id));
    }

    private static function notifications_for_user($user_id,$limit=200) {
        if (!self::ensure_notification_table()) return [];
        global $wpdb; $table=$wpdb->prefix.'surface_operations_notifications';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d ORDER BY is_read ASC, created_at DESC LIMIT %d",$user_id,$limit));
    }

    public static function handle_notification_actions() {
        if (!is_user_logged_in() || !self::is_staff() || empty($_POST['surface_operations_notification_action'])) return;
        check_admin_referer('surface_operations_notification','surface_operations_notification_nonce');
        if (!self::ensure_notification_table()) return;
        global $wpdb; $table=$wpdb->prefix.'surface_operations_notifications'; $uid=get_current_user_id();
        $action=sanitize_key(wp_unslash($_POST['surface_operations_notification_action']));
        $base=add_query_arg('soc_section','notifications',home_url('/'.self::CONSOLE_SLUG.'/'));
        if($action==='mark_all_read'){
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET is_read=1,read_at=%s WHERE user_id=%d AND is_read=0",current_time('mysql'),$uid));
            self::audit('notification.mark_all_read','notification',(string)$uid,'Marked all notifications as read');
            wp_safe_redirect(add_query_arg('notification_notice','all_read',$base)); exit;
        }
        $nid=absint($_POST['notification_id']??0);
        $row=$nid?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND user_id=%d",$nid,$uid)):null;
        if(!$row)return;
        $wpdb->update($table,['is_read'=>1,'read_at'=>current_time('mysql')],['id'=>$nid],['%d','%s'],['%d']);
        self::audit('notification.read','notification',(string)$nid,'Read notification: '.$row->title);
        if($action==='open' && $row->target_url){wp_safe_redirect($row->target_url);exit;}
        wp_safe_redirect(add_query_arg('notification_notice','read',$base));exit;
    }

    private static function can_view_escalation($row, $user_id) {
        if (!$row) return false;
        if ((int)$row->created_by === (int)$user_id) return true;
        return in_array(self::user_level($user_id), ['operations_manager','team_lead','operations_director'], true);
    }

    private static function normalized_escalation_level($level) {
        $level = sanitize_key((string) $level);
        $aliases = [
            'operations_manager'  => 'manager',
            'manager'            => 'manager',
            'teamlead'           => 'team_lead',
            'team_lead'          => 'team_lead',
            'director'           => 'operations_director',
            'operations_director'=> 'operations_director',
            'staff'              => 'creator',
            'operations_officer' => 'creator',
            'creator'            => 'creator',
            '0'                  => 'manager',
            ''                   => 'manager',
        ];
        return $aliases[$level] ?? $level;
    }

    private static function escalation_owner_label($level) {
        $level = self::normalized_escalation_level($level);
        $labels = [
            'creator'             => 'Source Staff',
            'manager'             => 'Operations Manager',
            'team_lead'           => 'Team Lead',
            'operations_director' => 'Operations Director',
        ];
        return $labels[$level] ?? ucwords(str_replace('_', ' ', $level));
    }

    private static function escalation_status_label($row) {
        if (!$row) return '';
        if ($row->status === 'approved') return 'Approved';
        if ($row->status === 'rejected') return 'Rejected';
        $level = self::normalized_escalation_level($row->current_level);
        if ($row->status === 'returned') return 'Returned to ' . self::escalation_owner_label($level);
        return 'Pending ' . self::escalation_owner_label($level) . ' Review';
    }

    private static function can_process_escalation($row, $user_id) {
        if (!$row || in_array($row->status, ['approved','rejected'], true)) return false;
        $actor_level = self::normalized_escalation_level(self::user_level($user_id));
        $case_level  = self::normalized_escalation_level($row->current_level);
        return in_array($actor_level, ['manager','team_lead','operations_director'], true)
            && $actor_level === $case_level;
    }

    private static function create_escalation($object_type, $object_id, $object_label, $requested_action, $reason, $severity, $notes) {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_escalations';
        $now = current_time('mysql');
        $code = 'ESC-' . strtoupper(wp_generate_password(8, false, false));
        $wpdb->insert($table, [
            'case_code'=>$code,'object_type'=>sanitize_key($object_type),'object_id'=>sanitize_text_field((string)$object_id),
            'object_label'=>sanitize_text_field($object_label),'requested_action'=>sanitize_key($requested_action),
            'reason'=>sanitize_key($reason),'severity'=>sanitize_key($severity),'notes'=>sanitize_textarea_field($notes),
            'status'=>'pending','current_level'=>self::next_escalation_level(get_current_user_id()),
            'created_by'=>get_current_user_id(),'assigned_user_id'=>0,'created_at'=>$now,'updated_at'=>$now,
        ], ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s']);
        $id = (int)$wpdb->insert_id;
        self::audit('escalation.created','escalation',(string)$id,'Created escalation '.$code.' for '.$object_label,[
            'object_type'=>$object_type,'object_id'=>(string)$object_id,'requested_action'=>$requested_action,'reason'=>$reason,'severity'=>$severity
        ]);
        $start_level=self::next_escalation_level(get_current_user_id());
        self::escalation_event($id,'created',$notes,'creator',$start_level);
        $target=add_query_arg(['soc_section'=>'escalations','view_escalation'=>$id],home_url('/'.self::CONSOLE_SLUG.'/'));
        self::notify_users(self::notification_recipients_for_level($start_level),'escalation_submitted','escalations',$severity==='urgent'?'urgent':'high','New Escalation',$object_label.' requires '.self::escalation_owner_label($start_level).' review.','escalation',$id,$target);
        return $id;
    }

    public static function handle_escalation_actions() {
        if (!is_user_logged_in() || !self::is_staff() || empty($_POST['surface_operations_escalation_action'])) return;
        $user = wp_get_current_user();
        if (!self::can_access('escalations', $user->ID)) return;
        check_admin_referer('surface_operations_escalation','surface_operations_escalation_nonce');
        if (!self::ensure_escalation_tables()) return;

        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_escalations';
        $action = sanitize_key(wp_unslash($_POST['surface_operations_escalation_action']));
        $base = home_url('/'.self::CONSOLE_SLUG.'/');

        if ($action === 'create') {
            $type=sanitize_key(wp_unslash($_POST['object_type']??'')); $id=sanitize_text_field(wp_unslash($_POST['object_id']??''));
            $label=sanitize_text_field(wp_unslash($_POST['object_label']??'')); $requested=sanitize_key(wp_unslash($_POST['requested_action']??'suspend'));
            $reason=sanitize_key(wp_unslash($_POST['reason']??'other')); $severity=sanitize_key(wp_unslash($_POST['severity']??'normal'));
            $notes=sanitize_textarea_field(wp_unslash($_POST['notes']??''));
            $created_id = 0;
            if ($type && $id && isset(self::escalation_reasons()[$reason])) $created_id=self::create_escalation($type,$id,$label,$requested,$reason,$severity,$notes);
            wp_safe_redirect(add_query_arg(['soc_section'=>'escalations','view_escalation'=>$created_id,'escalation_notice'=>'created'],$base)); exit;
        }

        $eid=absint($_POST['escalation_id']??0); if(!$eid)return;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$eid));
        if(!$row || !self::can_view_escalation($row,$user->ID)) return;
        $notes=sanitize_textarea_field(wp_unslash($_POST['decision_notes']??''));
        $redirect_args=['soc_section'=>'escalations','view_escalation'=>$eid];

        if ($action === 'note') {
            if ($notes === '') return;
            self::escalation_event($eid,'note_added',$notes,$row->current_level,$row->current_level);
            self::audit('escalation.note_added','escalation',(string)$eid,'Added note to escalation '.$row->case_code,['note'=>$notes]);
            $wpdb->update($table,['updated_at'=>current_time('mysql')],['id'=>$eid],['%s'],['%d']);
            $redirect_args['escalation_notice']='note_added';
            wp_safe_redirect(add_query_arg($redirect_args,$base)); exit;
        }

        if ($action === 'resubmit') {
            if ((int)$row->created_by !== (int)$user->ID || $row->status !== 'returned') return;
            $new_level=self::next_escalation_level($user->ID);
            $wpdb->update($table,['status'=>'pending','current_level'=>$new_level,'decision_notes'=>$notes,'updated_at'=>current_time('mysql')],['id'=>$eid],['%s','%s','%s','%s'],['%d']);
            self::escalation_event($eid,'resubmitted',$notes,'creator',$new_level);
            self::audit('escalation.resubmitted','escalation',(string)$eid,'Resubmitted escalation '.$row->case_code,['note'=>$notes,'to_level'=>$new_level]);
            $target=add_query_arg(['soc_section'=>'escalations','view_escalation'=>$eid],$base);
            self::notify_users(self::notification_recipients_for_level($new_level),'escalation_resubmitted','escalations','high','Escalation Resubmitted',$row->case_code.' has been resubmitted for review.','escalation',$eid,$target);
            $redirect_args['escalation_notice']='resubmitted';
            wp_safe_redirect(add_query_arg($redirect_args,$base)); exit;
        }

        if (!self::can_process_escalation($row,$user->ID)) return;
        $new_status=$row->status; $new_level=$row->current_level; $closed=null; $event='';
        if ($action==='forward') {
            $current_level=self::normalized_escalation_level($row->current_level);
            $map=['manager'=>'team_lead','team_lead'=>'operations_director'];
            if (!isset($map[$current_level])) return;
            $new_level=$map[$current_level]; $new_status='pending'; $event='forwarded';
        } elseif ($action==='return') {
            $current_level=self::normalized_escalation_level($row->current_level);
            $back=['manager'=>'creator','team_lead'=>'manager','operations_director'=>'team_lead'];
            $new_level=$back[$current_level]??'creator'; $new_status='returned'; $event='returned';
        } elseif ($action==='reject' && self::can_enforce($user->ID)) {
            $new_status='rejected'; $closed=current_time('mysql'); $event='rejected';
        } elseif ($action==='approve' && self::can_enforce($user->ID)) {
            self::apply_escalated_action($row); $new_status='approved'; $closed=current_time('mysql'); $event='approved';
        } else { return; }

        $data=['status'=>$new_status,'current_level'=>$new_level,'decision_notes'=>$notes,'updated_at'=>current_time('mysql')];
        $fmt=['%s','%s','%s','%s']; if($closed){$data['closed_at']=$closed;$fmt[]='%s';}
        $wpdb->update($table,$data,['id'=>$eid],$fmt,['%d']);
        self::escalation_event($eid,$event,$notes,$row->current_level,$new_level);
        self::audit('escalation.'.$event,'escalation',(string)$eid,ucfirst($event).' escalation '.$row->case_code.' from '.self::escalation_owner_label($row->current_level).' to '.self::escalation_owner_label($new_level),['decision_notes'=>$notes,'from_level'=>$row->current_level,'to_level'=>$new_level]);
        $target=add_query_arg(['soc_section'=>'escalations','view_escalation'=>$eid],$base);
        if($event==='forwarded') self::notify_users(self::notification_recipients_for_level($new_level),'escalation_forwarded','escalations','high','Escalation Forwarded',$row->case_code.' was forwarded to '.self::escalation_owner_label($new_level).'.','escalation',$eid,$target);
        elseif($event==='returned'){ $recipients=$new_level==='creator'?[(int)$row->created_by]:self::notification_recipients_for_level($new_level); self::notify_users($recipients,'escalation_returned','escalations','high','Escalation Returned',$row->case_code.' was returned to '.self::escalation_owner_label($new_level).'.','escalation',$eid,$target);}
        elseif(in_array($event,['approved','rejected'],true)) self::notify_users([(int)$row->created_by],'escalation_'.$event,'escalations',$event==='approved'?'normal':'high','Escalation '.ucfirst($event),$row->case_code.' has been '.$event.'.','escalation',$eid,$target);
        $redirect_args['escalation_notice']=$event;
        wp_safe_redirect(add_query_arg($redirect_args,$base)); exit;
    }

    private static function apply_escalated_action($row) {
        global $wpdb;
        $id=absint($row->object_id); $action=sanitize_key($row->requested_action);
        if ($row->object_type==='partner') update_user_meta($id,'surface_operations_partner_status',$action==='reactivate'?'active':'suspended');
        elseif ($row->object_type==='surfacetooth') update_post_meta($id,'_surface_operations_surfacetooth_status',$action==='reactivate'?'active':'suspended');
        elseif ($row->object_type==='campaign') {
            $t=$wpdb->prefix.'surface_campaigns'; if(self::table_exists($t))$wpdb->update($t,['status'=>$action==='reactivate'?'active':'suspended'],['id'=>$id],['%s'],['%d']);
        } elseif ($row->object_type==='advocate') update_user_meta($id,'surface_advocate_status',$action==='reactivate'?'active':'suspended');
        elseif ($row->object_type==='bundle') update_option('surface_operations_bundle_status_'.$id,$action==='reactivate'?'active':'suspended',false);
        self::audit($row->object_type.'.'.($action==='reactivate'?'active':'suspended'),$row->object_type,(string)$id,'Enforcement approved through '.$row->case_code);
    }

    private static function escalation_form($type,$id,$label,$requested='suspend') {

        ob_start(); ?>
        <details class="soc-escalate"><summary class="soc-btn soc-btn-light">Escalate</summary><form class="soc-form" method="post" style="min-width:280px;margin-top:10px"><?php wp_nonce_field('surface_operations_escalation','surface_operations_escalation_nonce'); ?>
        <input type="hidden" name="surface_operations_escalation_action" value="create"><input type="hidden" name="object_type" value="<?php echo esc_attr($type); ?>"><input type="hidden" name="object_id" value="<?php echo esc_attr($id); ?>"><input type="hidden" name="object_label" value="<?php echo esc_attr($label); ?>"><input type="hidden" name="requested_action" value="<?php echo esc_attr($requested); ?>">
        <label>Reason<select name="reason"><?php foreach(self::escalation_reasons() as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
        <label>Severity<select name="severity"><option value="normal">Normal</option><option value="high">High</option><option value="critical">Critical</option></select></label>
        <label>Notes<textarea name="notes" required></textarea></label><button class="soc-btn" type="submit">Submit Escalation</button></form></details>
        <?php return ob_get_clean();
    }

    public static function handle_surfacetooth_actions() {
        if (!is_user_logged_in() || !self::is_staff()) return;
        if (empty($_POST['surface_operations_surfacetooth_action'])) return;

        $user = wp_get_current_user();
        if (!self::can_access('surfaceteeth', $user->ID)) return;

        check_admin_referer('surface_operations_surfacetooth', 'surface_operations_surfacetooth_nonce');

        $post_id = absint($_POST['surfacetooth_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post || !in_array($post->post_type, ['product', 'surface_signal'], true)) return;
        if (!get_post_meta($post_id, '_surface_partner_user_id', true) && !$post->post_author) return;

        $action = sanitize_key(wp_unslash($_POST['surface_operations_surfacetooth_action']));

        if ($action === 'remove_media') {
            $media_key = sanitize_text_field(wp_unslash($_POST['media_key'] ?? ''));
            $media_index = isset($_POST['media_index']) ? absint($_POST['media_index']) : null;
            $removed = self::remove_surfacetooth_media_item($post, $media_key, $media_index);

            if ($removed) {
                self::audit(
                    'surfacetooth.media_removed',
                    'surfacetooth',
                    (string) $post_id,
                    'Removed SurfaceTooth media: ' . get_the_title($post_id),
                    ['media_key' => $media_key, 'media_index' => $media_index]
                );
            }

            wp_safe_redirect(add_query_arg([
                'soc_section' => 'surfaceteeth',
                'view_surfacetooth' => $post_id,
                'surfacetooth_notice' => $removed ? 'media_removed' : 'media_not_removed',
            ], home_url('/' . self::CONSOLE_SLUG . '/')));
            exit;
        }

        if ($action === 'edit') {
            $title = sanitize_text_field(wp_unslash($_POST['surfacetooth_title'] ?? ''));
            $description = sanitize_textarea_field(wp_unslash($_POST['surfacetooth_description'] ?? ''));
            $partner_id = self::surfacetooth_partner_id($post);

            if ($title !== '') {
                $post_update = [
                    'ID'           => $post_id,
                    'post_title'   => $title,
                    'post_content' => $description,
                ];
                if ($post->post_type === 'surface_signal') {
                    update_post_meta($post_id, '_surface_signal_message', $description);
                }
                wp_update_post($post_update);
            }

            $updated_channels = [];
            if ($partner_id) {
                foreach (self::surfacetooth_channel_fields() as $meta_key => $label) {
                    $submitted = isset($_POST[$meta_key])
                        ? sanitize_text_field(wp_unslash($_POST[$meta_key]))
                        : '';
                    update_user_meta($partner_id, $meta_key, $submitted);
                    $updated_channels[$label] = $submitted;
                }
            }

            self::audit(
                'surfacetooth.edited',
                'surfacetooth',
                (string) $post_id,
                'Edited SurfaceTooth and partner channels: ' . get_the_title($post_id),
                [
                    'partner_id' => $partner_id,
                    'channels'   => $updated_channels,
                ]
            );
            wp_safe_redirect(add_query_arg(['soc_section'=>'surfaceteeth','view_surfacetooth'=>$post_id,'surfacetooth_notice'=>'edited'],home_url('/'.self::CONSOLE_SLUG.'/'))); exit;
        }
        if (!in_array($action, ['suspend', 'reactivate'], true) || !self::can_enforce($user->ID)) return;

        $new_status = $action === 'suspend' ? 'suspended' : 'active';
        update_post_meta($post_id, '_surface_operations_surfacetooth_status', $new_status);

        self::audit(
            'surfacetooth.' . $new_status,
            'surfacetooth',
            (string) $post_id,
            ucfirst($new_status) . ' SurfaceTooth: ' . get_the_title($post_id),
            [
                'type' => self::surfacetooth_type($post),
                'sii'  => self::surfacetooth_sii($post),
            ]
        );

        $redirect = add_query_arg([
            'soc_section' => 'surfaceteeth',
            'surfacetooth_notice' => $new_status,
        ], home_url('/' . self::CONSOLE_SLUG . '/'));
        wp_safe_redirect($redirect);
        exit;
    }

    private static function surfacetooth_partner_id($post) {
        if (!$post) return 0;
        $partner_id = absint(get_post_meta($post->ID, '_surface_partner_user_id', true));
        return $partner_id ?: absint($post->post_author);
    }

    private static function surfacetooth_type($post) {
        if (!$post) return 'Unknown';
        if ($post->post_type === 'product') return 'Market';
        if ($post->post_type === 'surface_signal') {
            $signal_type = sanitize_key((string) get_post_meta($post->ID, '_surface_signal_type', true));
            $service_types = [
                'artisans','professional_services','home_services','health_wellness',
                'beauty_grooming','education_training','technology_digital','automotive',
                'hospitality_events','logistics_delivery','creative_services','other_services',
                'properties'
            ];
            return in_array($signal_type, $service_types, true) ? 'Service' : 'Broadcast';
        }
        return 'Unknown';
    }

    private static function surfacetooth_status($post) {
        if (!$post) return 'draft';
        $status = sanitize_key((string) get_post_meta($post->ID, '_surface_operations_surfacetooth_status', true));
        if (in_array($status, ['active', 'draft', 'suspended'], true)) return $status;
        return $post->post_status === 'publish' ? 'active' : 'draft';
    }

    private static function surfacetooth_sii($post) {
        if (!$post) return '';
        if ($post->post_type === 'product') {
            return trim((string) get_post_meta($post->ID, '_sku', true), " /@#\t\n\r\0\x0B");
        }
        $kxcode = (string) get_post_meta($post->ID, '_surface_kxcode', true);
        if ($kxcode !== '') return trim($kxcode, " /@#\t\n\r\0\x0B");
        $target = trim((string) get_post_meta($post->ID, '_surface_signal_target', true), " /@#\t\n\r\0\x0B");
        $suffix = trim((string) get_post_meta($post->ID, '_surface_signal_suffix', true), " /@#\t\n\r\0\x0B");
        return trim($target . ($target && $suffix ? '/' : '') . $suffix, '/');
    }

    private static function surfacetooth_description($post) {
        if (!$post) return '';
        if ($post->post_type === 'surface_signal') {
            $message = (string) get_post_meta($post->ID, '_surface_signal_message', true);
            if ($message !== '') return $message;
        }
        return $post->post_excerpt ?: wp_strip_all_tags($post->post_content);
    }

    private static function surfacetooth_channel_fields() {
        return [
            'surface_whatsapp'  => 'WhatsApp',
            'surface_instagram' => 'Instagram',
            'surface_facebook'  => 'Facebook',
            'surface_x'         => 'X (Twitter)',
            'surface_tiktok'    => 'TikTok',
            'surface_youtube'   => 'YouTube',
            'surface_website'   => 'Website',
        ];
    }

    private static function surfacetooth_channel_values($post) {
        if (!$post) return [];
        $partner_id = self::surfacetooth_partner_id($post);
        if (!$partner_id) return [];

        $values = [];
        foreach (self::surfacetooth_channel_fields() as $meta_key => $label) {
            $values[$meta_key] = trim((string) get_user_meta($partner_id, $meta_key, true));
        }
        return $values;
    }

    private static function surfacetooth_channels($post) {
        $values = self::surfacetooth_channel_values($post);
        $channels = [];
        foreach (self::surfacetooth_channel_fields() as $meta_key => $label) {
            if (($values[$meta_key] ?? '') !== '') {
                $channels[] = $label . ': ' . $values[$meta_key];
            }
        }
        return implode(' · ', $channels);
    }

    private static function surfacetooth_media_items($post) {
        if (!$post) return [];

        $items = [];
        $seen = [];
        $add = static function($url, $type, $label, $key, $index = null, $attachment_id = 0) use (&$items, &$seen) {
            $url = esc_url_raw((string) $url);
            if ($url === '') return;
            $signature = strtolower($url);
            if (isset($seen[$signature])) return;
            $seen[$signature] = true;
            $items[] = [
                'url' => $url,
                'type' => $type === 'video' ? 'video' : 'image',
                'label' => $label,
                'key' => (string) $key,
                'index' => $index,
                'attachment_id' => absint($attachment_id),
            ];
        };

        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $url = wp_get_attachment_url($thumbnail_id);
            if ($url) $add($url, 'image', 'Featured image', '_thumbnail_id', null, $thumbnail_id);
        }

        $registry = get_post_meta($post->ID, '_surface_media_registry', true);
        if (is_array($registry)) {
            foreach ($registry as $registry_key => $entry) {
                $entries = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
                foreach ($entries as $idx => $value) {
                    $url = '';
                    $attachment_id = 0;
                    if (is_array($value)) {
                        $url = (string) ($value['url'] ?? $value['ready_url'] ?? $value['src'] ?? '');
                        $attachment_id = absint($value['attachment_id'] ?? $value['id'] ?? 0);
                        if (!$url && $attachment_id) $url = (string) wp_get_attachment_url($attachment_id);
                    } elseif (is_numeric($value)) {
                        $attachment_id = absint($value);
                        $url = (string) wp_get_attachment_url($attachment_id);
                    } else {
                        $url = (string) $value;
                    }
                    $type = stripos((string) $registry_key, 'video') !== false || preg_match('/\.(mp4|mov|webm|m4v)(\?|$)/i', $url) ? 'video' : 'image';
                    $add($url, $type, 'SurfaceMark ' . ucfirst((string) $registry_key), '_surface_media_registry:' . $registry_key, is_array($entry) && array_is_list($entry) ? $idx : null, $attachment_id);
                }
            }
        }

        $all_meta = get_post_meta($post->ID);
        foreach ($all_meta as $meta_key => $values) {
            if ($meta_key === '_surface_media_registry' || $meta_key === '_thumbnail_id') continue;
            $key_l = strtolower((string) $meta_key);
            if (strpos($key_l, 'surface') === false) continue;
            if (!preg_match('/(image|video|media|thumbnail|poster|ready_url|asset)/', $key_l)) continue;

            foreach ((array) $values as $value_index => $raw) {
                $decoded = maybe_unserialize($raw);
                $candidates = is_array($decoded) ? $decoded : [$decoded];
                foreach ($candidates as $idx => $candidate) {
                    $url = '';
                    $attachment_id = 0;
                    if (is_array($candidate)) {
                        $url = (string) ($candidate['url'] ?? $candidate['ready_url'] ?? $candidate['src'] ?? '');
                        $attachment_id = absint($candidate['attachment_id'] ?? $candidate['id'] ?? 0);
                        if (!$url && $attachment_id) $url = (string) wp_get_attachment_url($attachment_id);
                    } elseif (is_numeric($candidate)) {
                        $attachment_id = absint($candidate);
                        $url = (string) wp_get_attachment_url($attachment_id);
                    } elseif (is_string($candidate) && preg_match('#^https?://#i', trim($candidate))) {
                        $url = trim($candidate);
                    }
                    if (!$url) continue;
                    $type = strpos($key_l, 'video') !== false || preg_match('/\.(mp4|mov|webm|m4v)(\?|$)/i', $url) ? 'video' : 'image';
                    $remove_index = is_array($decoded) ? $idx : null;
                    $add($url, $type, ucwords(str_replace(['_surface_', '_', '-'], ['', ' ', ' '], $meta_key)), $meta_key, $remove_index, $attachment_id);
                }
            }
        }

        $attachments = get_children([
            'post_parent' => $post->ID,
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'orderby' => 'menu_order ID',
            'order' => 'ASC',
        ]);
        foreach ($attachments as $attachment) {
            $mime = (string) get_post_mime_type($attachment->ID);
            if (strpos($mime, 'image/') !== 0 && strpos($mime, 'video/') !== 0) continue;
            $url = wp_get_attachment_url($attachment->ID);
            if ($url) $add($url, strpos($mime, 'video/') === 0 ? 'video' : 'image', $attachment->post_title ?: 'Attached media', 'attachment:' . $attachment->ID, null, $attachment->ID);
        }

        return $items;
    }

    private static function surfacetooth_media_summary($post) {
        $items = self::surfacetooth_media_items($post);
        if (!$items) return 'No media';
        $images = 0;
        $videos = 0;
        foreach ($items as $item) $item['type'] === 'video' ? $videos++ : $images++;
        $parts = [];
        if ($images) $parts[] = $images . ' ' . _n('image', 'images', $images);
        if ($videos) $parts[] = $videos . ' ' . _n('video', 'videos', $videos);
        return implode(' · ', $parts);
    }

    private static function remove_surfacetooth_media_item($post, $media_key, $media_index = null) {
        if (!$post || $media_key === '') return false;

        if ($media_key === '_thumbnail_id') {
            return delete_post_thumbnail($post->ID);
        }

        if (strpos($media_key, 'attachment:') === 0) {
            $attachment_id = absint(substr($media_key, strlen('attachment:')));
            if (!$attachment_id || (int) wp_get_post_parent_id($attachment_id) !== (int) $post->ID) return false;
            return (bool) wp_delete_attachment($attachment_id, false);
        }

        if (strpos($media_key, '_surface_media_registry:') === 0) {
            $registry_key = substr($media_key, strlen('_surface_media_registry:'));
            $registry = get_post_meta($post->ID, '_surface_media_registry', true);
            if (!is_array($registry) || !array_key_exists($registry_key, $registry)) return false;
            if ($media_index !== null && is_array($registry[$registry_key]) && array_is_list($registry[$registry_key])) {
                unset($registry[$registry_key][$media_index]);
                $registry[$registry_key] = array_values($registry[$registry_key]);
                if (!$registry[$registry_key]) unset($registry[$registry_key]);
            } else {
                unset($registry[$registry_key]);
            }
            update_post_meta($post->ID, '_surface_media_registry', $registry);
            return true;
        }

        if (strpos($media_key, '_surface_') !== 0) return false;
        $current = get_post_meta($post->ID, $media_key, true);
        if (is_array($current) && $media_index !== null && array_key_exists($media_index, $current)) {
            unset($current[$media_index]);
            $current = array_values($current);
            if ($current) update_post_meta($post->ID, $media_key, $current);
            else delete_post_meta($post->ID, $media_key);
            return true;
        }
        return delete_post_meta($post->ID, $media_key);
    }

    private static function surface_teeth($search = '') {
        global $wpdb;
        $sql = "SELECT DISTINCT p.* FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_surface_partner_user_id' WHERE p.post_type IN ('product','surface_signal') AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.post_date DESC";
        $posts = $wpdb->get_results($sql);

        $search = strtolower(trim((string) $search));
        if ($search === '') return $posts;

        return array_values(array_filter($posts, function($post) use ($search) {
            $partner_id = self::surfacetooth_partner_id($post);
            $partner = $partner_id ? get_user_by('id', $partner_id) : false;
            $partner_name = $partner ? $partner->display_name : '';
            $store = $partner_id ? (string) get_user_meta($partner_id, 'surface_store', true) : '';
            $haystack = strtolower(
                $post->post_title . ' ' . self::surfacetooth_sii($post) . ' ' .
                self::surfacetooth_type($post) . ' ' . $partner_name . ' ' . $store
            );
            return strpos($haystack, $search) !== false;
        }));
    }


    private static function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function campaign_status($campaign) {
        $status = sanitize_key((string) ($campaign->status ?? 'active'));
        if ($status === 'suspended') return 'suspended';
        if (in_array($status, ['ended','completed','closed','expired'], true)) return 'ended';

        $start = !empty($campaign->preferred_start_date) ? strtotime((string) $campaign->preferred_start_date . ' 00:00:00') : false;
        if ($start && $start > current_time('timestamp')) return 'scheduled';
        return 'active';
    }

    private static function campaign_partner_name($partner_id) {
        $partner_id = absint($partner_id);
        if (!$partner_id) return 'Global campaign';
        $partner = get_user_by('id', $partner_id);
        if (!$partner) return 'Unknown partner';
        $store = (string) get_user_meta($partner_id, 'surface_store', true);
        if ($store === '') $store = (string) get_user_meta($partner_id, 'surface_name', true);
        return $store ?: $partner->display_name;
    }

    private static function campaign_surfacetooth($campaign) {
        $post_id = absint($campaign->source_application_id ?? 0);
        if (!$post_id) return null;
        $post = get_post($post_id);
        return ($post && !in_array($post->post_status, ['trash','auto-draft'], true)) ? $post : null;
    }

    private static function campaign_counts($campaign_id) {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        $receipts = $wpdb->prefix . 'surface_receipts';
        $winners = $wpdb->prefix . 'surface_campaign_winners';
        $participation = self::table_exists($receipts) ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$receipts} WHERE campaign_id=%d", $campaign_id)) : 0;
        $winner_count = self::table_exists($winners) ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT participant_phone) FROM {$winners} WHERE campaign_id=%d", $campaign_id)) : 0;
        return ['participation'=>$participation, 'winners'=>$winner_count];
    }

    private static function campaign_cashback_summary($campaign_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_campaign_collectibles';
        if (!self::table_exists($table)) return 'Not configured';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT MAX(receipt_reward_percent) receipt_cashback, MAX(participation_reward_percent) participation_cashback, MAX(duplicate_exchange_percent) duplicate_cashback FROM {$table} WHERE campaign_id=%d",
            absint($campaign_id)
        ));
        if (!$row || ($row->receipt_cashback === null && $row->participation_cashback === null && $row->duplicate_cashback === null)) return 'Not configured';
        return 'Receipt '.rtrim(rtrim(number_format((float)$row->receipt_cashback,2,'.',''),'0'),'.').'% · Participation '.rtrim(rtrim(number_format((float)$row->participation_cashback,2,'.',''),'0'),'.').'% · Double collectible '.rtrim(rtrim(number_format((float)$row->duplicate_cashback,2,'.',''),'0'),'.').'%';
    }

    private static function campaign_grand_cashback($campaign) {
        $type = trim((string) ($campaign->grand_reward_type ?? ''));
        $value = trim((string) ($campaign->grand_reward_value ?? ''));
        if ($type === '' && $value === '') return 'Not configured';
        return trim(ucwords(str_replace('_',' ',$type)) . ($value !== '' ? ': '.$value : ''));
    }

    private static function campaign_progress($campaign) {
        $counts = self::campaign_counts($campaign->id);
        $expected = max(0, absint($campaign->expected_winners ?? 0));
        if ($expected > 0) return $counts['winners'].' / '.$expected.' winners';
        return $counts['participation'].' participations';
    }

    private static function campaigns($search = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_campaigns';
        if (!self::table_exists($table)) return [];
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");
        $search = strtolower(trim((string)$search));
        if ($search === '') return $rows;
        return array_values(array_filter($rows, function($campaign) use ($search) {
            $partner = self::campaign_partner_name($campaign->partner_id ?? 0);
            $tooth = self::campaign_surfacetooth($campaign);
            $sii = $tooth ? self::surfacetooth_sii($tooth) : self::partner_sii(absint($campaign->partner_id ?? 0));
            $haystack = strtolower(($campaign->campaign_name ?? '').' '.$partner.' '.$sii.' '.($campaign->campaign_scope ?? '').' '.($campaign->target_value ?? ''));
            return strpos($haystack, $search) !== false;
        }));
    }

    private static function wallet_owner($phone) {
        $phone = trim((string)$phone);
        if ($phone === '') return null;
        $keys = ['surface_phone','billing_phone','phone'];
        foreach ($keys as $key) {
            $users = get_users(['meta_key'=>$key,'meta_value'=>$phone,'number'=>1,'fields'=>'all']);
            if ($users) return $users[0];
        }
        return null;
    }

    private static function wallet_source_label($source) {
        $source = sanitize_key((string)$source);
        $labels = [
            'receipt_reward'=>'Receipt Cashback',
            'receipt_cashback'=>'Receipt Cashback',
            'participation_reward'=>'Participation Cashback',
            'participation_cashback'=>'Participation Cashback',
            'duplicate_exchange'=>'Double Collectible Cashback',
            'double_collectible_cashback'=>'Double Collectible Cashback',
            'grand_reward'=>'Grand Cashback',
            'grand_cashback'=>'Grand Cashback',
            'advocate_introduction_credit'=>'Advocacy Introduction',
            'advocate_bundle_credit'=>'Advocacy Bundle',
            'advocate_receipt_commission_credit'=>'Advocacy Receipt Commission',
            'bundle_purchase'=>'Bundle',
            'manual'=>'Manual',
        ];
        return $labels[$source] ?? ucwords(str_replace('_',' ',$source ?: 'Wallet'));
    }

    private static function wallet_reviews() {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_wallet_reviews';
        if (!self::table_exists($table)) return [];
        $ids = $wpdb->get_col("SELECT ledger_id FROM {$table}");
        return array_fill_keys(array_map('intval',(array)$ids), true);
    }

    private static function wallet_transactions($search = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_kx_wallet_ledger';
        if (!self::table_exists($table)) return [];
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 500");
        $search = strtolower(trim((string)$search));
        if ($search === '') return $rows;
        return array_values(array_filter($rows, function($row) use ($search) {
            $owner = self::wallet_owner($row->phone_number ?? '');
            $name = $owner ? $owner->display_name.' '.$owner->user_email : '';
            $sii = $owner ? self::partner_sii($owner->ID) : '';
            $haystack = strtolower(($row->reference ?? '').' '.($row->phone_number ?? '').' '.($row->source ?? '').' '.$name.' '.$sii);
            return strpos($haystack,$search) !== false;
        }));
    }

    private static function wallet_summary() {
        global $wpdb;
        $wallets = $wpdb->prefix . 'surface_kx_wallets';
        $ledger = $wpdb->prefix . 'surface_kx_wallet_ledger';
        $summary = ['balance'=>0.0,'credits'=>0.0,'debits'=>0.0,'pending'=>0,'failed'=>0];
        if (self::table_exists($wallets)) $summary['balance'] = (float)$wpdb->get_var("SELECT COALESCE(SUM(balance),0) FROM {$wallets}");
        if (self::table_exists($ledger)) {
            $summary['credits'] = (float)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$ledger} WHERE amount > 0");
            $summary['debits'] = abs((float)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$ledger} WHERE amount < 0"));
        }
        return $summary;
    }

    private static function wallet_related_context($row) {
        global $wpdb;
        $result = ['campaign'=>'Not linked','surfacetooth'=>'Not linked'];
        $receipts = $wpdb->prefix . 'surface_receipts';
        if (!self::table_exists($receipts) || empty($row->reference)) return $result;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$receipts}",0);
        $reference_columns = array_values(array_intersect(['receipt_id','reference','receipt_reference'],(array)$columns));
        if (!$reference_columns) return $result;
        $parts=[]; $args=[];
        foreach($reference_columns as $column){$parts[]="{$column}=%s";$args[]=(string)$row->reference;}
        $receipt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$receipts} WHERE ".implode(' OR ',$parts)." LIMIT 1",...$args));
        if (!$receipt) return $result;
        if (!empty($receipt->campaign_id)) {
            $campaigns=$wpdb->prefix.'surface_campaigns';
            if(self::table_exists($campaigns)){
                $campaign=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$campaigns} WHERE id=%d",absint($receipt->campaign_id)));
                if($campaign){
                    $result['campaign']=$campaign->campaign_name ?: 'Campaign #'.$campaign->id;
                    $tooth=self::campaign_surfacetooth($campaign);
                    if($tooth)$result['surfacetooth']=$tooth->post_title;
                }
            }
        }
        return $result;
    }

    public static function handle_wallet_actions() {
        if (!is_user_logged_in() || !self::is_staff()) return;
        if (empty($_POST['surface_operations_wallet_action'])) return;
        $user = wp_get_current_user();
        if (!self::can_access('wallet',$user->ID)) return;
        check_admin_referer('surface_operations_wallet','surface_operations_wallet_nonce');
        $action=sanitize_key(wp_unslash($_POST['surface_operations_wallet_action']));
        $ledger_id=absint($_POST['ledger_id'] ?? 0);
        if($action!=='review' || !$ledger_id)return;
        global $wpdb;
        $ledger=$wpdb->prefix.'surface_kx_wallet_ledger';
        $reviews=$wpdb->prefix.'surface_operations_wallet_reviews';
        if(!self::table_exists($ledger) || !self::table_exists($reviews))return;
        $transaction=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ledger} WHERE id=%d",$ledger_id));
        if(!$transaction)return;
        $wpdb->replace($reviews,['ledger_id'=>$ledger_id,'reviewed_by'=>$user->ID,'reviewed_at'=>current_time('mysql')],['%d','%d','%s']);
        self::audit(
            'wallet_reviewed',
            'wallet',
            (string) $ledger_id,
            'Marked wallet transaction as reviewed: ' . ($transaction->reference ?: 'Ledger #' . $ledger_id),
            [
                'ledger_id' => $ledger_id,
                'phone'     => $transaction->phone_number,
                'source'    => $transaction->source,
                'amount'    => $transaction->amount,
            ]
        );
        wp_safe_redirect(add_query_arg(['soc_section'=>'wallet','wallet_notice'=>'reviewed'],home_url('/'.self::CONSOLE_SLUG.'/')));
        exit;
    }

    private static function ensure_question_bank_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_question_bank';
        if (self::table_exists($table)) return true;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_text LONGTEXT NOT NULL,
            answer_type VARCHAR(30) NOT NULL DEFAULT 'single',
            option_1 VARCHAR(255) NULL,
            option_2 VARCHAR(255) NULL,
            option_3 VARCHAR(255) NULL,
            option_4 VARCHAR(255) NULL,
            correct_answer VARCHAR(255) NOT NULL,
            category VARCHAR(120) NOT NULL DEFAULT 'General',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            assigned_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reviewed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY status (status), KEY category (category),
            KEY assigned_user_id (assigned_user_id), KEY answer_type (answer_type)
        ) {$wpdb->get_charset_collate()};";
        dbDelta($sql);
        return self::table_exists($table);
    }

    private static function question_bank_rows($search='') {
        global $wpdb;
        if (!self::ensure_question_bank_table()) return [];
        $table=$wpdb->prefix.'surface_question_bank';
        if ($search==='') return $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT 500");
        $like='%'.$wpdb->esc_like($search).'%';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE question_text LIKE %s OR category LIKE %s OR status LIKE %s ORDER BY updated_at DESC, id DESC LIMIT 500",$like,$like,$like));
    }

    public static function handle_question_bank_actions() {
        if (empty($_POST['surface_operations_question_action'])) return;
        $user=wp_get_current_user();
        if (!self::is_staff($user) || !self::can_access('questionbank',$user->ID)) return;
        check_admin_referer('surface_operations_question','surface_operations_question_nonce');
        if (!self::ensure_question_bank_table()) return;
        global $wpdb; $table=$wpdb->prefix.'surface_question_bank';
        $action=sanitize_key(wp_unslash($_POST['surface_operations_question_action']));
        $id=absint($_POST['question_id']??0); $now=current_time('mysql');
        $redirect=add_query_arg('soc_section','questionbank',home_url('/'.self::CONSOLE_SLUG.'/'));

        if (in_array($action,['create','update'],true)) {
            $question=sanitize_textarea_field(wp_unslash($_POST['question_text']??''));
            $type=sanitize_key(wp_unslash($_POST['answer_type']??'single'));
            if(!in_array($type,['single','ordered'],true)) $type='single';
            $correct=strtoupper(preg_replace('/\\s+/','',sanitize_text_field(wp_unslash($_POST['correct_answer']??''))));
            $status=sanitize_key(wp_unslash($_POST['question_status']??'draft'));
            if(!in_array($status,['draft','review','approved','inactive'],true)) $status='draft';
            if($question===''){wp_safe_redirect(add_query_arg('question_notice','missing',$redirect));exit;}
            $data=['question_text'=>$question,'answer_type'=>$type,'option_1'=>sanitize_text_field(wp_unslash($_POST['option_1']??'')),'option_2'=>sanitize_text_field(wp_unslash($_POST['option_2']??'')),'option_3'=>sanitize_text_field(wp_unslash($_POST['option_3']??'')),'option_4'=>sanitize_text_field(wp_unslash($_POST['option_4']??'')),'correct_answer'=>$correct,'category'=>sanitize_text_field(wp_unslash($_POST['question_category']??'General')) ?: 'General','status'=>$status,'assigned_user_id'=>absint($_POST['assigned_user_id']??0),'updated_at'=>$now];
            if($status==='approved'){ $data['reviewed_by']=$user->ID; $data['reviewed_at']=$now; }
            if($action==='create'){ $data['created_by']=$user->ID; $data['created_at']=$now; $wpdb->insert($table,$data); $id=(int)$wpdb->insert_id; self::audit('question.created','question',(string)$id,'Created question bank item'); }
            elseif($id){ $wpdb->update($table,$data,['id'=>$id]); self::audit('question.updated','question',(string)$id,'Updated question bank item'); }
            wp_safe_redirect(add_query_arg('question_notice','saved',$redirect));exit;
        }
        if($id && in_array($action,['draft','review','approved','inactive'],true)){
            $data=['status'=>$action,'updated_at'=>$now]; if($action==='approved'){$data['reviewed_by']=$user->ID;$data['reviewed_at']=$now;}
            $wpdb->update($table,$data,['id'=>$id]); self::audit('question.status','question',(string)$id,'Question status changed to '.$action); wp_safe_redirect(add_query_arg('question_notice',$action,$redirect));exit;
        }
    }

    public static function handle_campaign_actions() {
        if (!is_user_logged_in() || !self::is_staff()) return;
        if (empty($_POST['surface_operations_campaign_action'])) return;
        $user = wp_get_current_user();
        if (!self::can_access('campaigns', $user->ID)) return;
        check_admin_referer('surface_operations_campaign', 'surface_operations_campaign_nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'surface_campaigns';
        if (!self::table_exists($table)) return;
        $campaign_id = absint($_POST['campaign_id'] ?? 0);
        $action = sanitize_key(wp_unslash($_POST['surface_operations_campaign_action']));
        if (!$campaign_id) return;
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $campaign_id));
        if (!$campaign) return;
        if($action==='edit'){
            $columns=$wpdb->get_col("DESC {$table}",0); $data=[];$formats=[];
            foreach(['campaign_name'=>'%s','end_date'=>'%s','expected_winners'=>'%d'] as $field=>$format){if(in_array($field,$columns,true)&&isset($_POST[$field])){$data[$field]=$format==='%d'?absint($_POST[$field]):sanitize_text_field(wp_unslash($_POST[$field]));$formats[]=$format;}}
            if($data)$wpdb->update($table,$data,['id'=>$campaign_id],$formats,['%d']);
            self::audit('campaign.edited','campaign',(string)$campaign_id,'Edited campaign: '.($data['campaign_name']??$campaign->campaign_name),$data);
            wp_safe_redirect(add_query_arg(['soc_section'=>'campaigns','view_campaign'=>$campaign_id,'campaign_notice'=>'edited'],home_url('/'.self::CONSOLE_SLUG.'/')));exit;
        }
        if(!in_array($action,['suspend','reactivate'],true)||!self::can_enforce($user->ID))return;
        $new_status = $action === 'suspend' ? 'suspended' : 'active';
        $wpdb->update($table, ['status'=>$new_status], ['id'=>$campaign_id], ['%s'], ['%d']);
        self::audit('campaign.'.$new_status, 'campaign', (string)$campaign_id, ucfirst($new_status).' campaign: '.$campaign->campaign_name, [
            'partner_id'=>absint($campaign->partner_id ?? 0),
            'scope'=>(string)($campaign->campaign_scope ?? ''),
        ]);
        wp_safe_redirect(add_query_arg(['soc_section'=>'campaigns','campaign_notice'=>$new_status], home_url('/'.self::CONSOLE_SLUG.'/')));
        exit;
    }


    private static function advocates($search = '') {
        $users = get_users(['meta_key'=>'surface_is_advocate','meta_value'=>'yes','orderby'=>'display_name','order'=>'ASC','number'=>500]);
        if ($search === '') return $users;
        $n = strtolower($search);
        return array_values(array_filter($users, function($u) use ($n) {
            $phone=(string)get_user_meta($u->ID,'surface_phone',true);
            $sii=self::advocate_sii($u->ID);
            return strpos(strtolower($u->display_name.' '.$u->user_email.' '.$phone.' '.$sii),$n)!==false;
        }));
    }
    private static function advocate_sii($id) { return trim((string)(get_user_meta($id,'surface_name',true) ?: get_user_meta($id,'surface_sii',true))); }
    private static function advocate_status($id) {
        $s=sanitize_key((string)get_user_meta($id,'surface_advocate_status',true));
        return $s==='suspended'?'suspended':(in_array($s,['active','approved'],true)?'active':'pending');
    }
    private static function advocate_introduced($id,$mission=false) {
        $sii=self::advocate_sii($id); if($sii==='') return 0;
        $ids=get_users(['meta_key'=>'introduced_by','meta_value'=>$sii,'fields'=>'ID','number'=>-1]);
        if(!$mission) return count($ids); $c=0; foreach($ids as $pid) if(get_user_meta($pid,'surface_onboarding_saved',true)) $c++; return $c;
    }
    private static function advocate_financials($id) {
        global $wpdb; $r=['earnings'=>0.0,'balance'=>0.0,'activity'=>[]];
        $phone=trim((string)get_user_meta($id,'surface_phone',true)); if($phone==='') return $r;
        $wt=$wpdb->prefix.'surface_kx_wallets'; $lt=$wpdb->prefix.'surface_kx_wallet_ledger';
        if(self::table_exists($wt)) $r['balance']=(float)$wpdb->get_var($wpdb->prepare("SELECT balance FROM {$wt} WHERE phone_number=%s LIMIT 1",$phone));
        if(self::table_exists($lt)) {
            $r['earnings']=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$lt} WHERE phone_number=%s AND source IN ('advocate_introduction_credit','advocate_bundle_credit')",$phone));
            $r['activity']=$wpdb->get_results($wpdb->prepare("SELECT source,reference,amount,created_at FROM {$lt} WHERE phone_number=%s AND source IN ('advocate_introduction_credit','advocate_bundle_credit') ORDER BY id DESC LIMIT 10",$phone));
        } return $r;
    }
    private static function advocate_summary() {
        $r=['total'=>0,'active'=>0,'pending'=>0,'suspended'=>0,'introduced'=>0,'earnings'=>0.0];
        foreach(self::advocates() as $a){$r['total']++;$r[self::advocate_status($a->ID)]++;$r['introduced']+=self::advocate_introduced($a->ID);$r['earnings']+=self::advocate_financials($a->ID)['earnings'];} return $r;
    }
    public static function handle_advocate_actions() {
        if(empty($_POST['surface_operations_advocate_action']) || !is_user_logged_in() || !self::is_staff()) return;
        $u=wp_get_current_user(); if(!self::can_access('advocates',$u->ID)) return;
        check_admin_referer('surface_operations_advocate','surface_operations_advocate_nonce');
        $id=absint($_POST['advocate_id']??0); $a=$id?get_user_by('id',$id):false;
        if(!$a || get_user_meta($id,'surface_is_advocate',true)!=='yes') return;
        $action=sanitize_key(wp_unslash($_POST['surface_operations_advocate_action'])); $map=['approve'=>'active','suspend'=>'suspended','reactivate'=>'active']; if(!isset($map[$action])) return; if(in_array($action,['suspend','reactivate'],true) && !self::can_enforce(get_current_user_id())) return;
        update_user_meta($id,'surface_advocate_status',$map[$action]); if($action==='approve') update_user_meta($id,'surface_advocate_approved_at',current_time('mysql'));
        self::audit('advocate_'.$action,'advocate',(string)$id,ucfirst($action).'d advocate: '.$a->display_name,['sii'=>self::advocate_sii($id),'status'=>$map[$action]]);
        wp_safe_redirect(add_query_arg(['soc_section'=>'advocates','advocate_notice'=>$action,'view_advocate'=>$id],home_url('/'.self::CONSOLE_SLUG.'/'))); exit;
    }

    private static function bundle_table() {
        global $wpdb;
        return $wpdb->prefix . 'surface_bundles';
    }

    private static function bundles($search = '') {
        global $wpdb;
        $table = self::bundle_table();
        if (!self::table_exists($table)) return [];
        $sql = "SELECT * FROM {$table}";
        $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $user_ids = get_users([
                'search' => '*' . $search . '*',
                'search_columns' => ['user_login','user_email','display_name'],
                'fields' => 'ID',
            ]);
            $sii_ids = get_users([
                'meta_query' => [['key'=>'surface_sii','value'=>$search,'compare'=>'LIKE']],
                'fields' => 'ID',
            ]);
            $ids = array_values(array_unique(array_map('absint', array_merge((array)$user_ids,(array)$sii_ids))));
            $where = ['bundle_code LIKE %s'];
            $args[] = $like;
            if ($ids) $where[] = 'user_id IN (' . implode(',', $ids) . ')';
            $sql .= ' WHERE (' . implode(' OR ', $where) . ')';
        }
        $sql .= ' ORDER BY purchased_at DESC, id DESC LIMIT 500';
        return $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
    }

    private static function bundle_status($bundle) {
        $status = sanitize_key((string)($bundle->status ?? 'active'));
        if ($status === 'suspended') return 'suspended';
        if (!empty($bundle->expires_at) && strtotime($bundle->expires_at) < current_time('timestamp')) return 'expired';
        if ((float)($bundle->remaining_mb ?? 0) <= 0) return 'consumed';
        return $status ?: 'active';
    }

    private static function bundle_summary() {
        global $wpdb;
        $table = self::bundle_table();
        $summary = ['total'=>0,'active'=>0,'expired'=>0,'used'=>0,'remaining'=>0];
        if (!self::table_exists($table)) return $summary;
        $rows = $wpdb->get_results("SELECT * FROM {$table}");
        foreach ($rows as $row) {
            $summary['total']++;
            $status = self::bundle_status($row);
            if ($status === 'active') $summary['active']++;
            if ($status === 'expired') $summary['expired']++;
            $summary['used'] += (float)($row->used_mb ?? 0);
            $summary['remaining'] += max(0,(float)($row->remaining_mb ?? 0));
        }
        return $summary;
    }

    private static function format_storage($mb) {
        $mb=(float)$mb;
        if ($mb >= 1024) return number_format($mb/1024,2).' GB';
        return number_format($mb,2).' MB';
    }

    private static function bundle_partner($bundle) {
        $user = !empty($bundle->user_id) ? get_user_by('id', absint($bundle->user_id)) : false;
        return $user ?: false;
    }

    private static function bundle_audit_history($bundle_id, $limit=20) {
        global $wpdb;
        $table=$wpdb->prefix.'surface_operations_audit';
        if (!self::table_exists($table)) return [];
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE object_type='bundle' AND object_id=%s ORDER BY created_at DESC LIMIT %d",(string)$bundle_id,$limit));
    }

    private static function registry_audit_history($registry_id, $limit=20) {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_audit';
        if (!self::table_exists($table)) return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE object_type='registry' AND object_id=%s ORDER BY created_at DESC LIMIT %d",
            (string) $registry_id,
            $limit
        ));
    }

    private static function surfacetooth_audit_history($surfacetooth_id, $limit=20) {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_audit';
        if (!self::table_exists($table)) return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE object_type='surfacetooth' AND object_id=%s ORDER BY created_at DESC LIMIT %d",
            (string) $surfacetooth_id,
            $limit
        ));
    }

    public static function handle_bundle_actions() {
        if (empty($_POST['surface_operations_bundle_action'])) return;
        if (!is_user_logged_in() || !self::is_staff()) return;
        $user=wp_get_current_user();
        if (!self::can_access('bundles',$user->ID)) return;
        check_admin_referer('surface_operations_bundle','surface_operations_bundle_nonce');
        global $wpdb;
        $table=self::bundle_table();
        if (!self::table_exists($table)) return;
        $bundle_id=absint($_POST['bundle_id'] ?? 0);
        $bundle=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$bundle_id));
        if (!$bundle) return;
        $action=sanitize_key(wp_unslash($_POST['surface_operations_bundle_action']));
        $notice='';
        if ($action==='suspend' || $action==='reactivate') {
            $new_status=$action==='suspend'?'suspended':'active';
            $wpdb->update($table,['status'=>$new_status],['id'=>$bundle_id],['%s'],['%d']);
            self::audit('bundle_'.$action,'bundle',(string)$bundle_id,ucfirst($action).'d bundle: '.($bundle->bundle_code ?: '#'.$bundle_id),['bundle_id'=>$bundle_id,'bundle_code'=>$bundle->bundle_code,'status'=>$new_status]);
            $notice=$action;
        } elseif ($action==='extend') {
            $days=max(1,min(3650,absint($_POST['extension_days'] ?? 0)));
            $base=!empty($bundle->expires_at) && strtotime($bundle->expires_at)>current_time('timestamp') ? strtotime($bundle->expires_at) : current_time('timestamp');
            $new_expiry=wp_date('Y-m-d H:i:s',strtotime('+'.$days.' days',$base));
            $wpdb->update($table,['expires_at'=>$new_expiry,'status'=>'active'],['id'=>$bundle_id],['%s','%s'],['%d']);
            self::audit('bundle_expiry_extended','bundle',(string)$bundle_id,'Extended bundle expiry: '.($bundle->bundle_code ?: '#'.$bundle_id),['bundle_id'=>$bundle_id,'bundle_code'=>$bundle->bundle_code,'days'=>$days,'old_expiry'=>$bundle->expires_at,'new_expiry'=>$new_expiry]);
            $notice='extended';
        }
        wp_safe_redirect(add_query_arg(['soc_section'=>'bundles','bundle_notice'=>$notice,'view_bundle'=>$bundle_id],home_url('/'.self::CONSOLE_SLUG.'/')));
        exit;
    }

    private static function ensure_resolver_log_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'surface_operations_resolver_logs';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) return true;
        self::activate();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $exists === $table;
    }

    private static function array_find_value($data, $keys) {
        if (!is_array($data)) return '';
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key])) return sanitize_text_field((string) $data[$key]);
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = self::array_find_value($value, $keys);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    private static function resolver_normalize_identity($value) {
        $value = strtolower(trim((string) $value, " /@#\t\n\r\0\x0B"));
        return preg_replace('/[^a-z0-9]/', '', $value);
    }

    private static function resolver_find_partner_id($params, $data, $requested = '', $resolved = '') {
        $explicit = absint(self::array_find_value($data, ['partner_user_id','partner_id','owner_user_id','owner_id']));
        if ($explicit && get_user_by('id', $explicit)) return $explicit;

        $candidates = [
            self::array_find_value($data, ['base_sii','partner_sii','owner_sii','surface_name']),
            self::array_find_value($params, ['base_sii','partner_sii','owner_sii','surface_name']),
            $resolved,
            $requested,
        ];
        $normalized = array_values(array_filter(array_unique(array_map([__CLASS__, 'resolver_normalize_identity'], $candidates))));
        if (!$normalized) return 0;

        $best_id = 0;
        $best_length = 0;
        $partners = get_users(['meta_key'=>'surface_name','meta_compare'=>'EXISTS','number'=>-1,'fields'=>'all']);
        foreach ($partners as $partner) {
            $base = self::resolver_normalize_identity(get_user_meta($partner->ID, 'surface_name', true));
            if ($base === '') continue;
            foreach ($normalized as $candidate) {
                if ($candidate === $base || strpos($candidate, $base) === 0) {
                    if (strlen($base) > $best_length) {
                        $best_id = (int) $partner->ID;
                        $best_length = strlen($base);
                    }
                }
            }
        }
        if ($best_id) return $best_id;

        global $wpdb;
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate, " /@#\t\n\r\0\x0B");
            if ($candidate === '') continue;
            $post_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_sku','_surface_kxcode') AND meta_value=%s ORDER BY post_id DESC LIMIT 1",
                $candidate
            ));
            if ($post_id) {
                $post = get_post($post_id);
                if ($post) return self::surfacetooth_partner_id($post);
            }
        }
        return 0;
    }

    private static function resolver_find_phone($params, $data = [], $request = null) {
        $phone = self::array_find_value($params, ['phone','phone_number','customer_phone','participant_phone','receipt_phone','recipient_phone','mobile']);
        if ($phone === '') $phone = self::array_find_value($data, ['phone','phone_number','customer_phone','participant_phone','receipt_phone','recipient_phone','mobile']);
        if ($phone === '' && $request instanceof WP_REST_Request) {
            foreach (['x-surface-phone','x-customer-phone','x-phone-number','x-participant-phone'] as $header) {
                $value = sanitize_text_field((string) $request->get_header($header));
                if ($value !== '') { $phone = $value; break; }
            }
        }
        return $phone;
    }

    private static function resolver_enrich_record($row) {
        if (!$row) return $row;
        $params = [];
        $meta = json_decode((string) ($row->request_meta ?? ''), true);
        if (is_array($meta)) $params = is_array($meta['params'] ?? null) ? $meta['params'] : $meta;
        $data = json_decode((string) ($row->response_summary ?? ''), true);
        if (!is_array($data)) $data = [];

        $updates = [];
        if (empty($row->partner_user_id)) {
            $partner_id = self::resolver_find_partner_id($params, $data, $row->requested_sii ?? '', $row->resolved_sii ?? '');
            if ($partner_id) { $row->partner_user_id = $partner_id; $updates['partner_user_id'] = $partner_id; }
        }
        if (empty($row->phone)) {
            $phone = self::resolver_find_phone($params, $data);
            if ($phone !== '') { $row->phone = $phone; $updates['phone'] = $phone; }
        }
        if ($updates && !empty($row->id)) {
            global $wpdb;
            $wpdb->update($wpdb->prefix.'surface_operations_resolver_logs', $updates, ['id'=>(int)$row->id]);
        }
        return $row;
    }

    public static function capture_resolve_request($response, $handler, $request) {
        if (!($request instanceof WP_REST_Request)) return $response;
        $route = (string) $request->get_route();
        if (strpos($route, '/surface/v1/resolve') !== 0) return $response;
        if (!self::ensure_resolver_log_table()) return $response;

        $data = is_wp_error($response) ? ['error'=>$response->get_error_message()] : rest_ensure_response($response)->get_data();
        if (!is_array($data)) $data = ['response'=>$data];
        $params = $request->get_params();
        $requested = self::array_find_value($params, ['sii','kxcode','code','surface','identity','phone']);
        $resolved = self::array_find_value($data, ['resolved_as','resolved_sii','sii','identity']);
        $partner_id = self::resolver_find_partner_id($params, $data, $requested, $resolved);
        $channel = sanitize_key(self::array_find_value($params, ['channel','trigger','source','mode']));
        if (!$channel) $channel = 'type';
        $ok = !is_wp_error($response) && (bool) self::array_find_value($data, ['ok','success']);
        if (!$ok && !is_wp_error($response)) {
            $status_code = rest_ensure_response($response)->get_status();
            $ok = $status_code >= 200 && $status_code < 400 && empty($data['error']);
        }
        $status = $ok ? 'successful' : 'failed';
        $result = self::array_find_value($data, ['type','result','node_type','status']) ?: $status;
        $device = sanitize_text_field((string) $request->get_header('user-agent'));
        $phone = self::resolver_find_phone($params, $data, $request);
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        $ms = max(0, (microtime(true)-$start)*1000);
        $resolve_id = 'RSV-' . strtoupper(wp_generate_password(12, false, false));
        $tooth = self::array_find_value($data, ['surface_tooth','surfacetooth','node_title','title']);
        $campaign = self::array_find_value($data, ['campaign_id','campaign','campaign_name']);
        $wallet = self::array_find_value($data, ['wallet_reference','wallet_id','reference']);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'surface_operations_resolver_logs', [
            'resolve_id'=>$resolve_id,
            'requested_sii'=>$requested,
            'resolved_sii'=>$resolved,
            'partner_user_id'=>$partner_id,
            'channel'=>$channel,
            'result'=>$result,
            'device'=>$device,
            'phone'=>$phone,
            'request_meta'=>wp_json_encode(['route'=>$route,'method'=>$request->get_method(),'params'=>$params]),
            'response_summary'=>wp_json_encode($data),
            'processing_time_ms'=>$ms,
            'status'=>$status,
            'linked_surfacetooth'=>$tooth,
            'linked_campaign'=>$campaign,
            'linked_wallet'=>$wallet,
            'created_at'=>current_time('mysql'),
        ], ['%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s']);
        return $response;
    }

    private static function resolver_logs($search='') {
        global $wpdb;
        if (!self::ensure_resolver_log_table()) return [];
        $table=$wpdb->prefix.'surface_operations_resolver_logs';
        if ($search==='') $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 250");
        else {
            $like='%'.$wpdb->esc_like($search).'%';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE resolve_id LIKE %s OR requested_sii LIKE %s OR resolved_sii LIKE %s OR phone LIKE %s OR channel LIKE %s ORDER BY id DESC LIMIT 250",$like,$like,$like,$like,$like));
        }
        foreach ($rows as $row) self::resolver_enrich_record($row);
        return $rows;
    }

    private static function resolver_summary() {
        global $wpdb;
        $out=['total'=>0,'successful'=>0,'failed'=>0,'active_teeth'=>0,'top_partner'=>'—','top_channel'=>'—'];
        if (!self::ensure_resolver_log_table()) return $out;
        $table=$wpdb->prefix.'surface_operations_resolver_logs';
        $out['total']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $out['successful']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='successful'");
        $out['failed']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='failed'");
        $out['active_teeth']=(int)$wpdb->get_var("SELECT COUNT(DISTINCT NULLIF(linked_surfacetooth,'')) FROM {$table}");
        $pid=(int)$wpdb->get_var("SELECT partner_user_id FROM {$table} WHERE partner_user_id>0 GROUP BY partner_user_id ORDER BY COUNT(*) DESC LIMIT 1");
        if($pid){$out['top_partner']=self::resolver_partner_name($pid);}
        $channel=$wpdb->get_var("SELECT channel FROM {$table} WHERE channel<>'' GROUP BY channel ORDER BY COUNT(*) DESC LIMIT 1");
        if($channel)$out['top_channel']=ucfirst($channel);
        return $out;
    }

    private static function ensure_support_tables() {
        global $wpdb;
        $cases = $wpdb->prefix . 'surface_operations_support_cases';
        $notes = $wpdb->prefix . 'surface_operations_support_notes';
        if (self::table_exists($cases) && self::table_exists($notes)) return true;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$cases} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_code VARCHAR(40) NOT NULL,
            subject VARCHAR(190) NOT NULL,
            description LONGTEXT NULL,
            reporter_name VARCHAR(190) NULL,
            reporter_email VARCHAR(190) NULL,
            reporter_phone VARCHAR(80) NULL,
            partner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            related_surfacetooth_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            related_campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            related_wallet_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(40) NOT NULL DEFAULT 'open',
            assigned_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            PRIMARY KEY (id), UNIQUE KEY case_code (case_code), KEY status (status), KEY priority (priority), KEY assigned_user_id (assigned_user_id), KEY partner_user_id (partner_user_id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$notes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            note_text LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY case_id (case_id), KEY user_id (user_id)
        ) {$charset};");
        return self::table_exists($cases) && self::table_exists($notes);
    }

    private static function support_statuses() {
        return ['open'=>'Open','in_progress'=>'In Progress','waiting_partner'=>'Waiting on Partner','waiting_customer'=>'Waiting on Customer','resolved'=>'Resolved','closed'=>'Closed'];
    }

    public static function handle_support_actions() {
        if (!is_user_logged_in() || !self::is_staff() || !self::can_access('support')) return;
        if (empty($_POST['surface_operations_support_action'])) return;
        if (!isset($_POST['surface_operations_support_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['surface_operations_support_nonce'])), 'surface_operations_support')) return;
        if (!self::ensure_support_tables()) return;
        global $wpdb;
        $cases=$wpdb->prefix.'surface_operations_support_cases';
        $notes=$wpdb->prefix.'surface_operations_support_notes';
        $action=sanitize_key(wp_unslash($_POST['surface_operations_support_action']));
        $case_id=absint($_POST['case_id'] ?? 0);
        $now=current_time('mysql');
        $notice='updated';
        if ($action==='create') {
            $subject=sanitize_text_field(wp_unslash($_POST['case_subject'] ?? ''));
            if ($subject==='') return;
            $code='SC-'.gmdate('ymd').'-'.strtoupper(wp_generate_password(5,false,false));
            $wpdb->insert($cases,[
                'case_code'=>$code,'subject'=>$subject,'description'=>sanitize_textarea_field(wp_unslash($_POST['case_description'] ?? '')),
                'reporter_name'=>sanitize_text_field(wp_unslash($_POST['reporter_name'] ?? '')),'reporter_email'=>sanitize_email(wp_unslash($_POST['reporter_email'] ?? '')),
                'reporter_phone'=>sanitize_text_field(wp_unslash($_POST['reporter_phone'] ?? '')),'partner_user_id'=>absint($_POST['partner_user_id'] ?? 0),
                'priority'=>in_array(sanitize_key($_POST['case_priority'] ?? ''),['low','normal','high','urgent'],true)?sanitize_key($_POST['case_priority']):'normal',
                'status'=>'open','assigned_user_id'=>absint($_POST['assigned_user_id'] ?? 0),'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
            ]);
            $case_id=(int)$wpdb->insert_id; $notice='created';
            self::audit('support_case_created','support_case',(string)$case_id,'Created support case: '.$code,['subject'=>$subject]);
        } elseif ($case_id) {
            $case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$cases} WHERE id=%d",$case_id));
            if (!$case) return;
            if ($action==='assign') {
                $assigned=absint($_POST['assigned_user_id'] ?? 0);
                $wpdb->update($cases,['assigned_user_id'=>$assigned,'updated_at'=>$now],['id'=>$case_id]);
                self::audit('support_case_assigned','support_case',(string)$case_id,'Assigned support case: '.$case->case_code,['assigned_user_id'=>$assigned]);
                $notice='assigned';
            } elseif ($action==='status') {
                $status=sanitize_key(wp_unslash($_POST['case_status'] ?? ''));
                if (!array_key_exists($status,self::support_statuses())) return;
                $data=['status'=>$status,'updated_at'=>$now,'closed_at'=>$status==='closed'?$now:null];
                $wpdb->update($cases,$data,['id'=>$case_id]);
                self::audit('support_case_status_changed','support_case',(string)$case_id,'Changed support case status: '.$case->case_code,['from'=>$case->status,'to'=>$status]);
                $notice='status';
            } elseif ($action==='note') {
                $text=sanitize_textarea_field(wp_unslash($_POST['note_text'] ?? ''));
                if ($text==='') return;
                $wpdb->insert($notes,['case_id'=>$case_id,'user_id'=>get_current_user_id(),'note_text'=>$text,'created_at'=>$now]);
                $wpdb->update($cases,['updated_at'=>$now],['id'=>$case_id]);
                self::audit('support_note_added','support_case',(string)$case_id,'Added internal support note: '.$case->case_code,['note_id'=>(int)$wpdb->insert_id]);
                $notice='note';
            } elseif ($action==='close') {
                $wpdb->update($cases,['status'=>'closed','closed_at'=>$now,'updated_at'=>$now],['id'=>$case_id]);
                self::audit('support_case_closed','support_case',(string)$case_id,'Closed support case: '.$case->case_code,[]);
                $notice='closed';
            }
        }
        wp_safe_redirect(add_query_arg(['soc_section'=>'support','view_case'=>$case_id,'support_notice'=>$notice],home_url('/'.self::CONSOLE_SLUG.'/'))); exit;
    }

    private static function support_cases($search='') {
        global $wpdb; if(!self::ensure_support_tables()) return [];
        $table=$wpdb->prefix.'surface_operations_support_cases';
        if($search==='') return $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC,id DESC LIMIT 250");
        $like='%'.$wpdb->esc_like($search).'%';
        $ids=get_users(['search'=>'*'.$search.'*','search_columns'=>['display_name','user_email','user_login'],'fields'=>'ID']);
        $sql="SELECT * FROM {$table} WHERE case_code LIKE %s OR subject LIKE %s OR reporter_name LIKE %s OR reporter_email LIKE %s OR reporter_phone LIKE %s";
        $args=[$like,$like,$like,$like,$like];
        if($ids){$sql.=' OR partner_user_id IN ('.implode(',',array_map('absint',$ids)).')';}
        return $wpdb->get_results($wpdb->prepare($sql.' ORDER BY updated_at DESC,id DESC LIMIT 250',$args));
    }

    private static function support_summary() {
        global $wpdb; $out=array_fill_keys(array_keys(self::support_statuses()),0); if(!self::ensure_support_tables()) return $out;
        $rows=$wpdb->get_results("SELECT status,COUNT(*) total FROM {$wpdb->prefix}surface_operations_support_cases GROUP BY status");
        foreach($rows as $r) if(isset($out[$r->status])) $out[$r->status]=(int)$r->total; return $out;
    }

    private static function support_notes($case_id) {
        global $wpdb; if(!self::ensure_support_tables()) return [];
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}surface_operations_support_notes WHERE case_id=%d ORDER BY id ASC",$case_id));
    }

    private static function support_partner_label($user_id) {
        $user_id=absint($user_id); if(!$user_id) return 'Customer / unlinked';
        $business=trim((string)get_user_meta($user_id,'surface_store',true));
        if($business!=='') return $business;
        $sii=self::partner_sii($user_id); if($sii!=='') return '/'.ltrim($sii,'/');
        $u=get_user_by('id',$user_id); return $u?$u->display_name:'Partner #'.$user_id;
    }

    private static function resolver_partner_name($id) {
        $id = absint($id);
        if (!$id) return 'Not linked';

        $business_name = trim((string) get_user_meta($id, 'surface_store', true));
        if ($business_name !== '') return $business_name;

        $base_sii = self::partner_sii($id);
        if ($base_sii !== '') return '/' . ltrim($base_sii, '/');

        return 'Partner #' . $id;
    }


    private static function analytics_range() {
        $preset = sanitize_key(wp_unslash($_GET['analytics_range'] ?? '30'));
        $today = current_time('Y-m-d');
        if ($preset === 'today') return [$today . ' 00:00:00', $today . ' 23:59:59', 'Today'];
        if ($preset === '7') return [date('Y-m-d 00:00:00', strtotime($today . ' -6 days')), $today . ' 23:59:59', 'Last 7 days'];
        if ($preset === 'custom') {
            $from = sanitize_text_field(wp_unslash($_GET['analytics_from'] ?? ''));
            $to = sanitize_text_field(wp_unslash($_GET['analytics_to'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return [$from . ' 00:00:00', $to . ' 23:59:59', $from . ' to ' . $to];
            }
        }
        return [date('Y-m-d 00:00:00', strtotime($today . ' -29 days')), $today . ' 23:59:59', 'Last 30 days'];
    }

    private static function analytics_data() {
        global $wpdb;
        [$from,$to,$label] = self::analytics_range();
        $partners = self::surface_partners('');
        $teeth = self::surface_teeth('');
        $campaigns = self::campaigns('');
        $advocates = self::advocates('');
        $support = self::support_summary();
        $bundle = self::bundle_summary();
        $wallet = self::wallet_summary();
        $resolver = self::resolver_summary();

        $daily=[];
        for($i=0;$i<=(int)((strtotime(substr($to,0,10))-strtotime(substr($from,0,10)))/86400);$i++) {
            $d=date('Y-m-d',strtotime(substr($from,0,10)." +{$i} days"));
            $daily[$d]=['resolves'=>0,'partners'=>0,'support'=>0,'wallet'=>0];
        }
        $resolver_table=$wpdb->prefix.'surface_operations_resolver_logs';
        if(self::table_exists($resolver_table)) {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d,COUNT(*) c FROM {$resolver_table} WHERE created_at BETWEEN %s AND %s GROUP BY DATE(created_at)",$from,$to));
            foreach($rows as $r) if(isset($daily[$r->d])) $daily[$r->d]['resolves']=(int)$r->c;
        }
        $rows=$wpdb->get_results($wpdb->prepare("SELECT DATE(user_registered) d,COUNT(*) c FROM {$wpdb->users} WHERE user_registered BETWEEN %s AND %s GROUP BY DATE(user_registered)",$from,$to));
        foreach($rows as $r) if(isset($daily[$r->d])) $daily[$r->d]['partners']=(int)$r->c;
        $support_table=$wpdb->prefix.'surface_operations_support_cases';
        if(self::table_exists($support_table)) {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d,COUNT(*) c FROM {$support_table} WHERE created_at BETWEEN %s AND %s GROUP BY DATE(created_at)",$from,$to));
            foreach($rows as $r) if(isset($daily[$r->d])) $daily[$r->d]['support']=(int)$r->c;
        }
        $ledger=$wpdb->prefix.'surface_kx_wallet_ledger';
        if(self::table_exists($ledger)) {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d,COUNT(*) c FROM {$ledger} WHERE created_at BETWEEN %s AND %s GROUP BY DATE(created_at)",$from,$to));
            foreach($rows as $r) if(isset($daily[$r->d])) $daily[$r->d]['wallet']=(int)$r->c;
        }

        $top_partners=[];$top_surfaces=[];
        if(self::table_exists($resolver_table)) {
            $top_partners=$wpdb->get_results($wpdb->prepare("SELECT partner_user_id,COUNT(*) total FROM {$resolver_table} WHERE created_at BETWEEN %s AND %s AND partner_user_id>0 GROUP BY partner_user_id ORDER BY total DESC LIMIT 5",$from,$to));
            $top_surfaces=$wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(resolved_sii,''),requested_sii) surface,COUNT(*) total FROM {$resolver_table} WHERE created_at BETWEEN %s AND %s GROUP BY surface HAVING surface<>'' ORDER BY total DESC LIMIT 5",$from,$to));
        }
        $audit_table=$wpdb->prefix.'surface_operations_audit';
        $top_staff=[];
        if(self::table_exists($audit_table)) $top_staff=$wpdb->get_results($wpdb->prepare("SELECT user_id,COUNT(*) total FROM {$audit_table} WHERE created_at BETWEEN %s AND %s AND user_id>0 GROUP BY user_id ORDER BY total DESC LIMIT 5",$from,$to));

        return compact('from','to','label','partners','teeth','campaigns','advocates','support','bundle','wallet','resolver','daily','top_partners','top_surfaces','top_staff');
    }

    public static function handle_analytics_export() {
        if (empty($_GET['soc_analytics_export']) || !is_user_logged_in() || !self::is_staff()) return;
        if (!self::can_access('analytics', get_current_user_id())) return;
        $nonce=sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if(!wp_verify_nonce($nonce,'soc_analytics_export')) return;
        $data=self::analytics_data();
        self::audit('analytics_exported','analytics','summary','Exported analytics CSV',['range'=>$data['label']]);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=surface-operations-analytics-'.date('Y-m-d').'.csv');
        $out=fopen('php://output','w');
        fputcsv($out,['Surface Operations Analytics',$data['label']]);
        fputcsv($out,[]);
        fputcsv($out,['Metric','Value']);
        fputcsv($out,['Partners',count($data['partners'])]);
        fputcsv($out,['SurfaceTeeth',count($data['teeth'])]);
        fputcsv($out,['Campaigns',count($data['campaigns'])]);
        fputcsv($out,['Resolves',$data['resolver']['total']]);
        fputcsv($out,['Wallet Credits',$data['wallet']['credits']]);
        fputcsv($out,['Bundles', $data['bundle']['total']]);
        fputcsv($out,['Advocates',count($data['advocates'])]);
        fputcsv($out,['Support Cases',array_sum($data['support'])]);
        fputcsv($out,[]); fputcsv($out,['Date','Resolves','Partner Growth','Wallet Activity','Support Cases']);
        foreach($data['daily'] as $date=>$row) fputcsv($out,[$date,$row['resolves'],$row['partners'],$row['wallet'],$row['support']]);
        fclose($out); exit;
    }


    private static function registry_table_exists() {
        global $wpdb;
        return self::table_exists($wpdb->prefix . 'surface_identity_registry');
    }

    private static function registry_assignment_table() {
        global $wpdb;
        return $wpdb->prefix . 'surface_operations_registry_assignments';
    }

    /**
     * The Registry queue must remain readable even when the plugin file was
     * replaced without a deactivate/reactivate cycle. Create the lightweight
     * operations-only assignment table on demand before it is joined.
     */
    private static function ensure_registry_assignment_table() {
        global $wpdb;
        $table = self::registry_assignment_table();
        if (self::table_exists($table)) return true;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registry_id BIGINT UNSIGNED NOT NULL,
            assigned_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            assigned_team VARCHAR(80) NOT NULL DEFAULT 'Surface Identity',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            internal_notes LONGTEXT NULL,
            assigned_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            assigned_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY registry_id (registry_id),
            KEY assigned_user_id (assigned_user_id),
            KEY assigned_team (assigned_team),
            KEY priority (priority)
        ) {$charset};");

        return self::table_exists($table);
    }

    /**
     * Bring identities created before the dedicated Registry flow into the
     * canonical Registry table. Existing Registry rows are never overwritten.
     */
    private static function sync_legacy_partner_identities() {
        global $wpdb;
        if (!self::registry_table_exists()) return;

        $registry = $wpdb->prefix . 'surface_identity_registry';
        $users = get_users([
            'meta_key' => 'surface_name',
            'meta_compare' => 'EXISTS',
            'number' => 5000,
            'fields' => 'all',
        ]);

        foreach ($users as $user) {
            $raw = (string) get_user_meta($user->ID, 'surface_name', true);
            $identity = strtolower(trim($raw));
            $identity = ltrim($identity, "/#@\\");
            $identity = preg_replace('/[^a-z0-9]/', '', $identity);
            if ($identity === '') continue;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$registry} WHERE identity_normalized=%s LIMIT 1",
                $identity
            ));
            if ($exists) continue;

            $registered = !empty($user->user_registered) ? $user->user_registered : current_time('mysql');
            $email = sanitize_email((string) get_user_meta($user->ID, 'surface_email', true));
            if (!$email) $email = sanitize_email((string) $user->user_email);
            $classification = ctype_digit($identity) && strlen($identity) === 3
                ? 'premium_numeric_3'
                : (ctype_digit($identity) && strlen($identity) === 4 ? 'premium_numeric_4' : 'regular');

            $wpdb->insert($registry, [
                'identity_name' => $identity,
                'identity_normalized' => $identity,
                'status' => 'active',
                'owner_user_id' => (int) $user->ID,
                'owner_email' => $email,
                'order_id' => null,
                'product_id' => null,
                'registered_at' => $registered,
                'starts_at' => $registered,
                // Legacy ownership had no Registry expiry. Keep the required
                // column populated without presenting it as a temporary hold.
                'expires_at' => '2099-12-31 23:59:59',
                'term_years' => 1,
                'amount_paid' => null,
                'currency' => 'NGN',
                'created_source' => 'legacy_partner_profile',
                'classification' => $classification,
                'reservation_price' => null,
                'reservation_email' => $email,
                'reservation_token' => null,
                'reservation_expires_at' => null,
                'payment_reference' => null,
                'paystack_id' => null,
                'paid_at' => null,
                'last_updated' => current_time('mysql'),
            ]);
        }
    }

    private static function registry_records($search = '', $status = '') {
        global $wpdb;
        if (!self::registry_table_exists()) return [];
        self::sync_legacy_partner_identities();
        $table = $wpdb->prefix . 'surface_identity_registry';
        $has_assignments = self::ensure_registry_assignment_table();
        $assign = self::registry_assignment_table();
        $where = ['1=1']; $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(r.identity_normalized LIKE %s OR r.owner_email LIKE %s OR r.reservation_email LIKE %s OR r.payment_reference LIKE %s)';
            array_push($args,$like,$like,$like,$like);
        }
        if ($status !== '') { $where[]='r.status=%s'; $args[]=$status; }
        if ($has_assignments) {
            $sql = "SELECT r.*, a.assigned_user_id, a.assigned_team, a.priority, a.internal_notes, a.assigned_at
                    FROM {$table} r LEFT JOIN {$assign} a ON a.registry_id=r.id
                    WHERE ".implode(' AND ',$where)." ORDER BY r.last_updated DESC, r.id DESC LIMIT 300";
        } else {
            $sql = "SELECT r.*, 0 AS assigned_user_id, '' AS assigned_team, 'normal' AS priority, '' AS internal_notes, NULL AS assigned_at
                    FROM {$table} r WHERE ".implode(' AND ',$where)." ORDER BY r.last_updated DESC, r.id DESC LIMIT 300";
        }
        if ($args) $sql=$wpdb->prepare($sql,$args);
        return $wpdb->get_results($sql);
    }

    private static function registry_record($id) {
        global $wpdb;
        if (!self::registry_table_exists()) return null;
        self::sync_legacy_partner_identities();
        $table=$wpdb->prefix.'surface_identity_registry';
        if (self::ensure_registry_assignment_table()) {
            $assign=self::registry_assignment_table();
            return $wpdb->get_row($wpdb->prepare("SELECT r.*, a.assigned_user_id, a.assigned_team, a.priority, a.internal_notes, a.assigned_at FROM {$table} r LEFT JOIN {$assign} a ON a.registry_id=r.id WHERE r.id=%d",$id));
        }
        return $wpdb->get_row($wpdb->prepare("SELECT r.*, 0 AS assigned_user_id, '' AS assigned_team, 'normal' AS priority, '' AS internal_notes, NULL AS assigned_at FROM {$table} r WHERE r.id=%d",$id));
    }

    private static function registry_status_label($status) {
        if (class_exists('Surface_Identity_Registry')) return Surface_Identity_Registry::status_label($status);
        return ucwords(str_replace('_',' ',sanitize_key($status)));
    }

    private static function registry_class_label($classification) {
        if (class_exists('Surface_Identity_Registry')) return Surface_Identity_Registry::classification_label_from_key($classification);
        return ucwords(str_replace('_',' ',sanitize_key($classification)));
    }

    private static function registry_can_transition($from,$to) {
        return class_exists('Surface_Identity_Registry') && Surface_Identity_Registry::can_transition($from,$to);
    }

    public static function handle_registry_actions() {
        if (!is_user_logged_in() || !self::is_staff() || !self::can_access('registry',get_current_user_id())) return;
        if (empty($_POST['surface_operations_registry_action'])) return;
        check_admin_referer('surface_operations_registry','surface_operations_registry_nonce');
        global $wpdb;
        $action=sanitize_key(wp_unslash($_POST['surface_operations_registry_action']));
        $record_id=absint($_POST['registry_id']??0);
        $record=self::registry_record($record_id);
        if (!$record) return;
        $base=home_url('/'.self::CONSOLE_SLUG.'/');
        $redirect=add_query_arg(['soc_section'=>'registry','view_registry'=>$record_id],$base);

        if ($action==='assign') {
            $assigned_user=absint($_POST['assigned_user_id']??0);
            $priority=sanitize_key(wp_unslash($_POST['registry_priority']??'normal'));
            if(!in_array($priority,['low','normal','high','urgent'],true))$priority='normal';
            $notes=sanitize_textarea_field(wp_unslash($_POST['registry_notes']??''));
            $team='Surface Identity';
            $table=self::registry_assignment_table();
            $now=current_time('mysql');
            $wpdb->replace($table,[
                'registry_id'=>$record_id,'assigned_user_id'=>$assigned_user,'assigned_team'=>$team,
                'priority'=>$priority,'internal_notes'=>$notes,'assigned_by'=>get_current_user_id(),
                'assigned_at'=>$assigned_user?$now:null,'updated_at'=>$now
            ],['%d','%d','%s','%s','%s','%d','%s','%s']);
            self::audit('registry.assigned','registry',(string)$record_id,'Assigned /'.$record->identity_normalized.' for identity review',['assigned_user_id'=>$assigned_user,'priority'=>$priority]);
            if($assigned_user){
                self::notify_user($assigned_user,'registry_assignment','registry',$priority,'Surface Identity assigned','/'.$record->identity_normalized.' requires review.','registry',(string)$record_id,add_query_arg(['soc_section'=>'registry','view_registry'=>$record_id],$base));
            }
            $redirect=add_query_arg('registry_notice','assigned',$redirect);
        } elseif ($action==='transition') {
            if (!self::can_enforce(get_current_user_id())) {
                self::audit('registry.transition_denied','registry',(string)$record_id,'Blocked unauthorized Registry lifecycle action for /'.$record->identity_normalized);
                $redirect=add_query_arg('registry_notice','permission_denied',$redirect);
                wp_safe_redirect($redirect); exit;
            }
            $to=sanitize_key(wp_unslash($_POST['registry_status']??''));
            if (!class_exists('Surface_Identity_Registry')) {
                $redirect=add_query_arg('registry_notice','registry_unavailable',$redirect);
            } else {
                $result=Surface_Identity_Registry::transition_status($record_id,$to);
                if(is_wp_error($result)) $redirect=add_query_arg('registry_error',rawurlencode($result->get_error_message()),$redirect);
                else {
                    self::audit('registry.status_changed','registry',(string)$record_id,'Changed /'.$record->identity_normalized.' from '.self::registry_status_label($record->status).' to '.self::registry_status_label($to),['from'=>$record->status,'to'=>$to]);
                    $redirect=add_query_arg('registry_notice','status_updated',$redirect);
                }
            }
        }
        wp_safe_redirect($redirect); exit;
    }

    public static function handle_protected_sii_actions() {
        if (!is_user_logged_in() || !self::is_staff() || !self::can_access('registry', get_current_user_id())) return;
        if (empty($_POST['surface_operations_protected_sii_action'])) return;
        check_admin_referer('surface_operations_protected_sii','surface_operations_protected_sii_nonce');

        $base = home_url('/'.self::CONSOLE_SLUG.'/');
        $redirect = add_query_arg('soc_section','registry',$base);
        $action = sanitize_key(wp_unslash($_POST['surface_operations_protected_sii_action']));

        if (!class_exists('Surface_Identity_Registry')
            || !is_callable(['Surface_Identity_Registry','save_managed_protected_identity'])) {
            wp_safe_redirect(add_query_arg('protected_notice','registry_unavailable',$redirect));
            exit;
        }

        if ($action === 'save') {
            $identity = sanitize_text_field(wp_unslash($_POST['protected_identity'] ?? ''));
            $category = sanitize_key(wp_unslash($_POST['protected_category'] ?? 'brand'));
            $reason = sanitize_text_field(wp_unslash($_POST['protected_reason'] ?? ''));
            $notes = sanitize_textarea_field(wp_unslash($_POST['protected_notes'] ?? ''));
            $result = Surface_Identity_Registry::save_managed_protected_identity(
                $identity, $category, $reason, $notes, get_current_user_id()
            );
            if (is_wp_error($result)) {
                $redirect = add_query_arg('protected_error', rawurlencode($result->get_error_message()), $redirect);
            } else {
                $normalized = Surface_Identity_Registry::normalize_identity($identity);
                self::audit('registry.protected_sii_saved','protected_sii',(string)$result,'Protected /'.$normalized,['category'=>$category,'reason'=>$reason]);
                $redirect = add_query_arg('protected_notice','saved',$redirect);
            }
        } elseif (in_array($action, ['release','reactivate'], true)) {
            $id = absint($_POST['protected_id'] ?? 0);
            $status = $action === 'release' ? 'released' : 'active';
            $ok = Surface_Identity_Registry::set_managed_protected_identity_status($id,$status,get_current_user_id());
            if ($ok) {
                self::audit('registry.protected_sii_'.$action,'protected_sii',(string)$id,ucfirst($action).' protected SII entry');
                $redirect = add_query_arg('protected_notice',$action === 'release' ? 'released' : 'reactivated',$redirect);
            } else {
                $redirect = add_query_arg('protected_error',rawurlencode('Protected SII status could not be updated.'),$redirect);
            }
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private static function protected_sii_rows() {
        if (!class_exists('Surface_Identity_Registry')
            || !is_callable(['Surface_Identity_Registry','get_managed_protected_identities'])) return [];
        return Surface_Identity_Registry::get_managed_protected_identities(false);
    }

    public static function render_console() {
        if (!is_user_logged_in() || !self::is_staff()) {
            return '<script>window.location.href=' . wp_json_encode(home_url('/' . self::LOGIN_SLUG . '/')) . ';</script>';
        }

        $user = wp_get_current_user();
        $team = self::user_team($user->ID) ?: 'Operations';
        $level = self::level_label(self::user_level($user->ID));
        $section = sanitize_key(wp_unslash($_GET['soc_section'] ?? 'dashboard'));
        if (!self::can_access($section, $user->ID)) $section = 'dashboard';
        $registry_search=sanitize_text_field(wp_unslash($_GET['registry_search']??''));
        $registry_status=sanitize_key(wp_unslash($_GET['registry_status']??''));
        $registry_records=$section==='registry'?self::registry_records($registry_search,$registry_status):[];
        $registry_notice=sanitize_key(wp_unslash($_GET['registry_notice']??''));
        $registry_error=sanitize_text_field(wp_unslash($_GET['registry_error']??''));
        $view_registry_id=absint($_GET['view_registry']??0);
        $view_registry=($section==='registry'&&$view_registry_id)?self::registry_record($view_registry_id):null;
        if($view_registry) self::audit('registry.viewed','registry',(string)$view_registry_id,'Viewed Surface Identity /'.$view_registry->identity_normalized,['status'=>$view_registry->status]);

        $protected_sii_rows=$section==='registry'?self::protected_sii_rows():[];
        $protected_notice=sanitize_key(wp_unslash($_GET['protected_notice']??''));
        $protected_error=sanitize_text_field(wp_unslash($_GET['protected_error']??''));

        $task_counts = self::task_counts($user->ID, $team);
        $all_staff_ids = get_users(['role'=>self::ROLE,'fields'=>'ID']);
        $active_staff=0; $suspended_staff=0;
        foreach ($all_staff_ids as $staff_id) self::staff_status($staff_id)==='suspended' ? $suspended_staff++ : $active_staff++;
        $recent_tasks = self::recent_tasks($user->ID,$team,6);
        $recent_audit = self::recent_audit(6);
        $logout_url = wp_logout_url(home_url('/'.self::LOGIN_SLUG.'/'));
        $base_url = home_url('/'.self::CONSOLE_SLUG.'/');
        $notification_count=self::notification_count($user->ID);
        $notifications=$section==='notifications'?self::notifications_for_user($user->ID):[];
        $notification_notice=sanitize_key(wp_unslash($_GET['notification_notice']??''));
        $staff_list = get_users(['role'=>self::ROLE,'orderby'=>'display_name','order'=>'ASC']);
        $task_notice = sanitize_key(wp_unslash($_GET['task_notice'] ?? ''));
        $task_filters = [
            'status'   => sanitize_key(wp_unslash($_GET['task_status'] ?? 'all')),
            'priority' => sanitize_key(wp_unslash($_GET['task_priority'] ?? 'all')),
            'team'     => sanitize_text_field(wp_unslash($_GET['task_team'] ?? '')),
            'user_id'  => absint($_GET['task_user_id'] ?? 0),
        ];
        $audit_filters = [
            'staff_id'   => absint($_GET['audit_staff_id'] ?? 0),
            'object_type'=> sanitize_key(wp_unslash($_GET['audit_object_type'] ?? '')),
            'action_key' => sanitize_key(wp_unslash($_GET['audit_action_key'] ?? '')),
            'date_from'  => sanitize_text_field(wp_unslash($_GET['audit_date_from'] ?? '')),
            'date_to'    => sanitize_text_field(wp_unslash($_GET['audit_date_to'] ?? '')),
            'search'     => sanitize_text_field(wp_unslash($_GET['audit_search'] ?? '')),
        ];
        $audit_entries = $section === 'audit' ? self::filtered_audit($audit_filters, 250) : [];
        $audit_options = $section === 'audit' ? self::audit_filter_options() : ['actions'=>[], 'objects'=>[]];
        $partner_search = sanitize_text_field(wp_unslash($_GET['partner_search'] ?? ''));
        $partners = $section === 'partners' ? self::surface_partners($partner_search) : [];
        $partner_notice = sanitize_key(wp_unslash($_GET['partner_notice'] ?? ''));
        $view_partner_id = absint($_GET['view_partner'] ?? 0);
        $view_partner = $view_partner_id ? get_user_by('id', $view_partner_id) : false;
        if ($view_partner && !self::is_surface_partner($view_partner)) $view_partner = false;
        $surfacetooth_search = sanitize_text_field(wp_unslash($_GET['surfacetooth_search'] ?? ''));
        $surfaceteeth = $section === 'surfaceteeth' ? self::surface_teeth($surfacetooth_search) : [];
        $surfacetooth_notice = sanitize_key(wp_unslash($_GET['surfacetooth_notice'] ?? ''));
        $view_surfacetooth_id = absint($_GET['view_surfacetooth'] ?? 0);
        $view_surfacetooth = $view_surfacetooth_id ? get_post($view_surfacetooth_id) : null;
        if ($view_surfacetooth && !in_array($view_surfacetooth->post_type, ['product','surface_signal'], true)) $view_surfacetooth = null;
        $campaign_search = sanitize_text_field(wp_unslash($_GET['campaign_search'] ?? ''));
        $campaigns = $section === 'campaigns' ? self::campaigns($campaign_search) : [];
        $campaign_notice = sanitize_key(wp_unslash($_GET['campaign_notice'] ?? ''));
        $question_search=sanitize_text_field(wp_unslash($_GET['question_search']??''));
        $question_rows=$section==='questionbank'?self::question_bank_rows($question_search):[];
        $question_notice=sanitize_key(wp_unslash($_GET['question_notice']??''));
        $edit_question_id=absint($_GET['edit_question']??0);
        $edit_question=null;
        if($section==='questionbank' && $edit_question_id && self::ensure_question_bank_table()){global $wpdb;$edit_question=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}surface_question_bank WHERE id=%d",$edit_question_id));}
        $view_campaign_id = absint($_GET['view_campaign'] ?? 0);
        $view_campaign = false;
        if ($view_campaign_id) {
            global $wpdb;
            $campaign_table = $wpdb->prefix . 'surface_campaigns';
            if (self::table_exists($campaign_table)) $view_campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$campaign_table} WHERE id=%d", $view_campaign_id));
        }
        $advocate_search=sanitize_text_field(wp_unslash($_GET['advocate_search']??''));
        $advocates=$section==='advocates'?self::advocates($advocate_search):[];
        $advocate_totals=$section==='advocates'?self::advocate_summary():['total'=>0,'active'=>0,'pending'=>0,'suspended'=>0,'introduced'=>0,'earnings'=>0];
        $advocate_notice=sanitize_key(wp_unslash($_GET['advocate_notice']??''));
        $view_advocate_id=absint($_GET['view_advocate']??0); $view_advocate=$view_advocate_id?get_user_by('id',$view_advocate_id):false;
        if($view_advocate && get_user_meta($view_advocate_id,'surface_is_advocate',true)!=='yes') $view_advocate=false;
        $view_advocate_financials=$view_advocate?self::advocate_financials($view_advocate_id):['earnings'=>0,'balance'=>0,'activity'=>[]];
        if($view_advocate && $section==='advocates') self::audit('advocate_viewed','advocate',(string)$view_advocate_id,'Viewed advocate: '.$view_advocate->display_name,['sii'=>self::advocate_sii($view_advocate_id)]);
        $bundle_search = sanitize_text_field(wp_unslash($_GET['bundle_search'] ?? ''));
        $bundles = $section === 'bundles' ? self::bundles($bundle_search) : [];
        $bundle_totals = $section === 'bundles' ? self::bundle_summary() : ['total'=>0,'active'=>0,'expired'=>0,'used'=>0,'remaining'=>0];
        $bundle_notice = sanitize_key(wp_unslash($_GET['bundle_notice'] ?? ''));
        $view_bundle_id = absint($_GET['view_bundle'] ?? 0);
        $view_bundle = false;
        $bundle_history = [];
        if ($view_bundle_id) {
            global $wpdb;
            $bundle_table = self::bundle_table();
            if (self::table_exists($bundle_table)) {
                $view_bundle = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$bundle_table} WHERE id=%d", $view_bundle_id));
                if ($view_bundle && $section === 'bundles') {
                    self::audit('bundle_viewed','bundle',(string)$view_bundle_id,'Viewed bundle: '.($view_bundle->bundle_code ?: '#'.$view_bundle_id),['bundle_id'=>$view_bundle_id,'bundle_code'=>$view_bundle->bundle_code]);
                    $bundle_history = self::bundle_audit_history($view_bundle_id);
                }
            }
        }
        $wallet_search = sanitize_text_field(wp_unslash($_GET['wallet_search'] ?? ''));
        $wallet_transactions = $section === 'wallet' ? self::wallet_transactions($wallet_search) : [];
        $wallet_totals = $section === 'wallet' ? self::wallet_summary() : ['balance'=>0,'credits'=>0,'debits'=>0,'pending'=>0,'failed'=>0];
        $wallet_reviews = $section === 'wallet' ? self::wallet_reviews() : [];
        $wallet_notice = sanitize_key(wp_unslash($_GET['wallet_notice'] ?? ''));
        $view_wallet_id = absint($_GET['view_wallet'] ?? 0);
        $view_wallet = false;
        if ($view_wallet_id) {
            global $wpdb;
            $wallet_ledger = $wpdb->prefix . 'surface_kx_wallet_ledger';
            if (self::table_exists($wallet_ledger)) {
                $view_wallet = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wallet_ledger} WHERE id=%d", $view_wallet_id));
                if ($view_wallet) {
                    self::audit(
                        'wallet_viewed',
                        'wallet',
                        (string) $view_wallet_id,
                        'Viewed wallet transaction: ' . ($view_wallet->reference ?: 'Ledger #' . $view_wallet_id),
                        [
                            'ledger_id' => $view_wallet_id,
                            'phone'     => $view_wallet->phone_number,
                            'source'    => $view_wallet->source,
                            'amount'    => $view_wallet->amount,
                        ]
                    );
                }
            }
        }

        $resolver_search = sanitize_text_field(wp_unslash($_GET['resolver_search'] ?? ''));
        $resolver_logs = $section === 'resolver' ? self::resolver_logs($resolver_search) : [];
        $resolver_totals = $section === 'resolver' ? self::resolver_summary() : ['total'=>0,'successful'=>0,'failed'=>0,'active_teeth'=>0,'top_partner'=>'—','top_channel'=>'—'];
        $view_resolve_id = absint($_GET['view_resolve'] ?? 0);
        $view_resolve = false;
        if ($view_resolve_id && self::ensure_resolver_log_table()) {
            global $wpdb;
            $view_resolve = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}surface_operations_resolver_logs WHERE id=%d", $view_resolve_id));
            $view_resolve = self::resolver_enrich_record($view_resolve);
            if ($view_resolve && $section === 'resolver') self::audit('resolve_viewed','resolver',(string)$view_resolve->resolve_id,'Viewed resolver record: '.$view_resolve->resolve_id,['requested_sii'=>$view_resolve->requested_sii,'status'=>$view_resolve->status]);
        }

        $analytics = $section === 'analytics' ? self::analytics_data() : null;

        $support_search = sanitize_text_field(wp_unslash($_GET['support_search'] ?? ''));
        $support_cases = $section === 'support' ? self::support_cases($support_search) : [];
        $support_totals = $section === 'support' ? self::support_summary() : array_fill_keys(array_keys(self::support_statuses()),0);
        $support_notice = sanitize_key(wp_unslash($_GET['support_notice'] ?? ''));
        $view_case_id = absint($_GET['view_case'] ?? 0);
        $view_case = false; $view_case_notes=[];
        if ($view_case_id && self::ensure_support_tables()) {
            global $wpdb;
            $view_case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}surface_operations_support_cases WHERE id=%d",$view_case_id));
            if($view_case && $section==='support') {
                $view_case_notes=self::support_notes($view_case_id);
                self::audit('support_case_viewed','support_case',(string)$view_case_id,'Viewed support case: '.$view_case->case_code,['status'=>$view_case->status]);
            }
        }

        $escalation_notice = sanitize_key(wp_unslash($_GET['escalation_notice'] ?? ''));
        $view_escalation_id = absint($_GET['view_escalation'] ?? 0);
        $view_escalation = false; $view_escalation_events = [];
        if ($section === 'escalations' && self::ensure_escalation_tables()) {
            global $wpdb;
            if ($view_escalation_id) {
                $candidate = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}surface_operations_escalations WHERE id=%d",$view_escalation_id));
                if ($candidate && self::can_view_escalation($candidate,$user->ID)) {
                    $view_escalation=$candidate;
                    $view_escalation_events=self::escalation_events($view_escalation_id);
                    self::audit('escalation.viewed','escalation',(string)$view_escalation_id,'Viewed escalation '.$candidate->case_code,['status'=>$candidate->status,'current_level'=>$candidate->current_level]);
                    self::escalation_event($view_escalation_id,'viewed','',$candidate->current_level,$candidate->current_level);
                }
            }
        }

        ob_start(); ?>
        <style>
        body{background:#f4f6f8!important}.soc-app{min-height:100vh;display:grid;grid-template-columns:250px 1fr;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827}.soc-sidebar{background:#111827;color:#fff;padding:26px 18px;position:sticky;top:0;height:100vh;box-sizing:border-box;display:flex;flex-direction:column;overflow:hidden}.soc-brand{font-size:19px;font-weight:800;padding:0 10px 24px}.soc-brand small{display:block;color:#9ca3af;font-size:11px;font-weight:600;margin-top:4px}.soc-nav{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding-right:4px}.soc-nav a{display:block;color:#cbd5e1;text-decoration:none;padding:11px 12px;border-radius:10px;margin:3px 0;font-size:14px}.soc-nav a:hover,.soc-nav a.active{background:#1f2937;color:#fff}.soc-sidebar-foot{position:static;flex:0 0 auto;border-top:1px solid #374151;padding-top:16px;margin-top:14px}.soc-sidebar-foot strong,.soc-sidebar-foot span{display:block}.soc-sidebar-foot span{font-size:12px;color:#9ca3af;margin:3px 0 10px}.soc-sidebar-foot a{color:#cbd5e1;font-size:13px}.soc-main{padding:30px}.soc-top{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:25px}.soc-top h1{font-size:29px;margin:0 0 4px}.soc-top p{margin:0;color:#6b7280}.soc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.soc-stat,.soc-panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px}.soc-stat{padding:20px}.soc-stat span{display:block;color:#6b7280;font-size:13px}.soc-stat strong{display:block;font-size:30px;margin-top:7px}.soc-columns{display:grid;grid-template-columns:1.25fr .9fr;gap:18px;margin-top:18px}.soc-panel{padding:21px}.soc-panel h2{font-size:17px;margin:0 0 16px}.soc-row{display:flex;justify-content:space-between;gap:14px;padding:13px 0;border-top:1px solid #f0f1f3}.soc-row:first-of-type{border-top:0}.soc-row-title{font-weight:700;font-size:14px}.soc-meta{font-size:12px;color:#6b7280;margin-top:4px}.soc-badge{height:max-content;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:750;background:#f3f4f6}.soc-empty{color:#6b7280;font-size:14px;padding:8px 0}.soc-task-grid{display:grid;grid-template-columns:340px 1fr;gap:18px}.soc-form label{display:block;font-size:12px;font-weight:700;margin:0 0 6px}.soc-form input,.soc-form select,.soc-form textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d1d5db;border-radius:10px;margin:0 0 13px;background:#fff}.soc-form textarea{min-height:90px;resize:vertical}.soc-btn{border:0;border-radius:10px;background:#111827;color:#fff;padding:10px 14px;font-weight:700;cursor:pointer}.soc-btn-light{background:#eef0f3;color:#111827}.soc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.soc-task{border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:12px}.soc-task-head{display:flex;justify-content:space-between;gap:12px}.soc-task h3{font-size:15px;margin:0}.soc-task p{font-size:13px;color:#4b5563}.soc-inline{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.soc-inline select,.soc-inline input{margin:0}.soc-alert{padding:12px 14px;border-radius:10px;background:#ecfdf5;color:#065f46;margin-bottom:16px}.soc-comments{margin-top:13px;padding-top:12px;border-top:1px solid #eef0f2}.soc-comment{font-size:12px;padding:7px 0}.soc-comment b{display:block}.soc-overdue{color:#b91c1c;font-weight:700}.soc-filters{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px}.soc-filters label{font-size:12px;font-weight:700}.soc-filters select{display:block;margin-top:5px;padding:8px;border:1px solid #d1d5db;border-radius:8px;background:#fff}.soc-filters input{display:block;margin-top:5px;padding:8px;border:1px solid #d1d5db;border-radius:8px;background:#fff}.soc-audit-item{border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:12px}.soc-audit-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.soc-audit-summary{font-weight:750;font-size:14px}.soc-audit-details{margin-top:12px;padding-top:12px;border-top:1px solid #eef0f2;font-size:12px;color:#4b5563}.soc-audit-details code{display:block;white-space:pre-wrap;word-break:break-word;background:#f8fafc;padding:10px;border-radius:8px;margin-top:8px}.soc-audit-count{font-size:13px;color:#6b7280;margin-bottom:12px}.soc-table-wrap{overflow-x:auto}.soc-table{width:100%;border-collapse:collapse}.soc-table th,.soc-table td{text-align:left;padding:13px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;vertical-align:middle}.soc-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280}.soc-partner-profile{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.soc-profile-field{padding:14px;background:#f8fafc;border-radius:12px}.soc-profile-field span{display:block;color:#6b7280;font-size:11px;text-transform:uppercase}.soc-profile-field strong{display:block;margin-top:5px;font-size:14px}.soc-timeline{border-left:2px solid #e5e7eb;margin-left:8px;padding-left:18px}.soc-timeline-item{margin:0 0 16px}.soc-note{background:#f8fafc;border-radius:12px;padding:13px;margin-bottom:10px}@media(max-width:900px){.soc-app{grid-template-columns:1fr}.soc-sidebar{height:auto;position:relative;overflow:visible}.soc-nav{overflow:visible;padding-right:0}.soc-sidebar-foot{position:static;margin-top:20px}.soc-main{padding:20px}.soc-grid{grid-template-columns:repeat(2,1fr)}.soc-columns,.soc-task-grid{grid-template-columns:1fr}}@media(max-width:520px){.soc-grid{grid-template-columns:1fr}}
        </style>
        <div class="soc-app"><aside class="soc-sidebar"><div class="soc-brand">Surface Operations<small>Operating the Surface Internet</small></div><nav class="soc-nav">
        <?php $nav=['dashboard'=>'Dashboard','notifications'=>'Notifications','tasks'=>'Tasks','registry'=>'Surface Identity','partners'=>'Partners','surfaceteeth'=>'SurfaceTeeth™','advocates'=>'Advocates','campaigns'=>'Campaigns','questionbank'=>'Question Bank','wallet'=>'Wallet','bundles'=>'Bundles','resolver'=>'Resolver','support'=>'Support','escalations'=>'Escalations','analytics'=>'Analytics','reports'=>'Reports','teams'=>'Teams','staff'=>'Staff','audit'=>'Audit']; foreach($nav as $key=>$label){if(!self::can_access($key,$user->ID))continue;$url=add_query_arg('soc_section',$key,$base_url);$nav_label=$label.(($key==='notifications'&&$notification_count)?' ('.$notification_count.')':'');echo '<a class="'.($key===$section?'active':'').'" href="'.esc_url($url).'">'.esc_html($nav_label).'</a>';} ?>
        </nav><div class="soc-sidebar-foot"><strong><?php echo esc_html($user->display_name); ?></strong><span><?php echo esc_html($level.' · '.$team); ?></span><a href="<?php echo esc_url($logout_url); ?>">Sign out</a></div></aside><main class="soc-main">
        <?php if($section==='notifications'): ?>
            <div class="soc-top"><div><h1>Notifications</h1><p>Operational updates requiring your attention.</p></div><?php if($notification_count): ?><form method="post"><?php wp_nonce_field('surface_operations_notification','surface_operations_notification_nonce'); ?><input type="hidden" name="surface_operations_notification_action" value="mark_all_read"><button class="soc-btn soc-btn-light">Mark All Read</button></form><?php endif; ?></div>
            <?php if($notification_notice): ?><div class="soc-alert">Notification status updated.</div><?php endif; ?>
            <section class="soc-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:18px"><div class="soc-stat"><span>Unread</span><strong><?php echo esc_html($notification_count); ?></strong></div><div class="soc-stat"><span>Total</span><strong><?php echo esc_html(count($notifications)); ?></strong></div></section>
            <section class="soc-panel"><h2>Notification Centre</h2><?php if(!$notifications): ?><div class="soc-empty">No notifications yet.</div><?php endif; ?><?php foreach($notifications as $notification): ?><article class="soc-task" style="<?php echo !$notification->is_read?'border-left:4px solid #111827;':''; ?>"><div class="soc-task-head"><div><h3><?php echo esc_html($notification->title); ?></h3><div class="soc-meta"><?php echo esc_html(ucfirst($notification->module).' · '.ucfirst($notification->priority).' · '.mysql2date('M j, Y g:i a',$notification->created_at)); ?></div></div><span class="soc-badge"><?php echo $notification->is_read?'Read':'Unread'; ?></span></div><p><?php echo esc_html($notification->summary); ?></p><div class="soc-actions"><form method="post"><?php wp_nonce_field('surface_operations_notification','surface_operations_notification_nonce'); ?><input type="hidden" name="notification_id" value="<?php echo esc_attr($notification->id); ?>"><input type="hidden" name="surface_operations_notification_action" value="<?php echo $notification->target_url?'open':'read'; ?>"><button class="soc-btn <?php echo $notification->is_read?'soc-btn-light':''; ?>"><?php echo $notification->target_url?'Open':'Mark as Read'; ?></button></form><?php if(!$notification->is_read&&$notification->target_url): ?><form method="post"><?php wp_nonce_field('surface_operations_notification','surface_operations_notification_nonce'); ?><input type="hidden" name="notification_id" value="<?php echo esc_attr($notification->id); ?>"><input type="hidden" name="surface_operations_notification_action" value="read"><button class="soc-btn soc-btn-light">Mark as Read</button></form><?php endif; ?></div></article><?php endforeach; ?></section>
        <?php elseif($section==='tasks'): ?>
            <div class="soc-top"><div><h1>Tasks</h1><p>Assign, claim and complete operational work.</p></div></div>
            <?php if($task_notice): ?><div class="soc-alert">Task action completed.</div><?php endif; ?>
            <section class="soc-grid" style="margin-bottom:18px"><div class="soc-stat"><span>My Open Tasks</span><strong><?php echo esc_html($task_counts['mine']); ?></strong></div><div class="soc-stat"><span>Team Queue</span><strong><?php echo esc_html($task_counts['team']); ?></strong></div><div class="soc-stat"><span>Due Today</span><strong><?php echo esc_html($task_counts['due_today']); ?></strong></div><div class="soc-stat"><span>Overdue</span><strong><?php echo esc_html($task_counts['overdue']); ?></strong></div></section>
            <section class="soc-task-grid">
                <?php if(self::can_manage_tasks($user->ID)): ?><div class="soc-panel"><h2>Assign Task</h2><form class="soc-form" method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="create"><label>Task</label><input name="task_title" required><label>Description</label><textarea name="task_description"></textarea><label>Module</label><select name="task_module"><?php foreach(['general'=>'General','registry'=>'Surface Identity','partners'=>'Partners','surfaceteeth'=>'SurfaceTeeth','advocacy'=>'Advocacy','campaigns'=>'Campaigns','wallet'=>'Wallet','bundles'=>'Bundles','support'=>'Support'] as $k=>$v)echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>'; ?></select><label>Priority</label><select name="task_priority"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select><label>Assign to staff</label><select name="task_user_id"><option value="0">Team queue</option><?php foreach($staff_list as $member){if(self::staff_status($member->ID)==='suspended')continue;echo '<option value="'.esc_attr($member->ID).'">'.esc_html($member->display_name.' · '.self::user_team($member->ID)).'</option>';} ?></select><label>Team</label><select name="task_team"><option value="">Select team</option><?php foreach(self::teams() as $t)echo '<option value="'.esc_attr($t).'">'.esc_html($t).'</option>'; ?></select><label>Due date</label><input type="datetime-local" name="task_due_at"><button class="soc-btn" type="submit">Assign Task</button></form></div><?php endif; ?>
                <div class="soc-panel"><h2><?php echo self::can_manage_tasks($user->ID)?'Operational Tasks':'My Tasks'; ?></h2><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="tasks"><label>Status<select name="task_status"><option value="all">All</option><option value="open" <?php selected($task_filters['status'],'open'); ?>>Open</option><option value="in_progress" <?php selected($task_filters['status'],'in_progress'); ?>>In Progress</option><option value="completed" <?php selected($task_filters['status'],'completed'); ?>>Completed</option></select></label><label>Priority<select name="task_priority"><option value="all">All</option><?php foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($task_filters['priority'],$k,false).'>'.esc_html($v).'</option>'; ?></select></label><?php if(self::can_manage_tasks($user->ID)): ?><label>Team<select name="task_team"><option value="">All teams</option><?php foreach(self::teams() as $t)echo '<option value="'.esc_attr($t).'" '.selected($task_filters['team'],$t,false).'>'.esc_html($t).'</option>'; ?></select></label><label>Staff<select name="task_user_id"><option value="0">All staff</option><?php foreach($staff_list as $member)echo '<option value="'.esc_attr($member->ID).'" '.selected($task_filters['user_id'],$member->ID,false).'>'.esc_html($member->display_name).'</option>'; ?></select></label><?php endif; ?><button class="soc-btn soc-btn-light" type="submit">Filter</button><a class="soc-btn soc-btn-light" style="text-decoration:none" href="<?php echo esc_url(add_query_arg('soc_section','tasks',$base_url)); ?>">Reset</a></form><?php $tasks=self::visible_tasks($user->ID,$team,self::can_manage_tasks($user->ID),$task_filters); if(!$tasks): ?><div class="soc-empty">No tasks found.</div><?php endif; ?><?php foreach($tasks as $task): $comments=self::task_comments($task->id); ?><article class="soc-task"><div class="soc-task-head"><div><h3><?php echo esc_html($task->title); ?></h3><div class="soc-meta"><?php echo esc_html(ucfirst($task->module).' · '.ucwords(str_replace('_',' ',$task->status)).' · '.ucfirst($task->priority)); ?><?php if($task->due_at): ?> · <span class="<?php echo ($task->status!=='completed' && strtotime($task->due_at)<current_time('timestamp'))?'soc-overdue':''; ?>">Due <?php echo esc_html(mysql2date('M j, g:i a',$task->due_at)); ?></span><?php endif; ?></div></div><span class="soc-badge"><?php echo esc_html($task->assigned_user_id?self::staff_name($task->assigned_user_id):($task->assigned_team?:'Unassigned')); ?></span></div><?php if($task->description): ?><p><?php echo nl2br(esc_html($task->description)); ?></p><?php endif; ?><div class="soc-actions"><?php if(self::can_manage_tasks($user->ID)): ?><form class="soc-inline" method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="reassign"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><select name="task_user_id"><option value="0">Team queue</option><?php foreach($staff_list as $member){if(self::staff_status($member->ID)==='suspended')continue;echo '<option value="'.esc_attr($member->ID).'" '.selected((int)$task->assigned_user_id,$member->ID,false).'>'.esc_html($member->display_name).'</option>';} ?></select><select name="task_team"><option value="">No team</option><?php foreach(self::teams() as $t)echo '<option value="'.esc_attr($t).'" '.selected((string)$task->assigned_team,$t,false).'>'.esc_html($t).'</option>'; ?></select><button class="soc-btn soc-btn-light" type="submit">Reassign</button></form><?php endif; ?><?php if(!$task->assigned_user_id && $task->assigned_team===$team): ?><form method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="claim"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><button class="soc-btn" type="submit">Claim</button></form><?php endif; ?><form class="soc-inline" method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="status"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><select name="task_status"><option value="open" <?php selected($task->status,'open'); ?>>Open</option><option value="in_progress" <?php selected($task->status,'in_progress'); ?>>In Progress</option><option value="completed" <?php selected($task->status,'completed'); ?>>Completed</option></select><button class="soc-btn soc-btn-light" type="submit">Update</button></form></div><div class="soc-comments"><?php foreach($comments as $comment): ?><div class="soc-comment"><b><?php echo esc_html(self::staff_name($comment->user_id)); ?></b><?php echo esc_html($comment->comment_text); ?> <span class="soc-meta"><?php echo esc_html(mysql2date('M j, g:i a',$comment->created_at)); ?></span></div><?php endforeach; ?><form class="soc-inline" method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="comment"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><input type="text" name="task_comment" placeholder="Add internal comment" required><button class="soc-btn soc-btn-light" type="submit">Comment</button></form></div></article><?php endforeach; ?></div>
            </section>

        <?php elseif($section==='registry'): ?>
            <?php
            $registry_counts=['total'=>0,'pending'=>0,'active'=>0,'attention'=>0];
            foreach(self::registry_records('','') as $rr){$registry_counts['total']++; if($rr->status==='active')$registry_counts['active']++; if(in_array($rr->status,['reserved_pending_payment','payment_confirmed','pending_otp','pending_verification'],true))$registry_counts['pending']++; if(in_array($rr->status,['pending_verification','suspended'],true))$registry_counts['attention']++;}
            ?>
            <div class="soc-top"><div><h1>Surface Identity</h1><p>Assign and process Surface Internet Identity cases from the Registry.</p></div></div>
            <?php if($registry_notice==='assigned'): ?><div class="soc-alert">Registry case assigned successfully.</div><?php elseif($registry_notice==='status_updated'): ?><div class="soc-alert">Registry lifecycle status updated.</div><?php elseif($registry_notice==='permission_denied'): ?><div class="soc-alert">Only the Operations Director can change a Registry lifecycle status. Other staff should review, assign, add notes, or escalate the case.</div><?php endif; ?>
            <?php if($registry_error): ?><div class="soc-alert" style="background:#fef2f2;color:#991b1b"><?php echo esc_html($registry_error); ?></div><?php endif; ?>
            <?php if(!self::registry_table_exists()): ?><section class="soc-panel"><div class="soc-empty">The Surface Identity Registry plugin or table is not available.</div></section>
            <?php else: ?>
            <section class="soc-grid" style="margin-bottom:18px"><div class="soc-stat"><span>Total Identities</span><strong><?php echo esc_html($registry_counts['total']); ?></strong></div><div class="soc-stat"><span>In Progress</span><strong><?php echo esc_html($registry_counts['pending']); ?></strong></div><div class="soc-stat"><span>Active</span><strong><?php echo esc_html($registry_counts['active']); ?></strong></div><div class="soc-stat"><span>Needs Attention</span><strong><?php echo esc_html($registry_counts['attention']); ?></strong></div></section>

            <?php if($protected_notice==='saved'): ?><div class="soc-alert">Protected SII saved and Registry enforcement updated.</div><?php elseif($protected_notice==='released'): ?><div class="soc-alert">Protected SII released. It will no longer be classified as protected by the managed list.</div><?php elseif($protected_notice==='reactivated'): ?><div class="soc-alert">Protected SII reactivated.</div><?php elseif($protected_notice==='registry_unavailable'): ?><div class="soc-alert" style="background:#fef2f2;color:#991b1b">Install the Protected SII-enabled Registry plugin before managing protected identities.</div><?php endif; ?>
            <?php if($protected_error): ?><div class="soc-alert" style="background:#fef2f2;color:#991b1b"><?php echo esc_html($protected_error); ?></div><?php endif; ?>

            <section class="soc-columns" style="margin-top:0;margin-bottom:18px;align-items:start">
                <div class="soc-panel">
                    <h2 style="margin-bottom:5px">Protect a Surface Internet Identity</h2>
                    <p class="soc-meta" style="margin-top:0">Add brands, institutions, government identities or other names that must require ownership verification before activation.</p>
                    <form class="soc-form" method="post">
                        <?php wp_nonce_field('surface_operations_protected_sii','surface_operations_protected_sii_nonce'); ?>
                        <input type="hidden" name="surface_operations_protected_sii_action" value="save">
                        <label>Surface Internet Identity<input name="protected_identity" required placeholder="google"></label>
                        <label>Category<select name="protected_category"><option value="brand">Brand</option><option value="financial">Financial Institution</option><option value="government">Government / Public Institution</option><option value="media">Media</option><option value="surface">Surface Internet</option><option value="other">Other</option></select></label>
                        <label>Reason<input name="protected_reason" placeholder="Ownership verification required"></label>
                        <label>Internal notes<textarea rows="4" name="protected_notes" placeholder="Optional staff note"></textarea></label>
                        <button class="soc-btn" type="submit">Protect SII</button>
                    </form>
                </div>
                <div class="soc-panel">
                    <h2 style="margin-bottom:5px">Managed Protected SIIs</h2>
                    <p class="soc-meta" style="margin-top:0">Protection is independent of SII class. Active entries here can lock either a Regular or Premium Numeric identity as Protected; its underlying class and price remain unchanged.</p>
                    <div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>SII</th><th>Category</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody>
                    <?php if(!$protected_sii_rows): ?><tr><td colspan="6" class="soc-empty">No staff-managed protected SIIs yet. Built-in protected names such as Google remain protected by the Registry.</td></tr><?php endif; ?>
                    <?php foreach($protected_sii_rows as $protected): $pc=class_exists('Surface_Identity_Registry')?Surface_Identity_Registry::classify_identity($protected->identity_normalized):[]; ?><tr><td><strong>/<?php echo esc_html($protected->identity_normalized); ?></strong></td><td><?php echo esc_html($pc['classification_label'] ?? 'Regular SII'); ?></td><td><?php echo esc_html(ucwords(str_replace('_',' ',$protected->category))); ?></td><td><?php echo esc_html($protected->reason ?: 'Ownership verification required'); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($protected->status)); ?></span></td><td><form method="post"><?php wp_nonce_field('surface_operations_protected_sii','surface_operations_protected_sii_nonce'); ?><input type="hidden" name="surface_operations_protected_sii_action" value="<?php echo esc_attr($protected->status==='active'?'release':'reactivate'); ?>"><input type="hidden" name="protected_id" value="<?php echo esc_attr($protected->id); ?>"><button class="soc-btn soc-btn-light" type="submit"><?php echo esc_html($protected->status==='active'?'Release':'Reactivate'); ?></button></form></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div>
            </section>
            <?php if($view_registry):
                $owner=$view_registry->owner_user_id?get_user_by('id',(int)$view_registry->owner_user_id):false;
                $owner_phone=$owner?(string)get_user_meta($owner->ID,'surface_phone',true):'';
                $assigned=$view_registry->assigned_user_id?get_user_by('id',(int)$view_registry->assigned_user_id):false;
                $display_email=$view_registry->owner_email ?: $view_registry->reservation_email;
                $registry_history=self::registry_audit_history($view_registry->id,20);
                $status_key=sanitize_key((string)$view_registry->status);
                $status_tone=in_array($status_key,['active','payment_confirmed'],true)?'#065f46':(in_array($status_key,['rejected','suspended','expired'],true)?'#991b1b':'#92400e');
                $status_bg=in_array($status_key,['active','payment_confirmed'],true)?'#ecfdf5':(in_array($status_key,['rejected','suspended','expired'],true)?'#fef2f2':'#fffbeb');
            ?>
            <section class="soc-panel" style="margin-bottom:18px;padding:0;overflow:hidden">
                <div style="padding:24px;border-bottom:1px solid #e5e7eb;background:linear-gradient(135deg,#ffffff 0%,#f8fafc 100%)">
                    <div class="soc-top" style="margin:0;align-items:flex-start">
                        <div style="display:flex;gap:14px;align-items:flex-start">
                            <div style="width:50px;height:50px;border-radius:16px;background:#111827;color:#fff;display:grid;place-items:center;font-size:23px;font-weight:800">/</div>
                            <div><div class="soc-meta" style="margin-bottom:5px">Surface Internet Identity</div><h2 style="margin:0 0 7px;font-size:28px">/<?php echo esc_html($view_registry->identity_normalized); ?></h2><span class="soc-badge" style="background:<?php echo esc_attr($status_bg); ?>;color:<?php echo esc_attr($status_tone); ?>;border:0"><?php echo esc_html(self::registry_status_label($view_registry->status)); ?></span></div>
                        </div>
                        <a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','registry',$base_url)); ?>">Back to Registry</a>
                    </div>
                </div>
                <div style="padding:22px">
                    <div class="soc-partner-profile">
                        <div class="soc-profile-field"><span>Classification</span><strong><?php echo esc_html(self::registry_class_label($view_registry->classification)); ?></strong></div>
                        <div class="soc-profile-field"><span>Applicant</span><strong><?php echo esc_html($owner?$owner->display_name:'Not yet linked'); ?></strong></div>
                        <div class="soc-profile-field"><span>Email</span><strong><?php echo esc_html($display_email ?: 'Not available'); ?></strong></div>
                        <div class="soc-profile-field"><span>Phone</span><strong><?php echo esc_html($owner_phone ?: 'Not available'); ?></strong></div>
                        <div class="soc-profile-field"><span>Payment</span><strong><?php echo $view_registry->amount_paid!==null ? esc_html(($view_registry->currency?:'NGN').' '.number_format_i18n((float)$view_registry->amount_paid,2)) : 'Not confirmed'; ?></strong></div>
                        <div class="soc-profile-field"><span>Payment Reference</span><strong><?php echo esc_html($view_registry->payment_reference ?: 'Not available'); ?></strong></div>
                        <div class="soc-profile-field"><span>Source</span><strong><?php echo esc_html(ucwords(str_replace('_',' ',$view_registry->created_source))); ?></strong></div>
                        <div class="soc-profile-field"><span>Registered</span><strong><?php echo esc_html($view_registry->registered_at?mysql2date('M j, Y g:i a',$view_registry->registered_at):'Not available'); ?></strong></div>
                        <div class="soc-profile-field"><span>Reservation Expires</span><strong><?php echo esc_html($view_registry->reservation_expires_at?mysql2date('M j, Y g:i a',$view_registry->reservation_expires_at):'Not applicable'); ?></strong></div>
                        <div class="soc-profile-field"><span>Last Updated</span><strong><?php echo esc_html($view_registry->last_updated?mysql2date('M j, Y g:i a',$view_registry->last_updated):'Not available'); ?></strong></div>
                        <div class="soc-profile-field"><span>Assigned Staff</span><strong><?php echo esc_html($assigned?$assigned->display_name:'Surface Identity queue'); ?></strong></div>
                        <div class="soc-profile-field"><span>Priority</span><strong><?php echo esc_html(ucfirst($view_registry->priority ?: 'normal')); ?></strong></div>
                    </div>
                </div>
            </section>

            <section class="soc-columns" style="margin-top:0;margin-bottom:18px;align-items:start">
                <div class="soc-panel"><h2 style="margin-bottom:5px">Case Management</h2><p class="soc-meta" style="margin-top:0">Assign responsibility and keep internal review notes.</p><form class="soc-form" method="post"><?php wp_nonce_field('surface_operations_registry','surface_operations_registry_nonce'); ?><input type="hidden" name="surface_operations_registry_action" value="assign"><input type="hidden" name="registry_id" value="<?php echo esc_attr($view_registry->id); ?>"><label>Assign to staff<select name="assigned_user_id"><option value="0">Surface Identity team queue</option><?php foreach($staff_list as $member){if(self::staff_status($member->ID)==='suspended')continue;echo '<option value="'.esc_attr($member->ID).'" '.selected((int)$view_registry->assigned_user_id,$member->ID,false).'>'.esc_html($member->display_name.' · '.self::user_team($member->ID)).'</option>';} ?></select></label><label>Priority<select name="registry_priority"><?php foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected((string)$view_registry->priority,$k,false).'>'.esc_html($v).'</option>'; ?></select></label><label>Internal notes<textarea rows="6" name="registry_notes" placeholder="Record review findings, verification needs or handover notes."><?php echo esc_textarea($view_registry->internal_notes); ?></textarea></label><button class="soc-btn" type="submit">Save Case</button></form></div>
                <div class="soc-panel"><h2 style="margin-bottom:5px">Lifecycle Control</h2><?php if(self::can_enforce($user->ID)): ?><p class="soc-meta" style="margin-top:0">Only valid Registry lifecycle transitions are available.</p><div class="soc-actions" style="margin-top:16px"><?php $shown_action=false; foreach(['active'=>'Approve / Activate','rejected'=>'Reject','suspended'=>'Suspend','expired'=>'Expire'] as $target=>$label){if(!self::registry_can_transition($view_registry->status,$target))continue; $shown_action=true; if($target==='active' && $view_registry->status==='suspended')$label='Reactivate'; ?><form method="post" onsubmit="return confirm('<?php echo esc_js($label.' /'.$view_registry->identity_normalized.'?'); ?>');"><?php wp_nonce_field('surface_operations_registry','surface_operations_registry_nonce'); ?><input type="hidden" name="surface_operations_registry_action" value="transition"><input type="hidden" name="registry_id" value="<?php echo esc_attr($view_registry->id); ?>"><input type="hidden" name="registry_status" value="<?php echo esc_attr($target); ?>"><button class="soc-btn <?php echo $target==='active'?'':'soc-btn-light'; ?>" type="submit"><?php echo esc_html($label); ?></button></form><?php } if(!$shown_action): ?><div class="soc-empty">No lifecycle action is currently available.</div><?php endif; ?></div><?php else: ?><p class="soc-meta" style="margin-top:0">Lifecycle decisions are restricted to the Operations Director. Review the case, update notes, assign it, or escalate it.</p><div style="margin-top:16px"><?php echo self::escalation_form('registry',$view_registry->id,'/'.$view_registry->identity_normalized,$view_registry->status==='suspended'?'reactivate':'suspend'); ?></div><?php endif; ?></div>
            </section>

            <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top" style="margin-bottom:14px"><div><h2 style="margin:0 0 5px">Operational Timeline</h2><p class="soc-meta" style="margin:0">Recent Registry activity recorded by the Operations Console.</p></div></div><?php if(!$registry_history): ?><div class="soc-empty">No operational activity has been recorded for this identity yet.</div><?php else: ?><div style="display:grid;gap:12px"><?php foreach($registry_history as $event): $actor=$event->actor_user_id?self::staff_name($event->actor_user_id):'System'; ?><article style="display:grid;grid-template-columns:14px 1fr;gap:12px;align-items:start"><span style="width:10px;height:10px;border-radius:50%;background:#111827;margin-top:6px"></span><div style="border-bottom:1px solid #eef2f7;padding-bottom:12px"><strong><?php echo esc_html($event->summary); ?></strong><div class="soc-meta"><?php echo esc_html($actor.' · '.mysql2date('M j, Y g:i a',$event->created_at)); ?></div></div></article><?php endforeach; ?></div><?php endif; ?></section>
            <?php endif; ?>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="registry"><label style="flex:1;min-width:240px">Search<input style="width:100%" type="search" name="registry_search" value="<?php echo esc_attr($registry_search); ?>" placeholder="Search identity, email or payment reference"></label><label>Status<select name="registry_status"><option value="">All statuses</option><?php $statuses=class_exists('Surface_Identity_Registry')?Surface_Identity_Registry::get_statuses():[]; foreach($statuses as $k=>$v){if($k==='available')continue;echo '<option value="'.esc_attr($k).'" '.selected($registry_status,$k,false).'>'.esc_html($v).'</option>';} ?></select></label><button class="soc-btn" type="submit">Filter</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','registry',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Identity</th><th>Applicant</th><th>Status</th><th>Classification</th><th>Protection</th><th>Assigned Staff</th><th>Updated</th><th>Action</th></tr></thead><tbody><?php if(!$registry_records): ?><tr><td colspan="8" class="soc-empty">No Registry identities found.</td></tr><?php endif; ?><?php foreach($registry_records as $record): $assignee=$record->assigned_user_id?get_user_by('id',(int)$record->assigned_user_id):false; ?><tr><td><strong>/<?php echo esc_html($record->identity_normalized); ?></strong></td><td><?php echo esc_html($record->owner_email ?: $record->reservation_email ?: 'Not available'); ?></td><td><span class="soc-badge"><?php echo esc_html(self::registry_status_label($record->status)); ?></span></td><td><?php echo esc_html(self::registry_class_label($record->classification)); ?></td><td><span class="soc-badge"><?php echo !empty($record->is_protected) ? 'Protected' : 'Not Protected'; ?></span></td><td><?php echo esc_html($assignee?$assignee->display_name:'Unassigned'); ?></td><td><?php echo esc_html($record->last_updated?mysql2date('M j, Y',$record->last_updated):'—'); ?></td><td><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'registry','view_registry'=>$record->id],$base_url)); ?>">View Details</a></td></tr><?php endforeach; ?></tbody></table></div></section>
            <?php endif; ?>
        <?php elseif($section==='partners'): ?>
            <?php
            $partner_counts=['total'=>0,'active'=>0,'pending'=>0,'suspended'=>0];
            $all_partners=self::surface_partners('');
            $partner_counts['total']=count($all_partners);
            foreach($all_partners as $p){$ps=self::partner_status($p->ID);if(isset($partner_counts[$ps]))$partner_counts[$ps]++;}
            ?>
            <div class="soc-top"><div><h1>Partner Operations</h1><p>Review Surface Partners and control operational access.</p></div></div>
            <?php if($partner_notice): ?><div class="soc-alert">Partner status updated.</div><?php endif; ?>
            <section class="soc-grid" style="margin-bottom:18px"><div class="soc-stat"><span>Total Partners</span><strong><?php echo esc_html($partner_counts['total']); ?></strong></div><div class="soc-stat"><span>Active Partners</span><strong><?php echo esc_html($partner_counts['active']); ?></strong></div><div class="soc-stat"><span>Pending Approval</span><strong><?php echo esc_html($partner_counts['pending']); ?></strong></div><div class="soc-stat"><span>Suspended Partners</span><strong><?php echo esc_html($partner_counts['suspended']); ?></strong></div></section>
            <?php if($view_partner):
                $vp_status=self::partner_status($view_partner->ID);
                $vp_store=(string)get_user_meta($view_partner->ID,'surface_store',true);
                $vp_email=(string)get_user_meta($view_partner->ID,'surface_email',true) ?: $view_partner->user_email;
                $vp_phone=(string)get_user_meta($view_partner->ID,'surface_phone',true);
            ?>
                <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top" style="margin-bottom:18px"><div><h2 style="margin:0">Partner Profile</h2><p>Read-only operational view.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','partners',$base_url)); ?>">Back to Partners</a></div><div class="soc-partner-profile"><div class="soc-profile-field"><span>Business Name</span><strong><?php echo esc_html($vp_store ?: $view_partner->display_name); ?></strong></div><div class="soc-profile-field"><span>SII</span><strong>/<?php echo esc_html(self::partner_sii($view_partner->ID) ?: 'Not assigned'); ?></strong></div><div class="soc-profile-field"><span>Owner</span><strong><?php echo esc_html($view_partner->display_name); ?></strong></div><div class="soc-profile-field"><span>Email</span><strong><?php echo esc_html($vp_email ?: 'Not available'); ?></strong></div><div class="soc-profile-field"><span>Phone</span><strong><?php echo esc_html($vp_phone ?: 'Not available'); ?></strong></div><div class="soc-profile-field"><span>Status</span><strong><?php echo esc_html(ucfirst($vp_status)); ?></strong></div><div class="soc-profile-field"><span>SurfaceTeeth</span><strong><?php echo esc_html(self::partner_surfaceteeth_count($view_partner->ID)); ?></strong></div><div class="soc-profile-field"><span>Bundle Summary</span><strong><?php echo esc_html(self::partner_bundle_summary($view_partner->ID)); ?></strong></div><div class="soc-profile-field"><span>Wallet Balance</span><strong><?php echo esc_html(self::partner_wallet_balance($view_partner->ID)); ?></strong></div><div class="soc-profile-field"><span>Date Joined</span><strong><?php echo esc_html(mysql2date('M j, Y',$view_partner->user_registered)); ?></strong></div></div></section>
            <?php endif; ?>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="partners"><label style="flex:1;min-width:240px">Search<input style="width:100%" type="search" name="partner_search" value="<?php echo esc_attr($partner_search); ?>" placeholder="Search Partner Name, SII or Email"></label><button class="soc-btn" type="submit">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','partners',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Partner</th><th>SII</th><th>SurfaceTeeth</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead><tbody><?php if(!$partners): ?><tr><td colspan="6" class="soc-empty">No partners found.</td></tr><?php endif; ?><?php foreach($partners as $partner): $ps=self::partner_status($partner->ID); $store=(string)get_user_meta($partner->ID,'surface_store',true); ?><tr><td><strong><?php echo esc_html($store ?: $partner->display_name); ?></strong><div class="soc-meta"><?php echo esc_html($partner->user_email); ?></div></td><td>/<?php echo esc_html(self::partner_sii($partner->ID) ?: '—'); ?></td><td><?php echo esc_html(self::partner_surfaceteeth_count($partner->ID)); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($ps)); ?></span></td><td><?php echo esc_html(mysql2date('M j, Y',$partner->user_registered)); ?></td><td><div class="soc-actions"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'partners','view_partner'=>$partner->ID],$base_url)); ?>">View</a><?php if(self::can_enforce($user->ID)): ?><form method="post"><?php wp_nonce_field('surface_operations_partner','surface_operations_partner_nonce'); ?><input type="hidden" name="partner_id" value="<?php echo esc_attr($partner->ID); ?>"><input type="hidden" name="surface_operations_partner_action" value="<?php echo esc_attr($ps==='suspended'?'reactivate':'suspend'); ?>"><button class="soc-btn <?php echo $ps==='suspended'?'':'soc-btn-light'; ?>" type="submit"><?php echo esc_html($ps==='suspended'?'Reactivate':'Suspend'); ?></button></form><?php else: echo self::escalation_form('partner',$partner->ID,$store ?: $partner->display_name,$ps==='suspended'?'reactivate':'suspend'); endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php elseif($section==='surfaceteeth'): ?>
            <?php
            $all_teeth=self::surface_teeth('');
            $tooth_counts=['total'=>count($all_teeth),'active'=>0,'draft'=>0,'suspended'=>0];
            foreach($all_teeth as $tooth){$ts=self::surfacetooth_status($tooth);if(isset($tooth_counts[$ts]))$tooth_counts[$ts]++;}
            ?>
            <div class="soc-top"><div><h1>SurfaceTeeth Operations</h1><p>Review partner SurfaceTeeth and control operational availability.</p></div></div>
            <?php if($surfacetooth_notice): ?><div class="soc-alert">SurfaceTooth status updated.</div><?php endif; ?>
            <section class="soc-grid" style="margin-bottom:18px"><div class="soc-stat"><span>Total SurfaceTeeth</span><strong><?php echo esc_html($tooth_counts['total']); ?></strong></div><div class="soc-stat"><span>Active</span><strong><?php echo esc_html($tooth_counts['active']); ?></strong></div><div class="soc-stat"><span>Draft</span><strong><?php echo esc_html($tooth_counts['draft']); ?></strong></div><div class="soc-stat"><span>Suspended</span><strong><?php echo esc_html($tooth_counts['suspended']); ?></strong></div></section>
            <?php if($view_surfacetooth):
                $vt_partner_id=self::surfacetooth_partner_id($view_surfacetooth);
                $vt_partner=$vt_partner_id?get_user_by('id',$vt_partner_id):false;
                $vt_store=$vt_partner_id?(string)get_user_meta($vt_partner_id,'surface_store',true):'';
                $vt_status=self::surfacetooth_status($view_surfacetooth);
                $vt_type=self::surfacetooth_type($view_surfacetooth);
                $vt_sii=self::surfacetooth_sii($view_surfacetooth);
                $vt_channels=self::surfacetooth_channels($view_surfacetooth);
                $vt_channel_values=self::surfacetooth_channel_values($view_surfacetooth);
                $vt_history=self::surfacetooth_audit_history($view_surfacetooth->ID,20);
                $vt_destination=(string)(get_post_meta($view_surfacetooth->ID,'_surface_destination',true) ?: get_post_meta($view_surfacetooth->ID,'surface_destination',true));
                $vt_media_summary=self::surfacetooth_media_summary($view_surfacetooth);
                $vt_media_items=self::surfacetooth_media_items($view_surfacetooth);
            ?>
                <section class="soc-panel" style="margin-bottom:18px">
                    <div class="soc-top" style="margin-bottom:18px"><div><div class="soc-meta"><?php echo esc_html($vt_type.' SurfaceTooth'); ?></div><h2 style="margin:4px 0 6px"><?php echo esc_html($view_surfacetooth->post_title); ?></h2><p style="margin:0"><?php echo esc_html($vt_sii?'/'.$vt_sii:'SII not assigned'); ?></p></div><div class="soc-actions"><span class="soc-badge"><?php echo esc_html(ucfirst($vt_status)); ?></span><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','surfaceteeth',$base_url)); ?>">Back to SurfaceTeeth</a></div></div>
                    <div class="soc-partner-profile"><div class="soc-profile-field"><span>Partner</span><strong><?php echo esc_html($vt_store ?: ($vt_partner ? $vt_partner->display_name : 'Unknown partner')); ?></strong></div><div class="soc-profile-field"><span>Partner SII</span><strong><?php echo esc_html($vt_partner_id && self::partner_sii($vt_partner_id)?'/'.self::partner_sii($vt_partner_id):'Not assigned'); ?></strong></div><div class="soc-profile-field"><span>SurfaceTooth Type</span><strong><?php echo esc_html($vt_type); ?></strong></div><div class="soc-profile-field"><span>Post ID</span><strong>#<?php echo esc_html($view_surfacetooth->ID); ?></strong></div><div class="soc-profile-field"><span>Created</span><strong><?php echo esc_html(mysql2date('M j, Y g:i a',$view_surfacetooth->post_date)); ?></strong></div><div class="soc-profile-field"><span>Last Updated</span><strong><?php echo esc_html(mysql2date('M j, Y g:i a',$view_surfacetooth->post_modified)); ?></strong></div><div class="soc-profile-field"><span>Surface Channels</span><strong><?php echo esc_html($vt_channels ?: 'No channels recorded'); ?></strong></div><div class="soc-profile-field"><span>Destination</span><strong><?php echo esc_html($vt_destination ?: 'Uses current SurfaceTooth resolver destination'); ?></strong></div><div class="soc-profile-field"><span>Bundle Summary</span><strong><?php echo esc_html($vt_partner_id?self::partner_bundle_summary($vt_partner_id):'Not available'); ?></strong></div><div class="soc-profile-field"><span>Featured Media</span><strong><?php echo esc_html($vt_media_summary); ?></strong></div><div class="soc-profile-field" style="grid-column:1/-1"><span>Description</span><strong><?php echo nl2br(esc_html(self::surfacetooth_description($view_surfacetooth) ?: 'No description available')); ?></strong></div></div>
                </section>
                <section class="soc-columns" style="margin-top:0;margin-bottom:18px;align-items:start">
                    <div class="soc-panel" style="margin-bottom:18px"><div class="soc-top"><div><h2 style="margin-bottom:5px">Featured Media</h2><p class="soc-meta" style="margin:0">Actual SurfaceMark images and videos connected to this SurfaceTooth.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('surfacetooth_id',$view_surfacetooth->ID,home_url('/surface-media/'))); ?>">Open SurfaceMark</a></div><?php if(!$vt_media_items): ?><div class="soc-empty">No media is currently connected to this SurfaceTooth.</div><?php else: ?><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px"><?php foreach($vt_media_items as $media): ?><article style="border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff"><div style="aspect-ratio:16/10;background:#f3f4f6;display:grid;place-items:center;overflow:hidden"><?php if($media['type']==='video'): ?><video src="<?php echo esc_url($media['url']); ?>" controls preload="metadata" style="width:100%;height:100%;object-fit:cover"></video><?php else: ?><img src="<?php echo esc_url($media['url']); ?>" alt="" style="width:100%;height:100%;object-fit:cover"><?php endif; ?></div><div style="padding:12px"><strong><?php echo esc_html($media['label']); ?></strong><div class="soc-meta" style="margin-top:3px"><?php echo esc_html(ucfirst($media['type'])); ?></div><div class="soc-actions" style="margin-top:10px"><a class="soc-btn soc-btn-light" href="<?php echo esc_url($media['url']); ?>" target="_blank" rel="noopener">View</a><form method="post" onsubmit="return confirm('Remove this media from the SurfaceTooth?');"><?php wp_nonce_field('surface_operations_surfacetooth','surface_operations_surfacetooth_nonce'); ?><input type="hidden" name="surface_operations_surfacetooth_action" value="remove_media"><input type="hidden" name="surfacetooth_id" value="<?php echo esc_attr($view_surfacetooth->ID); ?>"><input type="hidden" name="media_key" value="<?php echo esc_attr($media['key']); ?>"><?php if($media['index']!==null): ?><input type="hidden" name="media_index" value="<?php echo esc_attr($media['index']); ?>"><?php endif; ?><button class="soc-btn soc-btn-light" type="submit">Remove</button></form></div></div></article><?php endforeach; ?></div><?php endif; ?></div>
                    <div class="soc-panel"><h2 style="margin-bottom:5px">Operational Editing</h2><p class="soc-meta" style="margin-top:0">Edit the actual SurfaceTooth and partner onboarding records. Saved channel corrections remain synchronized with My Surface.</p><form class="soc-form" method="post"><?php wp_nonce_field('surface_operations_surfacetooth','surface_operations_surfacetooth_nonce'); ?><input type="hidden" name="surface_operations_surfacetooth_action" value="edit"><input type="hidden" name="surfacetooth_id" value="<?php echo esc_attr($view_surfacetooth->ID); ?>"><label>Title<input name="surfacetooth_title" value="<?php echo esc_attr($view_surfacetooth->post_title); ?>" required></label><label>Description<textarea rows="6" name="surfacetooth_description"><?php echo esc_textarea(self::surfacetooth_description($view_surfacetooth)); ?></textarea></label><h3 style="margin:8px 0 0">Surface Channels</h3><p class="soc-meta" style="margin:0 0 4px">These are the partner's real My Surface onboarding channels.</p><?php foreach(self::surfacetooth_channel_fields() as $channel_key=>$channel_label): ?><label><?php echo esc_html($channel_label); ?><input name="<?php echo esc_attr($channel_key); ?>" value="<?php echo esc_attr($vt_channel_values[$channel_key] ?? ''); ?>" placeholder="Enter <?php echo esc_attr($channel_label); ?> channel"></label><?php endforeach; ?><button class="soc-btn" type="submit">Save Changes</button></form></div>
                    <div class="soc-panel"><h2 style="margin-bottom:5px">Lifecycle Control</h2><?php if(self::can_enforce($user->ID)): ?><p class="soc-meta" style="margin-top:0">Operations Director controls operational availability.</p><div class="soc-actions" style="margin-top:16px"><form method="post" onsubmit="return confirm('<?php echo esc_js(($vt_status==='suspended'?'Reactivate ':'Suspend ').$view_surfacetooth->post_title.'?'); ?>');"><?php wp_nonce_field('surface_operations_surfacetooth','surface_operations_surfacetooth_nonce'); ?><input type="hidden" name="surfacetooth_id" value="<?php echo esc_attr($view_surfacetooth->ID); ?>"><input type="hidden" name="surface_operations_surfacetooth_action" value="<?php echo esc_attr($vt_status==='suspended'?'reactivate':'suspend'); ?>"><button class="soc-btn <?php echo $vt_status==='suspended'?'':'soc-btn-light'; ?>" type="submit"><?php echo esc_html($vt_status==='suspended'?'Reactivate':'Suspend'); ?></button></form></div><?php else: ?><p class="soc-meta" style="margin-top:0">Lifecycle decisions are restricted to the Operations Director. Other staff may review, edit permitted fields, and escalate.</p><div style="margin-top:16px"><?php echo self::escalation_form('surfacetooth',$view_surfacetooth->ID,$view_surfacetooth->post_title,$vt_status==='suspended'?'reactivate':'suspend'); ?></div><?php endif; ?></div>
                </section>
                <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top" style="margin-bottom:14px"><div><h2 style="margin:0 0 5px">Operational Timeline</h2><p class="soc-meta" style="margin:0">Recent SurfaceTooth activity recorded by the Operations Console.</p></div></div><?php if(!$vt_history): ?><div class="soc-empty">No operational activity has been recorded for this SurfaceTooth yet.</div><?php else: ?><div style="display:grid;gap:12px"><?php foreach($vt_history as $event): $actor=$event->actor_user_id?self::staff_name($event->actor_user_id):'System'; ?><article style="display:grid;grid-template-columns:14px 1fr;gap:12px;align-items:start"><span style="width:10px;height:10px;border-radius:50%;background:#111827;margin-top:6px"></span><div style="border-bottom:1px solid #e5e7eb;padding-bottom:12px"><strong><?php echo esc_html($event->summary); ?></strong><div class="soc-meta"><?php echo esc_html($actor.' · '.mysql2date('M j, Y g:i a',$event->created_at)); ?></div></div></article><?php endforeach; ?></div><?php endif; ?></section>
            <?php endif; ?>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="surfaceteeth"><label style="flex:1;min-width:240px">Search<input style="width:100%" type="search" name="surfacetooth_search" value="<?php echo esc_attr($surfacetooth_search); ?>" placeholder="Search title, SII or partner"></label><button class="soc-btn" type="submit">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','surfaceteeth',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Title</th><th>Type</th><th>Partner</th><th>SII</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody><?php if(!$surfaceteeth): ?><tr><td colspan="7" class="soc-empty">No SurfaceTeeth found.</td></tr><?php endif; ?><?php foreach($surfaceteeth as $tooth): $ts=self::surfacetooth_status($tooth); $tp_id=self::surfacetooth_partner_id($tooth); $tp=$tp_id?get_user_by('id',$tp_id):false; $tp_store=$tp_id?(string)get_user_meta($tp_id,'surface_store',true):''; ?><tr><td><strong><?php echo esc_html($tooth->post_title); ?></strong></td><td><?php echo esc_html(self::surfacetooth_type($tooth)); ?></td><td><?php echo esc_html($tp_store ?: ($tp?$tp->display_name:'Unknown')); ?></td><td>/<?php echo esc_html(self::surfacetooth_sii($tooth) ?: '—'); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($ts)); ?></span></td><td><?php echo esc_html(mysql2date('M j, Y',$tooth->post_date)); ?></td><td><div class="soc-actions"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'surfaceteeth','view_surfacetooth'=>$tooth->ID],$base_url)); ?>">View</a><?php if(self::can_enforce($user->ID)): ?><form method="post"><?php wp_nonce_field('surface_operations_surfacetooth','surface_operations_surfacetooth_nonce'); ?><input type="hidden" name="surfacetooth_id" value="<?php echo esc_attr($tooth->ID); ?>"><input type="hidden" name="surface_operations_surfacetooth_action" value="<?php echo esc_attr($ts==='suspended'?'reactivate':'suspend'); ?>"><button class="soc-btn <?php echo $ts==='suspended'?'':'soc-btn-light'; ?>" type="submit"><?php echo esc_html($ts==='suspended'?'Reactivate':'Suspend'); ?></button></form><?php else: echo self::escalation_form('surfacetooth',$tooth->ID,$tooth->post_title,$ts==='suspended'?'reactivate':'suspend'); endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php elseif($section==='advocates'):
            if($advocate_notice): ?><div class="soc-notice">Advocate action completed: <?php echo esc_html($advocate_notice); ?>.</div><?php endif; ?>
            <section class="soc-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:18px"><div class="soc-stat"><span>Total Advocates</span><strong><?php echo esc_html($advocate_totals['total']); ?></strong></div><div class="soc-stat"><span>Active Advocates</span><strong><?php echo esc_html($advocate_totals['active']); ?></strong></div><div class="soc-stat"><span>Pending Approval</span><strong><?php echo esc_html($advocate_totals['pending']); ?></strong></div><div class="soc-stat"><span>Suspended Advocates</span><strong><?php echo esc_html($advocate_totals['suspended']); ?></strong></div><div class="soc-stat"><span>Introduced Partners</span><strong><?php echo esc_html($advocate_totals['introduced']); ?></strong></div><div class="soc-stat"><span>Total Advocacy Earnings</span><strong>₦<?php echo esc_html(number_format($advocate_totals['earnings'],2)); ?></strong></div></section>
            <?php if($view_advocate): $st=self::advocate_status($view_advocate->ID);$sii=self::advocate_sii($view_advocate->ID);$joined=get_user_meta($view_advocate->ID,'surface_advocate_joined',true); ?>
            <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top"><div><h2>Advocate Profile</h2><p>Read-only advocacy activity and operational controls.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','advocates',$base_url)); ?>">Back to Advocates</a></div><div class="soc-partner-profile"><div class="soc-profile-field"><span>Name</span><strong><?php echo esc_html($view_advocate->display_name); ?></strong></div><div class="soc-profile-field"><span>SII</span><strong><?php echo esc_html($sii?'/'.$sii:'Not assigned'); ?></strong></div><div class="soc-profile-field"><span>Email</span><strong><?php echo esc_html($view_advocate->user_email?:'Not provided'); ?></strong></div><div class="soc-profile-field"><span>Phone</span><strong><?php echo esc_html(get_user_meta($view_advocate->ID,'surface_phone',true)?:'Not provided'); ?></strong></div><div class="soc-profile-field"><span>Status</span><strong><?php echo esc_html(ucfirst($st)); ?></strong></div><div class="soc-profile-field"><span>Date Joined</span><strong><?php echo esc_html($joined?wp_date('M j, Y',(int)$joined):'Not recorded'); ?></strong></div><div class="soc-profile-field"><span>Introduced Partners</span><strong><?php echo esc_html(self::advocate_introduced($view_advocate->ID)); ?></strong></div><div class="soc-profile-field"><span>Introduced Mission Partners</span><strong><?php echo esc_html(self::advocate_introduced($view_advocate->ID,true)); ?></strong></div><div class="soc-profile-field"><span>Total Advocacy Earnings</span><strong>₦<?php echo esc_html(number_format($view_advocate_financials['earnings'],2)); ?></strong></div><div class="soc-profile-field"><span>Wallet Balance</span><strong>₦<?php echo esc_html(number_format($view_advocate_financials['balance'],2)); ?></strong></div><div class="soc-profile-field" style="grid-column:1/-1"><span>Referral Link</span><strong><?php echo esc_html(home_url('/surface-internet-registry/?advocate='.rawurlencode($sii))); ?></strong></div></div><div class="soc-actions" style="margin-top:16px"><form method="post"><?php wp_nonce_field('surface_operations_advocate','surface_operations_advocate_nonce'); ?><input type="hidden" name="advocate_id" value="<?php echo esc_attr($view_advocate->ID); ?>"><input type="hidden" name="surface_operations_advocate_action" value="<?php echo esc_attr($st==='pending'?'approve':($st==='suspended'?'reactivate':'suspend')); ?>"><button class="soc-btn" type="submit"><?php echo esc_html($st==='pending'?'Approve':($st==='suspended'?'Reactivate':'Suspend')); ?></button></form></div></section><?php endif; ?>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="advocates"><label style="flex:1">Search<input style="width:100%" type="search" name="advocate_search" value="<?php echo esc_attr($advocate_search); ?>" placeholder="Search advocate name, phone, SII or email"></label><button class="soc-btn">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','advocates',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Advocate</th><th>Phone</th><th>SII</th><th>Status</th><th>Introduced</th><th>Mission Partners</th><th>Total Earnings</th><th>Joined</th><th>Actions</th></tr></thead><tbody><?php if(!$advocates): ?><tr><td colspan="9" class="soc-empty">No advocates found.</td></tr><?php endif; foreach($advocates as $a): $ast=self::advocate_status($a->ID);$af=self::advocate_financials($a->ID);$aj=get_user_meta($a->ID,'surface_advocate_joined',true); ?><tr><td><strong><?php echo esc_html($a->display_name); ?></strong><div class="soc-meta"><?php echo esc_html($a->user_email); ?></div></td><td><?php echo esc_html(get_user_meta($a->ID,'surface_phone',true)?:'—'); ?></td><td><?php echo esc_html(self::advocate_sii($a->ID)?'/'.self::advocate_sii($a->ID):'—'); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($ast)); ?></span></td><td><?php echo esc_html(self::advocate_introduced($a->ID)); ?></td><td><?php echo esc_html(self::advocate_introduced($a->ID,true)); ?></td><td>₦<?php echo esc_html(number_format($af['earnings'],2)); ?></td><td><?php echo esc_html($aj?wp_date('M j, Y',(int)$aj):'—'); ?></td><td><div class="soc-actions"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'advocates','view_advocate'=>$a->ID],$base_url)); ?>">View</a><?php if($ast==='pending' || self::can_enforce($user->ID)): ?><form method="post"><?php wp_nonce_field('surface_operations_advocate','surface_operations_advocate_nonce'); ?><input type="hidden" name="advocate_id" value="<?php echo esc_attr($a->ID); ?>"><input type="hidden" name="surface_operations_advocate_action" value="<?php echo esc_attr($ast==='pending'?'approve':($ast==='suspended'?'reactivate':'suspend')); ?>"><button class="soc-btn soc-btn-light"><?php echo esc_html($ast==='pending'?'Approve':($ast==='suspended'?'Reactivate':'Suspend')); ?></button></form><?php else: echo self::escalation_form('advocate',$a->ID,$a->display_name,$ast==='suspended'?'reactivate':'suspend'); endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php elseif($section==='questionbank'): ?>
            <div class="soc-top"><div><h1>Question Bank</h1><p>Create, assign, review and approve reusable ReceiptTooth questions.</p></div></div>
            <?php if($question_notice): ?><div class="soc-alert"><?php echo esc_html($question_notice==='saved'?'Question saved.':($question_notice==='missing'?'Enter a question before saving.':'Question moved to '.ucwords(str_replace('_',' ',$question_notice)).'.')); ?></div><?php endif; ?>
            <div class="soc-task-grid">
            <section class="soc-panel"><h2><?php echo $edit_question?'Edit Question':'New Question'; ?></h2><form class="soc-form" method="post"><?php wp_nonce_field('surface_operations_question','surface_operations_question_nonce'); ?><input type="hidden" name="surface_operations_question_action" value="<?php echo $edit_question?'update':'create'; ?>"><input type="hidden" name="question_id" value="<?php echo esc_attr($edit_question->id??0); ?>"><label>Question</label><textarea name="question_text" required><?php echo esc_textarea($edit_question->question_text??''); ?></textarea><label>Category</label><input name="question_category" value="<?php echo esc_attr($edit_question->category??'General'); ?>"><label>Answer Type</label><select name="answer_type"><option value="single" <?php selected($edit_question->answer_type??'single','single'); ?>>Single Choice</option><option value="ordered" <?php selected($edit_question->answer_type??'single','ordered'); ?>>Ordered Sequence</option></select><?php foreach([1,2,3,4] as $n): $f='option_'.$n; ?><label>Option <?php echo esc_html(chr(64+$n)); ?></label><input name="<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($edit_question->{$f}??''); ?>"><?php endforeach; ?><label>Correct Answer</label><input name="correct_answer" value="<?php echo esc_attr($edit_question->correct_answer??''); ?>" placeholder="A or A>B>C>D"><div class="soc-meta" style="margin:-8px 0 13px">Use A, B, C or D for Single Choice. For Ordered Sequence use letters in order, for example A&gt;C&gt;B&gt;D.</div><label>Assign to Staff</label><select name="assigned_user_id"><option value="0">Unassigned</option><?php foreach($staff_list as $member): ?><option value="<?php echo esc_attr($member->ID); ?>" <?php selected(absint($edit_question->assigned_user_id??0),$member->ID); ?>><?php echo esc_html($member->display_name.' · '.(self::user_team($member->ID)?:'Operations')); ?></option><?php endforeach; ?></select><label>Status</label><select name="question_status"><?php foreach(['draft'=>'Draft','review'=>'Review','approved'=>'Approved','inactive'=>'Inactive'] as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($edit_question->status??'draft',$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select><button class="soc-btn" type="submit"><?php echo $edit_question?'Save Changes':'Create Question'; ?></button><?php if($edit_question): ?> <a class="soc-btn soc-btn-light" style="text-decoration:none" href="<?php echo esc_url(add_query_arg('soc_section','questionbank',$base_url)); ?>">Cancel</a><?php endif; ?></form></section>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="questionbank"><label style="flex:1">Search<input style="width:100%" name="question_search" value="<?php echo esc_attr($question_search); ?>" placeholder="Question, category or status"></label><button class="soc-btn" type="submit">Search</button></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Question</th><th>Type</th><th>Category</th><th>Assigned</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php if(!$question_rows): ?><tr><td colspan="6" class="soc-empty">No questions yet.</td></tr><?php endif; ?><?php foreach($question_rows as $q): $assigned=$q->assigned_user_id?get_user_by('id',$q->assigned_user_id):false; ?><tr><td><strong><?php echo esc_html(wp_trim_words($q->question_text,16)); ?></strong><div class="soc-meta"><?php echo esc_html(trim(implode(' · ',array_filter([$q->option_1,$q->option_2,$q->option_3,$q->option_4])))); ?></div></td><td><?php echo esc_html($q->answer_type==='ordered'?'Ordered':'Single'); ?></td><td><?php echo esc_html($q->category); ?></td><td><?php echo esc_html($assigned?$assigned->display_name:'Unassigned'); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($q->status)); ?></span></td><td><div class="soc-actions"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'questionbank','edit_question'=>$q->id],$base_url)); ?>">Edit</a><?php foreach(['review'=>'Review','approved'=>'Approve','inactive'=>'Inactive'] as $ak=>$al): if($q->status===$ak)continue; ?><form method="post"><?php wp_nonce_field('surface_operations_question','surface_operations_question_nonce'); ?><input type="hidden" name="surface_operations_question_action" value="<?php echo esc_attr($ak); ?>"><input type="hidden" name="question_id" value="<?php echo esc_attr($q->id); ?>"><button class="soc-btn soc-btn-light" type="submit"><?php echo esc_html($al); ?></button></form><?php endforeach; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section></div>

        <?php elseif($section==='campaigns'):
            $all_campaigns=self::campaigns('');
            $campaign_counts=['total'=>count($all_campaigns),'active'=>0,'scheduled'=>0,'ended'=>0,'suspended'=>0];
            foreach($all_campaigns as $campaign){$cs=self::campaign_status($campaign);if(isset($campaign_counts[$cs]))$campaign_counts[$cs]++;}
        ?>
            <div class="soc-top"><div><h1>Campaign Operations</h1><p>Review Receipt SurfaceTooth campaigns and control operational availability.</p></div></div>
            <?php if($campaign_notice): ?><div class="soc-alert">Campaign status updated.</div><?php endif; ?>
            <section class="soc-grid" style="grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:18px"><div class="soc-stat"><span>Total Campaigns</span><strong><?php echo esc_html($campaign_counts['total']); ?></strong></div><div class="soc-stat"><span>Active</span><strong><?php echo esc_html($campaign_counts['active']); ?></strong></div><div class="soc-stat"><span>Scheduled</span><strong><?php echo esc_html($campaign_counts['scheduled']); ?></strong></div><div class="soc-stat"><span>Ended</span><strong><?php echo esc_html($campaign_counts['ended']); ?></strong></div><div class="soc-stat"><span>Suspended</span><strong><?php echo esc_html($campaign_counts['suspended']); ?></strong></div></section>
            <?php if($view_campaign):
                $vc_partner=self::campaign_partner_name($view_campaign->partner_id ?? 0);
                $vc_tooth=self::campaign_surfacetooth($view_campaign);
                $vc_counts=self::campaign_counts($view_campaign->id);
            ?>
                <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top" style="margin-bottom:18px"><div><h2 style="margin:0">Campaign Details</h2><p>Read-only operational view.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','campaigns',$base_url)); ?>">Back to Campaigns</a></div><div class="soc-partner-profile"><div class="soc-profile-field"><span>Campaign Name</span><strong><?php echo esc_html($view_campaign->campaign_name); ?></strong></div><div class="soc-profile-field"><span>Partner</span><strong><?php echo esc_html($vc_partner); ?></strong></div><div class="soc-profile-field"><span>SurfaceTooth</span><strong><?php echo esc_html($vc_tooth ? $vc_tooth->post_title : 'Receipt SurfaceTooth'); ?></strong></div><div class="soc-profile-field"><span>Campaign Type</span><strong><?php echo esc_html(ucfirst((string)($view_campaign->campaign_scope ?? 'partner')).' Receipt Campaign'); ?></strong></div><div class="soc-profile-field"><span>Status</span><strong><?php echo esc_html(ucfirst(self::campaign_status($view_campaign))); ?></strong></div><div class="soc-profile-field"><span>Start Date</span><strong><?php echo esc_html(!empty($view_campaign->preferred_start_date) ? mysql2date('M j, Y',$view_campaign->preferred_start_date) : 'Immediate'); ?></strong></div><div class="soc-profile-field"><span>End Date</span><strong><?php echo esc_html(!empty($view_campaign->end_date) ? $view_campaign->end_date : 'Not specified'); ?></strong></div><div class="soc-profile-field"><span>Target</span><strong><?php echo esc_html((string)($view_campaign->target_value ?? 'Not specified')); ?></strong></div><div class="soc-profile-field"><span>Expected Winners</span><strong><?php echo esc_html(absint($view_campaign->expected_winners ?? 0)); ?></strong></div><div class="soc-profile-field"><span>Current Winners</span><strong><?php echo esc_html($vc_counts['winners']); ?></strong></div><div class="soc-profile-field"><span>Participation Count</span><strong><?php echo esc_html($vc_counts['participation']); ?></strong></div><div class="soc-profile-field"><span>Progress</span><strong><?php echo esc_html(self::campaign_progress($view_campaign)); ?></strong></div><div class="soc-profile-field" style="grid-column:1/-1"><span>Cashback Configuration</span><strong><?php echo esc_html(self::campaign_cashback_summary($view_campaign->id)); ?></strong></div><div class="soc-profile-field" style="grid-column:1/-1"><span>Grand Cashback Configuration</span><strong><?php echo esc_html(self::campaign_grand_cashback($view_campaign)); ?></strong></div></div></section>
            <?php endif; ?>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="campaigns"><label style="flex:1;min-width:240px">Search<input style="width:100%" type="search" name="campaign_search" value="<?php echo esc_attr($campaign_search); ?>" placeholder="Search campaign, partner or SII"></label><button class="soc-btn" type="submit">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','campaigns',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Campaign</th><th>Partner</th><th>SurfaceTooth</th><th>Type</th><th>Start</th><th>End</th><th>Status</th><th>Progress</th><th>Actions</th></tr></thead><tbody><?php if(!$campaigns): ?><tr><td colspan="9" class="soc-empty">No campaigns found. Receipt campaigns will appear here when available.</td></tr><?php endif; ?><?php foreach($campaigns as $campaign): $cs=self::campaign_status($campaign);$ct=self::campaign_surfacetooth($campaign); ?><tr><td><strong><?php echo esc_html($campaign->campaign_name); ?></strong></td><td><?php echo esc_html(self::campaign_partner_name($campaign->partner_id ?? 0)); ?></td><td><?php echo esc_html($ct?$ct->post_title:'Receipt SurfaceTooth'); ?></td><td><?php echo esc_html(ucfirst((string)($campaign->campaign_scope ?? 'partner'))); ?></td><td><?php echo esc_html(!empty($campaign->preferred_start_date)?mysql2date('M j, Y',$campaign->preferred_start_date):'Immediate'); ?></td><td><?php echo esc_html(!empty($campaign->end_date)?$campaign->end_date:'—'); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($cs)); ?></span></td><td><?php echo esc_html(self::campaign_progress($campaign)); ?></td><td><div class="soc-actions"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'campaigns','view_campaign'=>$campaign->id],$base_url)); ?>">View</a><?php if(self::can_enforce($user->ID)): ?><form method="post"><?php wp_nonce_field('surface_operations_campaign','surface_operations_campaign_nonce'); ?><input type="hidden" name="campaign_id" value="<?php echo esc_attr($campaign->id); ?>"><input type="hidden" name="surface_operations_campaign_action" value="<?php echo esc_attr($cs==='suspended'?'reactivate':'suspend'); ?>"><button class="soc-btn <?php echo $cs==='suspended'?'':'soc-btn-light'; ?>" type="submit"><?php echo esc_html($cs==='suspended'?'Reactivate':'Suspend'); ?></button></form><?php else: echo self::escalation_form('campaign',$campaign->id,$campaign->campaign_name,$cs==='suspended'?'reactivate':'suspend'); endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>

        <?php elseif($section==='escalations'): ?>
            <?php
            global $wpdb;
            $et=$wpdb->prefix.'surface_operations_escalations';
            $level_key=self::user_level($user->ID);
            if(self::table_exists($et)) {
                if(in_array($level_key,['operations_manager','team_lead','operations_director'],true)) {
                    $escalations=$wpdb->get_results("SELECT * FROM {$et} ORDER BY created_at DESC LIMIT 300");
                } else {
                    $escalations=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$et} WHERE created_by=%d ORDER BY created_at DESC LIMIT 300",$user->ID));
                }
            } else $escalations=[];
            ?>
            <div class="soc-top"><div><h1><?php echo in_array($level_key,['operations_manager','team_lead','operations_director'],true)?'Escalations':'My Escalations'; ?></h1><p><?php echo in_array($level_key,['operations_manager','team_lead','operations_director'],true)?'Operational review and enforcement approval queue.':'Track the issues you have escalated and any decisions or requests for more information.'; ?></p></div></div>
            <?php if($escalation_notice): ?><div class="soc-alert">Escalation <?php echo esc_html(str_replace('_',' ',$escalation_notice)); ?>.</div><?php endif; ?>

            <?php if($view_escalation): ?>
            <section class="soc-panel" style="margin-bottom:18px">
                <div class="soc-top" style="margin-bottom:18px"><div><h2 style="margin:0"><?php echo esc_html($view_escalation->case_code); ?></h2><p>Full escalation details and activity timeline.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','escalations',$base_url)); ?>">Back to Escalations</a></div>
                <div class="soc-alert" style="margin-bottom:16px"><strong>Current Owner:</strong> <?php echo esc_html(self::escalation_owner_label($view_escalation->current_level)); ?><br><span class="soc-meta">Staff → Operations Manager → Team Lead → Operations Director</span></div>
                <div class="soc-partner-profile">
                    <div class="soc-profile-field"><span>Entity</span><strong><?php echo esc_html($view_escalation->object_label ?: ucfirst($view_escalation->object_type).' #'.$view_escalation->object_id); ?></strong></div>
                    <div class="soc-profile-field"><span>Module</span><strong><?php echo esc_html(ucfirst($view_escalation->object_type)); ?></strong></div>
                    <div class="soc-profile-field"><span>Requested Action</span><strong><?php echo esc_html(ucfirst($view_escalation->requested_action)); ?></strong></div>
                    <div class="soc-profile-field"><span>Reason</span><strong><?php echo esc_html(self::escalation_reasons()[$view_escalation->reason]??ucwords(str_replace('_',' ',$view_escalation->reason))); ?></strong></div>
                    <div class="soc-profile-field"><span>Severity</span><strong><?php echo esc_html(ucfirst($view_escalation->severity)); ?></strong></div>
                    <div class="soc-profile-field"><span>Status</span><strong><?php echo esc_html(self::escalation_status_label($view_escalation)); ?></strong></div>
                    <div class="soc-profile-field"><span>Raised By</span><strong><?php echo esc_html(self::staff_name($view_escalation->created_by)); ?></strong></div>
                    <div class="soc-profile-field"><span>Current Level</span><strong><?php echo esc_html(self::escalation_owner_label($view_escalation->current_level)); ?></strong></div>
                    <div class="soc-profile-field" style="grid-column:1/-1"><span>Original Notes</span><strong><?php echo nl2br(esc_html($view_escalation->notes ?: 'No notes supplied.')); ?></strong></div>
                </div>
                <div class="soc-columns">
                    <div><h2>Timeline</h2><div class="soc-timeline">
                        <?php if(!$view_escalation_events): ?><div class="soc-empty">No timeline events recorded yet.</div><?php endif; ?>
                        <?php foreach($view_escalation_events as $event): ?><div class="soc-timeline-item"><strong><?php echo esc_html(ucwords(str_replace('_',' ',$event->event_key))); ?></strong><div class="soc-meta"><?php echo esc_html(self::staff_name($event->actor_user_id).' · '.mysql2date('M j, Y g:i a',$event->created_at)); ?></div><?php if($event->from_level||$event->to_level): ?><div class="soc-meta"><?php echo esc_html(ucwords(str_replace('_',' ',$event->from_level)).' → '.ucwords(str_replace('_',' ',$event->to_level))); ?></div><?php endif; ?><?php if($event->event_note): ?><div class="soc-note" style="margin-top:7px"><?php echo nl2br(esc_html($event->event_note)); ?></div><?php endif; ?></div><?php endforeach; ?>
                    </div></div>
                    <div><h2>Actions</h2>
                        <?php if(!in_array($view_escalation->status,['approved','rejected'],true)): ?>
                        <form class="soc-form" method="post"><?php wp_nonce_field('surface_operations_escalation','surface_operations_escalation_nonce'); ?><input type="hidden" name="escalation_id" value="<?php echo esc_attr($view_escalation->id); ?>"><label>Note / Decision Notes</label><textarea name="decision_notes"></textarea><div class="soc-actions">
                            <button class="soc-btn soc-btn-light" name="surface_operations_escalation_action" value="note">Add Note</button>
                            <?php if((int)$view_escalation->created_by===(int)$user->ID && $view_escalation->status==='returned'): ?><button class="soc-btn" name="surface_operations_escalation_action" value="resubmit">Resubmit</button><?php endif; ?>
                            <?php if(self::can_process_escalation($view_escalation,$user->ID)): ?>
                                <?php if(self::can_enforce($user->ID)): ?><button class="soc-btn" name="surface_operations_escalation_action" value="approve">Approve Action</button><button class="soc-btn soc-btn-light" name="surface_operations_escalation_action" value="reject">Reject</button><button class="soc-btn soc-btn-light" name="surface_operations_escalation_action" value="return">Return for Investigation</button>
                                <?php else: ?>
                                    <?php $action_level=self::normalized_escalation_level($view_escalation->current_level); ?>
                                    <button class="soc-btn" name="surface_operations_escalation_action" value="forward"><?php echo esc_html($action_level==='manager'?'Forward to Team Lead':'Forward to Operations Director'); ?></button>
                                    <button class="soc-btn soc-btn-light" name="surface_operations_escalation_action" value="return"><?php echo esc_html($action_level==='manager'?'Return to Source Staff':'Return to Operations Manager'); ?></button><?php endif; ?>
                            <?php endif; ?>
                        </div></form>
                        <?php else: ?><div class="soc-empty">This escalation is closed.</div><?php endif; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <section class="soc-panel"><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Case</th><th>Entity</th><th>Reason</th><th>Raised By</th><th>Current Level</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php if(!$escalations): ?><tr><td colspan="7" class="soc-empty">No escalations found.</td></tr><?php endif; foreach($escalations as $e): ?><tr><td><strong><?php echo esc_html($e->case_code); ?></strong><div class="soc-meta"><?php echo esc_html(mysql2date('M j, Y g:i a',$e->created_at)); ?></div></td><td><?php echo esc_html($e->object_label ?: ucfirst($e->object_type).' #'.$e->object_id); ?><div class="soc-meta"><?php echo esc_html(ucfirst($e->requested_action)); ?></div></td><td><?php echo esc_html(self::escalation_reasons()[$e->reason]??ucwords(str_replace('_',' ',$e->reason))); ?><div class="soc-meta"><?php echo esc_html(ucfirst($e->severity)); ?></div></td><td><?php echo esc_html(self::staff_name($e->created_by)); ?></td><td><?php echo esc_html(self::escalation_owner_label($e->current_level)); ?></td><td><span class="soc-badge"><?php echo esc_html(self::escalation_status_label($e)); ?></span></td><td><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'escalations','view_escalation'=>$e->id],$base_url)); ?>">View Details</a></td></tr><?php endforeach; ?></tbody></table></div></section>

        <?php elseif($section==='analytics'): ?>
            <div class="soc-top"><div><h1>Analytics & Insights Centre</h1><p>Leadership view of activity across Surface Internet operations.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_merge($_GET,['soc_analytics_export'=>'csv']),$base_url),'soc_analytics_export')); ?>">Export CSV</a><button class="soc-btn soc-btn-light" type="button" onclick="window.print()">Print / Save PDF</button></div></div>
            <section class="soc-panel" style="margin-bottom:18px"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="analytics"><label>Range<select name="analytics_range"><option value="today" <?php selected($_GET['analytics_range']??'','today'); ?>>Today</option><option value="7" <?php selected($_GET['analytics_range']??'','7'); ?>>Last 7 days</option><option value="30" <?php selected($_GET['analytics_range']??'30','30'); ?>>Last 30 days</option><option value="custom" <?php selected($_GET['analytics_range']??'','custom'); ?>>Custom</option></select></label><label>From<input type="date" name="analytics_from" value="<?php echo esc_attr($_GET['analytics_from']??''); ?>"></label><label>To<input type="date" name="analytics_to" value="<?php echo esc_attr($_GET['analytics_to']??''); ?>"></label><button class="soc-btn">Apply</button></form><div class="soc-meta" style="margin-top:10px">Showing <?php echo esc_html($analytics['label']); ?></div></section>
            <section class="soc-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px"><div class="soc-stat"><span>Partners</span><strong><?php echo esc_html(count($analytics['partners'])); ?></strong></div><div class="soc-stat"><span>SurfaceTeeth</span><strong><?php echo esc_html(count($analytics['teeth'])); ?></strong></div><div class="soc-stat"><span>Campaigns</span><strong><?php echo esc_html(count($analytics['campaigns'])); ?></strong></div><div class="soc-stat"><span>Resolves</span><strong><?php echo esc_html($analytics['resolver']['total']); ?></strong></div><div class="soc-stat"><span>Wallet Transactions</span><strong><?php echo esc_html($analytics['wallet']['credits']+$analytics['wallet']['debits']); ?></strong></div><div class="soc-stat"><span>Bundles</span><strong><?php echo esc_html($analytics['bundle']['total']); ?></strong></div><div class="soc-stat"><span>Advocates</span><strong><?php echo esc_html(count($analytics['advocates'])); ?></strong></div><div class="soc-stat"><span>Support Cases</span><strong><?php echo esc_html(array_sum($analytics['support'])); ?></strong></div></section>
            <section class="soc-panel" style="margin-bottom:18px"><h2>Daily Operational Activity</h2><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Date</th><th>Resolves</th><th>Partner Growth</th><th>Wallet Activity</th><th>Support Cases</th></tr></thead><tbody><?php foreach($analytics['daily'] as $date=>$row): ?><tr><td><?php echo esc_html(mysql2date('M j', $date)); ?></td><td><?php echo esc_html($row['resolves']); ?></td><td><?php echo esc_html($row['partners']); ?></td><td><?php echo esc_html($row['wallet']); ?></td><td><?php echo esc_html($row['support']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
            <section class="soc-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:18px"><div class="soc-panel"><h2>Top Partners</h2><?php if(!$analytics['top_partners']): ?><p class="soc-empty">No resolve activity in this range.</p><?php endif; foreach($analytics['top_partners'] as $row): ?><p><strong><?php echo esc_html(self::resolver_partner_name($row->partner_user_id)); ?></strong><span style="float:right"><?php echo esc_html($row->total); ?> resolves</span></p><?php endforeach; ?></div><div class="soc-panel"><h2>Top SurfaceTeeth</h2><?php if(!$analytics['top_surfaces']): ?><p class="soc-empty">No resolve activity in this range.</p><?php endif; foreach($analytics['top_surfaces'] as $row): ?><p><strong><?php echo esc_html($row->surface?:'Unknown'); ?></strong><span style="float:right"><?php echo esc_html($row->total); ?> resolves</span></p><?php endforeach; ?></div><div class="soc-panel"><h2>Most Active Staff</h2><?php if(!$analytics['top_staff']): ?><p class="soc-empty">No audited staff activity in this range.</p><?php endif; foreach($analytics['top_staff'] as $row): $member=get_user_by('id',$row->user_id); ?><p><strong><?php echo esc_html($member?$member->display_name:'Staff #'.$row->user_id); ?></strong><span style="float:right"><?php echo esc_html($row->total); ?> actions</span></p><?php endforeach; ?></div></section>
            <section class="soc-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))"><div class="soc-panel"><h2>Bundle Consumption</h2><p><strong>Used:</strong> <?php echo esc_html(size_format($analytics['bundle']['used']*1024*1024)); ?></p><p><strong>Remaining:</strong> <?php echo esc_html(size_format($analytics['bundle']['remaining']*1024*1024)); ?></p></div><div class="soc-panel"><h2>Wallet Activity</h2><p><strong>Credits:</strong> <?php echo esc_html(number_format_i18n($analytics['wallet']['credits'],2)); ?></p><p><strong>Debits:</strong> <?php echo esc_html(number_format_i18n($analytics['wallet']['debits'],2)); ?></p></div><div class="soc-panel"><h2>Support Health</h2><p><strong>Open:</strong> <?php echo esc_html(($analytics['support']['open']??0)+($analytics['support']['in_progress']??0)); ?></p><p><strong>Resolved / Closed:</strong> <?php echo esc_html(($analytics['support']['resolved']??0)+($analytics['support']['closed']??0)); ?></p></div></section>

        <?php elseif($section==='support'): ?>
            <div class="soc-top"><div><h1>Support & Case Management</h1><p>Manage partner and customer operational cases in one audited workspace.</p></div></div>
            <?php if($support_notice): ?><div class="soc-alert">Support case action completed.</div><?php endif; ?>
            <section class="soc-grid" style="grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:18px">
                <?php foreach(self::support_statuses() as $key=>$label): ?><div class="soc-stat"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html($support_totals[$key] ?? 0); ?></strong></div><?php endforeach; ?>
            </section>
            <?php if($view_case): $assigned=get_user_by('id',(int)$view_case->assigned_user_id); ?>
            <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top" style="margin-bottom:18px"><div><h2 style="margin:0"><?php echo esc_html($view_case->case_code.' · '.$view_case->subject); ?></h2><p>Case details and internal operational timeline.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','support',$base_url)); ?>">Back to Support</a></div>
                <div class="soc-partner-profile"><div class="soc-profile-field"><span>Status</span><strong><?php echo esc_html(self::support_statuses()[$view_case->status] ?? ucfirst($view_case->status)); ?></strong></div><div class="soc-profile-field"><span>Priority</span><strong><?php echo esc_html(ucfirst($view_case->priority)); ?></strong></div><div class="soc-profile-field"><span>Reporter</span><strong><?php echo esc_html($view_case->reporter_name ?: 'Not supplied'); ?></strong></div><div class="soc-profile-field"><span>Phone / Email</span><strong><?php echo esc_html(trim(($view_case->reporter_phone ?: '').' '.($view_case->reporter_email ?: '')) ?: 'Not supplied'); ?></strong></div><div class="soc-profile-field"><span>Partner</span><strong><?php echo esc_html(self::support_partner_label($view_case->partner_user_id)); ?></strong></div><div class="soc-profile-field"><span>Assigned Staff</span><strong><?php echo esc_html($assigned?$assigned->display_name:'Unassigned'); ?></strong></div><div class="soc-profile-field" style="grid-column:1/-1"><span>Description</span><strong><?php echo nl2br(esc_html($view_case->description ?: 'No description supplied.')); ?></strong></div></div>
                <div class="soc-columns"><div><h2>Internal Notes</h2><?php if(!$view_case_notes): ?><div class="soc-empty">No internal notes yet.</div><?php endif; ?><?php foreach($view_case_notes as $note): ?><div class="soc-note"><strong><?php echo esc_html(self::staff_name($note->user_id)); ?></strong><div class="soc-meta"><?php echo esc_html(mysql2date('M j, Y g:i a',$note->created_at)); ?></div><div style="margin-top:7px"><?php echo nl2br(esc_html($note->note_text)); ?></div></div><?php endforeach; ?></div>
                <div><h2>Case Actions</h2><form class="soc-form" method="post"><?php wp_nonce_field('surface_operations_support','surface_operations_support_nonce'); ?><input type="hidden" name="case_id" value="<?php echo esc_attr($view_case->id); ?>"><input type="hidden" name="surface_operations_support_action" value="assign"><label>Assign / Reassign</label><select name="assigned_user_id"><option value="0">Unassigned</option><?php foreach($staff_list as $member): if(self::staff_status($member->ID)==='suspended')continue; ?><option value="<?php echo esc_attr($member->ID); ?>" <?php selected($view_case->assigned_user_id,$member->ID); ?>><?php echo esc_html($member->display_name); ?></option><?php endforeach; ?></select><button class="soc-btn" type="submit">Save Assignment</button></form>
                <form class="soc-form" method="post" style="margin-top:16px"><?php wp_nonce_field('surface_operations_support','surface_operations_support_nonce'); ?><input type="hidden" name="case_id" value="<?php echo esc_attr($view_case->id); ?>"><input type="hidden" name="surface_operations_support_action" value="status"><label>Change Status</label><select name="case_status"><?php foreach(self::support_statuses() as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($view_case->status,$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select><button class="soc-btn" type="submit">Update Status</button></form>
                <form class="soc-form" method="post" style="margin-top:16px"><?php wp_nonce_field('surface_operations_support','surface_operations_support_nonce'); ?><input type="hidden" name="case_id" value="<?php echo esc_attr($view_case->id); ?>"><input type="hidden" name="surface_operations_support_action" value="note"><label>Add Internal Note</label><textarea name="note_text" required></textarea><button class="soc-btn" type="submit">Add Note</button></form>
                <?php if($view_case->status!=='closed'): ?><form method="post" style="margin-top:16px"><?php wp_nonce_field('surface_operations_support','surface_operations_support_nonce'); ?><input type="hidden" name="case_id" value="<?php echo esc_attr($view_case->id); ?>"><input type="hidden" name="surface_operations_support_action" value="close"><button class="soc-btn" type="submit">Close Case</button></form><?php endif; ?></div></div>
            </section><?php endif; ?>
            <section class="soc-task-grid"><div class="soc-panel"><h2>New Case</h2><form class="soc-form" method="post"><?php wp_nonce_field('surface_operations_support','surface_operations_support_nonce'); ?><input type="hidden" name="surface_operations_support_action" value="create"><label>Subject</label><input name="case_subject" required><label>Description</label><textarea name="case_description"></textarea><label>Reporter Name</label><input name="reporter_name"><label>Email</label><input type="email" name="reporter_email"><label>Phone</label><input name="reporter_phone"><label>Related Partner</label><select name="partner_user_id"><option value="0">Customer / not linked</option><?php foreach(self::surface_partners('') as $partner): ?><option value="<?php echo esc_attr($partner->ID); ?>"><?php echo esc_html(self::support_partner_label($partner->ID)); ?></option><?php endforeach; ?></select><label>Priority</label><select name="case_priority"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select><label>Assign to</label><select name="assigned_user_id"><option value="0">Unassigned</option><?php foreach($staff_list as $member): if(self::staff_status($member->ID)==='suspended')continue; ?><option value="<?php echo esc_attr($member->ID); ?>"><?php echo esc_html($member->display_name); ?></option><?php endforeach; ?></select><button class="soc-btn" type="submit">Create Case</button></form></div>
            <div class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="support"><label style="flex:1;min-width:240px">Search<input style="width:100%" type="search" name="support_search" value="<?php echo esc_attr($support_search); ?>" placeholder="Case ID, subject, partner, customer, phone or SII"></label><button class="soc-btn" type="submit">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','support',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Case ID</th><th>Subject</th><th>Partner / Customer</th><th>Priority</th><th>Assigned Staff</th><th>Status</th><th>Created</th><th>Last Updated</th><th>Actions</th></tr></thead><tbody><?php if(!$support_cases): ?><tr><td colspan="9" class="soc-empty">No support cases found.</td></tr><?php endif; ?><?php foreach($support_cases as $case): $case_staff=get_user_by('id',(int)$case->assigned_user_id); ?><tr><td><strong><?php echo esc_html($case->case_code); ?></strong></td><td><?php echo esc_html($case->subject); ?><div class="soc-meta"><?php echo esc_html($case->reporter_name ?: $case->reporter_phone ?: 'Reporter not supplied'); ?></div></td><td><?php echo esc_html(self::support_partner_label($case->partner_user_id)); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($case->priority)); ?></span></td><td><?php echo esc_html($case_staff?$case_staff->display_name:'Unassigned'); ?></td><td><span class="soc-badge"><?php echo esc_html(self::support_statuses()[$case->status] ?? ucfirst($case->status)); ?></span></td><td><?php echo esc_html(mysql2date('M j, Y',$case->created_at)); ?></td><td><?php echo esc_html(mysql2date('M j, Y g:i a',$case->updated_at)); ?></td><td><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'support','view_case'=>$case->id],$base_url)); ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div></div></section>
        <?php elseif($section==='resolver'): ?>
            <div class="soc-top"><div><h1>Surface Resolver Operations Centre</h1><p>Monitor live Surface resolution activity without changing resolver behaviour.</p></div></div>
            <section class="soc-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:18px"><div class="soc-stat"><span>Total Resolves</span><strong><?php echo esc_html($resolver_totals['total']); ?></strong></div><div class="soc-stat"><span>Successful Resolves</span><strong><?php echo esc_html($resolver_totals['successful']); ?></strong></div><div class="soc-stat"><span>Failed Resolves</span><strong><?php echo esc_html($resolver_totals['failed']); ?></strong></div><div class="soc-stat"><span>Active SurfaceTeeth</span><strong><?php echo esc_html($resolver_totals['active_teeth']); ?></strong></div><div class="soc-stat"><span>Top Resolved Partner</span><strong style="font-size:20px"><?php echo esc_html($resolver_totals['top_partner']); ?></strong></div><div class="soc-stat"><span>Top Resolution Channel</span><strong style="font-size:20px"><?php echo esc_html($resolver_totals['top_channel']); ?></strong></div></section>
            <?php if($view_resolve): $req=json_decode((string)$view_resolve->request_meta,true);$resp=json_decode((string)$view_resolve->response_summary,true); ?>
            <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top"><div><h2>Resolve Details</h2><p>Read-only resolver record.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','resolver',$base_url)); ?>">Back to Resolver</a></div><div class="soc-partner-profile"><div class="soc-profile-field"><span>Resolve ID</span><strong><?php echo esc_html($view_resolve->resolve_id); ?></strong></div><div class="soc-profile-field"><span>Status</span><strong><?php echo esc_html(ucfirst($view_resolve->status)); ?></strong></div><div class="soc-profile-field"><span>Surface Requested</span><strong><?php echo esc_html($view_resolve->requested_sii?:'Not recorded'); ?></strong></div><div class="soc-profile-field"><span>Surface Resolved</span><strong><?php echo esc_html($view_resolve->resolved_sii?:'Not recorded'); ?></strong></div><div class="soc-profile-field"><span>Partner</span><strong><?php echo esc_html(self::resolver_partner_name($view_resolve->partner_user_id)); ?></strong></div><div class="soc-profile-field"><span>Resolution Channel</span><strong><?php echo esc_html(ucfirst($view_resolve->channel?:'Unknown')); ?></strong></div><div class="soc-profile-field"><span>Result</span><strong><?php echo esc_html(ucwords(str_replace('_',' ',$view_resolve->result))); ?></strong></div><div class="soc-profile-field"><span>Processing Time</span><strong><?php echo esc_html(number_format((float)$view_resolve->processing_time_ms,2)); ?> ms</strong></div><div class="soc-profile-field"><span>Phone</span><strong><?php echo esc_html($view_resolve->phone?:'Not supplied'); ?></strong></div><div class="soc-profile-field"><span>Timestamp</span><strong><?php echo esc_html(mysql2date('M j, Y g:i a',$view_resolve->created_at)); ?></strong></div><div class="soc-profile-field" style="grid-column:1/-1"><span>Device</span><strong><?php echo esc_html($view_resolve->device?:'Not recorded'); ?></strong></div><div class="soc-profile-field"><span>Linked SurfaceTooth</span><strong><?php echo esc_html($view_resolve->linked_surfacetooth?:'Not identified'); ?></strong></div><div class="soc-profile-field"><span>Linked Campaign</span><strong><?php echo esc_html($view_resolve->linked_campaign?:'Not identified'); ?></strong></div><div class="soc-profile-field"><span>Linked Wallet Event</span><strong><?php echo esc_html($view_resolve->linked_wallet?:'Not identified'); ?></strong></div></div></section><?php endif; ?>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="resolver"><label style="flex:1;min-width:260px">Search<input style="width:100%" type="search" name="resolver_search" value="<?php echo esc_attr($resolver_search); ?>" placeholder="Search resolve ID, SII, phone or channel"></label><button class="soc-btn">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','resolver',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Resolve ID</th><th>Surface</th><th>Partner</th><th>Channel</th><th>Result</th><th>Device</th><th>Timestamp</th><th>Actions</th></tr></thead><tbody><?php if(!$resolver_logs): ?><tr><td colspan="8" class="soc-empty">No resolver activity has been captured yet. New requests to /surface/v1/resolve will appear here.</td></tr><?php endif; foreach($resolver_logs as $log): ?><tr><td><strong><?php echo esc_html($log->resolve_id); ?></strong></td><td><?php echo esc_html($log->resolved_sii?:$log->requested_sii?:'—'); ?></td><td><?php echo esc_html(self::resolver_partner_name($log->partner_user_id)); ?></td><td><?php echo esc_html(ucfirst($log->channel?:'Unknown')); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($log->status)); ?></span><div class="soc-meta"><?php echo esc_html(ucwords(str_replace('_',' ',$log->result))); ?></div></td><td><?php echo esc_html($log->device?wp_trim_words($log->device,7,'…'):'—'); ?></td><td><?php echo esc_html(mysql2date('M j, Y g:i a',$log->created_at)); ?></td><td><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'resolver','view_resolve'=>$log->id],$base_url)); ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div></section>

        <?php elseif($section==='bundles'): ?>
            <div class="soc-top"><div><h1>Bundle Operations Centre</h1><p>Monitor partner bandwidth bundles, storage usage and expiry.</p></div></div>
            <?php if($bundle_notice): ?><div class="soc-alert"><?php echo esc_html($bundle_notice==='extended'?'Bundle expiry extended.':($bundle_notice==='suspend'?'Bundle suspended.':'Bundle reactivated.')); ?></div><?php endif; ?>
            <section class="soc-grid" style="grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:18px"><div class="soc-stat"><span>Total Bundles</span><strong><?php echo esc_html($bundle_totals['total']); ?></strong></div><div class="soc-stat"><span>Active Bundles</span><strong><?php echo esc_html($bundle_totals['active']); ?></strong></div><div class="soc-stat"><span>Expired Bundles</span><strong><?php echo esc_html($bundle_totals['expired']); ?></strong></div><div class="soc-stat"><span>Consumed Storage</span><strong><?php echo esc_html(self::format_storage($bundle_totals['used'])); ?></strong></div><div class="soc-stat"><span>Remaining Storage</span><strong><?php echo esc_html(self::format_storage($bundle_totals['remaining'])); ?></strong></div></section>
            <?php if($view_bundle): $vb_partner=self::bundle_partner($view_bundle);$vb_status=self::bundle_status($view_bundle); ?>
            <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top" style="margin-bottom:18px"><div><h2 style="margin:0">Bundle Details</h2><p>Operational view and controlled status actions.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','bundles',$base_url)); ?>">Back to Bundles</a></div><div class="soc-partner-profile"><div class="soc-profile-field"><span>Bundle Code</span><strong><?php echo esc_html($view_bundle->bundle_code ?: 'Bundle #'.$view_bundle->id); ?></strong></div><div class="soc-profile-field"><span>Partner</span><strong><?php echo esc_html($vb_partner?$vb_partner->display_name:'Unknown partner'); ?></strong></div><div class="soc-profile-field"><span>SII</span><strong><?php echo esc_html($vb_partner?(self::partner_sii($vb_partner->ID) ?: 'Not assigned'):'Not linked'); ?></strong></div><div class="soc-profile-field"><span>Status</span><strong><?php echo esc_html(ucfirst($vb_status)); ?></strong></div><div class="soc-profile-field"><span>Original Capacity</span><strong><?php echo esc_html(self::format_storage($view_bundle->capacity_mb ?? 0)); ?></strong></div><div class="soc-profile-field"><span>Used Storage</span><strong><?php echo esc_html(self::format_storage($view_bundle->used_mb ?? 0)); ?></strong></div><div class="soc-profile-field"><span>Remaining Storage</span><strong><?php echo esc_html(self::format_storage($view_bundle->remaining_mb ?? 0)); ?></strong></div><div class="soc-profile-field"><span>Purchase Date</span><strong><?php echo esc_html(!empty($view_bundle->purchased_at)?mysql2date('M j, Y g:i a',$view_bundle->purchased_at):'Not recorded'); ?></strong></div><div class="soc-profile-field"><span>Expiry Date</span><strong><?php echo esc_html(!empty($view_bundle->expires_at)?mysql2date('M j, Y g:i a',$view_bundle->expires_at):'No expiry recorded'); ?></strong></div><div class="soc-profile-field"><span>Price</span><strong>₦<?php echo esc_html(number_format((float)($view_bundle->price ?? 0),2)); ?></strong></div><div class="soc-profile-field" style="grid-column:1/-1"><span>SurfaceTeeth Using Bundle</span><strong>Usage is reflected in the consumed storage total. Per-SurfaceTooth allocation is not stored in the current bundle table.</strong></div></div><div class="soc-actions" style="margin-top:16px"><form method="post" class="soc-inline"><?php wp_nonce_field('surface_operations_bundle','surface_operations_bundle_nonce'); ?><input type="hidden" name="bundle_id" value="<?php echo esc_attr($view_bundle->id); ?>"><input type="hidden" name="surface_operations_bundle_action" value="extend"><label style="font-size:12px;font-weight:700">Extend by days<input type="number" min="1" max="3650" name="extension_days" value="30" style="width:110px;padding:9px;border:1px solid #d1d5db;border-radius:8px;display:block;margin-top:5px"></label><button class="soc-btn" type="submit">Extend Expiry</button></form><form method="post"><?php wp_nonce_field('surface_operations_bundle','surface_operations_bundle_nonce'); ?><input type="hidden" name="bundle_id" value="<?php echo esc_attr($view_bundle->id); ?>"><input type="hidden" name="surface_operations_bundle_action" value="<?php echo esc_attr($vb_status==='suspended'?'reactivate':'suspend'); ?>"><button class="soc-btn soc-btn-light" type="submit"><?php echo esc_html($vb_status==='suspended'?'Reactivate':'Suspend'); ?></button></form></div></section>
            <section class="soc-panel" style="margin-bottom:18px"><h2>Usage & Audit History</h2><?php if(!$bundle_history): ?><div class="soc-empty">No bundle activity recorded yet.</div><?php endif; ?><?php foreach($bundle_history as $entry): ?><div class="soc-row"><div><div class="soc-row-title"><?php echo esc_html($entry->summary); ?></div><div class="soc-meta"><?php echo esc_html(mysql2date('M j, Y g:i a',$entry->created_at)); ?></div></div><span class="soc-badge"><?php echo esc_html(ucwords(str_replace('_',' ',$entry->action_key))); ?></span></div><?php endforeach; ?></section>
            <?php endif; ?>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="bundles"><label style="flex:1;min-width:240px">Search<input style="width:100%" type="search" name="bundle_search" value="<?php echo esc_attr($bundle_search); ?>" placeholder="Search bundle code, partner or SII"></label><button class="soc-btn" type="submit">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','bundles',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Bundle Code</th><th>Partner</th><th>Capacity</th><th>Used</th><th>Remaining</th><th>Status</th><th>Expiry Date</th><th>Actions</th></tr></thead><tbody><?php if(!$bundles): ?><tr><td colspan="8" class="soc-empty">No bundles found. Bundles will appear when the Surface Bundles table is available.</td></tr><?php endif; ?><?php foreach($bundles as $bundle): $b_partner=self::bundle_partner($bundle);$b_status=self::bundle_status($bundle); ?><tr><td><strong><?php echo esc_html($bundle->bundle_code ?: 'Bundle #'.$bundle->id); ?></strong></td><td><?php echo esc_html($b_partner?$b_partner->display_name:'Unknown partner'); ?><?php if($b_partner && self::partner_sii($b_partner->ID)): ?><div class="soc-meta">/<?php echo esc_html(self::partner_sii($b_partner->ID)); ?></div><?php endif; ?></td><td><?php echo esc_html(self::format_storage($bundle->capacity_mb ?? 0)); ?></td><td><?php echo esc_html(self::format_storage($bundle->used_mb ?? 0)); ?></td><td><?php echo esc_html(self::format_storage($bundle->remaining_mb ?? 0)); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($b_status)); ?></span></td><td><?php echo esc_html(!empty($bundle->expires_at)?mysql2date('M j, Y',$bundle->expires_at):'—'); ?></td><td><div class="soc-actions"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'bundles','view_bundle'=>$bundle->id],$base_url)); ?>">View</a><form method="post"><?php wp_nonce_field('surface_operations_bundle','surface_operations_bundle_nonce'); ?><input type="hidden" name="bundle_id" value="<?php echo esc_attr($bundle->id); ?>"><input type="hidden" name="surface_operations_bundle_action" value="<?php echo esc_attr($b_status==='suspended'?'reactivate':'suspend'); ?>"><button class="soc-btn <?php echo $b_status==='suspended'?'':'soc-btn-light'; ?>" type="submit"><?php echo esc_html($b_status==='suspended'?'Reactivate':'Suspend'); ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php elseif($section==='wallet'): ?>
            <div class="soc-top"><div><h1>Wallet Operations Centre</h1><p>Monitor wallet balances and transaction activity without changing financial records.</p></div></div>
            <?php if($wallet_notice): ?><div class="soc-alert">Transaction marked as reviewed.</div><?php endif; ?>
            <section class="soc-grid" style="grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:18px"><div class="soc-stat"><span>Total Wallet Balance</span><strong>₦<?php echo esc_html(number_format((float)$wallet_totals['balance'],2)); ?></strong></div><div class="soc-stat"><span>Total Credits</span><strong>₦<?php echo esc_html(number_format((float)$wallet_totals['credits'],2)); ?></strong></div><div class="soc-stat"><span>Total Debits</span><strong>₦<?php echo esc_html(number_format((float)$wallet_totals['debits'],2)); ?></strong></div><div class="soc-stat"><span>Pending Transactions</span><strong><?php echo esc_html($wallet_totals['pending']); ?></strong></div><div class="soc-stat"><span>Failed Transactions</span><strong><?php echo esc_html($wallet_totals['failed']); ?></strong></div></section>
            <?php if($view_wallet): $vw_owner=self::wallet_owner($view_wallet->phone_number);$vw_context=self::wallet_related_context($view_wallet);$vw_reviewed=!empty($wallet_reviews[(int)$view_wallet->id]); ?>
            <section class="soc-panel" style="margin-bottom:18px"><div class="soc-top" style="margin-bottom:18px"><div><h2 style="margin:0">Transaction Details</h2><p>Read-only financial record.</p></div><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','wallet',$base_url)); ?>">Back to Wallet</a></div><div class="soc-partner-profile"><div class="soc-profile-field"><span>Reference</span><strong><?php echo esc_html($view_wallet->reference ?: 'Ledger #'.$view_wallet->id); ?></strong></div><div class="soc-profile-field"><span>Wallet Owner</span><strong><?php echo esc_html($vw_owner?$vw_owner->display_name:'Unknown wallet owner'); ?></strong></div><div class="soc-profile-field"><span>Phone Number</span><strong><?php echo esc_html($view_wallet->phone_number); ?></strong></div><div class="soc-profile-field"><span>Partner / SII</span><strong><?php echo esc_html($vw_owner?(self::partner_sii($vw_owner->ID) ?: 'Not a registered partner'):'Not linked'); ?></strong></div><div class="soc-profile-field"><span>Amount</span><strong><?php echo esc_html(($view_wallet->amount<0?'-':'').'₦'.number_format(abs((float)$view_wallet->amount),2)); ?></strong></div><div class="soc-profile-field"><span>Transaction Type</span><strong><?php echo esc_html($view_wallet->amount<0?'Debit':'Credit'); ?></strong></div><div class="soc-profile-field"><span>Source</span><strong><?php echo esc_html(self::wallet_source_label($view_wallet->source)); ?></strong></div><div class="soc-profile-field"><span>Status</span><strong>Completed<?php echo $vw_reviewed?' · Reviewed':''; ?></strong></div><div class="soc-profile-field"><span>Date / Time</span><strong><?php echo esc_html(mysql2date('M j, Y g:i a',$view_wallet->created_at)); ?></strong></div><div class="soc-profile-field"><span>Balance After</span><strong>₦<?php echo esc_html(number_format((float)$view_wallet->balance_after,2)); ?></strong></div><div class="soc-profile-field"><span>Related Campaign</span><strong><?php echo esc_html($vw_context['campaign']); ?></strong></div><div class="soc-profile-field"><span>Related SurfaceTooth</span><strong><?php echo esc_html($vw_context['surfacetooth']); ?></strong></div><div class="soc-profile-field" style="grid-column:1/-1"><span>Notes</span><strong>Original wallet ledger record. Financial values cannot be changed from the Operations Console.</strong></div></div></section>
            <?php endif; ?>
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="wallet"><label style="flex:1;min-width:240px">Search<input style="width:100%" type="search" name="wallet_search" value="<?php echo esc_attr($wallet_search); ?>" placeholder="Search reference, phone, partner or SII"></label><button class="soc-btn" type="submit">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','wallet',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Reference</th><th>Partner / User</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody><?php if(!$wallet_transactions): ?><tr><td colspan="7" class="soc-empty">No wallet transactions found. Transactions will appear here when the wallet ledger is available.</td></tr><?php endif; ?><?php foreach($wallet_transactions as $transaction): $wt_owner=self::wallet_owner($transaction->phone_number);$wt_reviewed=!empty($wallet_reviews[(int)$transaction->id]); ?><tr><td><strong><?php echo esc_html($transaction->reference ?: 'Ledger #'.$transaction->id); ?></strong><div class="soc-meta"><?php echo esc_html($transaction->phone_number); ?></div></td><td><?php echo esc_html($wt_owner?$wt_owner->display_name:'Unknown wallet owner'); ?><?php if($wt_owner && self::partner_sii($wt_owner->ID)): ?><div class="soc-meta">/<?php echo esc_html(self::partner_sii($wt_owner->ID)); ?></div><?php endif; ?></td><td><?php echo esc_html(self::wallet_source_label($transaction->source)); ?></td><td><strong><?php echo esc_html(($transaction->amount<0?'-':'').'₦'.number_format(abs((float)$transaction->amount),2)); ?></strong></td><td><span class="soc-badge">Completed<?php echo $wt_reviewed?' · Reviewed':''; ?></span></td><td><?php echo esc_html(mysql2date('M j, Y g:i a',$transaction->created_at)); ?></td><td><div class="soc-actions"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'wallet','view_wallet'=>$transaction->id],$base_url)); ?>">View</a><?php if(!$wt_reviewed): ?><form method="post"><?php wp_nonce_field('surface_operations_wallet','surface_operations_wallet_nonce'); ?><input type="hidden" name="ledger_id" value="<?php echo esc_attr($transaction->id); ?>"><input type="hidden" name="surface_operations_wallet_action" value="review"><button class="soc-btn" type="submit">Mark Reviewed</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php elseif($section==='audit'): ?>
            <div class="soc-top"><div><h1>Audit Centre</h1><p>Review who did what, where and when across Surface Operations.</p></div></div>
            <section class="soc-panel">
                <form class="soc-filters" method="get">
                    <input type="hidden" name="soc_section" value="audit">
                    <label>Staff<select name="audit_staff_id"><option value="0">All staff</option><?php foreach($staff_list as $member): ?><option value="<?php echo esc_attr($member->ID); ?>" <?php selected($audit_filters['staff_id'],$member->ID); ?>><?php echo esc_html($member->display_name); ?></option><?php endforeach; ?></select></label>
                    <label>Module<select name="audit_object_type"><option value="">All modules</option><?php foreach($audit_options['objects'] as $object): ?><option value="<?php echo esc_attr($object); ?>" <?php selected($audit_filters['object_type'],$object); ?>><?php echo esc_html(ucwords(str_replace(['_','-'],' ',$object))); ?></option><?php endforeach; ?></select></label>
                    <label>Action<select name="audit_action_key"><option value="">All actions</option><?php foreach($audit_options['actions'] as $action): ?><option value="<?php echo esc_attr($action); ?>" <?php selected($audit_filters['action_key'],$action); ?>><?php echo esc_html(ucwords(str_replace(['.','_','-'],' ',$action))); ?></option><?php endforeach; ?></select></label>
                    <label>From<input type="date" name="audit_date_from" value="<?php echo esc_attr($audit_filters['date_from']); ?>"></label>
                    <label>To<input type="date" name="audit_date_to" value="<?php echo esc_attr($audit_filters['date_to']); ?>"></label>
                    <label>Search<input type="search" name="audit_search" value="<?php echo esc_attr($audit_filters['search']); ?>" placeholder="Summary, object or IP"></label>
                    <button class="soc-btn" type="submit">Filter</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','audit',$base_url)); ?>">Reset</a>
                </form>
                <div class="soc-audit-count"><?php echo esc_html(count($audit_entries)); ?> activities shown · newest first</div>
                <?php if(!$audit_entries): ?><div class="soc-empty">No audit activity matches these filters.</div><?php endif; ?>
                <?php foreach($audit_entries as $entry): $actor=self::staff_name($entry->actor_user_id); $context=self::audit_context($entry->context); ?>
                    <article class="soc-audit-item"><div class="soc-audit-head"><div><div class="soc-audit-summary"><?php echo esc_html($entry->summary); ?></div><div class="soc-meta"><?php echo esc_html($actor.' · '.ucwords(str_replace(['.','_','-'],' ',$entry->action_key)).' · '.mysql2date('M j, Y g:i a',$entry->created_at)); ?></div></div><span class="soc-badge"><?php echo esc_html(ucwords(str_replace(['_','-'],' ',$entry->object_type ?: 'system'))); ?></span></div>
                    <details class="soc-audit-details"><summary>View details</summary><div><strong>Object:</strong> <?php echo esc_html(($entry->object_type ?: 'system').($entry->object_id ? ' #'.$entry->object_id : '')); ?></div><div><strong>IP address:</strong> <?php echo esc_html($entry->ip_address ?: 'Not recorded'); ?></div><?php if($context): ?><div class="soc-audit-context"><?php echo wp_kses_post($context); ?></div><?php else: ?><div>No additional context recorded.</div><?php endif; ?></details></article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="soc-top"><div><h1><?php echo esc_html(self::greeting().', '.self::first_name($user->display_name)); ?></h1><p>Here is what needs attention across your operations.</p></div></div><section class="soc-grid"><div class="soc-stat"><span>My Open Tasks</span><strong><?php echo esc_html($task_counts['mine']); ?></strong></div><div class="soc-stat"><span>Team Queue</span><strong><?php echo esc_html($task_counts['team']); ?></strong></div><div class="soc-stat"><span>Due Today</span><strong><?php echo esc_html($task_counts['due_today']); ?></strong></div><div class="soc-stat"><span>Overdue</span><strong><?php echo esc_html($task_counts['overdue']); ?></strong></div></section><section class="soc-columns"><div class="soc-panel"><h2>My To-do List</h2><?php if(!$recent_tasks): ?><div class="soc-empty">No tasks have been assigned yet.</div><?php endif; ?><?php foreach($recent_tasks as $task): ?><div class="soc-row"><div><div class="soc-row-title"><?php echo esc_html($task->title); ?></div><div class="soc-meta"><?php echo esc_html(ucfirst($task->module).($task->due_at?' · Due '.mysql2date('M j, g:i a',$task->due_at):'')); ?></div></div><span class="soc-badge"><?php echo esc_html(ucfirst($task->priority)); ?></span></div><?php endforeach; ?></div><div class="soc-panel"><h2>Recent Operations Activity</h2><?php if(!$recent_audit): ?><div class="soc-empty">Activity will appear here as operations begin.</div><?php endif; ?><?php foreach($recent_audit as $entry): ?><div class="soc-row"><div><div class="soc-row-title"><?php echo esc_html($entry->summary); ?></div><div class="soc-meta"><?php echo esc_html(mysql2date('M j, g:i a',$entry->created_at)); ?></div></div></div><?php endforeach; ?></div></section>
        <?php endif; ?></main></div><?php return ob_get_clean();
    }

    private static function can_manage_tasks($user_id) {
        return in_array(self::user_level($user_id), ['operations_director','operations_manager','team_lead'], true);
    }

    private static function can_work_task($task, $user_id, $team) {
        if (self::can_manage_tasks($user_id)) return true;
        return (int)$task->assigned_user_id === (int)$user_id || (empty($task->assigned_user_id) && (string)$task->assigned_team === (string)$team);
    }

    private static function visible_tasks($user_id, $team, $all=false, $filters=[]) {
        global $wpdb;
        $table=$wpdb->prefix.'surface_operations_tasks';
        $conditions=[];
        $args=[];

        if (!$all) {
            $conditions[]='(assigned_user_id=%d OR (assigned_team=%s AND (assigned_user_id IS NULL OR assigned_user_id=0)))';
            $args[]=$user_id;
            $args[]=$team;
        }

        $status=sanitize_key($filters['status'] ?? 'all');
        $priority=sanitize_key($filters['priority'] ?? 'all');
        $filter_team=sanitize_text_field($filters['team'] ?? '');
        $filter_user=absint($filters['user_id'] ?? 0);

        if (in_array($status,['open','in_progress','completed'],true)) {
            $conditions[]='status=%s';
            $args[]=$status;
        }
        if (in_array($priority,['low','normal','high','urgent'],true)) {
            $conditions[]='priority=%s';
            $args[]=$priority;
        }
        if ($all && $filter_team!=='') {
            $conditions[]='assigned_team=%s';
            $args[]=$filter_team;
        }
        if ($all && $filter_user) {
            $conditions[]='assigned_user_id=%d';
            $args[]=$filter_user;
        }

        $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
        $sql="SELECT * FROM {$table}{$where} ORDER BY CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END, CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, due_at IS NULL, due_at ASC, id DESC LIMIT 100";
        if ($args) $sql=$wpdb->prepare($sql,$args);
        return $wpdb->get_results($sql);
    }

    private static function task_comments($task_id) {
        global $wpdb; $table=$wpdb->prefix.'surface_operations_task_comments';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE task_id=%d ORDER BY id ASC",$task_id));
    }

    private static function staff_name($user_id) {
        $u=get_user_by('id',(int)$user_id); return $u ? $u->display_name : 'Staff';
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
        if (!self::ensure_audit_table()) return [];
        $table = $wpdb->prefix . 'surface_operations_audit';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit));
    }

    private static function filtered_audit($filters = [], $limit = 250) {
        global $wpdb;
        if (!self::ensure_audit_table()) return [];
        $table = $wpdb->prefix . 'surface_operations_audit';
        $conditions = [];
        $args = [];

        $staff_id = absint($filters['staff_id'] ?? 0);
        $object_type = sanitize_key($filters['object_type'] ?? '');
        $action_key = sanitize_key($filters['action_key'] ?? '');
        $date_from = sanitize_text_field($filters['date_from'] ?? '');
        $date_to = sanitize_text_field($filters['date_to'] ?? '');
        $search = sanitize_text_field($filters['search'] ?? '');

        if ($staff_id) { $conditions[] = 'actor_user_id=%d'; $args[] = $staff_id; }
        if ($object_type) { $conditions[] = 'object_type=%s'; $args[] = $object_type; }
        if ($action_key) { $conditions[] = 'action_key=%s'; $args[] = $action_key; }
        if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $conditions[] = 'created_at >= %s'; $args[] = $date_from . ' 00:00:00'; }
        if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) { $conditions[] = 'created_at <= %s'; $args[] = $date_to . ' 23:59:59'; }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $conditions[] = '(summary LIKE %s OR action_key LIKE %s OR object_type LIKE %s OR object_id LIKE %s OR ip_address LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d";
        $args[] = max(1, min(500, absint($limit)));
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    private static function audit_filter_options() {
        global $wpdb;
        if (!self::ensure_audit_table()) return ['actions'=>[], 'objects'=>[]];
        $table = $wpdb->prefix . 'surface_operations_audit';
        return [
            'actions' => array_values(array_filter(array_map('sanitize_key', (array) $wpdb->get_col("SELECT DISTINCT action_key FROM {$table} WHERE action_key<>'' ORDER BY action_key ASC")))),
            'objects' => array_values(array_filter(array_map('sanitize_key', (array) $wpdb->get_col("SELECT DISTINCT object_type FROM {$table} WHERE object_type<>'' ORDER BY object_type ASC")))),
        ];
    }

    private static function audit_context($context) {
        if (!$context) return '';

        $decoded = json_decode((string) $context, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || !$decoded) {
            $plain = sanitize_textarea_field((string) $context);
            return $plain ? '<div>' . nl2br(esc_html($plain)) . '</div>' : '';
        }

        return self::audit_context_html($decoded);
    }

    private static function audit_context_html($data, $depth = 0) {
        if (!is_array($data) || !$data) return '';

        $rows = [];
        foreach ($data as $key => $value) {
            $label = self::audit_context_label($key);

            if (is_array($value)) {
                if (self::audit_context_is_change($value)) {
                    $previous = self::audit_context_value($value['previous'] ?? $value['old'] ?? $value['before'] ?? 'Not recorded');
                    $new = self::audit_context_value($value['new'] ?? $value['after'] ?? $value['current'] ?? 'Not recorded');
                    $rows[] = '<div class="soc-audit-change"><strong>' . esc_html($label) . '</strong><span>' . esc_html($previous) . ' &rarr; ' . esc_html($new) . '</span></div>';
                } else {
                    $nested = self::audit_context_html($value, $depth + 1);
                    if ($nested) {
                        $rows[] = '<div class="soc-audit-group"><strong>' . esc_html($label) . '</strong>' . $nested . '</div>';
                    }
                }
                continue;
            }

            $display = self::audit_context_value($value);
            if ($display === '') continue;
            $rows[] = '<div class="soc-audit-change"><strong>' . esc_html($label) . '</strong><span>' . esc_html($display) . '</span></div>';
        }

        if (!$rows) return '';
        return '<div class="soc-audit-readable">' . implode('', $rows) . '</div>';
    }

    private static function audit_context_is_change($value) {
        if (!is_array($value)) return false;
        $keys = array_map('strval', array_keys($value));
        $has_before = (bool) array_intersect($keys, ['previous','old','before']);
        $has_after = (bool) array_intersect($keys, ['new','after','current']);
        return $has_before && $has_after;
    }

    private static function audit_context_label($key) {
        $labels = [
            'partner_id' => 'Partner',
            'post_id' => 'SurfaceTooth',
            'registry_id' => 'Surface Identity record',
            'user_id' => 'User',
            'assigned_user_id' => 'Assigned staff',
            'assigned_team' => 'Assigned team',
            'internal_notes' => 'Internal notes',
            'phone_number' => 'Phone number',
            'ip_address' => 'IP address',
            'sii' => 'Surface Identity',
            'surface_name' => 'Surface Identity',
            'surfacetooth_id' => 'SurfaceTooth',
            'campaign_id' => 'Campaign',
            'wallet_id' => 'Wallet',
            'bundle_id' => 'Bundle',
            'channels' => 'Surface Channels',
            'whatsapp' => 'WhatsApp',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'twitter' => 'X',
            'x' => 'X',
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            'website' => 'Website',
            'linkedin' => 'LinkedIn',
        ];
        $clean = sanitize_key((string) $key);
        return $labels[$clean] ?? ucwords(str_replace(['_','-'], ' ', (string) $key));
    }

    private static function audit_context_value($value) {
        if (is_bool($value)) return $value ? 'Yes' : 'No';
        if ($value === null) return 'Not recorded';
        if (is_scalar($value)) {
            $text = trim((string) $value);
            return $text === '' ? 'Not recorded' : $text;
        }
        return '';
    }

    private static function audit($action, $object_type, $object_id, $summary, $context = []) {
        global $wpdb;
        if (!self::ensure_audit_table()) return false;
        return $wpdb->insert($wpdb->prefix . 'surface_operations_audit', [
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
