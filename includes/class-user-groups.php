<?php

class HWP_User_Groups
{
    public function __construct()
    {
        $user_groups = get_option('headless_wp_settings')['hwp_user_groups'];

        if ($user_groups) {
            add_action('init', array($this, 'register_user_groups_post_type'));
            add_action('add_meta_boxes', array($this, 'user_groups_add_custom_fields_meta_box'));
            add_action('save_post_user_group', array($this, 'user_groups_save_custom_fields'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('wp_ajax_hwp_user_search', array($this, 'ajax_user_search'));

            // Custom User Profile Fields für Gruppen
            add_action('show_user_profile', array($this, 'hwp_user_groups_profile_fields'), 1);
            add_action('edit_user_profile', array($this, 'hwp_user_groups_profile_fields'), 1);

            // Save Groups when User Profile is updated
            add_action('personal_options_update', array($this, 'hwp_user_groups_profile_save'));
            add_action('edit_user_profile_update', array($this, 'hwp_user_groups_profile_save'));

            add_action('wp_ajax_hwp_update_user_groups', array($this, 'hwp_update_user_groups_ajax'));

            // Add REST API fields for user
            add_action('rest_api_init', array($this, 'register_user_groups_rest_fields'));

            // API Endpoints for user groups
            add_action('rest_api_init', array($this, 'add_user_groups_rest_api'));
        }
    }

    public function register_user_groups_post_type()
    {
        $labels = array(
            'name'               => 'User Groups',
            'singular_name'      => 'User Group',
            'menu_name'          => 'User Groups',
            'name_admin_bar'     => 'User Group',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New User Group',
            'new_item'           => 'New User Group',
            'edit_item'          => 'Edit User Group',
            'view_item'          => 'View User Group',
            'all_items'          => 'Groups',
            'search_items'       => 'Search User Groups',
            'parent_item_colon'  => 'Parent User Groups:',
            'not_found'          => 'No user groups found.',
            'not_found_in_trash' => 'No user groups found in Trash.'
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'show_ui'            => true,
            'show_in_menu'       => 'users.php',
            'show_in_rest'       => true,
            'supports'           => array('title', 'editor', 'author', 'thumbnail', 'excerpt'),
            'has_archive'        => false,
            'rewrite'            => false,
            'menu_icon'          => 'dashicons-groups',
        );

        register_post_type('user-group', $args);
    }

    public function enqueue_admin_assets($hook)
    {
        global $post;

        if ($hook === 'post.php' || $hook === 'post-new.php' || $hook === 'user-edit.php' || $hook === 'profile.php') {

            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), null, true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');

            wp_enqueue_script('hwp_user_groups_admin', plugins_url('assets/admin-user-groups.js', __FILE__), array('jquery', 'select2', 'jquery-ui-sortable'), null, true);
            wp_enqueue_style('hwp_user_groups_admin_css', plugins_url('assets/admin-user-groups.css', __FILE__));

            // User fields
            wp_localize_script('hwp_user_groups_admin', 'hwp_user_groups_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('hwp_user_groups_ajax_nonce')
            ));

            // Group fields
            wp_localize_script('hwp_user_groups_admin', 'HWP_User_Groups', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('hwp_user_groups_nonce'),
            ));
        }
    }

    public function user_groups_add_custom_fields_meta_box()
    {
        add_meta_box(
            'user_groups_custom_fields',
            'User Group Details',
            array($this, 'user_groups_render_custom_fields_meta_box'),
            'user-group',
            'normal',
            'default'
        );
    }

    public function user_groups_get_custom_fields()
    {
        return array(
            array(
                'type' => 'user_multiselect',
                'id' => 'group_admins',
                'label' => 'Group administrators',
                'user_label' => 'Group administrator',
                'user_name' => 'admin',
                'description' => 'Select users who are administrators of this group.',
                'default' => array(),
            ),
            array(
                'type' => 'user_multiselect',
                'id' => 'group_members',
                'label' => 'Group members',
                'user_label' => 'Group member',
                'user_name' => 'member',
                'description' => 'Select users who are members of this group.',
                'default' => array(),
            ),
            array(
                'type' => 'user_multiselect',
                'id' => 'invited_users',
                'label' => 'Invited users',
                'user_label' => 'Group invitations',
                'user_name' => 'invitations',
                'description' => 'Users who are invited to join this group.',
                'default' => array(),
            ),
            array(
                'type' => 'user_multiselect',
                'id' => 'member_requests',
                'label' => 'Member requests',
                'user_label' => 'Group requests',
                'user_name' => 'requests',
                'description' => 'Users who have requested to join this group.',
                'default' => array(),
            ),
            // array(
            //     'type' => 'text',
            //     'id' => 'group_description',
            //     'label' => 'Group description',
            //     'description' => 'Short description of the group.',
            //     'default' => '',
            // ),
        );
    }

    public function user_groups_render_custom_fields_meta_box($post)
    {
        $fields = $this->user_groups_get_custom_fields();

        wp_nonce_field('user_groups_save_meta', 'user_groups_meta_nonce');

        foreach ($fields as $field) {
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

                    // Sortable List Placeholder
                    // echo '<ul class="hwp-selected-users hwp-sortable" data-field-id="' . esc_attr($id) . '">';
                    // foreach ($value as $user_id) {
                    //     $user = get_user_by('ID', $user_id);
                    //     if ($user) {
                    //         echo '<li data-user-id="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . ' <span class="remove">×</span></li>';
                    //     }
                    // }
                    // echo '</ul>';
                    break;

                case 'text':
                    echo '<input type="text" name="' . esc_attr($id) . '" value="' . esc_attr($value) . '" style="width:100%;">';
                    break;
            }

            echo '<hr>';
        }
    }

    public function user_groups_save_custom_fields($post_id)
    {
        if (!isset($_POST['user_groups_meta_nonce']) || !wp_verify_nonce($_POST['user_groups_meta_nonce'], 'user_groups_save_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = $this->user_groups_get_custom_fields();

        foreach ($fields as $field) {
            $id = $field['id'];

            if ($field['type'] === 'user_multiselect') {
                if (isset($_POST[$id]) && is_array($_POST[$id])) {
                    $cleaned_values = array_map('intval', $_POST[$id]);
                    update_post_meta($post_id, $id, $cleaned_values);
                } else {
                    delete_post_meta($post_id, $id);
                }
            } elseif ($field['type'] === 'text') {
                $value = isset($_POST[$id]) ? sanitize_text_field($_POST[$id]) : '';
                update_post_meta($post_id, $id, $value);
            }
        }
    }

    public function ajax_user_search()
    {
        check_ajax_referer('hwp_user_groups_nonce', 'nonce');

        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        $users = get_users(array(
            'search'         => '*' . esc_attr($search) . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'number'         => 20,
        ));

        $results = array_map(function ($user) {
            return array('id' => $user->ID, 'text' => $user->display_name . ' (' . $user->user_email . ')');
        }, $users);

        wp_send_json($results);
    }

    public function hwp_user_groups_profile_fields($user)
    {
        wp_nonce_field('hwp_user_groups_profile_update', 'hwp_user_groups_profile_nonce');

        $user_id = $user->ID;

        $user_groups = get_posts(array(
            'post_type'   => 'user-group',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));

        echo '<h3>User Groups</h2>';
        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';

        foreach ($this->user_get_custom_fields() as $field) {
            echo '<tr>';
            echo '<th><label for="' . esc_attr($field['id']) . '">' . esc_html($field['user_label']) . '</label></th>';
            echo '<td><div class="regular-text"><select class="hwp-user-group-select" name="' . esc_attr($field['id']) . '[]" multiple="multiple" style="width: 100%;">';

            foreach ($user_groups as $group) {
                $group_users = get_post_meta($group->ID, $field['id'], true);
                $group_users = is_array($group_users) ? $group_users : array();

                $selected = in_array($user_id, $group_users) ? 'selected' : '';
                echo '<option value="' . esc_attr($group->ID) . '" ' . $selected . '>' . esc_html($group->post_title) . '</option>';
            }

            echo '</select></div></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '<input type="hidden" id="hwp_user_id" value="' . esc_attr($user->ID) . '"></div>';
    }

    public function hwp_user_groups_profile_save($user_id)
    {
        if (!isset($_POST['hwp_user_groups_profile_nonce']) || !wp_verify_nonce($_POST['hwp_user_groups_profile_nonce'], 'hwp_user_groups_profile_update')) {
            return;
        }

        $user_id = intval($user_id);

        $user_groups = get_posts(array(
            'post_type'   => 'user-group',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));

        foreach ($this->user_get_custom_fields() as $field) {
            $meta_key = $field['id'];
            $new_groups = isset($_POST[$meta_key]) ? array_map('intval', (array)$_POST[$meta_key]) : array();

            foreach ($user_groups as $group) {
                $group_users = get_post_meta($group->ID, $meta_key, true);
                $group_users = is_array($group_users) ? $group_users : array();

                if (in_array($group->ID, $new_groups) && !in_array($user_id, $group_users)) {
                    $group_users[] = $user_id;
                } elseif (!in_array($group->ID, $new_groups) && in_array($user_id, $group_users)) {
                    $group_users = array_diff($group_users, array($user_id));
                }

                update_post_meta($group->ID, $meta_key, array_values($group_users));
            }
        }
    }

    public function user_get_custom_fields()
    {
        $user_group_fields = $this->user_groups_get_custom_fields();
        $user_group_fields = array_filter($this->user_groups_get_custom_fields(), function ($field) {
            return in_array($field['type'], ['user_multiselect']);
        });

        return $user_group_fields;
    }

    public function hwp_update_user_groups_ajax()
    {
        error_log('AJAX called: hwp_update_user_groups_ajax');
        if (!current_user_can('edit_users')) {
            wp_send_json_error('Unauthorized');
        }

        check_ajax_referer('hwp_user_groups_ajax_nonce', 'nonce');

        $user_id = intval($_POST['user_id']);
        $meta_key = sanitize_text_field($_POST['meta_key']);
        $group_ids = isset($_POST['group_ids']) ? array_map('intval', (array)$_POST['group_ids']) : array();

        $user_group_fields = $this->user_get_custom_fields();

        $filtered_fields = array_filter($user_group_fields, function ($field) use ($meta_key) {
            return $field['id'] === $meta_key;
        });

        if (empty($filtered_fields)) {
            wp_send_json_error('Invalid meta key');
        }

        // if (!in_array($meta_key, ['group_admins', 'group_members', 'pending_group_members'])) {
        //     wp_send_json_error('Invalid role');
        // }

        $user_groups = get_posts(array(
            'post_type'   => 'user-group',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));

        foreach ($user_groups as $group) {
            $group_users = get_post_meta($group->ID, $meta_key, true);
            $group_users = is_array($group_users) ? $group_users : array();

            if (in_array($group->ID, $group_ids) && !in_array($user_id, $group_users)) {
                $group_users[] = $user_id;
            } elseif (!in_array($group->ID, $group_ids) && in_array($user_id, $group_users)) {
                $group_users = array_diff($group_users, array($user_id));
            }

            update_post_meta($group->ID, $meta_key, array_values($group_users));
        }

        wp_send_json_success('User groups updated');
    }

    public function register_user_groups_rest_fields()
    {
        register_rest_field('user', 'user_groups', array(
            'get_callback' => array($this, 'get_user_groups_for_rest'),
            'schema'       => null,
        ));
    }

    public function get_user_groups_for_rest($user)
    {
        $user_id = $user['id'];

        $user_groups = get_posts(array(
            'post_type'   => 'user-group',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));

        $result = [];

        foreach ($this->user_get_custom_fields() as $field) {
            $meta_key = $field['id'];
            $name = $field['user_name'];
            $groups_for_user = [];

            foreach ($user_groups as $group) {
                $group_users = get_post_meta($group->ID, $meta_key, true);
                $group_users = is_array($group_users) ? $group_users : array();
                $members = count(get_post_meta($group->ID, 'group_members'));

                if (in_array($user_id, $group_users)) {
                    $groups_for_user[] = array(
                        'id'    => $group->ID,
                        'title' => $group->post_title,
                        'public' => get_post_status($group->ID) === 'publish',
                        'description' => get_post_meta($group->ID, 'group_description', true),
                        'members' => $members,
                        // 'admins' => get_post_meta($group->ID, 'group_admins', true),
                    );
                }
            }

            $result[$name] = $groups_for_user;
        }

        return $result;
    }


    public function add_user_groups_rest_api()
    {
        register_rest_route('headless-wp/v1', 'user-groups', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'create_user_group'),
                'permission_callback' => function () {
                    return current_user_can('edit_users');
                },
                'args'                => array(
                    'title' => array(
                        'required'    => true,
                        'type'        => 'string',
                    ),
                ),
            )
        ));
    }

    public function create_user_group($request)
    {
        $title = $request['title'];
        $post = array(
            'post_title'    => wp_strip_all_tags($title),
            'post_content'  => '',
            'post_status'   => 'publish',
            'post_author'   => get_current_user_id(),
            'post_type'     => 'user-group',
        );
        $post_id = wp_insert_post($post);
        return $post_id;
    }
}
