<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles custom REST API endpoints for public post types.
 */
class HWP_Public_Post_Types_API
{
    /**
     * The REST API namespace for this endpoint.
     * Use a unique namespace specific to your plugin.
     * @var string
     */
    protected $api_namespace = 'wp/v2';

    public function __construct()
    {
        $this->add_hooks();
    }

    /**
     * Adds the necessary WordPress hooks.
     * The rest_api_init hook is crucial for registering REST API routes.
     */
    protected function add_hooks()
    {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Registers the custom REST API endpoint for fetching public post types.
     */
    public function register_rest_routes()
    {
        register_rest_route($this->api_namespace, '/public-types', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_public_post_types'),
            'permission_callback' => '__return_true', // Public endpoint, no authentication needed
            'args'                => array(
                'has_archive' => array(
                    'description'       => __('Limit results to post types that have archives.', 'your-plugin-textdomain'), // Replace 'your-plugin-textdomain'
                    'type'              => 'boolean',
                    // Use custom callbacks for older WP versions (pre-5.3)
                    'sanitize_callback' => function ($value) {
                        return (bool) $value;
                    },
                    'validate_callback' => function ($value) {
                        return is_bool($value) || in_array($value, array('1', '0', 'true', 'false'), true);
                    },
                    'default'           => false,
                ),
            ),
        ));
    }

    /**
     * Callback function to retrieve public post types.
     *
     * This method fetches all registered post types and filters them
     * based on their `publicly_queryable` status, optionally also by `has_archive`.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The REST API response.
     */
    public function get_public_post_types(WP_REST_Request $request)
    {
        $response = array();
        // Get all post types as objects
        $post_types = get_post_types('', 'objects');

        // Get the 'has_archive' parameter from the request
        $filter_has_archive = $request->get_param('has_archive');
        $force_include_types = array('page');

        foreach ($post_types as $post_type_obj) {
            // Check if the post type is intended to be shown in the REST API
            if (! $post_type_obj->show_in_rest) {
                continue;
            }

            // Crucial filter: Check if the post type is publicly queryable (frontend viewable)
            if ($post_type_obj->publicly_queryable || in_array($post_type_obj->name, $force_include_types)) {
                // Apply optional 'has_archive' filter if requested
                if ($filter_has_archive && ! $post_type_obj->has_archive) {
                    continue; // Skip if filtering by has_archive but this type doesn't have one
                }

                // Construct the data array for this public post type
                $item = array(
                    'slug'                   => $post_type_obj->name, // Internal name/slug
                    'name'                   => $post_type_obj->labels->name, // Plural display name
                    'singular_name'          => $post_type_obj->labels->singular_name, // Singular display name
                    'description'            => $post_type_obj->description,
                    'hierarchical'           => $post_type_obj->hierarchical,
                    'rest_base'              => ! empty($post_type_obj->rest_base) ? $post_type_obj->rest_base : $post_type_obj->name, // Endpoint base
                    'has_archive'            => $post_type_obj->has_archive,
                    // Explicitly indicating its public viewability status in your custom response
                    'is_publicly_viewable'   => $post_type_obj->publicly_queryable,
                    'icon'                   => $post_type_obj->menu_icon,
                    // Add more properties from $post_type_obj as needed
                );
                $response[$post_type_obj->name] = $item;
            }
        }

        return new WP_REST_Response($response, 200);
    }
}
