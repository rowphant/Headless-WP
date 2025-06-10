<?php

class HWP_User_Confirmation
{
    public function __construct()
    {
        $confirm_new_users = get_option('headless_wp_settings')['hwp_confirm_new_users'];

        if ($confirm_new_users) {
            // Show acivation code and status in user profile
            add_action('show_user_profile', array($this, 'show_user_meta_fields'));
            add_action('edit_user_profile', array($this, 'show_user_meta_fields'));
            add_action('personal_options_update', array($this, 'save_user_meta_fields'));
            add_action('edit_user_profile_update', array($this, 'save_user_meta_fields'));

            // Show acivation code and status in user list
            add_filter('manage_users_columns', array($this, 'modify_user_list_table'));
            add_filter('manage_users_custom_column', array($this, 'add_user_list_table_row'), 10, 3);

            // Add user_register hook
            add_action('user_register', array($this, 'init_activation_code'), 10, 2);

            // Add a new endpoint to confirm user
            add_action('rest_api_init', array($this, 'headless_wp_user_confirmation_endpoint'));

            // Add a new endpoint to send verification email
            add_action('rest_api_init', array($this, 'headless_wp_endpoint_send_verification_email'));

            // Add user meta field "account_activated" to the API endpoint users/me
            add_action('rest_api_init', array($this, 'add_account_activated_to_api'));
        }
    }

    /**
     * Add user_register hook, to send a confirmation email, after a new user has submitted the register form
     */
    public function init_activation_code($user_id)
    {
        $user_data = get_userdata($user_id);
        $response = array();
        $error = new WP_Error();

        if (!$user_data) {
            $response['code'] = 400;
            $response['message'] = 'User not found';
        } else {
            // create md5 code to verify later
            $code = $this->generateRandomString(32);

            // create the activation code and activation status
            update_user_meta($user_id, 'account_activated', false);
            update_user_meta($user_id, 'activation_code', $code);
            update_user_meta($user_id, 'activation_code_date',  new DateTime());

            // Remove user roles for new users (inactive users)
            $u = new WP_User($user_id);
            $u->set_role('');

            // make it into a code to send it to user via email
            $string = array('id' => $user_id, 'code' => $code);

            // create the url
            $confirmation_path = get_option('headless_wp_settings')['hwp_confirmation_path'] ?: 'confirm-user';
            $base_url = get_option('headless_wp_settings')['hwp_app_host'] ?: 'http://localhost:3000';
            $url = $base_url . '/' . $confirmation_path . '?code=' . base64_encode(serialize($string));

            // get user data
            $user_info = get_userdata($user_id);

            // set parameters for wp_mail
            $to = $user_info->user_email;
            $headers = array('Content-Type: text/html; charset=UTF-8');
            $subject = 'Confirm your email address at robertmetzner.com';
            $body = '<h1>Your registration needs to be confirmed.</h1><p><a href="' . $url . '">Click here to verify your email address</a></p>';

            // send an email out to user
            wp_mail($to, $subject, $body, $headers);

            $response['code'] = 200;
            $response['message'] = 'Verification email has been sent!';
        }

        return $response;
    }

