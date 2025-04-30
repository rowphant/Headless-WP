<?php
/**
 * Register a new user
 * 
 * Required paramaters for Rest API:
 *      username
 *      email
 *      password
 *
 * @param  WP_REST_Request $request Full details about the request.
 * @return array $args.
 **/

class HWP_User_Register {
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_endpoint'));
    }

    public function register_endpoint()
    {
        register_rest_route('wp/v2', 'users/register', array(
            'methods' => 'POST',
            'callback' => array($this, 'register_user'),
        ));
    }

    public function register_user($request = null)
    {
        $response = array();
        $error = new WP_Error();

        $username = $request['username'];
        $email = $request['email'];
        $password = $request['password'];
        // $role = sanitize_text_field($parameters['role']);

        if (empty($username)) {
            $error->add(400, __("Username is required.", 'wp-rest-user'), array('status' => 400));
            return $error;
        }

        if (empty($email)) {
            $error->add(401, __("Email is required.", 'wp-rest-user'), array('status' => 400));
            return $error;
        }

        if (empty($password)) {
            $error->add(404, __("Password is required.", 'wp-rest-user'), array('status' => 400));
            return $error;
        }

        // if (empty($role)) {
        //     $role = 'subscriber';
        // } else {
        //     if ($GLOBALS['wp_roles']->is_role($role)) {
        //         // Silence is gold
        //     } else {
        //         $error->add(405, __("Role field 'role' is not a valid. Check your User Roles from Dashboard.", 'wp_rest_user'), array('status' => 400));
        //         return $error;
        //     }
        // }

        $user_id = username_exists($username);
        if (!$user_id && email_exists($email) == false) {

            // Create new user in Wordpress
            // $user_id = wp_create_user($username, $password, $email);
            // Randomly generated password
            $password = wp_generate_password();
            $user_id = wp_create_user($username, $password, $email);

            if (!is_wp_error($user_id)) {
                // Ger User Meta Data (Sensitive, Password included. DO NOT pass to front end.)
                $user = get_user_by('id', $user_id);
                // $user->set_role($role);
                $user->set_role('subscriber');
                // WooCommerce specific code
                if (class_exists('WooCommerce')) {
                    $user->set_role('customer');
                }
                // Ger User Data (Non-Sensitive, Pass to front end.)
                $response['code'] = 200;
                $response['message'] = __("User '" . $username . "' Registration was Successful", "wp-rest-user");
            } else {
                return $user_id;
            }
        } else {
            $error->add(406, __("Email or username already exists, please try 'Reset Password'", 'wp-rest-user'), array('status' => 400));
            return $error;
        }
        return new WP_REST_Response($response, 123);
    }
}

