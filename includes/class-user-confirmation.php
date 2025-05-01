<?php

class HWP_User_Confirmation
{

    public function __construct()
    {
        // Show acivation code and status in user profile
        add_action('show_user_profile', array($this, 'show_user_meta_fields'));
        add_action('edit_user_profile', array($this, 'show_user_meta_fields'));

        // Show acivation code and status in user list
        add_filter('manage_users_columns', array($this, 'modify_user_list_table'));
        add_filter('manage_users_custom_column', array($this, 'add_user_list_table_row'), 10, 3);

        // Add user_register hook
        add_action('user_register', array($this, 'my_registration'), 10, 2);

        // Add a new endpoint to confirm user
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
        $code = $this->generateRandomString(32);

        // make it into a code to send it to user via email
        $string = array('id' => $user_id, 'code' => $code);

        // create the activation code and activation status
        update_user_meta($user_id, 'account_activated', 0);
        update_user_meta($user_id, 'activation_code', $code);

        // Remove user roles for new users (inactive users)
        $u = new WP_User($user_id);
        $u->set_role('');

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
    public function headless_wp_user_confirmation_endpoint()
    {
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
            update_user_meta($id, 'account_activated', 1);

            // Set user Role to recognize activated users
            $u = new WP_User($id);
            $u->set_role('subscriber');

            $response['code'] = 200;
            $response['message'] = 'Your account has been activated!';
        } else {
            $response['code'] = 200;
            $response['message'] = "The given activation code is either wrong or not valid anymore.";
        }

        return new WP_REST_Response($response, 123);
    }

    /**
     * API Endpoint: Send new verification email
     */
    public function headless_wp_endpoint_send_verification_email()
    {
        $users_can_register = get_option('users_can_register');

        if ($users_can_register) {
            register_rest_route('wp/v2', 'send-verification-email', array(
                'methods' => 'POST',
                'callback' => array($this, 'headless_wp_send_verification_email'),
            ));
        }
    }

    public function headless_wp_send_verification_email($request = null)
    {
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
    public function generateRandomString($stringLength = 32)
    {
        //specify characters to be used in generating random string, do not specify any characters that wordpress does not allow in the creation.
        $characters = "0123456789ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_[]{}!@$%^*().,>=-;|:?";

        //get the total length of specified characters to be used in generating random string
        $charactersLength = strlen($characters);

        //declare a string that we will use to create the random string 
        $randomString = '';

        for ($i = 0; $i < $stringLength; $i++) {
            //generate random characters
            $randomCharacter = $characters[rand(0, $charactersLength - 1)];
            //add the random characters to the random string
            $randomString .=  $randomCharacter;
        };

        //sanitize_user, just in case 
        $sanRandomString = sanitize_user($randomString);

        //check that random string contains Uppercase/Lowercase/Intergers/Special Char and that it is the correct length
        if ((preg_match('([a-zA-Z].*[0-9]|[0-9].*[a-zA-Z].*[_\W])', $sanRandomString) == 1) && (strlen($sanRandomString) == $stringLength)) {
            //return the random string if it meets the complexity criteria 
            return $sanRandomString;
        } else {
            // if the random string does not meet minimium criteria call function again 
            return $this->generateRandomString($stringLength);
        }
    }

    public function show_user_meta_fields($user)
    {
        $activation_code = get_user_meta($user->ID, 'activation_code', true);
        if (!empty($activation_code)) :
?>
            <h3><?php _e('User Activation Status', 'wp-rest-user'); ?></h3>
            <table class="form-table">
                <tr>
                    <th><label for="account_activated"><?php _e('Account Activated', 'wp-rest-user'); ?></label></th>
                    <td>
                        <?php
                        $account_activated = get_user_meta($user->ID, 'account_activated', true);
                        echo $account_activated ? __('🟢 Yes', 'wp-rest-user') : __('🔴 No', 'wp-rest-user');
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="activation_code"><?php _e('Activation Code', 'wp-rest-user'); ?></label></th>
                    <td>
                        <?php
                        $account_activated = get_user_meta($user->ID, 'activation_code', true);
                        echo $account_activated;
                        ?>
                    </td>
                </tr>
            </table>
<?php
        endif;
    }


    public function modify_user_list_table($columns)
    {
        $columns['account_activated'] = __('Account Activated', 'wp-rest-user');
        return $columns;
    }

    public function add_user_list_table_row($value, $column_name, $user_id)
    {
        if ($column_name == 'account_activated') {
            $activation_code = get_user_meta($user_id, 'activation_code', true);
            $account_activated = get_user_meta($user_id, 'account_activated', true);
            
            if ($activation_code) {
                $value = $account_activated ? __('🟢', 'wp-rest-user') : __('🔴', 'wp-rest-user');
            } else {
                $value = __('', 'wp-rest-user');
            }
        }
        return $value;
    }
}
