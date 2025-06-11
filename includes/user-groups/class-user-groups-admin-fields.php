<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-user-groups-base.php';

class HWP_User_Groups_Admin_Fields extends HWP_User_Groups_Base
{
    public function __construct()
    {
        parent::__construct();
        $this->add_hooks();
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
        // These are crucial for the Select2 fields to populate with users/groups.
        add_action('wp_ajax_hwp_user_search', array($this, 'ajax_user_search'));
        // For admin fields, usually no 'nopriv' needed as search is done by logged-in admin users.
        // add_action('wp_ajax_nopriv_hwp_user_search', array($this, 'ajax_user_search')); 

        add_action('wp_ajax_hwp_group_search', array($this, 'ajax_group_search'));
        // add_action('wp_ajax_nopriv_hwp_group_search', array($this, 'ajax_group_search'));
    }

    /**
     * Adds a custom meta box for User Group custom fields.
     */
    public function user_groups_add_custom_fields_meta_box()
    {
        add_meta_box(
            'hwp_user_groups_custom_fields',
            'Group Details',
            array($this, 'user_groups_custom_fields_html'),
            $this->post_type,
            'normal',
            'high'
        );
    }

    /**
     * Displays the HTML for the custom fields meta box.
     *
     * @param WP_Post $post The current post object.
     */
    public function user_groups_custom_fields_html($post)
    {
        wp_nonce_field('user_groups_save_custom_fields', 'user_groups_custom_fields_nonce');

        $fields = $this->user_groups_get_custom_fields(); // Get fields from base class

        // Render fields
        foreach ($fields as $field) {
            $meta_value = get_post_meta($post->ID, $field['id'], true);
            if (!is_array($meta_value)) { // Ensure it's an array for consistency
                $meta_value = [];
            }
            if ($field['type'] === 'user_select_multiple_readonly' || $field['type'] === 'text_area_readonly') {
                // For readonly fields, just display the values
                $display_value = '';
                if ($field['id'] === 'invitations') {
                    $display_value = implode(', ', $meta_value);
                } else {
                    // For user IDs, fetch user display names
                    $user_names = [];
                    foreach ($meta_value as $user_id) {
                        $user_data = get_userdata($user_id);
                        if ($user_data) {
                            $user_names[] = $user_data->display_name . ' (' . $user_data->user_email . ')';
                        }
                    }
                    $display_value = implode(', ', $user_names);
                }
?>
                <p>
                    <label for="<?php echo esc_attr($field['id']); ?>"><strong><?php echo esc_html($field['title']); ?>:</strong></label><br>
                    <textarea id="<?php echo esc_attr($field['id']); ?>" name="<?php echo esc_attr($field['id']); ?>" class="large-text" rows="3" readonly><?php echo esc_textarea($display_value); ?></textarea>
                <p class="description"><?php echo esc_html($field['description']); ?></p>
                </p>
            <?php
            } else {
                // For editable fields like 'group_type', 'members', 'admins'
            ?>
                <p>
                    <label for="<?php echo esc_attr($field['id']); ?>"><strong><?php echo esc_html($field['title']); ?>:</strong></label><br>
                    <?php if ($field['type'] === 'select') : ?>
                        <select id="<?php echo esc_attr($field['id']); ?>" name="<?php echo esc_attr($field['id']); ?>" class="postbox">
                            <?php foreach ($field['options'] as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($meta_value, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($field['type'] === 'user_select_multiple') :
                        // Get current values for Select2 pre-population
                        $selected_users = [];
                        if (!empty($meta_value) && is_array($meta_value)) {
                            foreach ($meta_value as $user_id) {
                                $user_data = get_userdata($user_id);
                                if ($user_data) {
                                    $selected_users[] = array('id' => $user_data->ID, 'text' => $user_data->display_name . ' (' . $user_data->user_email . ')');
                                }
                            }
                        }
                    ?>
                        <select id="<?php echo esc_attr($field['id']); ?>" name="<?php echo esc_attr($field['id']); ?>[]" class="hwp-select2-ajax-users" multiple="multiple" style="width: 100%;" data-placeholder="Benutzer auswählen">
                            <?php
                            foreach ($selected_users as $user) {
                                echo '<option value="' . esc_attr($user['id']) . '" selected="selected">' . esc_html($user['text']) . '</option>';
                            }
                            ?>
                        </select>
                    <?php endif; ?>
                <p class="description"><?php echo esc_html($field['description']); ?></p>
                </p>
        <?php
            }
        }

        // Display the group author directly (not editable here, but for info)
        $author_id = (int)$post->post_author;
        $author_data = get_userdata($author_id);
        $author_name = $author_data ? $author_data->display_name . ' (' . $author_data->user_email . ')' : 'N/A';
        ?>
        <p>
            <label><strong>Group Author:</strong></label><br>
            <input type="text" class="large-text" value="<?php echo esc_attr($author_name); ?>" readonly>
        <p class="description">The user who created this group.</p>
        </p>
<?php
    }


    /**
     * Saves the custom field data when a User Group post is saved.
     *
     * @param int $post_id The ID of the post being saved.
     */
    public function user_groups_save_custom_fields($post_id)
    {
        // Check if our nonce is set.
        if (!isset($_POST['user_groups_custom_fields_nonce'])) {
            return $post_id;
        }

        // Verify that the nonce is valid.
        if (!wp_verify_nonce($_POST['user_groups_custom_fields_nonce'], 'user_groups_save_custom_fields')) {
            return $post_id;
        }

        // If this is an autosave, our form has not been submitted, so we don't want to do anything.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        // Check the user's permissions.
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }

        $fields = $this->user_groups_get_custom_fields();
        $base = new HWP_User_Groups_Base(); // Instance of base class for helpers

        foreach ($fields as $field) {
            $field_id = $field['id'];

            // Skip fields that are display-only or handled differently
            if ($field['type'] === 'user_select_multiple_readonly' || $field['type'] === 'text_area_readonly') {
                continue;
            }

            $old_value = get_post_meta($post_id, $field_id, true);
            $new_value = isset($_POST[$field_id]) ? $_POST[$field_id] : [];

            // Sanitize new_value based on field type
            if ($field['type'] === 'select') {
                $new_value = sanitize_text_field($new_value);
            } elseif ($field['type'] === 'user_select_multiple') {
                // Ensure new_value is an array of integers, even if single value from select2
                $new_value = is_array($new_value) ? array_map('intval', $new_value) : [];
            }


            // Handle group_type specifically
            if ($field_id === 'group_type') {
                if ($new_value && $new_value !== $old_value) {
                    update_post_meta($post_id, $field_id, $new_value);
                }
            }
            // Handle members field
            elseif ($field_id === 'members') {
                // Get current members (ensuring it's an array)
                $current_members = get_post_meta($post_id, 'members', true);
                if (!is_array($current_members)) {
                    $current_members = [];
                }

                // Add new members
                foreach ($new_value as $user_id) {
                    if (!in_array($user_id, $current_members)) {
                        $base->add_user_to_group($user_id, $post_id);
                    }
                }

                // Remove old members no longer in the list
                foreach ($current_members as $user_id) {
                    if (!in_array($user_id, $new_value)) {
                        $base->remove_user_from_group($user_id, $post_id);
                        // Also remove from admins if they were only a member and now removed
                        $base->remove_user_as_group_admin($user_id, $post_id);
                    }
                }
            }
            // Handle admins field
            elseif ($field_id === 'admins') {
                $current_admins = get_post_meta($post_id, 'admins', true);
                if (!is_array($current_admins)) {
                    $current_admins = [];
                }

                // Add new admins
                foreach ($new_value as $user_id) {
                    if (!in_array($user_id, $current_admins)) {
                        $base->add_user_as_group_admin($user_id, $post_id);
                    }
                }

                // Remove old admins no longer in the list
                foreach ($current_admins as $user_id) {
                    if (!in_array($user_id, $new_value)) {
                        $base->remove_user_as_group_admin($user_id, $post_id);
                    }
                }
            }
        }
    }


    /**
     * Enqueue admin-specific assets (JS, CSS) for custom fields.
     */
    public function enqueue_admin_assets($hook)
    {
        $screen = get_current_screen();
        if ($screen->post_type === $this->post_type) {
            // Enqueue Select2 CSS
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0-rc.0');
            // Enqueue Select2 JS
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0-rc.0', true);

            // Nur auf den relevanten Seiten laden
            if ('post.php' !== $hook && 'post-new.php' !== $hook) { // Oder spezifischer für deinen Custom Post Type
                return;
            }

            wp_enqueue_script(
                'hwp-user-groups-admin-scripts',
                plugin_dir_url(__FILE__) . '../assets/js/admin-scripts.js',
                array('jquery', 'select2'), // Stelle sicher, dass Select2 als Abhängigkeit geladen wird
                '1.0.0',
                true
            );

            wp_localize_script(
                'hwp-user-groups-admin-scripts',
                'hwpUserGroupsAdminAjax',
                array(
                    'ajax_url'          => admin_url('admin-ajax.php'),
                    'user_search_nonce' => wp_create_nonce('hwp_user_groups_admin_nonce_ajax'),
                    // Füge ggf. weitere Nonces hinzu, z.B. für group_search
                    'group_search_nonce' => wp_create_nonce('hwp_user_groups_admin_nonce_ajax'), // Wenn du auch Gruppensuche hast
                )
            );
        }
    }

    /**
     * AJAX callback to search for users for Select2 fields.
     * Used for 'members' and 'admins' fields.
     */
    public function ajax_user_search()
    {
        // Permission check: User must be able to edit posts of this type (e.g., 'edit_user_groups')
        // Or a more general capability like 'list_users' or 'edit_users' if they manage users
        if (!current_user_can('list_users')) { // Assuming users who can manage groups can also search users
            wp_send_json_error('You do not have permission to search users.');
        }

        check_ajax_referer('hwp_user_groups_admin_nonce_ajax', 'nonce'); // Use the specific nonce for admin AJAX

        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $users_query = new WP_User_Query(array(
            'search'         => '*' . esc_attr($search) . '*',
            'search_columns' => array('user_login', 'user_nicename', 'user_email', 'display_name'),
            'number'         => 20,
            'fields'         => array('ID', 'display_name', 'user_email'),
        ));

        $users = $users_query->get_results();

        $results = array_map(function ($user) {
            return array('id' => $user->ID, 'text' => $user->display_name . ' (' . $user->user_email . ')');
        }, $users);

        wp_send_json(array('results' => $results)); // Select2 expects 'results' key
    }

    /**
     * AJAX callback to search for user groups.
     * While not explicitly used in the 'user_select' type above, this is here
     * for completeness if you had a 'group_select' field type.
     * It could also be used for a future feature to select parent groups, etc.
     */
    public function ajax_group_search()
    {
        // For admin context, typically 'edit_posts' of the specific CPT
        if (!current_user_can('edit_posts', get_current_user_id())) { // Or current_user_can('edit_user_groups') if you defined custom caps
            wp_send_json_error('You do not have permission to search groups.');
        }

        check_ajax_referer('hwp_user_groups_admin_nonce_ajax', 'nonce'); // Use the specific nonce for admin AJAX

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

        wp_send_json(array('results' => $results));
    }
}
