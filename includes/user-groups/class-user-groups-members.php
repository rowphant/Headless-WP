<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-user-groups-base.php';

class HWP_User_Groups_Members extends HWP_User_Groups_Base
{
    public function __construct()
    {
        parent::__construct();
        $this->add_hooks();
    }

    /**
     * Extends the base add_hooks method with member-specific hooks.
     */
    protected function add_hooks()
    {
        parent::add_hooks();
        add_action('rest_api_init', array($this, 'register_member_api_endpoints'));
    }

    /**
     * Registers the REST API endpoints for managing group members.
     */
    public function register_member_api_endpoints()
    {
        // Endpoint to add a member to a group (by group admin/author)
        register_rest_route('hwp/v1', '/group-members/add', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_add_group_member'),
            'permission_callback' => array($this, 'permission_check_group_owner_or_admin'),
            'args'                => array(
                'group_id' => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'description'       => 'The ID of the group.',
                    'sanitize_callback' => 'absint',
                ),
                'user_id' => array( // ID of the user to be added
                    'type'              => 'integer',
                    'required'          => true,
                    'description'       => 'The ID of the user to add as a member.',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // Endpoint to remove a member from a group (by group admin/author or self-removal)
        register_rest_route('hwp/v1', '/group-members/remove', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_remove_group_member'),
            'permission_callback' => array($this, 'permission_check_logged_in_user'), // Broader check, then internal logic
            'args'                => array(
                'group_id' => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'description'       => 'The ID of the group.',
                    'sanitize_callback' => 'absint',
                ),
                'user_id' => array( // ID of the user to be removed
                    'type'              => 'integer',
                    'required'          => true,
                    'description'       => 'The ID of the user to remove from members.',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    /**
     * Handles adding a user as a member to a group.
     * Called by group owner/admin.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_add_group_member(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $user_id_to_add = $request->get_param('user_id');

        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        $user_to_add = get_user_by('ID', $user_id_to_add);
        if (!$user_to_add) {
            return new WP_REST_Response(array('success' => false, 'message' => 'User to add not found.'), 404);
        }

        // Use the helper from the base class
        if ($this->is_user_member_of_group($user_id_to_add, $group_id)) {
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s is already a member of "%s".', $user_to_add->display_name, $group_post->post_title)), 200);
        }

        $added = $this->add_user_to_group($user_id_to_add, $group_id);

        if ($added) {
            // Optional: Clean up any pending invitations or requests for this user/group
            $this->remove_invitation_from_group_meta($group_id, $user_to_add->user_email);
            $this->remove_invitation_from_user_meta($user_id_to_add, $group_id);
            $this->remove_request_from_group_meta($group_id, $user_id_to_add);
            $this->remove_request_from_user_meta($user_id_to_add, $group_id);

            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s successfully added as a member to group "%s".', $user_to_add->display_name, $group_post->post_title)), 200);
        } else {
            return new WP_REST_Response(array('success' => false, 'message' => 'Failed to add member.'), 500);
        }
    }

    /**
     * Handles removing a user from a group's members.
     * Can be called by group owner/admin or the member themselves.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_remove_group_member(WP_REST_Request $request)
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
            return new WP_REST_Response(array('success' => false, 'message' => 'User to remove not found.'), 404);
        }

        // Permission check: current user must be group owner/admin OR the user to be removed
        if (!current_user_can('manage_options') && $current_user_id !== (int)get_post_field('post_author', $group_id) && $current_user_id !== (int)$user_id_to_remove && !$this->is_user_group_admin($current_user_id, $group_id)) {
             return new WP_REST_Response(array('success' => false, 'message' => 'You do not have permission to remove this member.'), 403);
        }

        $this->remove_user_from_group($user_id_to_remove, $group_id);
        
        // Use the helper from the base class
        if (!$this->is_user_member_of_group($user_id_to_remove, $group_id)) {
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s is not a member of "%s".', $user_to_remove->display_name, $group_post->post_title)), 200);
        }

        $removed = $this->remove_user_from_group($user_id_to_remove, $group_id);

        if ($removed) {
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s successfully removed from group "%s".', $user_to_remove->display_name, $group_post->post_title)), 200);
        } else {
            return new WP_REST_Response(array('success' => false, 'message' => 'Failed to remove member.'), 500);
        }
    }

    // Helper methods for cleanup (if not present in Invitations/Requests or Base)
    // Assuming these are private helpers in Invitations/Requests that you might need to make protected in Base
    // or copy them here if they are only for specific classes.
    // I'm including placeholder stubs, you should ensure they are correctly implemented
    // and accessible (e.g., from HWP_User_Groups_Base).
    protected function remove_request_from_group_meta($group_id, $user_id_to_remove) { /* ... */ } // Assuming this is in HWP_User_Groups_Requests
    protected function remove_request_from_user_meta($user_id, $group_id_to_remove) { /* ... */ } // Assuming this is in HWP_User_Groups_Requests
}