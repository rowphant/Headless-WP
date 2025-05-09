<?php

class HWP_User_Image
{
    public function __construct()
    {
        $user_profile_image = get_option('headless_wp_settings')['hwp_user_profile_image'];

        if ($user_profile_image) {
            add_action('show_user_profile', array($this, 'show_user_meta_fields'));
            add_action('edit_user_profile', array($this, 'show_user_meta_fields'));
            add_action('personal_options_update', array($this, 'save_user_meta_fields'));
            add_action('edit_user_profile_update', array($this, 'save_user_meta_fields'));
    
            add_filter('rest_prepare_user', array($this, 'add_account_image_to_api'), 10, 3);
        }
    }

    public function show_user_meta_fields($user)
    {
?>
        <h3><?php _e('Profile Image', 'headless-wp'); ?></h3>
        <table class="form-table">
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

    public function add_account_image_to_api($response, $user, $request)
    {
        $image_id = get_user_meta($user->ID, 'profile_image', true);
        if ($image_id) {
            $image_sizes = get_intermediate_image_sizes();
            $image_urls = array();

            foreach ($image_sizes as $size) {
                $image_urls[$size] = wp_get_attachment_image_url($image_id, $size);
            }

            $response->data['profile_image'] = array(
                'id' => $image_id,
                'sizes' => $image_urls,

            );
        }

        return $response;
    }
}
