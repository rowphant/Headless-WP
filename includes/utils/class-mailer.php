<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

class HWP_Mailer {

    /**
     * Generates the HTML body for a group invitation email to an existing user.
     *
     * @param WP_User $user The invited user object.
     * @param WP_Post $group The group post object.
     * @param string  $token The invitation token.
     * @return string The HTML email body.
     */
    public function get_invitation_email_body_for_user(WP_User $user, WP_Post $group) {
        $host = get_option('headless_wp_settings')['hwp_app_host'] ?: 'http://localhost:3000';
        // $invitation_link = esc_url(get_home_url() . '/join-group/?group_id=' . $group->ID . '&user_id=' . $user->ID . '&token=' . $token);
        // $decline_link = esc_url(get_home_url() . '/decline-group-invite/?group_id=' . $group->ID . '&user_id=' . $user->ID . '&token=' . $token);
        $invitation_link = esc_url($host . '/group-invitation/?a=1&gid=' . $group->ID . '&uid=' . $user->ID . '&t=' . $token);
        $decline_link = esc_url($host . '/group-invitation/?a=0&gid=' . $group->ID . '&uid=' . $user->ID . '&t=' . $token);

        return sprintf(
            'Hello %s,<br><br>' .
            'You have been invited to join the group "%s".<br><br>' .
            'To accept the invitation, please click the following link:<br>' .
            '<a href="%s">%s</a><br><br>' .
            'If you don\'t want to join this group, you can ignore this email or click here to decline: <a href="%s">%s</a><br><br>' .
            'Best regards,<br>' .
            'Your Website Team',
            $user->display_name,
            $group->post_title,
            $invitation_link,
            $invitation_link,
            $decline_link,
            $decline_link
        );
    }

    /**
     * Generates the HTML body for a group invitation email to a non-existing user (email address).
     *
     * @param string  $email The invited email address.
     * @param WP_Post $group The group post object.
     * @param string  $token The invitation token.
     * @return string The HTML email body.
     */
    public function get_invitation_email_body_for_email($email, WP_Post $group) {
        $registration_link = esc_url(get_home_url() . '/register-and-join-group/?group_id=' . $group->ID . '&email=' . rawurlencode($email) . '&token=' . $token);
        $decline_link = esc_url(get_home_url() . '/decline-group-invite/?group_id=' . $group->ID . '&email=' . rawurlencode($email) . '&token=' . $token);

        return sprintf(
            'Hello,<br><br>' .
            'You have been invited to join the group "%s" on our website.<br>' .
            'It appears you do not have an account with us yet. To join the group, please register first.<br><br>' .
            'To register and accept the invitation, please click the following link:<br>' .
            '<a href="%s">%s</a><br><br>' .
            'If you don\'t want to join this group, you can ignore this email or click here to decline: <a href="%s">%s</a><br><br>' .
            'Best regards,<br>' .
            'Your Website Team',
            $group->post_title,
            $registration_link,
            $registration_link,
            $decline_link,
            $decline_link
        );
    }

    // You can add more methods here for other email types (e.g., request accepted, request rejected)
    // public function get_request_accepted_email_body(...) { ... }
}