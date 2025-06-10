<?php

class HWP_User_Groups
{
    /**
     * Option key for headless WP settings.
     * @var string
     */
    private $settings_option_key = 'headless_wp_settings';

    /**
     * Post type slug for user groups.
     * @var string
     */
    private $post_type = 'user-groups';

    /**
     * Constructor.
     */
    public function __construct()
    {
        $hwp_settings = get_option($this->settings_option_key);
        $user_groups_enabled = isset($hwp_settings['hwp_user_groups']) && $hwp_settings['hwp_user_groups'];

        if ($user_groups_enabled) {
            $this->add_hooks();
        }
    }

    /**
     * Adds all necessary WordPress hooks.
     * Encapsulates hook additions for better readability.
     */
    private function add_hooks()
    {
        add_action('init', array($this, 'register_user_groups_post_type'));
        add_action('add_meta_boxes', array($this, 'user_groups_add_custom_fields_meta_box'));
        add_action('save_post_' . $this->post_type, array($this, 'user_groups_save_custom_fields'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX hooks for user and group search
        add_action('wp_ajax_hwp_user_search', array($this, 'ajax_user_search'));
        add_action('wp_ajax_hwp_group_search', array($this, 'ajax_group_search'));
        add_action('wp_ajax_hwp_update_user_groups', array($this, 'hwp_update_user_groups_ajax'));

        // Custom User Profile Fields for Groups
        add_action('show_user_profile', array($this, 'hwp_user_groups_profile_fields'), 1);
        add_action('edit_user_profile', array($this, 'hwp_user_groups_profile_fields'), 1);

        // Save Groups when User Profile is updated
        add_action('personal_options_update', array($this, 'hwp_user_groups_profile_save'));
        add_action('edit_user_profile_update', array($this, 'hwp_user_groups_profile_save'));

        // REST API Hooks
        add_action('rest_api_init', array($this, 'register_group_meta_for_rest_api'));
        add_action('rest_api_init', array($this, 'register_user_group_fields_for_rest_api'));
        add_action('rest_api_init', array($this, 'register_invitation_api_endpoint')); // New REST API endpoint
    }

    /**
     * Registers the custom post type for User Groups.
     */
    public function register_user_groups_post_type()
    {
        $labels = array(
            'name'                => 'User Groups',
            'singular_name'       => 'User Group',
            'menu_name'           => 'User Groups',
            'name_admin_bar'      => 'User Group',
            'add_new'             => 'Add New',
            'add_new_item'        => 'Add New User Group',
            'new_item'            => 'New User Group',
            'edit_item'           => 'Edit User Group',
            'view_item'           => 'View User Group',
            'all_items'           => 'Groups',
            'search_items'        => 'Search User Groups',
            'parent_item_colon'   => 'Parent User Groups:',
            'not_found'           => 'No user groups found.',
            'not_found_in_trash'  => 'No user groups found in Trash.'
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => 'users.php',
            'show_in_rest'        => true,
            'supports'            => array('title', 'editor', 'author', 'thumbnail', 'excerpt'),
            'has_archive'         => false,
            'rewrite'             => false,
            'menu_icon'           => 'dashicons-groups',
        );

        register_post_type($this->post_type, $args);
    }

    /**
     * Enqueues admin scripts and styles for relevant pages.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_assets($hook)
    {
        // Check if on a post edit/new page for 'user-groups' or user profile page
        $is_group_edit_screen = ($hook === 'post.php' || $hook === 'post-new.php') && get_current_screen()->post_type === $this->post_type;
        $is_user_profile_screen = ($hook === 'user-edit.php' || $hook === 'profile.php');

        if ($is_group_edit_screen || $is_user_profile_screen) {
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), null, true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');

            wp_enqueue_script('hwp_user_groups_admin', plugins_url('includes/assets/admin-user-groups.js', __FILE__), array('jquery', 'select2', 'jquery-ui-sortable'), null, true);
            wp_enqueue_style('hwp_user_groups_admin_css', plugins_url('includes/assets/admin-user-groups.css', __FILE__));

            // Localize scripts with AJAX URLs and nonces
            wp_localize_script('hwp_user_groups_admin', 'HWP_User_Groups', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('hwp_user_groups_nonce'),
            ));

            wp_localize_script('hwp_user_groups_admin', 'hwp_user_groups_profile_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('hwp_user_groups_profile_nonce_ajax')
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
     * Defines the custom fields for User Groups.
     *
     * @return array Array of field definitions.
     */
    public function user_groups_get_custom_fields()
    {
        return array(
            // New field for groups authored by the user (read-only in profile, derived)
            array(
                'type' => 'author_groups',
                'id' => 'authored_groups', // Post meta key for the group (not directly used here)
                'label' => 'Authored Groups',
                'user_label' => 'Author', // Label for user profile field
                'user_name' => 'group_author', // User meta key or REST API field name
                'description' => 'Groups for which this user is the author.',
                'default' => array(),
            ),
            array(
                'type' => 'user_multiselect',
                'id' => 'admins',
                'label' => 'Group administrators',
                'user_label' => 'Administrator',
                'user_name' => 'group_admin',
                'description' => 'Select users who are administrators of this group.',
                'default' => array(),
            ),
            array(
                'type' => 'user_multiselect',
                'id' => 'members',
                'label' => 'Group members',
                'user_label' => 'Member',
                'user_name' => 'group_member',
                'description' => 'Select users who are members of this group.',
                'default' => array(),
            ),
            array(
                'type' => 'user_multiselect',
                'id' => 'requests',
                'label' => 'Member requests',
                'user_label' => 'Requests',
                'user_name' => 'group_requests',
                'description' => 'Users who have requested to join this group.',
                'default' => array(),
            ),
            array(
                'type' => 'text',
                'id' => 'invitations',
                'label' => 'Invitations',
                'user_label' => 'Invitations',
                'user_name' => 'group_invitations',
                'description' => 'Users who are invited to join this group (enter comma-separated emails).',
                'default' => array(),
            ),
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

                // 1. Add new users to their user_meta
                $users_to_add = array_diff($new_values, $old_values);
                foreach ($users_to_add as $user_id_to_add) {
                    $current_user_groups = get_user_meta($user_id_to_add, $user_meta_key, true);
                    if (!is_array($current_user_groups)) {
                        $current_user_groups = [];
                    }
                    if (!in_array($post_id, $current_user_groups)) {
                        $current_user_groups[] = $post_id;
                        update_user_meta($user_id_to_add, $user_meta_key, array_values($current_user_groups)); // Reindex array
                    }
                }

                // 2. Remove old users from their user_meta
                $users_to_remove = array_diff($old_values, $new_values);
                foreach ($users_to_remove as $user_id_to_remove) {
                    $current_user_groups = get_user_meta($user_id_to_remove, $user_meta_key, true);
                    if (is_array($current_user_groups)) {
                        if (($key = array_search($post_id, $current_user_groups)) !== false) {
                            unset($current_user_groups[$key]);
                            update_user_meta($user_id_to_remove, $user_meta_key, array_values($current_user_groups)); // Reindex array
                        }
                    }
                }
            } elseif ($field['type'] === 'text' && $id === 'invitations') {
                $old_emails = get_post_meta($post_id, $id, true);
                $old_emails = is_array($old_emails) ? $old_emails : [];

                $raw_value = isset($_POST[$id]) ? $_POST[$id] : '';
                // Sanitize and filter emails, ensure uniqueness
                $new_emails = array_unique(array_filter(array_map('sanitize_email', array_map('trim', explode(',', $raw_value))), 'is_email'));

                // Update post meta
                update_post_meta($post_id, $id, $new_emails);

                // --- Syncing to User Meta (for invitations) ---

                // 1. Add new emails to their user_meta and send invitation email
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
                            update_user_meta($user_id, $user_meta_key, array_values($user_invitations)); // Reindex array
                            $this->send_group_invitation_email_to_user($user_id, $post_id); // Send email to existing user
                        }
                    } else {
                        // User does not exist, send invitation to register and join
                        $this->send_group_invitation_email_to_email($email_to_add, $post_id);
                    }
                }

                // 2. Remove old emails from their user_meta
                $emails_to_remove = array_diff($old_emails, $new_emails);
                foreach ($emails_to_remove as $email_to_remove) {
                    $user = get_user_by('email', $email_to_remove);
                    if ($user) {
                        $user_id = $user->ID;
                        $user_invitations = get_user_meta($user_id, $user_meta_key, true);
                        if (is_array($user_invitations)) {
                            if (($key = array_search($post_id, $user_invitations, true)) !== false) {
                                unset($user_invitations[$key]);
                                update_user_meta($user_id, $user_meta_key, array_values($user_invitations)); // Reindex array
                            }
                        }
                    }
                }
            } elseif ($field['type'] === 'text') {
                // For other text fields, just save the value directly to post meta
                $value = isset($_POST[$id]) ? $_POST[$id] : '';
                if (is_array($value)) {
                    // This case should ideally not happen for single text fields, but added as fallback
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

        // Check if the user has permission to edit users
        if (!current_user_can('list_users')) {
            wp_send_json_error('You do not have permission to search users.');
        }

        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $users = get_users(array(
            'search'         => '*' . esc_attr($search) . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'number'         => 20,
            'fields'         => array('ID', 'display_name', 'user_email'), // Optimize fields retrieved
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

        // Check if the user has permission to edit users (as this is from user profile)
        if (!current_user_can('edit_users')) {
            wp_send_json_error('You do not have permission to search groups.');
        }

        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $groups = get_posts(array(
            'post_type'      => $this->post_type,
            's'              => $search,
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'fields'         => array('ID', 'post_title'), // Optimize fields retrieved
        ));

        $results = array_map(function ($group) {
            return array('id' => $group->ID, 'text' => $group->post_title);
        }, $groups);

        wp_send_json($results);
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

            echo '<small>field name: <code>' . esc_html($field['user_name']) . '</code>';
            if ($field['type'] !== 'author_groups') { // Only show raw value for fields stored in user meta
                echo '<br/>Value: <code>' . esc_html(print_r(get_user_meta($user_id, $field['user_name'], true), true)) . '</code>';
            }
            echo '</small>';

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
            return; // User not found
        }
        $user_email = $user_obj->user_email;

        $user_group_fields = $this->user_get_custom_fields();

        foreach ($user_group_fields as $field) {
            // Skip 'author_groups' as it's not a field directly saved/synced via user meta
            if ($field['type'] === 'author_groups') {
                continue;
            }

            $user_meta_key = $field['user_name']; // e.g., 'group_admin', 'group_member'
            $group_meta_key = $field['id'];      // e.g., 'admins', 'members'

            // Get the new selection of groups for this user field from the POST data
            $new_selected_groups_for_user = isset($_POST[$user_meta_key]) ? array_map('intval', (array)$_POST[$user_meta_key]) : [];

            // Get the old selection of groups for this user field from user meta
            $old_selected_groups_for_user = get_user_meta($user_id, $user_meta_key, true);
            $old_selected_groups_for_user = is_array($old_selected_groups_for_user) ? $old_selected_groups_for_user : [];

            // Update user meta for the current field
            update_user_meta($user_id, $user_meta_key, array_values($new_selected_groups_for_user)); // Reindex array

            // Determine which groups were added and which were removed for this user
            $groups_added_to_user = array_diff($new_selected_groups_for_user, $old_selected_groups_for_user);
            $groups_removed_from_user = array_diff($old_selected_groups_for_user, $new_selected_groups_for_user);

            // Sync these changes to the corresponding group post meta
            foreach ($groups_added_to_user as $group_id) {
                // Fetch group data to ensure it exists and is published
                $group_post = get_post($group_id);
                if (!$group_post || $group_post->post_status !== 'publish') {
                    continue; // Skip if group doesn't exist or is not published
                }

                $current_group_data = get_post_meta($group_id, $group_meta_key, true);
                $current_group_data = is_array($current_group_data) ? $current_group_data : [];

                if ($group_meta_key === 'invitations') {
                    if (!in_array($user_email, $current_group_data)) {
                        $current_group_data[] = $user_email;
                        update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                        $this->send_group_invitation_email_to_user($user_id, $group_id); // Send invitation email to user
                    }
                } else {
                    if (!in_array($user_id, $current_group_data)) {
                        $current_group_data[] = $user_id;
                        update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                    }
                }
            }

            foreach ($groups_removed_from_user as $group_id) {
                 // Fetch group data to ensure it exists and is published
                $group_post = get_post($group_id);
                if (!$group_post || $group_post->post_status !== 'publish') {
                    continue; // Skip if group doesn't exist or is not published
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
                }
            }
        }
    }

    /**
     * Returns the custom fields relevant for user profiles.
     *
     * @return array Array of field definitions.
     */
    public function user_get_custom_fields()
    {
        return $this->user_groups_get_custom_fields();
    }

    /**
     * AJAX callback to update user groups from the user profile screen.
     */
    public function hwp_update_user_groups_ajax()
    {
        // Check user permissions for editing users
        if (!current_user_can('edit_users')) {
            wp_send_json_error('Unauthorized. You do not have permission to edit users.');
        }

        // Nonce verification
        check_ajax_referer('hwp_user_groups_profile_nonce_ajax', 'nonce'); // Adjusted nonce for profile AJAX

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

        // The 'author_groups' field is not directly editable via AJAX, it's derived
        if ($field_definition['type'] === 'author_groups') {
            wp_send_json_error('Authored groups cannot be directly updated via AJAX.');
        }

        $group_meta_key = $field_definition['id'];

        // Get old user meta values to compare
        $old_selected_groups_for_user = get_user_meta($user_id, $user_meta_key_from_js, true);
        $old_selected_groups_for_user = is_array($old_selected_groups_for_user) ? $old_selected_groups_for_user : [];

        // Update the user's meta with the selected groups
        update_user_meta($user_id, $user_meta_key_from_js, array_values($group_ids)); // Reindex array

        // Determine which groups were added and which were removed for this user via AJAX
        $groups_added_to_user = array_diff($group_ids, $old_selected_groups_for_user);
        $groups_removed_from_user = array_diff($old_selected_groups_for_user, $group_ids);

        $user_obj = get_user_by('ID', $user_id);
        $user_email = $user_obj ? $user_obj->user_email : '';


        // Sync changes to the corresponding group post meta
        foreach ($groups_added_to_user as $group_id) {
            // Fetch group data to ensure it exists and is published
            $group_post = get_post($group_id);
            if (!$group_post || $group_post->post_status !== 'publish') {
                continue; // Skip if group doesn't exist or is not published
            }

            $current_group_data = get_post_meta($group_id, $group_meta_key, true);
            $current_group_data = is_array($current_group_data) ? $current_group_data : [];

            if ($group_meta_key === 'invitations') {
                if (!in_array($user_email, $current_group_data)) {
                    $current_group_data[] = $user_email;
                    update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                    $this->send_group_invitation_email_to_user($user_id, $group_id); // Send invitation email to user
                }
            } else {
                if (!in_array($user_id, $current_group_data)) {
                    $current_group_data[] = $user_id;
                    update_post_meta($group_id, $group_meta_key, array_values($current_group_data));
                }
            }
        }

        foreach ($groups_removed_from_user as $group_id) {
            // Fetch group data to ensure it exists and is published
            $group_post = get_post($group_id);
            if (!$group_post || $group_post->post_status !== 'publish') {
                continue; // Skip if group doesn't exist or is not published
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
            }
        }

        wp_send_json_success('User groups updated via AJAX');
    }

    /**
     * Registers custom fields of 'user-groups' post type for REST API.
     */
    public function register_group_meta_for_rest_api()
    {
        $fields_to_expose = $this->user_groups_get_custom_fields();

        foreach ($fields_to_expose as $field) {
            // Skip 'authored_groups' for the group post type API (it's not group meta)
            if ($field['type'] === 'author_groups') {
                continue;
            }

            register_rest_field(
                $this->post_type,
                $field['id'],
                array(
                    'get_callback' => function ($object, $field_name, $request) use ($field) {
                        $value = get_post_meta($object['id'], $field_name, true);

                        // Handle 'invitations': return as array of sanitized emails
                        if ($field['type'] === 'text' && $field_name === 'invitations') {
                            return is_array($value) ? array_map('sanitize_email', $value) : [];
                        }

                        // For user_multiselect, return array of int IDs
                        if ($field['type'] === 'user_multiselect') {
                            return is_array($value) ? array_map('intval', $value) : [];
                        }

                        return $value; // For other text fields, return as is
                    },
                    'update_callback' => null, // Meta is managed by save_post hook, not directly via REST API update
                    'schema' => array(
                        'description' => $field['description'],
                        'type'        => ($field['type'] === 'user_multiselect' || ($field['type'] === 'text' && $field['id'] === 'invitations')) ? 'array' : 'string',
                        'items'       => ($field['type'] === 'user_multiselect') ? ['type' => 'integer'] : ['type' => 'string'],
                        'context'     => array('view', 'edit'),
                    ),
                )
            );
        }
    }

    /**
     * Registers custom fields of User for REST API, including group details.
     */
    public function register_user_group_fields_for_rest_api()
    {
        foreach ($this->user_get_custom_fields() as $field) {
            $user_meta_key = $field['user_name']; // e.g., 'group_admin', 'group_member', 'group_author'
            $group_field_id = $field['id']; // e.g., 'admins', 'members', 'authored_groups'

            register_rest_field(
                'user',
                $user_meta_key, // Use the user_name as the REST API field name
                array(
                    'get_callback' => function ($object, $field_name, $request) use ($user_meta_key, $group_field_id) {
                        $user_id = $object['id'];
                        $group_ids = [];

                        if ($field_name === 'group_author') {
                            // For authored groups, query posts where the user is the author
                            $authored_posts = get_posts(array(
                                'author'         => $user_id,
                                'post_type'      => $this->post_type,
                                'posts_per_page' => -1,
                                'post_status'    => 'publish',
                                'fields'         => 'ids', // Only get IDs for efficiency
                            ));
                            $group_ids = $authored_posts;
                        } else {
                            // For other fields, get from user meta
                            $group_ids = get_user_meta($user_id, $user_meta_key, true);
                        }

                        $group_ids = is_array($group_ids) ? array_filter(array_map('intval', $group_ids)) : [];

                        $user_group_details = [];
                        foreach ($group_ids as $group_id) {
                            $group_post = get_post($group_id);
                            // Ensure group exists and is published
                            if (!$group_post || $group_post->post_status !== 'publish') {
                                continue;
                            }

                            $group_detail = [
                                'id'             => $group_id,
                                'title'          => $group_post->post_title,
                                'slug'           => $group_post->post_name, // Add slug for convenience
                                'status'         => $group_post->post_status,
                                'author'         => intval($group_post->post_author),
                                // Always include admins and members for authored groups for context
                                'admins'         => array_map('intval', (array)get_post_meta($group_id, 'admins', true)),
                                'members'        => array_map('intval', (array)get_post_meta($group_id, 'members', true)),
                            ];

                            // Check permissions for sensitive fields like 'requests' and 'invitations'
                            $current_user_id = get_current_user_id();
                            $is_current_user_admin_wp = current_user_can('manage_options'); // WordPress Administrator role
                            $is_author_of_group = ($group_post->post_author == $current_user_id);
                            $group_admins_meta = (array)get_post_meta($group_id, 'admins', true);
                            $is_admin_of_group = in_array($current_user_id, $group_admins_meta);


                            if ($is_current_user_admin_wp || $is_author_of_group || $is_admin_of_group) {
                                $group_detail['requests'] = array_map('intval', (array)get_post_meta($group_id, 'requests', true));
                                $raw_invitations = get_post_meta($group_id, 'invitations', true);
                                $group_detail['invitations'] = is_array($raw_invitations) ? array_map('sanitize_email', $raw_invitations) : [];
                            } else {
                                // Explicitly set to empty if no permission
                                $group_detail['requests'] = [];
                                $group_detail['invitations'] = [];
                            }

                            $user_group_details[] = $group_detail;
                        }
                        return $user_group_details;
                    },
                    'update_callback' => null, // Managed by hwp_user_groups_profile_save hook
                    'schema'          => array(
                        'description' => 'Groups where the user is a ' . $field['user_label'],
                        'type'        => 'array',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'id'            => array('type' => 'integer', 'description' => 'Group ID'),
                                'title'         => array('type' => 'string', 'description' => 'Group Title'),
                                'slug'          => array('type' => 'string', 'description' => 'Group Slug'),
                                'status'        => array('type' => 'string', 'description' => 'Group Status'),
                                'author'        => array('type' => 'integer', 'description' => 'User ID of the group author'),
                                'admins'        => array('type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'User IDs of group administrators'),
                                'members'       => array('type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'User IDs of group members'),
                                'requests'      => array('type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'User IDs of users who requested to join (if current user has permission).'),
                                'invitations'   => array('type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Email addresses of invited users (if current user has permission).'),
                            ),
                        ),
                        'context'     => array('view', 'edit'),
                    ),
                )
            );
        }
    }

    /**
     * Sends an invitation email to an existing user for a group.
     *
     * @param int $user_id The ID of the invited user.
     * @param int $group_id The ID of the group.
     */
    private function send_group_invitation_email_to_user($user_id, $group_id)
    {
        $user = get_user_by('ID', $user_id);
        $group = get_post($group_id);

        if (!$user || !$group || $group->post_status !== 'publish') {
            return; // Invalid user or group
        }

        $to = $user->user_email;
        $subject = sprintf('You\'ve been invited to join the group "%s"', $group->post_title);

        // Generate a token that specifically binds user ID and group ID
        $token = $this->generate_invitation_token($user_id, $group_id);

        // Frontend URL for accepting the invitation (adjust this to your frontend application)
        // Example: yourfrontend.com/accept-group-invite?group_id=X&user_id=Y&token=Z
        $invitation_link = get_home_url() . '/join-group/?group_id=' . $group_id . '&user_id=' . $user_id . '&token=' . $token;

        $message = sprintf(
            'Hello %s,<br><br>' .
            'You have been invited to join the group "%s".<br><br>' .
            'To accept the invitation, please click the following link:<br>' .
            '<a href="%s">%s</a><br><br>' .
            'If you don\'t want to join this group, you can ignore this email or click here to decline: <a href="%s">%s</a><br><br>' .
            'Best regards,<br>' .
            'Your Website Team',
            $user->display_name,
            $group->post_title,
            esc_url($invitation_link),
            esc_url($invitation_link),
            esc_url(get_home_url() . '/decline-group-invite/?group_id=' . $group_id . '&user_id=' . $user_id . '&token=' . $token),
            esc_url(get_home_url() . '/decline-group-invite/?group_id=' . $group_id . '&user_id=' . $user_id . '&token=' . $token)
        );

        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Sends an invitation email to an email address (for non-existing users).
     *
     * @param string $email The email address to send the invitation to.
     * @param int $group_id The ID of the group.
     */
    private function send_group_invitation_email_to_email($email, $group_id)
    {
        if (!is_email($email)) {
            return; // Invalid email address
        }

        $group = get_post($group_id);

        if (!$group || $group->post_status !== 'publish') {
            return; // Invalid group
        }

        $to = $email;
        $subject = sprintf('You\'ve been invited to join the group "%s"', $group->post_title);

        // Generate a token that specifically binds the email and group ID
        $token = $this->generate_invitation_token($email, $group_id, true);

        // Frontend URL for registration and accepting the invitation
        // Example: yourfrontend.com/register-and-join-group?group_id=X&email=Y&token=Z
        $registration_link = get_home_url() . '/register-and-join-group/?group_id=' . $group_id . '&email=' . rawurlencode($email) . '&token=' . $token;

        $message = sprintf(
            'Hello,<br><br>' .
            'You have been invited to join the group "%s" on our website.<br>' .
            'It appears you do not have an account with us yet. To join the group, please register first.<br><br>' .
            'To register and accept the invitation, please click the following link:<br>' .
            '<a href="%s">%s</a><br><br>' .
            'If you don\'t want to join this group, you can ignore this email or click here to decline: <a href="%s">%s</a><br><br>' .
            'Best regards,<br>' .
            'Your Website Team',
            $group->post_title,
            esc_url($registration_link),
            esc_url($registration_link),
            esc_url(get_home_url() . '/decline-group-invite/?group_id=' . $group_id . '&email=' . rawurlencode($email) . '&token=' . $token),
            esc_url(get_home_url() . '/decline-group-invite/?group_id=' . $group_id . '&email=' . rawurlencode($email) . '&token=' . $token)
        );

        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Generates a unique token for invitation links.
     *
     * IMPORTANT: For a production system, consider storing these tokens
     * in the database with an expiration and a flag for whether they've been used.
     * This current implementation is stateless, meaning the token validation
     * relies solely on re-hashing the known parameters.
     *
     * @param int|string $identifier User ID or email.
     * @param int $group_id The ID of the group.
     * @param bool $is_email_invitation True if the identifier is an email, false if a user ID.
     * @return string The generated token.
     */
    private function generate_invitation_token($identifier, $group_id, $is_email_invitation = false)
    {
        // Use a longer expiration for tokens, e.g., a few days or a week
        // You might want to store expiry in a transient or custom table for proper management.
        $expiry_time = time() + (DAY_IN_SECONDS * 7); // Token valid for 7 days

        // For simplicity in this stateless token, we'll include expiry in the hash,
        // but for a truly robust system, expiry should be checked against a stored value.
        $string_to_hash = $identifier . '_' . $group_id . '_' . $expiry_time . '_' . (defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '');
        return hash('sha256', $string_to_hash);
    }

    /**
     * Validates an invitation token.
     *
     * @param string $token The token to validate.
     * @param int|string $identifier User ID or email.
     * @param int $group_id The ID of the group.
     * @param bool $is_email_invitation True if the identifier is an email, false if a user ID.
     * @return bool True if the token is valid, false otherwise.
     */
    private function validate_invitation_token($token, $identifier, $group_id, $is_email_invitation = false)
    {
        // Re-generate the token with a range of possible expiry times to account for small time differences.
        // This is a basic approach for stateless tokens.
        // A better approach involves storing tokens with expiry in the DB.
        $current_time = time();
        $valid_for_days = 7; // Must match the duration in generate_invitation_token

        for ($i = 0; $i <= $valid_for_days; $i++) {
            $possible_expiry_time = $current_time + (DAY_IN_SECONDS * $i);
            $string_to_hash = $identifier . '_' . $group_id . '_' . $possible_expiry_time . '_' . (defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '');
            if (hash('sha256', $string_to_hash) === $token) {
                return true; // Token matches a possible valid expiry time
            }
        }
        return false;
    }


    /**
     * Registers the REST API endpoint for accepting/declining group invitations.
     */
    public function register_invitation_api_endpoint()
    {
        register_rest_route('hwp/v1', '/group-invitations/(?P<action>(accept|decline))', array(
            'methods'             => 'POST', // Use POST for state-changing operations
            'callback'            => array($this, 'handle_group_invitation_api_callback'),
            'permission_callback' => '__return_true', // Public endpoint, validation happens in callback
            'args'                => array(
                'group_id' => array(
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the group.',
                ),
                'token' => array(
                    'type'        => 'string',
                    'required'    => true,
                    'description' => 'The invitation token.',
                ),
                'user_id' => array(
                    'type'        => 'integer',
                    'required'    => false,
                    'description' => 'Optional: The ID of the user (if already registered).',
                ),
                'email' => array(
                    'type'        => 'string',
                    'required'    => false,
                    'description' => 'Optional: The email address of the invited user (if not yet registered).',
                ),
            ),
        ));
    }

    /**
     * Handles the REST API callback for group invitation actions.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The REST API response.
     */
    public function handle_group_invitation_api_callback(WP_REST_Request $request)
    {
        $action = $request->get_param('action');
        $group_id = intval($request->get_param('group_id'));
        $token = sanitize_text_field($request->get_param('token'));
        $user_id = intval($request->get_param('user_id'));
        $email = sanitize_email($request->get_param('email'));

        // Validate basic parameters
        if (empty($group_id) || empty($token)) {
            return new WP_Error('hwp_invitation_missing_params', 'Missing group ID or token.', array('status' => 400));
        }

        // Get group details
        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== $this->post_type || $group_post->post_status !== 'publish') {
            return new WP_Error('hwp_invitation_invalid_group', 'Invalid or non-existent group.', array('status' => 404));
        }

        $identifier = '';
        $is_email_invitation = false;
        $target_user = null; // Will store WP_User object if applicable

        if (!empty($user_id)) {
            $target_user = get_user_by('ID', $user_id);
            if (!$target_user) {
                return new WP_Error('hwp_invitation_invalid_user_id', 'Invalid user ID provided.', array('status' => 404));
            }
            $identifier = $user_id; // For token validation
            $email = $target_user->user_email; // Ensure email is consistent
        } elseif (!empty($email)) {
            $target_user = get_user_by('email', $email);
            $identifier = $email; // For token validation
            $is_email_invitation = true;
        } else {
            return new WP_Error('hwp_invitation_no_identifier', 'Either user_id or email must be provided.', array('status' => 400));
        }

        // Validate the token
        if (!$this->validate_invitation_token($token, $identifier, $group_id, $is_email_invitation)) {
            // Log this for security monitoring
            error_log(sprintf('HWP User Groups: Invalid invitation token for identifier "%s", group ID %d, token "%s".', $identifier, $group_id, $token));
            return new WP_Error('hwp_invitation_invalid_token', 'Invalid or expired invitation token.', array('status' => 403));
        }

        // --- Action Logic ---
        $group_meta_invitations = (array)get_post_meta($group_id, 'invitations', true);
        $group_meta_members = (array)get_post_meta($group_id, 'members', true);
        $group_meta_requests = (array)get_post_meta($group_id, 'requests', true); // In case they sent a request

        $response_message = '';
        $success_status = 200;

        if ($action === 'accept') {
            // 1. Remove invitation email from group meta (if it exists)
            if (in_array($email, $group_meta_invitations, true)) {
                $group_meta_invitations = array_values(array_diff($group_meta_invitations, [$email]));
                update_post_meta($group_id, 'invitations', $group_meta_invitations);
                $response_message .= 'Invitation removed from group. ';
            }

            // 2. Remove user from 'requests' if they also sent a request
            if ($target_user && in_array($target_user->ID, $group_meta_requests)) {
                $group_meta_requests = array_values(array_diff($group_meta_requests, [$target_user->ID]));
                update_post_meta($group_id, 'requests', $group_meta_requests);
                $response_message .= 'Request removed from group. ';
            }

            if ($target_user) {
                // User already exists, add them to 'members'
                if (!in_array($target_user->ID, $group_meta_members)) {
                    $group_meta_members[] = $target_user->ID;
                    update_post_meta($group_id, 'members', array_values($group_meta_members));
                    $response_message .= sprintf('User %s successfully added as a member to group "%s".', $target_user->display_name, $group_post->post_title);

                    // Also update user's meta if not already there
                    $user_memberships = get_user_meta($target_user->ID, 'group_member', true);
                    $user_memberships = is_array($user_memberships) ? $user_memberships : [];
                    if (!in_array($group_id, $user_memberships)) {
                        $user_memberships[] = $group_id;
                        update_user_meta($target_user->ID, 'group_member', array_values($user_memberships));
                    }
                } else {
                    $response_message .= sprintf('User %s is already a member of group "%s".', $target_user->display_name, $group_post->post_title);
                }

                // Remove from user's 'group_invitations' meta
                $user_invitations_meta = get_user_meta($target_user->ID, 'group_invitations', true);
                if (is_array($user_invitations_meta) && in_array($group_id, $user_invitations_meta)) {
                    $user_invitations_meta = array_values(array_diff($user_invitations_meta, [$group_id]));
                    update_user_meta($target_user->ID, 'group_invitations', $user_invitations_meta);
                }

            } else {
                // User does not exist yet. Frontend should handle registration.
                // The invitation email is still removed from the group.
                $response_message .= sprintf('Invitation for %s accepted for group "%s". User needs to register to become a member.', $email, $group_post->post_title);
                $success_status = 202; // Accepted but more action required
            }

        } elseif ($action === 'decline') {
            // 1. Remove invitation email from group meta
            if (in_array($email, $group_meta_invitations, true)) {
                $group_meta_invitations = array_values(array_diff($group_meta_invitations, [$email]));
                update_post_meta($group_id, 'invitations', $group_meta_invitations);
                $response_message .= 'Invitation removed from group. ';
            }

            // 2. Remove from user's 'group_invitations' meta if user exists
            if ($target_user) {
                $user_invitations_meta = get_user_meta($target_user->ID, 'group_invitations', true);
                if (is_array($user_invitations_meta) && in_array($group_id, $user_invitations_meta)) {
                    $user_invitations_meta = array_values(array_diff($user_invitations_meta, [$group_id]));
                    update_user_meta($target_user->ID, 'group_invitations', $user_invitations_meta);
                }
                $response_message .= sprintf('Invitation for user %s to group "%s" successfully declined.', $target_user->display_name, $group_post->post_title);
            } else {
                $response_message .= sprintf('Invitation for %s to group "%s" successfully declined.', $email, $group_post->post_title);
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => $response_message,
            'group_id' => $group_id,
            'group_title' => $group_post->post_title,
            'user_id' => $user_id,
            'email' => $email,
            'action' => $action
        ), $success_status);
    }
}