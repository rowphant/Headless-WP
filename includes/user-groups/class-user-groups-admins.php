<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-user-groups-base.php';

class HWP_User_Groups_Admins extends HWP_User_Groups_Base
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
        parent::add_hooks();
        add_action('rest_api_init', array($this, 'register_admin_api_endpoints'));
    }

    /**
     * Registers the REST API endpoints for managing group admins.
     */
    public function register_admin_api_endpoints()
    {
        // Endpoint to add a user as a group admin (by group owner/admin)
        register_rest_route('hwp/v1', '/group-admins/add', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_add_group_admin'),
            'permission_callback' => array($this, 'permission_check_group_owner_or_admin'),
            'args'                => array(
                'group_id' => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'description'       => 'The ID of the group.',
                    'sanitize_callback' => 'absint',
                ),
                'user_id' => array( // ID of the user to be made admin
                    'type'              => 'integer',
                    'required'          => true,
                    'description'       => 'The ID of the user to make an admin.',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // Endpoint to remove a user as a group admin (by group owner/admin or self-removal)
        register_rest_route('hwp/v1', '/group-admins/remove', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_remove_group_admin'),
            'permission_callback' => array($this, 'permission_check_logged_in_user'), // Broader check, then internal logic
            'args'                => array(
                'group_id' => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'description'       => 'The ID of the group.',
                    'sanitize_callback' => 'absint',
                ),
                'user_id' => array( // ID of the user to be removed as admin
                    'type'              => 'integer',
                    'required'          => true,
                    'description'       => 'The ID of the user to remove as admin.',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    /**
     * Handles adding a user as an admin to a group.
     * Called by group owner/admin.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_add_group_admin(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $user_id_to_add = $request->get_param('user_id');

        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        $user_to_add = get_user_by('ID', $user_id_to_add);
        if (!$user_to_add) {
            return new WP_REST_Response(array('success' => false, 'message' => 'User to make admin not found.'), 404);
        }

        // A user must be a member before becoming an admin
        if (!$this->is_user_member_of_group($user_id_to_add, $group_id)) {
            return new WP_REST_Response(array('success' => false, 'message' => sprintf('%s must be a member of "%s" before being made an admin.', $user_to_add->display_name, $group_post->post_title)), 400);
        }

        // Use the helper from the base class
        if ($this->is_user_group_admin($user_id_to_add, $group_id)) {
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s is already an admin of "%s".', $user_to_add->display_name, $group_post->post_title)), 200);
        }

        $added = $this->add_user_as_group_admin($user_id_to_add, $group_id);

        if ($added) {
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s successfully added as an admin to group "%s".', $user_to_add->display_name, $group_post->post_title)), 200);
        } else {
            return new WP_REST_Response(array('success' => false, 'message' => 'Failed to add admin.'), 500);
        }
    }

    /**
     * Handles removing a user as an admin from a group.
     * Can be called by group owner/admin or the admin themselves.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_remove_group_admin(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $user_id_to_remove = $request->get_param('user_id');
        $current_user_id = get_current_user_id();

        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        $user_to_remove = get_user_by('ID', $user_id_to_remove);
        if (!$user_to_remove) {
            return new WP_REST_Response(array('success' => false, 'message' => 'User to remove as admin not found.'), 404);
        }

        // Permission check: current user must be group owner/admin OR the user to be removed
        $group_owner_id = (int)get_post_field('post_author', $group_id);
        if (!current_user_can('manage_options') && $current_user_id !== $group_owner_id && $current_user_id !== (int)$user_id_to_remove && !$this->is_user_group_admin($current_user_id, $group_id)) {
             return new WP_REST_Response(array('success' => false, 'message' => 'You do not have permission to remove this admin.'), 403);
        }

        // Prevent removing the sole owner or last admin by themselves
        if ($user_id_to_remove === $group_owner_id) {
             // Only allow owner removal if there's another admin OR if an actual site admin is doing it.
             // This can get complex, simple check for now:
             if ($user_id_to_remove === $current_user_id && $current_user_id === $group_owner_id && !current_user_can('manage_options')) {
                 $group_admins_meta = get_post_meta($group_id, 'group_admins', true);
                 if (!is_array($group_admins_meta)) $group_admins_meta = [];
                 $other_admins_count = count($group_admins_meta);

                 if ($other_admins_count === 0) { // If no other explicit admins, owner cannot remove themselves
                    return new WP_REST_Response(array('success' => false, 'message' => 'The group owner cannot remove themselves as admin if they are the only administrator. Assign another admin first.'), 403);
                 }
             }
        }


        // Use the helper from the base class
        if (!$this->is_user_group_admin($user_id_to_remove, $group_id)) {
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s is not an admin of "%s".', $user_to_remove->display_name, $group_post->post_title)), 200);
        }

        $removed = $this->remove_user_as_group_admin($user_id_to_remove, $group_id);

        if ($removed) {
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s successfully removed as admin from group "%s".', $user_to_remove->display_name, $group_post->post_title)), 200);
        } else {
            return new WP_REST_Response(array('success' => false, 'message' => 'Failed to remove admin.'), 500);
        }
    }
}