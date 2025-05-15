<?php

class HWP_User_Image
{
    public function __construct()
    {
        $user_profile_image = get_option('headless_wp_settings')['hwp_user_profile_image'];

        if ($user_profile_image) {
            add_action('show_user_profile', array($this, 'show_user_meta_fields'), 2);
            add_action('edit_user_profile', array($this, 'show_user_meta_fields'), 2);

            add_action('personal_options_update', array($this, 'save_user_meta_fields'));
            add_action('edit_user_profile_update', array($this, 'save_user_meta_fields'));

            add_action('rest_api_init', array($this, 'add_profile_image_field'));
        }
    }

    public function show_user_meta_fields($user)
    {
?>
        <h3><?php _e('Profile Image', 'headless-wp'); ?></h3>
        <table class="form-table" role="presentation">
            <tr class="user-profile-image">
                <th>
                    <label for="profile_image"><?php _e('Profile Image', 'headless-wp'); ?></label>
                </th>
                <td>
                    <?php
                    $image_id = get_user_meta($user->ID, 'profile_image', true);
                    $image_html = wp_get_attachment_image($image_id, array(150, 150));
                    if (empty($image_html)) {
                        $image_html = '<img src="' . esc_url(includes_url('images/blank.gif')) . '" alt="" />';
                    }
                    ?>
                    <div class="profile-image">
                        <?php echo $image_html; ?>
                    </div>
                    <p class="description">
                        <?php _e('Upload a new profile image.', 'headless-wp'); ?>
                    </p>
                    <input type="hidden" name="profile_image" id="profile_image" value="<?php echo esc_attr($image_id); ?>" />
                    <button type="button" class="button upload-image-button"><?php _e('Upload Image', 'headless-wp'); ?></button>
                </td>
            </tr>
        </table>
        <script>
            jQuery(document).ready(function($) {
                var file_frame;
                $('.upload-image-button').on('click', function(event) {
                    event.preventDefault();

                    if (file_frame) {
                        file_frame.open();
                        return;
                    }

                    file_frame = wp.media.frames.file_frame = wp.media({
                        title: '<?php _e('Select Profile Image', 'headless-wp'); ?>',
                        button: {
                            text: '<?php _e('Use this image', 'headless-wp'); ?>',
                        },
                        multiple: false
                    });

                    file_frame.on('select', function() {
                        var attachment = file_frame.state().get('selection').first().toJSON();
                        $('#profile_image').val(attachment.id);
                        $('.profile-image').html('<img src="' + attachment.sizes.thumbnail.url + '" alt="" />');
                    });

                    file_frame.open();
                });

                // ➡️ Custom Field über den "Update Profil" Button verschieben:
                var profileImageRow = $('.user-profile-image').closest('tr');
                var submitRow = $('tr.submit'); // Die Zeile mit dem Button
                profileImageRow.insertBefore(submitRow);
            });
        </script>

<?php
    }

    public function save_user_meta_fields($user_id)
    {
        if (! current_user_can('edit_user', $user_id)) {
            return false;
        }

        if (isset($_POST['profile_image'])) {
            $image_id = $_POST['profile_image'];
            update_user_meta($user_id, 'profile_image', $image_id);
        }
    }

    public function prepare_profile_picture_for_api($user)
    {
        $image_id = get_user_meta($user['id'], 'profile_image', true);

        if ($image_id) {
            $image_sizes = get_intermediate_image_sizes();
            $image_urls = array();

            foreach ($image_sizes as $size) {
                $image_urls[$size] = wp_get_attachment_image_url($image_id, $size);
            }

            return array(
                'id' => is_numeric($image_id) ? (int) $image_id : $image_id,
                'sizes' => $image_urls,
            );
        } else {
            return false;
        }
    }


    public function update_profile_image($request)
    {
        $user_id = get_current_user_id();
        $image_id = $request['profile_image'];

        if (! current_user_can('edit_user', $user_id)) {
            return new WP_Error('rest_cannot_edit', __('Sorry, you cannot edit this user.'), array('status' => 401));
        }

        if (isset($image_id)) {
            update_user_meta($user_id, 'profile_image', $image_id);
        }

        return rest_ensure_response(['profile_image' => get_user_meta($user_id, 'profile_image', true)]);
    }

    public function add_profile_image_field()
    {
        register_rest_field(
            'user',
            'profile_image',
            array(
                'get_callback' => function ($user) {
                    error_log('Getting profile image...');
                    // return get_user_meta($user['id'], 'profile_image', true);
                    return $this->prepare_profile_picture_for_api($user);
                },
                'update_callback' => function ($value, $user) {
                    error_log('Updating profile image...');
                    update_user_meta($user->ID, 'profile_image', $value);
                    return $value;
                },
                'schema' => array(
                    'description' => 'The ID of the user\'s profile image.',
                    'type' => 'integer',
                ),
            )
        );
        error_log('Profile image field added.');
    }
}
