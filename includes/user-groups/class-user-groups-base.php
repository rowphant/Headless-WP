<?php
if (!defined('ABSPATH')) {
    exit;
}

class HWP_User_Groups_Base
{
    /**
     * Option key for headless WP settings.
     * @var string
     */
    protected $settings_option_key = 'headless_wp_settings';

    /**
     * Post type slug for user groups.
     * @var string
     */
    protected $post_type = 'user-groups';

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
     * This method will be extended by child classes.
     */
    protected function add_hooks()
    {
        add_action('init', array($this, 'register_user_groups_post_type'));
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
     * Defines the custom fields for User Groups.
     * This method is central and used by multiple parts of the plugin.
     *
     * @return array Array of field definitions.
     */
    public function user_groups_get_custom_fields()
    {
        return array(
            array(
                'type' => 'author_groups',
                'id' => 'authored_groups',
                'label' => 'Authored Groups',
                'user_label' => 'Author',
                'user_name' => 'group_author',
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
     * Returns the custom fields relevant for user profiles.
     * This simply calls the main fields method as they are largely overlapping.
     *
     * @return array Array of field definitions.
     */
    public function user_get_custom_fields()
    {
        return $this->user_groups_get_custom_fields();
    }

    /**
     * Permission callback for REST API endpoints: checks if the current user is logged in.
     * This is a general permission check.
     *
     * @return bool|WP_Error True if logged in, WP_Error otherwise.
     */
    public function permission_check_logged_in_user()
    {
        if (is_user_logged_in()) {
            return true;
        }
        return new WP_Error('rest_not_logged_in', 'You are not logged in.', array('status' => 401));
    }

    /**
     * Permission callback for REST API endpoints: checks if the current user is
     * the group owner or an administrator.
     * This is a more specific permission check.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return bool|WP_Error True if permitted, WP_Error otherwise.
     */
    public function permission_check_group_owner_or_admin(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        // Ensure group_id is a valid integer before use
        if (!is_numeric($group_id) || $group_id <= 0) {
            return new WP_Error('rest_invalid_param', 'Invalid group_id.', array('status' => 400));
        }

        $group_owner_id = get_post_field('post_author', $group_id);
        $current_user_id = get_current_user_id();

        if (current_user_can('manage_options') || $current_user_id === (int)$group_owner_id) {
            return true;
        }
        return new WP_Error('rest_forbidden', 'You do not have permission to perform this action for this group.', array('status' => 403));
    }

    /**
     * Helper to add a user to a group.
     * This method is now in the base class for reuse.
     *
     * @param int $user_id The ID of the user.
     * @param int $group_id The ID of the group.
     * @return bool True if added or already member, false on error.
     */
    protected function add_user_to_group($user_id, $group_id)
    {
        // Sanitize inputs
        $user_id = (int)$user_id;
        $group_id = (int)$group_id;

        if ($user_id <= 0 || $group_id <= 0) {
            error_log('add_user_to_group: Invalid user_id or group_id provided.');
            return false;
        }

        $group_members = get_post_meta($group_id, 'members', true);

        if (!is_array($group_members)) {
            $group_members = [];
        }

        if (!in_array($user_id, $group_members, true)) { // Use strict comparison
            $group_members[] = $user_id;
            $updated = update_post_meta($group_id, 'members', $group_members);
            // clean_post_cache($group_id); // Invalidate cache after update
            return $updated !== false; // Return true on successful update or false on failure
        }
        return true; // User was already a member, consider it a success for idempotency
    }

    /**
     * Helper to check if a user is already a member of a group.
     * This method is now in the base class for reuse.
     *
     * @param int $user_id The ID of the user.
     * @param int $group_id The ID of the group.
     * @return bool True if the user is a member, false otherwise.
     */
    protected function is_user_member_of_group($user_id, $group_id)
    {
        // Sanitize inputs
        $user_id = (int)$user_id;
        $group_id = (int)$group_id;

        if ($user_id <= 0 || $group_id <= 0) {
            return false; // Invalid IDs cannot be members
        }

        // clean_post_cache($group_id); // Invalidate cache to ensure latest data is read

        $group_members = get_post_meta($group_id, 'members', true);

        if (!is_array($group_members)) {
            return false;
        }
        // Ensure comparison with intval for robust checking
        return in_array($user_id, array_map('intval', $group_members), true); // Use strict comparison
    }
}