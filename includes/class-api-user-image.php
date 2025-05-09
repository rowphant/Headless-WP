<?php

class HWP_Api_User_Image
{
    public function __construct()
    {
        $user_profile_image = get_option('headless_wp_settings')['hwp_user_profile_image'];
        $upload_user_image = get_option('headless_wp_settings')['hwp_upload_user_image'];

        if ($user_profile_image && $upload_user_image) {
            add_action('rest_api_init', array($this, 'hwp_upload_endpoint'));
        }
    }

    // Funktion zum Erstellen des API-Endpunkts
    public function hwp_upload_endpoint()
    {
        register_rest_route('wp/v2', '/user-image', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_user_image'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));
    }

    public function upload_user_image($request)
    {
        $upload_user_image_multiple = get_option('headless_wp_settings')['hwp_upload_user_image_multiple'];
        $current_user_image_id = get_user_meta(get_current_user_id(), 'profile_image', true);

        return $this->uploadFile(array(
            'extensions' => array('jpg', 'jpeg', 'png'),
            'delete_old' => $upload_user_image_multiple ? false : $current_user_image_id,
            // 'new_id' => $request['new_id'],
            // 'update_user_meta' => true
        ));
    }

    /**
     * https://github.com/adeleyeayodeji/wordpress-image-upload-api
     * Upload image to wp rest api
     */

    public function test($args)
    {
        return new WP_REST_Response([
            'success' => false,
            'data'    => [
                'message' => __('You are not the owner of this image.'),
            ],
        ], 403);
    }

    public function uploadFile($args)
    {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        if ($args['new_id']) {
            if (isset($args['new_id'])) {
                $new_attachment_id = absint($args['new_id']);
                $author_id = get_post_field('post_author', $new_attachment_id);

                if ($author_id !== get_current_user_id()) {
                    return new WP_REST_Response([
                        'success' => false,
                        'data'    => [
                            'message' => __('You are not the owner of this image.'),
                        ],
                    ], 403);
                }

                update_user_meta(get_current_user_id(), 'profile_image', $new_attachment_id);

                return new WP_REST_Response(['message' => 'User image updated'], 200);
            } else {
                return new WP_REST_Response([
                    'success' => false,
                    'data'    => [
                        'message' => __('The specified image does not exist.'),
                    ],
                ], 404);
            }
        } else {
            /**
             * Upload only images and files with the following extensions
             **/
            $file_extensions_default = array('jpg', 'jpeg', 'jpe', 'gif', 'png', 'bmp', 'tiff', 'tif', 'ico', 'zip', 'pdf', 'docx');
            $file_extension_type = isset($args["extensions"]) ? $args["extensions"] : $file_extensions_default;
            $file_extension = strtolower(pathinfo($_FILES['async-upload']['name'], PATHINFO_EXTENSION));

            if (!in_array($file_extension, $file_extension_type)) {

                return new WP_REST_Response([
                    'success' => false,
                    'data'    => [
                        'message'  => __('The uploaded file is not a valid file. Please try again.'),
                        'filename' => esc_html($_FILES['async-upload']['name']),
                    ],
                ], 400);
            }

            /**
             * Remove old media item
             */
            if ($args['delete_old']) {
                wp_delete_attachment($args['delete_old'], true);
            }

            /**
             * Upload image
             **/
            if (empty($_FILES['async-upload']['name'])) {
                return new WP_REST_Response([
                    'success' => false,
                    'data'    => [
                        'message' => __('No file was uploaded. Please try again.'),
                    ],
                ], 400);
            }

            $attachment_id = media_handle_upload('async-upload', null, []);

            if (is_wp_error($attachment_id)) {

                return new WP_REST_Response([
                    'success' => false,
                    'data'    => [
                        'message'  => $attachment_id->get_error_message(),
                        'filename' => esc_html($_FILES['async-upload']['name']),
                    ],
                ], 400);
            }

            $attachment = wp_prepare_attachment_for_js($attachment_id);
            if (!$attachment) {

                return new WP_REST_Response([
                    'success' => false,
                    'data'    => [
                        'message'  => __('Image cannot be uploaded.'),
                        'filename' => esc_html($_FILES['async-upload']['name']),
                    ],
                ], 400);
            }

            if ($args['update_user_meta']) {
                update_user_meta(get_current_user_id(), 'profile_image', $attachment_id);
            }

            // return new WP_REST_Response($response, 200);
            return new WP_REST_Response(['message' => 'Image uploaded successfully', 'id' => $attachment_id], 200);
        }
    }
}
