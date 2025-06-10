<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-user-groups-base.php';

class HWP_User_Groups_Profile extends HWP_User_Groups_Base
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Extends the base add_hooks method with user profile specific hooks.
     */
    protected function add_hooks()
    {
        parent::add_hooks(); // Call parent to register post type

        // Custom User Profile Fields for Groups
        add_action('show_user_profile', array($this, 'hwp_user_groups_profile_fields'), 1);
        add_action('edit_user_profile', array($this, 'hwp_user_groups_profile_fields'), 1);

        // Save Groups when User Profile is updated
        add_action('personal_options_update', array($this, 'hwp_user_groups_profile_save'));
        add_action('edit_user_profile_update', array($this, 'hwp_user_groups_profile_save'));

        add_action('wp_ajax_hwp_update_user_groups', array($this, 'hwp_update_user_groups_ajax'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets')); // Enqueue for profile screen
    }

    /**
     * Enqueues admin scripts and styles for user profile pages.
     * This method is duplicated for clarity, could be combined if desired
     * but this keeps profile assets separate from group post assets.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_assets($hook)
    {
        $is_user_profile_screen = ($hook === 'user-edit.php' || $hook === 'profile.php');

        if ($is_user_profile_screen) {
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), null, true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');

            wp_enqueue_script('hwp_user_groups_admin', plugins_url('includes/assets/admin-user-groups.js', dirname(__FILE__, 2)), array('jquery', 'select2', 'jquery-ui-sortable'), null, true);
            wp_enqueue_style('hwp_user_groups_admin_css', plugins_url('includes/assets/admin-user-groups.css', dirname(__FILE__, 2)));

            wp_localize_script('hwp_user_groups_admin', 'hwp_user_groups_profile_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('hwp_user_groups_profile_nonce_ajax')
            ));
        }
    }


    /**
     * Displays custom user group fields on the user profile edit screen.
     *
     * @param WP_User $user The user object.
     */
    public function hwp_user_groups_profile_fields($user)
    {
        wp_nonce_field('hwp_user_groups_profile_update', 'hwp_user_groups_profile_nonce');

        $user_id = $user->ID;

        echo '<h3>User Groups</h2>';
        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';

        foreach ($this->user_get_custom_fields() as $field) {
            echo '<tr>';
            echo '<th><label for="' . esc_attr($field['user_name']) . '">' . esc_html($field['user_label']) . '</label></th>';
            echo '<td>';

            // Special handling for 'author_groups' field
            if ($field['type'] === 'author_groups') {
                $authored_groups = get_posts(array(
                    'author'         => $user_id,
                    'post_type'      => $this->post_type,
                    'posts_per_page' => -1, // Get all
                    'post_status'    => 'publish',
                    'fields'         => array('ID', 'post_title'),
                ));
                if (!empty($authored_groups)) {
                    echo '<ul>';
                    foreach ($authored_groups as $group) {
                        echo '<li><a href="' . get_edit_post_link($group->ID) . '">' . esc_html($group->post_title) . '</a> (ID: ' . esc_html($group->ID) . ')</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p>No groups authored by this user.</p>';
                }
            } else {
                // Existing select2 fields for group assignments
                echo '<div class="regular-text"><select class="hwp-user-group-select" name="' . esc_attr($field['user_name']) . '[]" multiple="multiple" data-field-name="' . esc_attr($field['user_name']) . '" style="width: 100%;">';

                // Populate selected groups for the user
                $selected_groups_for_user = get_user_meta($user_id, $field['user_name'], true);
                $selected_groups_for_user = is_array($selected_groups_for_user) ? $selected_groups_for_user : [];

                foreach ($selected_groups_for_user as $group_id) {
                    $group = get_post($group_id);
                    if ($group && $group->post_status === 'publish') {
                        echo '<option value="' . esc_attr($group->ID) . '" selected>' . esc_html($group->post_title) . '</option>';
                    }
                }
                echo '</select></div>';
            }

            // echo '<small>field name: <code>' . esc_html($field['user_name']) . '</code>';
            // if ($field['type'] !== 'author_groups') {
            //     echo '<br/>Value: <code>' . esc_html(print_r(get_user_meta($user_id, $field['user_name'], true), true)) . '</code>';
            // }
            // echo '</small>';

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '<input type="hidden" id="hwp_user_id" value="' . esc_attr($user->ID) . '"></div>';
    }

    /**
     * Saves custom user group fields from the user profile edit screen.
     *
     * @param int $user_id The ID of the user being updated.
     */
    public function hwp_user_groups_profile_save($user_id)
    {
        // Nonce verification for security
        if (!isset($_POST['hwp_user_groups_profile_nonce']) || !wp_verify_nonce($_POST['hwp_user_groups_profile_nonce'], 'hwp_user_groups_profile_update')) {
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        $user_obj = get_user_by('ID', $user_id);
        if (!$user_obj) {
            return;
        }
        $user_email = $user_obj->user_email;

        $user_group_fields = $this->user_get_custom_fields();

        foreach ($user_group_fields as $field) {
            if ($field['type'] === 'author_groups') {
                continue;
            }

            $user_meta_key = $field['user_name'];
            $group_meta_key = $field['id'];

            $new_selected_groups_for_user = isset($_POST[$user_meta_key]) ? array_map('intval', (array)$_POST[$user_meta_key]) : [];

            $old_selected_groups_for_user = get_user_meta($user_id, $user_meta_key, true);
            $old_selected_groups_for_user = is_array($old_selected_groups_for_user) ? $old_selected_groups_for_user : [];

            update_user_meta($user_id, $user_meta_key, array_values($new_selected_groups_for_user));

            $groups_added_to_user = array_diff($new_selected_groups_for_user, $old_selected_groups_for_user);
            $groups_removed_from_user = array_diff($old_selected_groups_for_user, $new_selected_groups_for_user);

            foreach ($groups_added_to_user as $group_id) {
                $group_post = get_post($group_id);
                if (!$group_post || $group_post->post_status !== 'publish') {
                    continue;
                }

                $current_group_data = get_post_meta($group_id, $group_meta_key, true);
                $current_group_data = is_array($current_group_data) ? $current_group_data : [];

                if ($group_meta_key === 'invitations') {
                    if (!in_array($user_email, $current_group_data)) {
                        $current_group_data[] = $user_email;
                        update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                        // Sending email will be handled by the invitation class
                        // $this->send_group_invitation_email_to_user($user_id, $group_id);
                    }
                } else {
                    if (!in_array($user_id, $current_group_data)) {
                        $current_group_data[] = $user_id;
                        update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                    }
                }
            }

            foreach ($groups_removed_from_user as $group_id) {
                $group_post = get_post($group_id);
                if (!$group_post || $group_post->post_status !== 'publish') {
                    continue;
                }

                $current_group_data = get_post_meta($group_id, $group_meta_key, true);
                $current_group_data = is_array($current_group_data) ? $current_group_data : [];

                if ($group_meta_key === 'invitations') {
                    if (($key = array_search($user_email, $current_group_data)) !== false) {
                        unset($current_group_data[$key]);
                        update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                    }
                } else {
                    if (($key = array_search($user_id, $current_group_data)) !== false) {
                        unset($current_group_data[$key]);
                        update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                    }
                    // If a user is removed from 'members' via profile, ensure they are also removed from 'requests' if they were there
                    if ($group_meta_key === 'members') {
                        $group_meta_requests = (array)get_post_meta($group_id, 'requests', true);
                        if (($key_req = array_search($user_id, $group_meta_requests)) !== false) {
                            unset($group_meta_requests[$key_req]);
                            update_post_meta($group_id, 'requests', array_values($group_meta_requests));
                        }
                    }
                }
            }
        }
    }

    /**
     * AJAX callback to update user groups from the user profile screen.
     */
    public function hwp_update_user_groups_ajax()
    {
        if (!current_user_can('edit_users')) {
            wp_send_json_error('Unauthorized. You do not have permission to edit users.');
        }

        check_ajax_referer('hwp_user_groups_profile_nonce_ajax', 'nonce');

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $user_meta_key_from_js = isset($_POST['meta_key']) ? sanitize_text_field($_POST['meta_key']) : '';
        $group_ids = isset($_POST['group_ids']) ? array_map('intval', (array)$_POST['group_ids']) : array();

        if (empty($user_id) || empty($user_meta_key_from_js)) {
            wp_send_json_error('Missing user ID or meta key.');
        }

        $user_group_fields = $this->user_get_custom_fields();

        $field_definition = null;
        foreach ($user_group_fields as $field) {
            if ($field['user_name'] === $user_meta_key_from_js) {
                $field_definition = $field;
                break;
            }
        }

        if (empty($field_definition)) {
            wp_send_json_error('Invalid meta key definition.');
        }

        if ($field_definition['type'] === 'author_groups') {
            wp_send_json_error('Authored groups cannot be directly updated via AJAX.');
        }

        $group_meta_key = $field_definition['id'];

        $old_selected_groups_for_user = get_user_meta($user_id, $user_meta_key_from_js, true);
        $old_selected_groups_for_user = is_array($old_selected_groups_for_user) ? $old_selected_groups_for_user : [];

        update_user_meta($user_id, $user_meta_key_from_js, array_values($group_ids));

        $groups_added_to_user = array_diff($group_ids, $old_selected_groups_for_user);
        $groups_removed_from_user = array_diff($old_selected_groups_for_user, $group_ids);

        $user_obj = get_user_by('ID', $user_id);
        $user_email = $user_obj ? $user_obj->user_email : '';

        foreach ($groups_added_to_user as $group_id) {
            $group_post = get_post($group_id);
            if (!$group_post || $group_post->post_status !== 'publish') {
                continue;
            }

            $current_group_data = get_post_meta($group_id, $group_meta_key, true);
            $current_group_data = is_array($current_group_data) ? $current_group_data : [];

            if ($group_meta_key === 'invitations') {
                if (!in_array($user_email, $current_group_data)) {
                    $current_group_data[] = $user_email;
                    update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                    // Sending email will be handled by the invitation class
                    // $this->send_group_invitation_email_to_user($user_id, $group_id);
                }
            } else {
                if (!in_array($user_id, $current_group_data)) {
                    $current_group_data[] = $user_id;
                    update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                }
            }
        }

        foreach ($groups_removed_from_user as $group_id) {
            $group_post = get_post($group_id);
            if (!$group_post || $group_post->post_status !== 'publish') {
                continue;
            }

            $current_group_data = get_post_meta($group_id, $group_meta_key, true);
            $current_group_data = is_array($current_group_data) ? $current_group_data : [];

            if ($group_meta_key === 'invitations') {
                if (($key = array_search($user_email, $current_group_data)) !== false) {
                    unset($current_group_data[$key]);
                    update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                }
            } else {
                if (($key = array_search($user_id, $current_group_data)) !== false) {
                    unset($current_group_data[$key]);
                    update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                }
                // If a user is removed from 'members' via AJAX, ensure they are also removed from 'requests' if they were there
                if ($group_meta_key === 'members') {
                    $group_meta_requests = (array)get_post_meta($group_id, 'requests', true);
                    if (($key_req = array_search($user_id, $group_meta_requests)) !== false) {
                        unset($group_meta_requests[$key_req]);
                        update_post_meta($group_id, 'requests', array_values($group_meta_requests));
                    }
                }
            }
        }

        wp_send_json_success('User groups updated via AJAX');
    }
}