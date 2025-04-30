<?php

class HWP_User_Confirmation {

    public function __construct() {
        add_action('user_register', array($this, 'my_registration'), 10, 2);
        add_action('rest_api_init', array($this, 'headless_wp_user_confirmation_endpoint'));

        // Add a new endpoint to send verification email
        add_action('rest_api_init', array($this, 'headless_wp_endpoint_send_verification_email'));
    }

    /**
     * Add user_register hook, to send a confirmation email, after a new user has submitted the register form
     */
    public function my_registration($user_id)
    {
        // create md5 code to verify later
        $code = generateRandomString(32);

        // make it into a code to send it to user via email
        $string = array('id' => $user_id, 'code' => $code);

        // create the activation code and activation status
        update_user_meta($user_id, 'account_activated', 0);
        update_user_meta($user_id, 'activation_code', $code);

        // Remove user roles for new users (inactive users)
        $u = new WP_User( $user_id );
        $u->set_role( '' );

        // $role->remove_cap( 'activate_plugins' );
        // $role->remove_cap( 'update_plugins' );

        // create the url
        // $base_url = get_site_url();
        // $base_url = "https://robertmetzner.com";
        $base_url = "http://localhost:3000";
        $url = $base_url . '/confirm-user?act=' . base64_encode(serialize($string));

        // get user data
        $user_info = get_userdata($user_id);

        // set parameters for wp_mail
        $to = $user_info->user_email;
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $subject = 'Confirm your email address at robertmetzner.com';
        $body = '<h1>Your registration needs to be confirmed.</h1><p><a href="' . $url . '">Click here to verify your email address</a></p>';

        // send an email out to user
        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Verify user by given activation code
     */
    public function headless_wp_user_confirmation_endpoint() {
        $users_can_register = get_option('users_can_register');

        if ($users_can_register) {
            register_rest_route('wp/v2', 'confirm', array(
                'methods' => 'POST',
                'callback' => array($this, 'headless_wp_user_confirmation'),
            ));
        }
    }

    public function headless_wp_user_confirmation($request = null)
    {
        $response = array();

        $activation_code = $request['code'];
        $error = new WP_Error();

        if (empty($activation_code)) {
            $error->add(400, __("Confirmation code is required.", 'wp-rest-user'), array('status' => 400));
            return $error;
        }

        $data = unserialize(base64_decode($activation_code));
        $id = $data['id'];
        $code = get_user_meta($id, 'activation_code', true);

        if ($id && $code && ($code === $data['code'])) {
            // update the user meta
            update_user_meta($id, 'is_activated', 1);

            // Set user Role to recognize activated users
            $u = new WP_User( $id );
            $u->set_role( 'subscriber' );

            $response['code'] = 200;
            // $message = "Your account has been activated! Code: ". $code. "<br/>". $data['code'];
            $response['message'] = 'Your account has been activated!';
        } else {
            $response['code'] = 200;
            $response['message'] = "The given activation code is either wrong or not valid anymore.";
            // $error->add(406, __("The given activation code is either wrong or not valid anymore.", 'wp-rest-user'), array('status' => 400));
            // return $error;
        }

        return new WP_REST_Response($response, 123);
    }

    /**
     * API Endpoint: Send new verification email
     */
    public function headless_wp_endpoint_send_verification_email() {
        $users_can_register = get_option('users_can_register');

        if ($users_can_register) {
            register_rest_route('wp/v2', 'send-verification-email', array(
                'methods' => 'POST',
                'callback' => array($this, 'headless_wp_send_verification_email'),
            ));
        }
    }

    public function headless_wp_send_verification_email($request = null) {
        $response = array();

        $user_id = $request['id'];
        $user_data = get_userdata($user_id);

        if ($user_data) {
            $user_email = $user_data->user_email;
        
            $code = get_user_meta($user_id, 'activation_code', true);
            $string = array('id' => $user_id, 'code' => $code);
            $base_url = "http://localhost:3000";
            $url = $base_url . '/confirm-user?act=' . base64_encode(serialize($string));
        
            // set parameters for wp_mail
            $to = $user_email;
            $headers = array('Content-Type: text/html; charset=UTF-8');
            $subject = 'Verifiy your email address at robertmetzner.com';
            $body = '<h1>To complete your registration at robertmetzner.com you need to verify your email address.</h1><p><a href="' . $url . '">Click here to verify your email address</a></p>';
        
            // send an email out to user
            wp_mail($to, $subject, $body, $headers);
        
            $response['message'] = "Verification email has been send.";
            $response['code'] = 200;
        } else {
            $error = new WP_Error();
            $error->add(400, __("Oops. Something went wrong.", 'wp-rest-user'), array('status' => 400));
            return $error;
        }

        return new WP_REST_Response($response, 123);
    }
}

