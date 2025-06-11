<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-user-groups-base.php';
require_once plugin_dir_path(__FILE__) . '../utils/class-mailer.php';

class HWP_User_Groups_Invitations extends HWP_User_Groups_Base
{
    private $mailer;

    public function __construct()
    {
        parent::__construct();
        $this->mailer = new HWP_Mailer();
        $this->add_hooks();
    }

    /**
     * Extends the base add_hooks method with invitation-specific hooks.
     */
    protected function add_hooks()
    {
        parent::add_hooks(); // Call parent to register post type

        add_action('rest_api_init', array($this, 'register_invitation_api_endpoint'));
        add_action('save_post_' . $this->post_type, array($this, 'handle_invitation_emails_on_group_save'));
    }

    /**
     * Registers the REST API endpoint for handling group invitations.
     * This now includes the endpoint for deleting invitations and creating new ones by email.
     */
    public function register_invitation_api_endpoint()
    {
        // Endpoint to accept/decline an invitation
        register_rest_route('hwp/v1', '/group-invitations', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_group_invitation_action'),
            'permission_callback' => array($this, 'permission_check_logged_in_user'),
            'args'                => array(
                'group_id' => array(
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the group.',
                ),
                // IMPORTANT: Identifier should be string because it can be an email or a user ID (as string).
                'identifier' => array(
                    'type'        => 'string',
                    'required'    => true,
                    'description' => 'User ID or email address.',
                ),
                'action' => array(
                    'type'        => 'string',
                    'required'    => true,
                    'enum'        => ['accept', 'decline'],
                    'description' => 'Action to perform: "accept" or "decline".',
                ),
            ),
        ));