    public function wphg_sendConfirmationEmail($args = array(
        'to' => null,
        'subject' => null,
        'body' => null,
    ))
    {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $to = (isset($args['to']) && $args['to']) ? $args['to'] : get_option('admin_email');
        $subject = (isset($args['subject']) && $args['subject']) ? $args['subject'] : 'Confirm your email address at robertmetzner.com';
        $body = $args['body'];

        if (empty($body)) {
            $body = '<h1>Test E-Mail Body</h1>';
        }

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Verify user by given activation code
     */
    public function headless_wp_user_confirmation_endpoint()
    {
        register_rest_route('wp/v2', 'confirm-user', array(
            'methods' => 'POST',
            'callback' => array($this, 'headless_wp_user_confirmation'),
        ));
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
        $code_date = get_user_meta($id, 'activation_code_date', true);
        $expiration_time = get_option('headless_wp_settings')['hwp_confirm_users_expiration'] ?: 60;

        // Check if code is expired

        if ($code_date) {
            // Überprüfen, ob $code_date bereits ein DateTime-Objekt ist
            if ($code_date instanceof DateTime) {
                $activation_time = $code_date; // Verwende das vorhandene Objekt direkt
            } else {
                // Andernfalls versuche, ein DateTime-Objekt aus dem String zu erstellen
                try {
                    $activation_time = new DateTime($code_date);
                } catch (Exception $e) {
                    // Falls $code_date kein gültiger String ist, behandle den Fehler
                    $response['code'] = 400;
                    $response['message'] = "Invalid activation code date format.";
                    return new WP_REST_Response($response, 400);
                }
            }

            $current_time = new DateTime();
            $interval = $current_time->diff($activation_time);
            $minutes = $interval->i + ($interval->h * 60); // Korrektur: $interval->h sollte mit 60 multipliziert werden

            if ($minutes > $expiration_time) { // Vergleich mit $expiration_time
                $response['code'] = 400;
                $response['message'] = "The activation code has expired.";
                return new WP_REST_Response($response, 400);
            }
        }

        $code = get_user_meta($id, 'activation_code', true);

        if ($id && $code && ($code === $data['code'])) {
            // update the user meta
            update_user_meta($id, 'account_activated', true);

            // Set user Role to recognize activated users
            $u = new WP_User($id);
            $u->set_role('subscriber');

            $response['code'] = 200;
            $response['message'] = 'Your account has been activated!';
        } else {
            $response['code'] = 401;
            $response['message'] = "The given activation code is either wrong or not valid anymore.";
        }

        return new WP_REST_Response($response, 123);
    }

    /**
     * API Endpoint: Send new verification email
     */
    public function headless_wp_endpoint_send_verification_email()
    {
        $users_can_register = get_option('headless_wp_settings')['hwp_user_registration'];

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
        $init_activation_code = $this->init_activation_code($user_id);

        if ($init_activation_code) {
            $response = $init_activation_code;
        }

        return new WP_REST_Response([
            'success' => ($response['code'] === 200),
            'data'    => [
                'message' => __($response['message'], 'wp-rest-user'),
            ],
        ], $response['code']);
    }

    public function generateRandomString($stringLength = 32)
    {
        $characters = "0123456789ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_[]{}!@$%^*().,>=-;|:?";
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $stringLength; $i++) {
            $randomCharacter = $characters[rand(0, $charactersLength - 1)];
            $randomString .=  $randomCharacter;
        };

        $sanRandomString = sanitize_user($randomString);

        if ((preg_match('([a-zA-Z].*[0-9]|[0-9].*[a-zA-Z].*[_\W])', $sanRandomString) == 1) && (strlen($sanRandomString) == $stringLength)) {
            return $sanRandomString;
        } else {
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
                        <select name="account_activated" id="account_activated">
                            <option value="1" <?php selected(get_user_meta($user->ID, 'account_activated', true), 1); ?>>
                                <?php _e('🟢 Active', 'wp-rest-user'); ?>
                            </option>
                            <option value="0" <?php selected(get_user_meta($user->ID, 'account_activated', true), 0); ?>>
                                <?php _e('🔴 Not active', 'wp-rest-user'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <!-- <tr>
                    <th><label for="activation_code"><?php _e('Activation Code', 'wp-rest-user'); ?></label></th>
                    <td>
                        <?php
                        $account_activated = get_user_meta($user->ID, 'activation_code', true);
                        echo $account_activated;
                        ?>
                    </td>
                </tr> -->
            </table>
<?php
        endif;
    }

    public function save_user_meta_fields($user_id)
    {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }

        if (isset($_POST['account_activated'])) {
            update_user_meta($user_id, 'account_activated', $_POST['account_activated']);
        }
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

    // Add user meta field "account_activated" to the API endpoint users/me
    public function add_account_activated_to_api()
    {
        register_rest_field('user', 'account_activated', array(
            'get_callback'    => function ($user) {
                $state = (int) get_user_meta($user['id'], 'account_activated', true);

                if (current_user_can('administrator')) {
                    $state = 1;
                }

                return $state;
            },
            'update_callback' => function ($value, $user, $field_name) {
                if ($value !== null) {
                    update_user_meta($user->ID, $field_name, $value ? 1 : 0);
                }
            },
            'schema'          => array(
                'description' => __('Whether the user account is activated.'),
                'type'        => 'boolean',
            ),
        ));
    }
}
