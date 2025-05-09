<?php
class HWP_Reset_Password
{
    public function __construct()
    {
        $reset_password = get_option('headless_wp_settings')['hwp_reset_password'];

        if ($reset_password) {
            add_action('rest_api_init', function () {
                register_rest_route('wp/v2', '/reset-password/request', [
                    'methods' => 'POST',
                    'callback' => [$this, 'handle_password_reset_request'],
                    'permission_callback' => '__return_true'
                ]);

                register_rest_route('wp/v2', '/reset-password/confirm', [
                    'methods' => 'POST',
                    'callback' => [$this, 'handle_password_reset_confirm'],
                    'permission_callback' => '__return_true'
                ]);
            });
        }
    }

    public function handle_password_reset_request($request)
    {
        $email = sanitize_email($request['email']);
        $user = get_user_by('email', $email);

        if (!$user) {
            return new WP_Error('no_user', 'Kein Benutzer mit dieser E-Mail gefunden.', ['status' => 404]);
        }

        $reset_code = wp_generate_password(20, false);
        update_user_meta($user->ID, 'hwp_reset_code', $reset_code);
        update_user_meta($user->ID, 'hwp_reset_code_time', time());
        $base_url = 'http://localhost:3000';

        $reset_link = $base_url . '/reset-password?code=' . $reset_code . '&user_id=' . $user->ID;

        wp_mail($email, 'Passwort zurücksetzen', "Nutze diesen Link, um dein Passwort zurückzusetzen: $reset_link");

        return ['success' => true, 'message' => 'Eine E-Mail mit einem Link zum Zurücksetzen des Passwortes wurde gesendet. Der Link ist 1 Stunde gültig.'];
    }

    public function handle_password_reset_confirm($request)
    {
        $user_id = intval($request['user_id']);
        $code = sanitize_text_field($request['code']);
        $new_password = sanitize_text_field($request['new_password']);

        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('invalid_user', 'Ungültiger Benutzer.', ['status' => 404]);
        }

        $saved_code = get_user_meta($user_id, 'hwp_reset_code', true);
        $code_time = get_user_meta($user_id, 'hwp_reset_code_time', true);

        if ($saved_code !== $code || time() - $code_time > 3600) {
            return new WP_Error('invalid_code', 'Ungültiger oder abgelaufener Code.', ['status' => 400]);
        }

        wp_set_password($new_password, $user_id);
        delete_user_meta($user_id, 'hwp_reset_code');
        delete_user_meta($user_id, 'hwp_reset_code_time');

        return ['success' => true, 'message' => 'Passwort wurde erfolgreich zurückgesetzt.'];
    }
}
