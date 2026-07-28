<?php
/**
 * Plugin Name: Surface Operations Console
 * Description: Internal Surface Internet operations, staff access, hierarchy, tasks and audit foundation.
 * Version: 1.3.7
 * Author: KX
 */

if (!defined('ABSPATH')) exit;

final class Surface_Operations_Console {

    const VERSION = '1.3.7';
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
        add_action('template_redirect', [__CLASS__, 'handle_task_actions'], 5);
        add_action('template_redirect', [__CLASS__, 'handle_partner_actions'], 6);
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
                        <p><label><strong>Module</strong><br><select class="widefat" name="task_module"><?php foreach(['general'=>'General','partners'=>'Partners','surfaceteeth'=>'SurfaceTeeth','advocacy'=>'Advocacy','campaigns'=>'Campaigns','wallet'=>'Wallet','bundles'=>'Bundles','support'=>'Support'] as $k=>$v) echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>'; ?></select></label></p>
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

    public static function render_console() {
        if (!is_user_logged_in() || !self::is_staff()) {
            return '<script>window.location.href=' . wp_json_encode(home_url('/' . self::LOGIN_SLUG . '/')) . ';</script>';
        }

        $user = wp_get_current_user();
        $team = self::user_team($user->ID) ?: 'Operations';
        $level = self::level_label(self::user_level($user->ID));
        $section = sanitize_key(wp_unslash($_GET['soc_section'] ?? 'dashboard'));
        if (!self::can_access($section, $user->ID)) $section = 'dashboard';
        $task_counts = self::task_counts($user->ID, $team);
        $all_staff_ids = get_users(['role'=>self::ROLE,'fields'=>'ID']);
        $active_staff=0; $suspended_staff=0;
        foreach ($all_staff_ids as $staff_id) self::staff_status($staff_id)==='suspended' ? $suspended_staff++ : $active_staff++;
        $recent_tasks = self::recent_tasks($user->ID,$team,6);
        $recent_audit = self::recent_audit(6);
        $logout_url = wp_logout_url(home_url('/'.self::LOGIN_SLUG.'/'));
        $base_url = home_url('/'.self::CONSOLE_SLUG.'/');
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

        ob_start(); ?>
        <style>
        body{background:#f4f6f8!important}.soc-app{min-height:100vh;display:grid;grid-template-columns:250px 1fr;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827}.soc-sidebar{background:#111827;color:#fff;padding:26px 18px;position:sticky;top:0;height:100vh;box-sizing:border-box}.soc-brand{font-size:19px;font-weight:800;padding:0 10px 24px}.soc-brand small{display:block;color:#9ca3af;font-size:11px;font-weight:600;margin-top:4px}.soc-nav a{display:block;color:#cbd5e1;text-decoration:none;padding:11px 12px;border-radius:10px;margin:3px 0;font-size:14px}.soc-nav a:hover,.soc-nav a.active{background:#1f2937;color:#fff}.soc-sidebar-foot{position:absolute;left:18px;right:18px;bottom:22px;border-top:1px solid #374151;padding-top:16px}.soc-sidebar-foot strong,.soc-sidebar-foot span{display:block}.soc-sidebar-foot span{font-size:12px;color:#9ca3af;margin:3px 0 10px}.soc-sidebar-foot a{color:#cbd5e1;font-size:13px}.soc-main{padding:30px}.soc-top{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:25px}.soc-top h1{font-size:29px;margin:0 0 4px}.soc-top p{margin:0;color:#6b7280}.soc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.soc-stat,.soc-panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px}.soc-stat{padding:20px}.soc-stat span{display:block;color:#6b7280;font-size:13px}.soc-stat strong{display:block;font-size:30px;margin-top:7px}.soc-columns{display:grid;grid-template-columns:1.25fr .9fr;gap:18px;margin-top:18px}.soc-panel{padding:21px}.soc-panel h2{font-size:17px;margin:0 0 16px}.soc-row{display:flex;justify-content:space-between;gap:14px;padding:13px 0;border-top:1px solid #f0f1f3}.soc-row:first-of-type{border-top:0}.soc-row-title{font-weight:700;font-size:14px}.soc-meta{font-size:12px;color:#6b7280;margin-top:4px}.soc-badge{height:max-content;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:750;background:#f3f4f6}.soc-empty{color:#6b7280;font-size:14px;padding:8px 0}.soc-task-grid{display:grid;grid-template-columns:340px 1fr;gap:18px}.soc-form label{display:block;font-size:12px;font-weight:700;margin:0 0 6px}.soc-form input,.soc-form select,.soc-form textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d1d5db;border-radius:10px;margin:0 0 13px;background:#fff}.soc-form textarea{min-height:90px;resize:vertical}.soc-btn{border:0;border-radius:10px;background:#111827;color:#fff;padding:10px 14px;font-weight:700;cursor:pointer}.soc-btn-light{background:#eef0f3;color:#111827}.soc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.soc-task{border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:12px}.soc-task-head{display:flex;justify-content:space-between;gap:12px}.soc-task h3{font-size:15px;margin:0}.soc-task p{font-size:13px;color:#4b5563}.soc-inline{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.soc-inline select,.soc-inline input{margin:0}.soc-alert{padding:12px 14px;border-radius:10px;background:#ecfdf5;color:#065f46;margin-bottom:16px}.soc-comments{margin-top:13px;padding-top:12px;border-top:1px solid #eef0f2}.soc-comment{font-size:12px;padding:7px 0}.soc-comment b{display:block}.soc-overdue{color:#b91c1c;font-weight:700}.soc-filters{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px}.soc-filters label{font-size:12px;font-weight:700}.soc-filters select{display:block;margin-top:5px;padding:8px;border:1px solid #d1d5db;border-radius:8px;background:#fff}.soc-filters input{display:block;margin-top:5px;padding:8px;border:1px solid #d1d5db;border-radius:8px;background:#fff}.soc-audit-item{border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:12px}.soc-audit-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.soc-audit-summary{font-weight:750;font-size:14px}.soc-audit-details{margin-top:12px;padding-top:12px;border-top:1px solid #eef0f2;font-size:12px;color:#4b5563}.soc-audit-details code{display:block;white-space:pre-wrap;word-break:break-word;background:#f8fafc;padding:10px;border-radius:8px;margin-top:8px}.soc-audit-count{font-size:13px;color:#6b7280;margin-bottom:12px}.soc-table-wrap{overflow-x:auto}.soc-table{width:100%;border-collapse:collapse}.soc-table th,.soc-table td{text-align:left;padding:13px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;vertical-align:middle}.soc-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280}.soc-partner-profile{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.soc-profile-field{padding:14px;background:#f8fafc;border-radius:12px}.soc-profile-field span{display:block;color:#6b7280;font-size:11px;text-transform:uppercase}.soc-profile-field strong{display:block;margin-top:5px;font-size:14px}@media(max-width:900px){.soc-app{grid-template-columns:1fr}.soc-sidebar{height:auto;position:relative}.soc-sidebar-foot{position:static;margin-top:20px}.soc-main{padding:20px}.soc-grid{grid-template-columns:repeat(2,1fr)}.soc-columns,.soc-task-grid{grid-template-columns:1fr}}@media(max-width:520px){.soc-grid{grid-template-columns:1fr}}
        </style>
        <div class="soc-app"><aside class="soc-sidebar"><div class="soc-brand">Surface Operations<small>Operating the Surface Internet</small></div><nav class="soc-nav">
        <?php $nav=['dashboard'=>'Dashboard','tasks'=>'Tasks','partners'=>'Partners','surfaceteeth'=>'SurfaceTeeth™','advocates'=>'Advocates','campaigns'=>'Campaigns','wallet'=>'Wallet','bundles'=>'Bundles','support'=>'Support','reports'=>'Reports','teams'=>'Teams','staff'=>'Staff','audit'=>'Audit']; foreach($nav as $key=>$label){if(!self::can_access($key,$user->ID))continue;$url=add_query_arg('soc_section',$key,$base_url);echo '<a class="'.($key===$section?'active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';} ?>
        </nav><div class="soc-sidebar-foot"><strong><?php echo esc_html($user->display_name); ?></strong><span><?php echo esc_html($level.' · '.$team); ?></span><a href="<?php echo esc_url($logout_url); ?>">Sign out</a></div></aside><main class="soc-main">
        <?php if($section==='tasks'): ?>
            <div class="soc-top"><div><h1>Tasks</h1><p>Assign, claim and complete operational work.</p></div></div>
            <?php if($task_notice): ?><div class="soc-alert">Task action completed.</div><?php endif; ?>
            <section class="soc-grid" style="margin-bottom:18px"><div class="soc-stat"><span>My Open Tasks</span><strong><?php echo esc_html($task_counts['mine']); ?></strong></div><div class="soc-stat"><span>Team Queue</span><strong><?php echo esc_html($task_counts['team']); ?></strong></div><div class="soc-stat"><span>Due Today</span><strong><?php echo esc_html($task_counts['due_today']); ?></strong></div><div class="soc-stat"><span>Overdue</span><strong><?php echo esc_html($task_counts['overdue']); ?></strong></div></section>
            <section class="soc-task-grid">
                <?php if(self::can_manage_tasks($user->ID)): ?><div class="soc-panel"><h2>Assign Task</h2><form class="soc-form" method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="create"><label>Task</label><input name="task_title" required><label>Description</label><textarea name="task_description"></textarea><label>Module</label><select name="task_module"><?php foreach(['general'=>'General','partners'=>'Partners','surfaceteeth'=>'SurfaceTeeth','advocacy'=>'Advocacy','campaigns'=>'Campaigns','wallet'=>'Wallet','bundles'=>'Bundles','support'=>'Support'] as $k=>$v)echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>'; ?></select><label>Priority</label><select name="task_priority"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select><label>Assign to staff</label><select name="task_user_id"><option value="0">Team queue</option><?php foreach($staff_list as $member){if(self::staff_status($member->ID)==='suspended')continue;echo '<option value="'.esc_attr($member->ID).'">'.esc_html($member->display_name.' · '.self::user_team($member->ID)).'</option>';} ?></select><label>Team</label><select name="task_team"><option value="">Select team</option><?php foreach(self::teams() as $t)echo '<option value="'.esc_attr($t).'">'.esc_html($t).'</option>'; ?></select><label>Due date</label><input type="datetime-local" name="task_due_at"><button class="soc-btn" type="submit">Assign Task</button></form></div><?php endif; ?>
                <div class="soc-panel"><h2><?php echo self::can_manage_tasks($user->ID)?'Operational Tasks':'My Tasks'; ?></h2><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="tasks"><label>Status<select name="task_status"><option value="all">All</option><option value="open" <?php selected($task_filters['status'],'open'); ?>>Open</option><option value="in_progress" <?php selected($task_filters['status'],'in_progress'); ?>>In Progress</option><option value="completed" <?php selected($task_filters['status'],'completed'); ?>>Completed</option></select></label><label>Priority<select name="task_priority"><option value="all">All</option><?php foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($task_filters['priority'],$k,false).'>'.esc_html($v).'</option>'; ?></select></label><?php if(self::can_manage_tasks($user->ID)): ?><label>Team<select name="task_team"><option value="">All teams</option><?php foreach(self::teams() as $t)echo '<option value="'.esc_attr($t).'" '.selected($task_filters['team'],$t,false).'>'.esc_html($t).'</option>'; ?></select></label><label>Staff<select name="task_user_id"><option value="0">All staff</option><?php foreach($staff_list as $member)echo '<option value="'.esc_attr($member->ID).'" '.selected($task_filters['user_id'],$member->ID,false).'>'.esc_html($member->display_name).'</option>'; ?></select></label><?php endif; ?><button class="soc-btn soc-btn-light" type="submit">Filter</button><a class="soc-btn soc-btn-light" style="text-decoration:none" href="<?php echo esc_url(add_query_arg('soc_section','tasks',$base_url)); ?>">Reset</a></form><?php $tasks=self::visible_tasks($user->ID,$team,self::can_manage_tasks($user->ID),$task_filters); if(!$tasks): ?><div class="soc-empty">No tasks found.</div><?php endif; ?><?php foreach($tasks as $task): $comments=self::task_comments($task->id); ?><article class="soc-task"><div class="soc-task-head"><div><h3><?php echo esc_html($task->title); ?></h3><div class="soc-meta"><?php echo esc_html(ucfirst($task->module).' · '.ucwords(str_replace('_',' ',$task->status)).' · '.ucfirst($task->priority)); ?><?php if($task->due_at): ?> · <span class="<?php echo ($task->status!=='completed' && strtotime($task->due_at)<current_time('timestamp'))?'soc-overdue':''; ?>">Due <?php echo esc_html(mysql2date('M j, g:i a',$task->due_at)); ?></span><?php endif; ?></div></div><span class="soc-badge"><?php echo esc_html($task->assigned_user_id?self::staff_name($task->assigned_user_id):($task->assigned_team?:'Unassigned')); ?></span></div><?php if($task->description): ?><p><?php echo nl2br(esc_html($task->description)); ?></p><?php endif; ?><div class="soc-actions"><?php if(self::can_manage_tasks($user->ID)): ?><form class="soc-inline" method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="reassign"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><select name="task_user_id"><option value="0">Team queue</option><?php foreach($staff_list as $member){if(self::staff_status($member->ID)==='suspended')continue;echo '<option value="'.esc_attr($member->ID).'" '.selected((int)$task->assigned_user_id,$member->ID,false).'>'.esc_html($member->display_name).'</option>';} ?></select><select name="task_team"><option value="">No team</option><?php foreach(self::teams() as $t)echo '<option value="'.esc_attr($t).'" '.selected((string)$task->assigned_team,$t,false).'>'.esc_html($t).'</option>'; ?></select><button class="soc-btn soc-btn-light" type="submit">Reassign</button></form><?php endif; ?><?php if(!$task->assigned_user_id && $task->assigned_team===$team): ?><form method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="claim"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><button class="soc-btn" type="submit">Claim</button></form><?php endif; ?><form class="soc-inline" method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="status"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><select name="task_status"><option value="open" <?php selected($task->status,'open'); ?>>Open</option><option value="in_progress" <?php selected($task->status,'in_progress'); ?>>In Progress</option><option value="completed" <?php selected($task->status,'completed'); ?>>Completed</option></select><button class="soc-btn soc-btn-light" type="submit">Update</button></form></div><div class="soc-comments"><?php foreach($comments as $comment): ?><div class="soc-comment"><b><?php echo esc_html(self::staff_name($comment->user_id)); ?></b><?php echo esc_html($comment->comment_text); ?> <span class="soc-meta"><?php echo esc_html(mysql2date('M j, g:i a',$comment->created_at)); ?></span></div><?php endforeach; ?><form class="soc-inline" method="post"><?php wp_nonce_field('surface_operations_task','surface_operations_task_nonce'); ?><input type="hidden" name="surface_operations_task_action" value="comment"><input type="hidden" name="task_id" value="<?php echo esc_attr($task->id); ?>"><input type="text" name="task_comment" placeholder="Add internal comment" required><button class="soc-btn soc-btn-light" type="submit">Comment</button></form></div></article><?php endforeach; ?></div>
            </section>
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
            <section class="soc-panel"><form class="soc-filters" method="get"><input type="hidden" name="soc_section" value="partners"><label style="flex:1;min-width:240px">Search<input style="width:100%" type="search" name="partner_search" value="<?php echo esc_attr($partner_search); ?>" placeholder="Search Partner Name, SII or Email"></label><button class="soc-btn" type="submit">Search</button><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg('soc_section','partners',$base_url)); ?>">Reset</a></form><div class="soc-table-wrap"><table class="soc-table"><thead><tr><th>Partner</th><th>SII</th><th>SurfaceTeeth</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead><tbody><?php if(!$partners): ?><tr><td colspan="6" class="soc-empty">No partners found.</td></tr><?php endif; ?><?php foreach($partners as $partner): $ps=self::partner_status($partner->ID); $store=(string)get_user_meta($partner->ID,'surface_store',true); ?><tr><td><strong><?php echo esc_html($store ?: $partner->display_name); ?></strong><div class="soc-meta"><?php echo esc_html($partner->user_email); ?></div></td><td>/<?php echo esc_html(self::partner_sii($partner->ID) ?: '—'); ?></td><td><?php echo esc_html(self::partner_surfaceteeth_count($partner->ID)); ?></td><td><span class="soc-badge"><?php echo esc_html(ucfirst($ps)); ?></span></td><td><?php echo esc_html(mysql2date('M j, Y',$partner->user_registered)); ?></td><td><div class="soc-actions"><a class="soc-btn soc-btn-light" href="<?php echo esc_url(add_query_arg(['soc_section'=>'partners','view_partner'=>$partner->ID],$base_url)); ?>">View</a><form method="post"><?php wp_nonce_field('surface_operations_partner','surface_operations_partner_nonce'); ?><input type="hidden" name="partner_id" value="<?php echo esc_attr($partner->ID); ?>"><input type="hidden" name="surface_operations_partner_action" value="<?php echo esc_attr($ps==='suspended'?'reactivate':'suspend'); ?>"><button class="soc-btn <?php echo $ps==='suspended'?'':'soc-btn-light'; ?>" type="submit"><?php echo esc_html($ps==='suspended'?'Reactivate':'Suspend'); ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section>
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
                    <details class="soc-audit-details"><summary>View details</summary><div><strong>Object:</strong> <?php echo esc_html(($entry->object_type ?: 'system').($entry->object_id ? ' #'.$entry->object_id : '')); ?></div><div><strong>IP address:</strong> <?php echo esc_html($entry->ip_address ?: 'Not recorded'); ?></div><?php if($context): ?><code><?php echo esc_html($context); ?></code><?php else: ?><div>No additional context recorded.</div><?php endif; ?></details></article>
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
        if (json_last_error() === JSON_ERROR_NONE && !empty($decoded)) {
            return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return sanitize_textarea_field((string) $context);
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
