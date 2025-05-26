<?php

class HWP_Options
{
    private $options;
    private $defaults;
    private $fields = [];

    public function __construct()
    {
        $this->fields = array(
            array(
                'type' => 'text',
                'id' => 'hwp_app_host',
                'label' => 'App Host',
                'default' => 'http://localhost:3000',
                'tab' => 'General',
            ),
            // array(
            //     'type' => 'headline',
            //     'id' => 'headline_user_registration',
            //     'label' => 'User registration',
            //     'tab' => 'User',
            // ),
            array(
                'type' => 'select',
                'id' => 'hwp_user_registration',
                'label' => 'API User registration',
                'description' => 'Allow users to register via the API',
                'options' => array(
                    0 => 'No',
                    1 => 'Yes',
                    'global' => 'Global'
                ),
                'default' => 'global',
                'tab' => 'API',
            ),
            array(
                'type' => 'select',
                'id' => 'hwp_reset_password',
                'label' => 'API Reset password',
                'description' => 'Allow users to reset their password.',
                'options' => array(
                    0 => 'No',
                    1 => 'Yes'
                ),
                'default' => 1,
                'tab' => 'API',
            ),
            // array(
            //     'type' => 'headline',
            //     'id' => 'headline_user_confirmation',
            //     'label' => 'User confirmation',
            //     'tab' => 'Account activation',
            // ),
            array(
                'type' => 'select',
                'id' => 'hwp_confirm_new_users',
                'label' => 'Confirm new users',
                'description' => 'Send a confirmation email to new users.',
                'options' => array(
                    0 => 'No',
                    1 => 'Yes'
                ),
                'default' => 1,
                'tab' => 'Account activation',
            ),
            array(
                'type' => 'text',
                'id' => 'hwp_confirm_users_expiration',
                'label' => 'Confirmation link expiration',
                'description' => 'The time in minutes before the confirmation link expires.',
                'prefix' => '',
                'suffix' => ' minutes',
                'default' => 60,
                'tab' => 'Account activation',
            ),
            array(
                'type' => 'text',
                'id' => 'hwp_confirmation_path',
                'label' => 'Confirmation path',
                'description' => 'The path is used in the confirmation link.',
                'prefix' => empty(get_option('headless_wp_settings')['hwp_app_host']) ? 'http://localhost:3000/&nbsp;' : esc_url(get_option('headless_wp_settings')['hwp_app_host']) . '/',
                'default' => 'confirm-user',
                'tab' => 'Account activation',
            ),
            array(
                'type' => 'custom_send_email',
                'id' => 'hwp_send_test_email',
                'label' => 'Send confirmation email',
                'description' => 'Send a test confirmation email to this address.',
                'default' => get_option('admin_email'),
                'tab' => 'Account activation',
            ),
            // array(
            //     'type' => 'headline',
            //     'id' => 'headline_user_profile_image',
            //     'label' => 'User profile image',
            //     'tab' => 'User',
            // ),
            array(
                'type' => 'select',
                'id' => 'hwp_user_profile_image',
                'label' => 'User image',
                'description' => 'Enable the field "profile image".',
                'options' => array(
                    0 => 'No',
                    1 => 'Yes'
                ),
                'default' => 1,
                'tab' => 'General',
            ),
            array(
                'type' => 'select',
                'id' => 'hwp_upload_user_image',
                'label' => 'API Upload user image',
                'description' => 'Allow users to upload a profile image.',
                'options' => array(
                    0 => 'No',
                    1 => 'Yes'
                ),
                'default' => 1,
                'tab' => 'API',
            ),
            array(
                'type' => 'select',
                'id' => 'hwp_upload_user_image_multiple',
                'label' => 'API Upload multiple user images',
                'description' => 'Allow users to upload multiple profile images to the media library. If not enabled, old media files will be deleted when a new image is uploaded.',
                'options' => array(
                    0 => 'No',
                    1 => 'Yes'
                ),
                'default' => 0,
                'tab' => 'API',
            ),
            array(
                'type' => 'select',
                'id' => 'hwp_user_groups',
                'label' => 'Enable',
                // 'description' => 'Allow users to upload multiple profile images to the media library. If not enabled, old media files will be deleted when a new image is uploaded.',
                'options' => array(
                    0 => 'No',
                    1 => 'Yes'
                ),
                'default' => 0,
                'tab' => 'User groups',
            ),
            array(
                'type' => 'select',
                'id' => 'hwp_user_groups_default_status',
                'label' => 'Default status',
                'description' => 'Status of a group after creation.',
                'options' => array(
                    'private' => 'Private',
                    'public' => 'Public'
                ),
                'default' => 'private',
                'tab' => 'User groups',
            ),
            array(
                'type' => 'select',
                'id' => 'hwp_user_groups_status_editable',
                'label' => 'Editable',
                'description' => 'Allow the group admin to edit the status of the group.',
                'options' => array(
                    0 => 'No',
                    1 => 'Yes'
                ),
                'default' => 0,
                'tab' => 'User groups',
            ),
        );

        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'page_init'));
        add_action('rest_api_init', array($this, 'add_api_endpoint'));

        add_action('admin_enqueue_scripts', function () {
            wp_localize_script('jquery', 'wpApiSettings', array(
                'nonce' => wp_create_nonce('wp_rest')
            ));
        });
    }

    public function add_plugin_page()
    {
        add_options_page(
            'Headless WP Settings',
            'Headless WP',
            'manage_options',
            'headless-wp-settings',
            array($this, 'create_admin_page')
        );
    }

    public function create_admin_page()
    {
        $tabs = array_unique(array_column($this->fields, 'tab'));
?>
        <div class="wrap">
            <h1>Headless WP Settings</h1>
            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $index => $tab) : ?>
                    <a href="#tab-<?php echo $index; ?>" class="nav-tab"><?php echo esc_html($tab); ?></a>
                <?php endforeach; ?>
            </h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('headless_wp_settings_group');
                foreach ($tabs as $index => $tab) {
                    echo '<div id="tab-' . esc_attr($index) . '" class="hwp-tab-content">';
                    do_settings_sections('headless-wp-settings-' . $index);
                    echo '</div>';
                }
                submit_button();
                ?>
            </form>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('.hwp-tab-content').hide();
                $('.hwp-tab-content').first().show();
                $('.nav-tab').first().addClass('nav-tab-active');

                $('.nav-tab').click(function(e) {
                    e.preventDefault();
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');

                    $('.hwp-tab-content').hide();
                    $($(this).attr('href')).show();
                });

                // Test Mail Button
                $('#hwp_resend_confirmation_button').on('click', function() {
                    if (!confirm('Send confirmation email to test user?')) return;

                    $.post(ajaxurl, {
                        action: 'hwp_send_confirmation'
                    }, function(response) {
                        if (response.success) {
                            alert('Confirmation email sent successfully.');
                        } else {
                            alert('Error: ' + response.data);
                        }
                    });
                });
            });
        </script>