        // Endpoint for deleting/revoking group invitations
        register_rest_route('hwp/v1', '/group-invitations/delete', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_delete_group_invitation'),
            'permission_callback' => array($this, 'permission_check_group_owner_or_admin'),
            'args'                => array(
                'group_id' => array(
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the group.',
                ),
                // This identifier should now also be the email for consistency in the meta field.
                'identifier' => array(
                    'type'        => 'string',
                    'required'    => true,
                    'description' => 'The email address of the invitee to delete the invitation for.',
                ),
            ),
        ));

        // NEW: Endpoint for creating/sending a group invitation by email
        register_rest_route('hwp/v1', '/group-invitations/send', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_send_email_invitation'),
            'permission_callback' => array($this, 'permission_check_group_owner_or_admin'), // Only group owner/admin can send invitations
            'args'                => array(
                'group_id' => array(
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'The ID of the group for which to send the invitation.',
                ),
                'email' => array(
                    'type'        => 'string',
                    'required'    => true,
                    'format'      => 'email', // Basic email format validation
                    'description' => 'The email address of the person to invite.',
                ),
            ),
        ));
    }

    /**
     * Handles sending invitation emails when a group is saved.
     * This method is called from `save_post` hook, but the actual email sending logic
     * is encapsulated here.
     *
     * @param int $post_id The ID of the post being saved.
     */
    public function handle_invitation_emails_on_group_save($post_id)
    {
        // Nonce verification for security
        if (!isset($_POST['user_groups_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['user_groups_meta_nonce'])), 'user_groups_meta_box')) {
            return $post_id;
        }

        // Check if the user has permissions to save this post.
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }

        // If this is an autosave, our form data will be empty.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        // Get group information
        $group = get_post($post_id);
        if (!$group || $group->post_type !== $this->post_type) {
            return $post_id;
        }

        // Get existing invitations from group meta
        // Now expecting this to primarily store emails for consistency
        $existing_group_invitations = get_post_meta($post_id, 'invitations', true);
        if (!is_array($existing_group_invitations)) {
            $existing_group_invitations = [];
        }

        // Get new invitations from post data
        $new_invitations_raw = isset($_POST['hwp_group_invitations']) ? sanitize_textarea_field(wp_unslash($_POST['hwp_group_invitations'])) : '';
        $new_invitations_array = array_map('trim', explode(',', $new_invitations_raw));
        $new_invitations_array = array_filter($new_invitations_array); // Remove empty entries

        $invitations_to_process = [];
        foreach ($new_invitations_array as $invitee_input) {
            if (is_email($invitee_input)) {
                // Check if this email is already invited (using the email as the identifier)
                if (!in_array($invitee_input, $existing_group_invitations, true)) {
                    $invitations_to_process[] = ['type' => 'email', 'identifier' => $invitee_input];
                }
            } elseif (is_numeric($invitee_input)) {
                $user_id = (int)$invitee_input;
                $user = get_user_by('ID', $user_id);
                if ($user) {
                    // Always check against the email in existing invitations
                    if (!in_array($user->user_email, $existing_group_invitations, true)) {
                        $invitations_to_process[] = ['type' => 'user_id', 'identifier' => $user_id]; // Store original ID for sending
                    }
                } else {
                    // Log or handle invalid user ID
                    error_log('Invalid user ID for invitation to group ' . $post_id . ': ' . $invitee_input);
                }
            } else {
                // Log or handle invalid input
                error_log('Invalid invitation input for group ' . $post_id . ': ' . $invitee_input);
            }
        }

        // Process new invitations
        foreach ($invitations_to_process as $invitation) {
            // Call the unified function to handle the entire invitation process
            $this->send_group_invitation($post_id, $invitation['identifier'], $invitation['type']);
        }
    }


    /**
     * Handles the acceptance or decline of a group invitation.
     * This method is called from the REST API endpoint.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_group_invitation_action(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $identifier_from_request = $request->get_param('identifier'); // Dies ist der Wert aus der Anfrage
        $action = $request->get_param('action');

        $group_post = get_post($group_id);

        if (!$group_post || $group_post->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        if (!in_array($action, ['accept', 'decline'])) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid action.'), 400);
        }

        // Check if the identifier is logged in (only if it's a user ID)
        if (is_numeric($identifier_from_request)) {
            $current_user = wp_get_current_user();
            $target_user = get_user_by('ID', $identifier_from_request);
            $email = $target_user->user_email ?: null;

            if (!$current_user || $current_user->ID !== $target_user->ID) {
                return new WP_REST_Response(array('success' => false, 'message' => 'The invited user must be logged in to accept the invitation.'), 403);
            }
        }

        // --- Bestimme die E-Mail, die im 'invitations'-Metafeld der Gruppe erwartet wird ---
        $target_user = null; // WordPress user object if found
        $invited_email_for_meta = null; // The email address to look for in 'invitations' meta

        if (filter_var($identifier_from_request, FILTER_VALIDATE_EMAIL)) {
            // Wenn der Request-Identifier eine E-Mail ist, verwenden wir diese direkt.
            $invited_email_for_meta = sanitize_email($identifier_from_request);
            $target_user = get_user_by('email', $invited_email_for_meta); // Versuche, einen existierenden User zu finden
        } else {
            // Wenn der Request-Identifier eine User ID ist, müssen wir die E-Mail dieser User ID holen.
            $target_user = get_user_by('ID', (int)$identifier_from_request);
            if ($target_user) {
                $invited_email_for_meta = $target_user->user_email;
            } else {
                // Hier ist ein Problem: ID wurde übergeben, aber kein User gefunden.
                // Könnte bedeuten, dass die ID falsch ist oder der User gelöscht wurde.
                return new WP_REST_Response(array('success' => false, 'message' => 'User (by ID) not found or invalid identifier.'), 404);
            }
        }

        // Falls wir keine E-Mail zum Suchen im Metafeld haben (sollte nach obiger Logik nicht passieren)
        if (empty($invited_email_for_meta)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Could not determine invitation email address.'), 400);
        }

        // Hole aktuelle Einladungen der Gruppe - Diese enthalten jetzt NUR E-Mails
        $group_meta_invitations = get_post_meta($group_id, 'invitations', true);
        if (!is_array($group_meta_invitations)) {
            $group_meta_invitations = [];
        }

        // Prüfung, ob die E-Mail in den Einladungen der Gruppe existiert (jetzt nach dem "already member" Check)
        if (!in_array($invited_email_for_meta, $group_meta_invitations, true)) {
            return new WP_REST_Response(array('success' => false, 'message' => sprintf('Invitation for %s doesn\'t exist or has already been processed for group "%s".', $invited_email_for_meta, $group_post->post_title)), 409);
        }

        // --- Initialisiere Variablen für die Antwort und User-Aktionen ---
        $response_message = '';
        $user_id_for_group_actions = $target_user ? $target_user->ID : null;
        $user_email_for_response = $invited_email_for_meta; // Für die Antwort verwenden wir die gefundene E-Mail 

        if ($action === 'accept') {
            // --- Logik für AKZEPTIEREN ---
            if ($user_id_for_group_actions) { // User existiert (entweder ID-Einladung oder Email-Einladung für bestehenden User)
                clean_post_cache($group_id);
                error_log("DEBUG: Before is_user_member_of_group check. User ID: {$user_id_for_group_actions}, Group ID: {$group_id}");
                // Prio 1: Prüfen, ob User bereits Mitglied ist!
                if ($this->is_user_member_of_group($user_id_for_group_actions, $group_id)) {
                    // Der User ist bereits Mitglied. Einladung bereinigen.
                    $this->remove_invitation_from_group_meta($group_id, $invited_email_for_meta);
                    $this->remove_invitation_from_user_meta($user_id_for_group_actions, $group_id); // Annahme: 'group_invitations' im User-Meta speichert Group IDs

                    return new WP_REST_Response(array('success' => true, 'message' => sprintf('%s is already a member of "%s". Invitation cleaned up.', $target_user->display_name, $group_post->post_title)), 200);
                } else {
                    error_log("DEBUG: is_user_member_of_group returned FALSE. User is NOT yet a member.");
                }

                // User zur Gruppe hinzufügen
                $this->add_user_to_group($user_id_for_group_actions, $group_id);
                $response_message .= sprintf('User %s successfully joined group "%s".', $target_user->display_name, $group_post->post_title);

                // Einladung aus User-Meta entfernen (wenn relevant)
                $this->remove_invitation_from_user_meta($user_id_for_group_actions, $group_id);
            } else { // E-Mail-Einladung für einen *noch nicht existierenden* User
                // Hier wird der User noch nicht zur Gruppe hinzugefügt.
                $response_message .= sprintf('%s has successfully accepted the invitation to group "%s". Please complete registration if necessary.', $invited_email_for_meta, $group_post->post_title);
            }

            // Einladung aus Gruppen-Meta entfernen (immer bei 'accept')
            $this->remove_invitation_from_group_meta($group_id, $invited_email_for_meta);
        } elseif ($action === 'decline') {
            // --- Logik für ABLEHNEN ---
            // Einladung aus Gruppen-Meta entfernen
            $this->remove_invitation_from_group_meta($group_id, $invited_email_for_meta);
            $response_message .= sprintf('Invitation for %s to group "%s" successfully declined.', $invited_email_for_meta, $group_post->post_title);

            // Einladung aus User-Meta entfernen, falls User existiert
            if ($user_id_for_group_actions) {
                $this->remove_invitation_from_user_meta($user_id_for_group_actions, $group_id);
            }
        }

        return new WP_REST_Response(array(
            'success'     => true,
            'message'     => $response_message,
            'group_id'    => $group_id,
            'group_title' => $group_post->post_title,
            'user_id'     => $user_id_for_group_actions,
            'user_name'   => $target_user ? $target_user->display_name : null,
            'email'       => $user_email_for_response,
            'action'      => $action,
        ));
    }

    /**
     * Handles deleting/revoking a group invitation.
     * This method is called from the REST API endpoint.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_delete_group_invitation(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $identifier_from_request = $request->get_param('identifier'); // Can be user ID or email

        $group_post = get_post($group_id);
        if (!$group_post || $group_post->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        // Determine the email address to remove from meta
        $email_to_remove = null;
        if (filter_var($identifier_from_request, FILTER_VALIDATE_EMAIL)) {
            $email_to_remove = sanitize_email($identifier_from_request);
        } else {
            // If identifier is a User ID, get the email from the user
            $user = get_user_by('ID', (int)$identifier_from_request);
            if ($user) {
                $email_to_remove = $user->user_email;
            } else {
                return new WP_REST_Response(array('success' => false, 'message' => 'Invalid identifier. Expected email address or valid user ID.'), 400);
            }
        }

        if (empty($email_to_remove)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Could not determine email address for deletion.'), 400);
        }

        // Get current invitations for the group (these should be emails)
        $group_invitations = get_post_meta($group_id, 'invitations', true);
        if (!is_array($group_invitations)) {
            $group_invitations = [];
        }

        // Check if the invitation exists (using the email address)
        if (!in_array($email_to_remove, $group_invitations, true)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invitation not found for this identifier in this group.'), 404);
        }

        // Remove the invitation from group meta
        $updated_group_invitations = array_values(array_diff($group_invitations, [$email_to_remove]));
        update_post_meta($group_id, 'invitations', $updated_group_invitations);

        $response_message = sprintf('Invitation for "%s" to group "%s" successfully deleted.', $email_to_remove, $group_post->post_title);

        // If a user exists for this email, also remove from user's meta
        $user_for_email = get_user_by('email', $email_to_remove);
        if ($user_for_email) {
            $user_invitations_meta = get_user_meta($user_for_email->ID, 'group_invitations', true);
            if (is_array($user_invitations_meta) && in_array($group_id, $user_invitations_meta)) {
                $updated_user_invitations_meta = array_values(array_diff($user_invitations_meta, [$group_id]));
                update_user_meta($user_for_email->ID, 'group_invitations', $updated_user_invitations_meta);
            }
            $response_message = sprintf('Invitation for user %s to group "%s" successfully revoked.', $user_for_email->display_name, $group_post->post_title);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => $response_message,
            'group_id' => $group_id,
            'identifier' => $email_to_remove,
        ), 200);
    }

    /**
     * NEW: Handles sending a group invitation via email from a REST endpoint.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_send_email_invitation(WP_REST_Request $request)
    {
        $group_id = $request->get_param('group_id');
        $invitee_email = sanitize_email($request->get_param('email'));

        if (!is_email($invitee_email)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid email address provided.'), 400);
        }

        $group = get_post($group_id);
        if (!$group || $group->post_type !== $this->post_type) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Group not found.'), 404);
        }

        // Check if this email is already invited or is a member
        $existing_group_invitations = get_post_meta($group_id, 'invitations', true);
        if (!is_array($existing_group_invitations)) {
            $existing_group_invitations = [];
        }

        if (in_array($invitee_email, $existing_group_invitations, true)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'This email address has already been invited to this group.'), 409);
        }

        // Also check if the email belongs to an existing member
        $user_by_email = get_user_by('email', $invitee_email);
        if ($user_by_email && $this->is_user_member_of_group($user_by_email->ID, $group_id)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'A user with this email address is already a member of this group.'), 409);
        }

        // Use the existing send_group_invitation method
        $sent = $this->send_group_invitation($group_id, $invitee_email, 'email');

        if ($sent) {
            return new WP_REST_Response(array('success' => true, 'message' => sprintf('Invitation sent to %s for group "%s".', $invitee_email, $group->post_title)), 200);
        } else {
            return new WP_REST_Response(array('success' => false, 'message' => 'Failed to send invitation. Please check server logs.'), 500);
        }
    }


    /**
     * Sends a group invitation email and updates associated metadata.
     * This function encapsulates the entire invitation sending process.
     *
     * @param int $group_id The ID of the group.
     * @param int|string $identifier The user ID or email address to invite.
     * @param string $type 'user_id' for user ID, 'email' for email address.
     * @return bool True on successful invitation, false on failure.
     */
    public function send_group_invitation($group_id, $identifier, $type)
    {
        $group = get_post($group_id);
        if (!$group || $group->post_status !== 'publish') {
            error_log('Failed to send invitation: Group not found or not published (ID: ' . $group_id . ').');
            return false;
        }

        $to = '';
        $subject = sprintf('You\'ve been invited to join the group "%s"', $group->post_title);
        $message = '';
        $target_user = null; // Will hold WP_User object if applicable
        $identifier_to_store_in_meta = ''; // This will ALWAYS be the email address for group meta

        // 1. Recognize user existence and get relevant data
        if ($type === 'user_id') {
            $target_user = get_user_by('ID', (int)$identifier);
            if (!$target_user) {
                error_log('Failed to send invitation: User not found for ID ' . $identifier . '.');
                return false;
            }
            $to = $target_user->user_email;
            $identifier_to_store_in_meta = $target_user->user_email; // Store email even if invited by ID
            $message = $this->mailer->get_invitation_email_body_for_user($target_user, $group);
        } elseif ($type === 'email') {
            if (!is_email($identifier)) {
                error_log('Failed to send invitation: Invalid email address ' . $identifier . '.');
                return false;
            }
            $to = $identifier;
            $identifier_to_store_in_meta = $identifier; // Store the provided email
            // Check if an account already exists for this email
            $target_user = get_user_by('email', $identifier);
            if ($target_user) {
                $message = $this->mailer->get_invitation_email_body_for_user($target_user, $group);
            } else {
                $message = $this->mailer->get_invitation_email_body_for_email($identifier, $group);
            }
        } else {
            error_log('Failed to send invitation: Invalid type specified (' . $type . ').');
            return false;
        }

        if (empty($to) || empty($message)) {
            error_log('Failed to send invitation: Email recipient or message body is empty.');
            return false;
        }

        // 2. Update the 'invitations' field in the user_group (post meta)
        // This array will now ONLY contain email addresses
        $group_invitations_meta = get_post_meta($group_id, 'invitations', true);
        if (!is_array($group_invitations_meta)) {
            $group_invitations_meta = [];
        }

        // Store the email address for consistency
        if (!in_array($identifier_to_store_in_meta, $group_invitations_meta, true)) {
            $group_invitations_meta[] = $identifier_to_store_in_meta;
            update_post_meta($group_id, 'invitations', $group_invitations_meta);
            error_log("DEBUG: Sent Invitation: Updated group_id {$group_id} invitations: " . print_r(get_post_meta($group_id, 'invitations', true), true)); // Hinzugefügt
        } else {
            error_log("DEBUG: Sent Invitation: Email " . $identifier_to_store_in_meta . " already invited to group " . $group_id);
        }


        // 3. Update the user's meta-field 'group_invitations' (if user exists)
        // This meta field still correctly stores Group IDs for the user.
        if ($target_user) {
            $user_invitations_meta = get_user_meta($target_user->ID, 'group_invitations', true);
            if (!is_array($user_invitations_meta)) {
                $user_invitations_meta = [];
            }
            if (!in_array($group_id, $user_invitations_meta)) {
                $user_invitations_meta[] = $group_id;
                update_user_meta($target_user->ID, 'group_invitations', $user_invitations_meta);
            }
        }

        // 4. Send the invitation email
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $email_sent = wp_mail($to, $subject, $message, $headers);

        if (!$email_sent) {
            error_log('Failed to send invitation email to ' . $to . ' for group ' . $group_id);
        }

        return $email_sent;
    }
}
