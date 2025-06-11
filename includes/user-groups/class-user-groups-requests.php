<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-user-groups-base.php';

class HWP_User_Groups_Requests extends HWP_User_Groups_Base
{
    public function __construct()
    {
        parent::__construct();
        $this->add_hooks();
    }

    /**
     * Extends the base add_hooks method with request-specific hooks.
     */
    protected function add_hooks()
    {
        parent::add_hooks(); // Call parent to register post type if not already done

        add_action('rest_api_init', array($this, 'register_request_api_endpoints'));
    }

    /**
     * Registers the REST API endpoints for handling group join requests.
     */
    public function register_request_api_endpoints()
    {
        // Endpoint for logged-in users to send a join request to a group
        register_rest_route('hwp/v1', '/group-requests/send', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_send_group_request'),
            'permission_callback' => array($this, 'permission_check_logged_in_user'), // Only logged-in users can send requests
            'args'                => array(
                'group_id' => array(
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the group to request to join.',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // Endpoint for the group admin/author to accept or decline a join request
        register_rest_route('hwp/v1', '/group-requests/action', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_group_request_action'),
            'permission_callback' => array($this, 'permission_check_group_owner_or_admin'), // Only group owner/admin can action requests
            'args'                => array(
                'group_id' => array(
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the group.',
                    'sanitize_callback' => 'absint',
                ),
                'user_id' => array( // This is the ID of the user who made the request
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the user who made the join request.',
                    'sanitize_callback' => 'absint',
                ),
                'action' => array(
                    'type'        => 'string',
                    'required'    => true,
                    'enum'        => ['accept', 'decline'],
                    'description' => 'Action to perform: "accept" or "decline".',
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ));

        // Endpoint for the user who made the request to delete/cancel their own request
        register_rest_route('hwp/v1', '/group-requests/delete', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_delete_group_request'),
            'permission_callback' => array($this, 'permission_check_logged_in_user'), // Only the requesting user or admin can delete their request
            'args'                => array(
                'group_id' => array(
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the group.',
                    'sanitize_callback' => 'absint',
                ),
                'user_id' => array( // The ID of the user whose request is to be deleted
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the user who made the join request to delete.',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    /**
     * Handles sending a join request to a group.
     * This method is called from the REST API endpoint by a logged-in user.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_send_group_request(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $current_user_id = get_current_user_id();

        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        // Check if the user is already a member
        if ($this->is_user_member_of_group($current_user_id, $group_id)) {
            $this->add_user_to_group($current_user_id, $group_id);
            return new WP_REST_Response(array('success' => false, 'message' => 'You are already a member of this group.'), 409);
        }

        // Check if the user has already sent a request
        $group_requests = get_post_meta($group_id, 'requests', true);
        if (!is_array($group_requests)) {
            $group_requests = [];
        }

        if (in_array($current_user_id, $group_requests, true)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'You have already sent a join request to this group.'), 409);
        }

        // Add the user ID to the group's 'requests' meta field
        $group_requests[] = $current_user_id;
        update_post_meta($group_id, 'requests', $group_requests);

        // Add the group ID to the user's 'group_requests' meta field (optional, for user dashboard)
        $user_requests_meta = get_user_meta($current_user_id, 'group_requests', true);
        if (!is_array($user_requests_meta)) {
            $user_requests_meta = [];
        }
        if (!in_array($group_id, $user_requests_meta, true)) {
            $user_requests_meta[] = $group_id;
            update_user_meta($current_user_id, 'group_requests', $user_requests_meta);
        }

        return new WP_REST_Response(array('success' => true, 'message' => sprintf('Join request sent to group "%s".', $group_post->post_title)), 200);
    }

    /**
     * Handles the acceptance or decline of a group join request.
     * This method is called from the REST API endpoint by a group admin/author.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_group_request_action(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $requested_user_id = $request->get_param('user_id');
        $action = $request->get_param('action');

        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        $requested_user = get_user_by('ID', $requested_user_id);
        if (!$requested_user) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Requested user not found.'), 404);
        }

        if (!in_array($action, ['accept', 'decline'])) {
             return new WP_REST_Response(array('success' => false, 'message' => 'Invalid action.'), 400);
        }

        // Check if the user is already a member (important for idempotency)
        if ($this->is_user_member_of_group($requested_user_id, $group_id)) {
            // If already a member, just clean up the request and return success
            $this->remove_request_from_group_meta($group_id, $requested_user_id);
            $this->remove_request_from_user_meta($requested_user_id, $group_id);
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s is already a member of "%s". Request cleaned up.', $requested_user->display_name, $group_post->post_title)), 200);
        }

        // Get current requests for the group
        $group_requests = get_post_meta($group_id, 'requests', true);
        if (!is_array($group_requests)) {
            $group_requests = [];
        }

        // Check if the request exists
        if (!in_array($requested_user_id, $group_requests, true)) {
            return new WP_REST_Response(array('success' => false, 'message' => sprintf('Request for %s doesn\'t exist or has already been processed for group "%s".', $requested_user->display_name, $group_post->post_title)), 409);
        }

        $response_message = '';
        if ($action === 'accept') {
            // Add user to the group
            $this->add_user_to_group($requested_user_id, $group_id);
            $response_message = sprintf('User %s successfully joined group "%s".', $requested_user->display_name, $group_post->post_title);

        } elseif ($action === 'decline') {
            $response_message = sprintf('Join request for %s to group "%s" successfully declined.', $requested_user->display_name, $group_post->post_title);
        }

        // Remove the request from group meta
        $this->remove_request_from_group_meta($group_id, $requested_user_id);
        // Remove the request from user meta
        $this->remove_request_from_user_meta($requested_user_id, $group_id);


        return new WP_REST_Response(array(
            'success'     => true,
            'message'     => $response_message,
            'group_id'    => $group_id,
            'group_title' => $group_post->post_title,
            'user_id'     => $requested_user_id,
            'action'      => $action,
        ), 200);
    }

    /**
     * Handles a user deleting/cancelling their own join request.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_delete_group_request(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $user_id_to_delete = $request->get_param('user_id'); // The user whose request is to be deleted
        $current_user_id = get_current_user_id();

        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        // Permission check: only the user who made the request or an admin can delete it
        if ($user_id_to_delete !== $current_user_id && !current_user_can('manage_options')) {
            return new WP_REST_Response(array('success' => false, 'message' => 'You do not have permission to delete this request.'), 403);
        }

        $requested_user = get_user_by('ID', $user_id_to_delete);
        if (!$requested_user) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Requested user not found.'), 404);
        }

        // Get current requests for the group
        $group_requests = get_post_meta($group_id, 'requests', true);
        if (!is_array($group_requests)) {
            $group_requests = [];
        }

        // Check if the request exists
        if (!in_array($user_id_to_delete, $group_requests, true)) {
            return new WP_REST_Response(array('success' => false, 'message' => sprintf('Request for %s to group "%s" doesn\'t exist or has already been processed.', $requested_user->display_name, $group_post->post_title)), 404);
        }

        // Remove the request from group meta
        $this->remove_request_from_group_meta($group_id, $user_id_to_delete);
        // Remove the request from user meta
        $this->remove_request_from_user_meta($user_id_to_delete, $group_id);

        return new WP_REST_Response(array('success' => true, 'message' => sprintf('Join request for %s to group "%s" successfully cancelled.', $requested_user->display_name, $group_post->post_title)), 200);
    }
}