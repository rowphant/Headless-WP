<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-user-groups-base.php';

class HWP_User_Groups_REST_API extends HWP_User_Groups_Base
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Extends the base add_hooks method with REST API specific hooks.
     */
    protected function add_hooks()
    {
        parent::add_hooks(); // Call parent to register post type

        add_action('rest_api_init', array($this, 'register_group_meta_for_rest_api'));
        add_action('rest_api_init', array($this, 'register_user_group_fields_for_rest_api'));
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

                        if ($field['type'] === 'text' && $field_name === 'invitations') {
                            return is_array($value) ? array_map('sanitize_email', $value) : [];
                        }

                        if ($field['type'] === 'user_multiselect') {
                            return is_array($value) ? array_map('intval', $value) : [];
                        }

                        return $value;
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
     * Registers custom fields for User for REST API, including group details.
     */
    public function register_user_group_fields_for_rest_api()
    {
        foreach ($this->user_get_custom_fields() as $field) {
            $user_meta_key = $field['user_name'];
            $group_field_id = $field['id'];

            register_rest_field(
                'user',
                $user_meta_key,
                array(
                    'get_callback' => function ($object, $field_name, $request) use ($user_meta_key, $group_field_id) {
                        $user_id = $object['id'];
                        $group_ids = [];
                        $allowed_statuses = ['publish', 'pending', 'draft', 'auto-draft', 'future', 'private']; // Include all post statuses except 'trash'

                        // Get groups where the user is the author
                        if ($field_name === 'group_author') {
                            $authored_posts = get_posts(array(
                                'author'         => $user_id,
                                'post_type'      => $this->post_type,
                                'posts_per_page' => -1,
                                'post_status'    => $allowed_statuses,
                                'fields'         => 'ids',
                            ));
                            $group_ids = $authored_posts;
                        } else {
                            $group_ids = get_user_meta($user_id, $user_meta_key, true);
                        }

                        // Filter and sanitize group IDs
                        $group_ids = is_array($group_ids) ? array_filter(array_map('intval', $group_ids)) : [];
                        
                        // Sort groups by title
                        usort($group_ids, function ($a, $b) {
                            $post_a = get_post($a);
                            $post_b = get_post($b);
                            return strcasecmp($post_a->post_title, $post_b->post_title);
                        });

                        $user_group_details = [];
                        foreach ($group_ids as $group_id) {
                            $group_post = get_post($group_id);

                            // Stop processing 'foreach' if the group is not one of the allowed post statuses
                            if (!$group_post || !in_array($group_post->post_status, $allowed_statuses, true)) {
                                continue;
                            }

                            $group_detail = [
                                'id'             => $group_id,
                                'title'          => $group_post->post_title,
                                'slug'           => $group_post->post_name,
                                'status'         => $group_post->post_status,
                                'author'         => intval($group_post->post_author),
                                'admins'         => get_post_meta($group_id, 'admins', true),
                                'members'        => get_post_meta($group_id, 'members', true),
                            ];

                            $current_user_id = get_current_user_id();
                            $is_current_user_admin_wp = current_user_can('manage_options');
                            $is_author_of_group = ($group_post->post_author == $current_user_id);
                            $group_admins_meta = (array)get_post_meta($group_id, 'admins', true);
                            $is_admin_of_group = in_array($current_user_id, $group_admins_meta);


                            if ($is_current_user_admin_wp || $is_author_of_group || $is_admin_of_group) {
                                $group_detail['requests'] = get_post_meta($group_id, 'requests', true);
                                $raw_invitations = get_post_meta($group_id, 'invitations', true);
                                $group_detail['invitations'] = is_array($raw_invitations) ? array_map('sanitize_email', $raw_invitations) : [];
                            } else {
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
}