<?php
    }

    public function page_init()
    {
        register_setting('headless_wp_settings_group', 'headless_wp_settings', array($this, 'validate'));

        $tabs = array_unique(array_column($this->fields, 'tab'));

        foreach ($tabs as $index => $tab_name) {
            $section_id = 'hwp_tab_section_' . $index;

            add_settings_section(
                $section_id,
                '', // Keine Überschrift nötig
                '__return_false', // Kein Output (Div Handling passiert im Admin Page)
                'headless-wp-settings-' . $index // pro Tab eigene Page
            );

            foreach ($this->fields as $field) {
                if ($field['tab'] !== $tab_name) continue;

                if ($field['type'] === 'headline') {
                    add_settings_field(
                        $field['id'],
                        sprintf('<h3 class="h3" style="margin-bottom: 0;">%s</h3>', esc_html($field['label'])),
                        array($this, 'create_headline_callback'),
                        'headless-wp-settings-' . $index,
                        $section_id,
                        $field
                    );
                } else {
                    add_settings_field(
                        $field['id'],
                        sprintf('<div class="h3" style="margin-bottom: 0;">%s</div><p style="margin-top: 0; font-weight: normal; opacity: .6;">%s</p>', esc_html($field['label']), esc_html($field['description'] ?? '')),
                        array($this, 'create_field_callback'),
                        'headless-wp-settings-' . $index,
                        $section_id,
                        array(
                            'type' => $field['type'],
                            'id' => $field['id'],
                            'description' => $field['description'] ?? '',
                            'options' => $field['options'] ?? array(),
                            'prefix' => $field['prefix'] ?? '',
                            'suffix' => $field['suffix'] ?? '',
                            'default' => $field['default'] ?? ''
                        )
                    );
                }
            }
        }
    }



    public function print_section_info() {}

    public function create_field_callback($args)
    {
        $options = get_option('headless_wp_settings');
        foreach ($this->fields as $field) {
            if (!isset($options[$field['id']])) {
                $options[$field['id']] = $field['default'] ?? '';
            }
        }

        $value = $options[$args['id']] ?? $args['default'];
        switch ($args['type']) {
            case 'text':
                printf(
                    '<span style="opacity: .6;">%s</span><input type="text" id="%s" name="headless_wp_settings[%s]" value="%s" placeholder="%s"/><span style="opacity: .6;">%s</span>',
                    esc_html($args['prefix']),
                    esc_attr($args['id']),
                    esc_attr($args['id']),
                    esc_attr($value),
                    esc_attr($args['default']),
                    esc_html($args['suffix']),
                );
                break;
            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="headless_wp_settings[%s]" %s />',
                    esc_attr($args['id']),
                    esc_attr($args['id']),
                    checked($value, true, false)
                );
                break;
            case 'radio':
                foreach ($args['options'] as $key => $label) {
                    printf(
                        '<label><input type="radio" id="%s[%s]" name="headless_wp_settings[%s]" value="%s" %s /> %s</label><br>',
                        esc_attr($args['id']),
                        esc_attr($key),
                        esc_attr($args['id']),
                        esc_attr($key),
                        checked($value, $key, false),
                        esc_html($label)
                    );
                }
                break;
            case 'select':
                printf(
                    '<select id="%s" name="headless_wp_settings[%s]">',
                    esc_attr($args['id']),
                    esc_attr($args['id'])
                );
                foreach ($args['options'] as $key => $label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($key),
                        selected($value, $key, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
                break;
            case 'custom_send_email':
                $input_id = esc_attr($args['id']) . '_input';
                $button_id = esc_attr($args['id']) . '_button';
                $default_email = esc_attr($value);

                echo '<input type="email" id="' . $input_id . '" value="' . $default_email . '" style="min-width: 300px; margin-right: 10px;" />';
                echo '<button type="button" id="' . $button_id . '" class="button button-primary">Send confirmation email</button>';
                echo '<p class="description">' . esc_html($args['description']) . '</p>';

                // JS Output
                echo "<script>
        document.addEventListener('DOMContentLoaded', function () {
            const button = document.getElementById('$button_id');
            const input = document.getElementById('$input_id');
            button.addEventListener('click', function () {
                const email = input.value;
                if (!email) {
                    alert('Please enter a valid email address.');
                    return;
                }
                fetch('" . esc_url_raw(rest_url('headless-wp/v1/send-test-confirmation')) . "', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': wpApiSettings.nonce
                    },
                    body: JSON.stringify({ email: email })
                })
                .then(res => res.json())
                .then(data => {
                    alert(data.message || 'Email sent successfully.');
                })
                .catch(error => {
                    alert('Error sending email');
                    console.error(error);
                });
            });
        });
    </script>";
                break;
        }
    }

    public function create_headline_callback($args)
    {
        printf(
            '<h2>%s</h2>',
            esc_html($args['label'])
        );
    }

    public function validate($input)
    {
        $validated = array();
        foreach ($input as $key => $value) {
            $validated[$key] = sanitize_text_field($value);
        }
        return $validated;
    }

    public function add_api_endpoint()
    {
        register_rest_route('headless-wp/v1', '/options', array(
            'methods' => 'GET',
            'callback' => [$this, 'get_fields'],
            'permission_callback' => '__return_true'
        ));

        register_rest_route('headless-wp/v1', '/send-test-confirmation', array(
            'methods' => 'POST',
            'callback' => [$this, 'handle_test_email_send'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    public function get_fields()
    {
        $options = get_option('headless_wp_settings', []);

        $fields_data = array_reduce($this->fields, function ($carry, $field) use ($options) {
            if ($field['type'] !== 'headline') {
                $id = $field['id'];

                $key = strpos($id, 'hwp_') === 0 ? substr($id, 4) : $id;
                $value = array_key_exists($id, $options) ? $options[$id] : $field['default'];

                /**
                 * Use 'users_can_register' when hwp_api_user_registration is 'global'
                 */
                if ($field['id'] === 'hwp_user_registration' && $value === 'global') {
                    $value = get_option('users_can_register');
                }

                if (is_numeric($value)) {
                    $carry[$key] = (int)$value;
                } else {
                    $carry[$key] = $value;
                }
            }
            return $carry;
        }, []);

        return new WP_REST_Response($fields_data, 200);
    }

    public function handle_test_email_send($request)
    {
        $email = sanitize_email($request->get_param('email'));
        if (!isset($email) && !empty($email)) {
            return new WP_REST_Response(['message' => 'Invalid email address'], 400);
        }

        // Die Funktion aus dem User Confirmation Modul verwenden
        if (class_exists('HWP_User_Confirmation')) {
            $confirmation = new HWP_User_Confirmation();
            $confirmation->wphg_sendConfirmationEmail(['to' => $email]); // Optional: Methode anpassen, falls notwendig
            return new WP_REST_Response(['message' => 'Confirmation email sent to ' . $email], 200);
        }

        return new WP_REST_Response(['message' => 'Confirmation system not available.'], 500);
    }
}
