<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-user-groups-base.php';

class HWP_User_Groups_Admin extends HWP_User_Groups_Base
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Extends the base add_hooks method with admin-specific hooks.
     */
    protected function add_hooks()
    {
        parent::add_hooks(); // Call parent to register post type

        add_action('add_meta_boxes', array($this, 'user_groups_add_custom_fields_meta_box'));
        add_action('save_post_' . $this->post_type, array($this, 'user_groups_save_custom_fields'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX hooks for user and group search
        add_action('wp_ajax_hwp_user_search', array($this, 'ajax_user_search'));
        add_action('wp_ajax_hwp_group_search', array($this, 'ajax_group_search'));
    }

    /**
     * Enqueues admin scripts and styles for relevant pages.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_assets($hook)
    {
        // Check if on a post edit/new page for 'user-groups'
        $is_group_edit_screen = ($hook === 'post.php' || $hook === 'post-new.php') && get_current_screen()->post_type === $this->post_type;

        if ($is_group_edit_screen) {
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), null, true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');

            wp_enqueue_script('hwp_user_groups_admin', plugins_url('includes/assets/admin-user-groups.js', dirname(__FILE__, 2)), array('jquery', 'select2', 'jquery-ui-sortable'), null, true);
            wp_enqueue_style('hwp_user_groups_admin_css', plugins_url('includes/assets/admin-user-groups.css', dirname(__FILE__, 2)));

            // Localize scripts with AJAX URLs and nonces
            wp_localize_script('hwp_user_groups_admin', 'HWP_User_Groups', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('hwp_user_groups_nonce'),
            ));
        }
    }

    /**
     * Adds the custom fields meta box to the User Group edit screen.
     */
    public function user_groups_add_custom_fields_meta_box()
    {
        add_meta_box(
            'user_groups_custom_fields',
            'User Group Details',
            array($this, 'user_groups_render_custom_fields_meta_box'),
            $this->post_type,
            'normal',
            'default'
        );
    }

    /**
     * Renders the custom fields meta box content for User Groups.
     *
     * @param WP_Post $post The current post object.
     */
    public function user_groups_render_custom_fields_meta_box($post)
    {
        $fields = $this->user_groups_get_custom_fields();

        wp_nonce_field('user_groups_save_meta', 'user_groups_meta_nonce');

        foreach ($fields as $field) {
            // Skip the 'author_groups' field in the group edit screen, as it's for user profiles
            if ($field['type'] === 'author_groups') {
                continue;
            }

            $id = $field['id'];
            $value = get_post_meta($post->ID, $id, true);
            if (empty($value) && !empty($field['default'])) {
                $value = $field['default'];
            }

            echo '<p><label for="' . esc_attr($id) . '"><strong>' . esc_html($field['label']) . '</strong></label><br>';
            if (!empty($field['description'])) {
                echo '<span style="opacity:0.6;">' . esc_html($field['description']) . '</span></p>';
            }

            switch ($field['type']) {
                case 'user_multiselect':
                    $value = is_array($value) ? $value : array();

                    echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '[]" multiple class="hwp-user-select" data-field-id="' . esc_attr($id) . '" style="width:100%;">';

                    foreach ($value as $user_id) {
                        $user = get_user_by('ID', $user_id);
                        if ($user) {
                            echo '<option value="' . esc_attr($user->ID) . '" selected>' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</option>';
                        }
                    }

                    echo '</select>';
                    break;

                case 'text':
                    // Ensure invitations field always displays comma-separated emails for editing
                    if ($id === 'invitations' && is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    echo '<input type="text" name="' . esc_attr($id) . '" value="' . esc_attr($value) . '" style="width:100%;">';
                    break;
            }

            echo '<hr>';
        }
    }

    /**
     * Saves the custom fields for User Groups post type.
     *
     * @param int $post_id The ID of the post being saved.
     */
    public function user_groups_save_custom_fields($post_id)
    {
        // Nonce verification for security
        if (!isset($_POST['user_groups_meta_nonce']) || !wp_verify_nonce($_POST['user_groups_meta_nonce'], 'user_groups_save_meta')) {
            return;
        }

        // Check for autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = $this->user_groups_get_custom_fields();

        foreach ($fields as $field) {
            // Skip the 'author_groups' field as it's not directly saved from the group post meta
            if ($field['type'] === 'author_groups') {
                continue;
            }

            $id = $field['id'];
            $user_meta_key = $field['user_name']; // user_meta key for this group field

            if ($field['type'] === 'user_multiselect') {
                $old_values = get_post_meta($post_id, $id, true);
                $old_values = is_array($old_values) ? $old_values : [];

                $new_values = isset($_POST[$id]) && is_array($_POST[$id]) ? array_map('intval', $_POST[$id]) : [];

                // Update post meta
                update_post_meta($post_id, $id, $new_values);

                // --- Syncing to User Meta ---
                $users_to_add = array_diff($new_values, $old_values);
                foreach ($users_to_add as $user_id_to_add) {
                    $current_user_groups = get_user_meta($user_id_to_add, $user_meta_key, true);
                    if (!is_array($current_user_groups)) {
                        $current_user_groups = [];
                    }
                    if (!in_array($post_id, $current_user_groups)) {
                        $current_user_groups[] = $post_id;
                        update_user_meta($user_id_to_add, $user_meta_key, array_values($current_user_groups));
                    }
                }

                $users_to_remove = array_diff($old_values, $new_values);
                foreach ($users_to_remove as $user_id_to_remove) {
                    $current_user_groups = get_user_meta($user_id_to_remove, $user_meta_key, true);
                    if (is_array($current_user_groups)) {
                        if (($key = array_search($post_id, $current_user_groups)) !== false) {
                            unset($current_user_groups[$key]);
                            update_user_meta($user_id_to_remove, $user_meta_key, array_values($current_user_groups));
                        }
                    }
                }
            } elseif ($field['type'] === 'text' && $id === 'invitations') {
                $old_emails = get_post_meta($post_id, $id, true);
                $old_emails = is_array($old_emails) ? $old_emails : [];

                $raw_value = isset($_POST[$id]) ? $_POST[$id] : '';
                $new_emails = array_unique(array_filter(array_map('sanitize_email', array_map('trim', explode(',', $raw_value))), 'is_email'));

                update_post_meta($post_id, $id, $new_emails);

                // --- Syncing to User Meta (for invitations) ---
                $emails_to_add = array_diff($new_emails, $old_emails);
                foreach ($emails_to_add as $email_to_add) {
                    $user = get_user_by('email', $email_to_add);
                    if ($user) {
                        $user_id = $user->ID;
                        $user_invitations = get_user_meta($user_id, $user_meta_key, true);
                        if (!is_array($user_invitations)) {
                            $user_invitations = [];
                        }
                        if (!in_array($post_id, $user_invitations, true)) {
                            $user_invitations[] = $post_id;
                            update_user_meta($user_id, $user_meta_key, array_values($user_invitations));
                            // This part of sending email will be moved to the invitation handler class
                            // $this->send_group_invitation_email_to_user($user_id, $post_id);
                        }
                    } else {
                        // This part of sending email will be moved to the invitation handler class
                        // $this->send_group_invitation_email_to_email($email_to_add, $post_id);
                    }
                }

                $emails_to_remove = array_diff($old_emails, $new_emails);
                foreach ($emails_to_remove as $email_to_remove) {
                    $user = get_user_by('email', $email_to_remove);
                    if ($user) {
                        $user_id = $user->ID;
                        $user_invitations = get_user_meta($user_id, $user_meta_key, true);
                        if (is_array($user_invitations)) {
                            if (($key = array_search($post_id, $user_invitations, true)) !== false) {
                                unset($user_invitations[$key]);
                                update_user_meta($user_id, $user_meta_key, array_values($user_invitations));
                            }
                        }
                    }
                }
            } elseif ($field['type'] === 'text') {
                $value = isset($_POST[$id]) ? $_POST[$id] : '';
                if (is_array($value)) {
                    $value = implode(', ', array_map('sanitize_text_field', $value));
                } else {
                    $value = sanitize_text_field($value);
                }
                update_post_meta($post_id, $id, $value);
            }
        }
    }

    /**
     * AJAX callback to search for users.
     */
    public function ajax_user_search()
    {
        check_ajax_referer('hwp_user_groups_nonce', 'nonce');

        if (!current_user_can('list_users')) {
            wp_send_json_error('You do not have permission to search users.');
        }

        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $users = get_users(array(
            'search'         => '*' . esc_attr($search) . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'number'         => 20,
            'fields'         => array('ID', 'display_name', 'user_email'),
        ));

        $results = array_map(function ($user) {
            return array('id' => $user->ID, 'text' => $user->display_name . ' (' . $user->user_email . ')');
        }, $users);

        wp_send_json($results);
    }

    /**
     * AJAX callback to search for user groups.
     */
    public function ajax_group_search()
    {
        check_ajax_referer('hwp_user_groups_profile_nonce_ajax', 'nonce');

        if (!current_user_can('edit_users')) { // This is called from user profile, so edit_users permission is appropriate
            wp_send_json_error('You do not have permission to search groups.');
        }

        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $groups = get_posts(array(
            'post_type'      => $this->post_type,
            's'              => $search,
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'fields'         => array('ID', 'post_title'),
        ));

        $results = array_map(function ($group) {
            return array('id' => $group->ID, 'text' => $group->post_title);
        }, $groups);

        wp_send_json($results);
    }
}