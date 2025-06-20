<?php
// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles custom REST API endpoints for public post types.
 */
class HWP_ACF_Fields
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

    public function register_rest_routes()
    {
        /**
         * Registriert einen neuen REST API Endpunkt für ACF Feldinformationen.
         * Dieser Endpunkt gibt alle Felder eines bestimmten Post Types zurück,
         * optional auf eine spezifische Feldgruppe beschränkt.
         */
        register_rest_route('wp/v2', '/acf-fields/post-type/(?P<post_type>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_all_acf_fields_for_post_type_filtered'),
            'args' => array(
                'field_group_key' => array(
                    'sanitize_callback' => 'sanitize_text_field', // Bereinigt den String
                    'validate_callback' => 'rest_validate_request_arg', // Stellt sicher, dass der Parameter ein String ist
                    'required' => false, // Macht den Parameter optional
                ),
            ),
            'permission_callback' => function () {
                // return current_user_can('edit_posts'); // Berechtigungsprüfung beibehalten
                return true; // Für die Entwicklung oder wenn du keine Berechtigungsprüfung benötigst
            },
        ));
    }

    /**
     * Callback-Funktion für den REST API Endpunkt.
     * Gibt ACF-Feldinformationen für einen bestimmten Post Type zurück,
     * optional auf eine spezifische Feldgruppe beschränkt.
     *
     * @param WP_REST_Request $request Die Anfrage der REST API.
     * @return WP_REST_Response Die Antwort der REST API.
     */
    public function get_all_acf_fields_for_post_type_filtered(WP_REST_Request $request)
    {
        $post_type = $request->get_param('post_type');
        $field_group_key_filter = $request->get_param('field_group_key'); // Neuen optionalen Parameter abrufen

        $all_fields_for_post_type = [];

        if (!function_exists('acf_get_field_groups')) {
            return new WP_REST_Response(array('message' => 'ACF ist nicht aktiv.'), 500);
        }

        $field_groups = acf_get_field_groups();

        if (empty($field_groups)) {
            return new WP_REST_Response(array('message' => 'Keine ACF Feldgruppen gefunden.'), 404);
        }

        foreach ($field_groups as $field_group) {
            // Wenn ein field_group_key_filter gesetzt ist und dieser nicht mit dem aktuellen Schlüssel übereinstimmt, überspringen.
            if ($field_group_key_filter && $field_group['key'] !== $field_group_key_filter) {
                continue;
            }

            $applies_to_post_type = false;

            if (isset($field_group['location']) && is_array($field_group['location'])) {
                foreach ($field_group['location'] as $group_rules) {
                    foreach ($group_rules as $rule) {
                        if (isset($rule['param']) && $rule['param'] === 'post_type' && $rule['operator'] === '==' && $rule['value'] === $post_type) {
                            $applies_to_post_type = true;
                            break 2;
                        }
                    }
                }
            }

            if ($applies_to_post_type) {
                $fields = acf_get_fields($field_group['key']);

                if (!empty($fields)) {
                    usort($fields, function ($a, $b) {
                        return $a['menu_order'] - $b['menu_order'];
                    });

                    // Für die Beschränkung auf eine Gruppe ist es oft sinnvoll, die Gruppe als oberstes Element zu haben.
                    if ($field_group_key_filter) {
                        // Wenn nach einer spezifischen Gruppe gefiltert wird, gib nur deren Felder zurück

                        $fields_with_group = [
                            $field_group['key'] => [
                                'id' => $field_group['ID'],
                                'key' => $field_group['key'],
                                'title' => $field_group['title'],
                                // 'group' => $field_group,
                                'description' => $field_group['description'] ?? '',
                                'location' => $field_group['location'] ?? [],
                                'active' => $field_group['active'] ?? true,
                                'show_in_rest' => $field_group['show_in_rest'] ?? true,
                                'fields' => $fields,
                            ],
                        ];
                        return new WP_REST_Response($fields_with_group, 200);
                    } else {
                        // Andernfalls, füge alle Felder zu einer flachen Liste hinzu (wie zuvor)
                        foreach ($fields as $field) {
                            $all_fields_for_post_type[$field_group['key']] = [
                                'id' => $field_group['ID'],
                                'key' => $field_group['key'],
                                'title' => $field_group['title'],
                                // 'group' => $field_group,
                                'description' => $field_group['description'] ?? '',
                                'location' => $field_group['location'] ?? [],
                                'active' => $field_group['active'] ?? true,
                                'show_in_rest' => $field_group['show_in_rest'] ?? true,
                                'fields' => $fields,
                            ];
                        }
                    }
                }
            }
        }

        if (empty($all_fields_for_post_type)) {
            // Angepasste Nachricht, wenn eine Gruppe gesucht, aber nicht gefunden wurde
            $message = sprintf('Keine ACF-Felder für den Post Type "%s" gefunden.', $post_type);
            if ($field_group_key_filter) {
                $message = sprintf('Keine ACF-Feldgruppe mit dem Schlüssel "%s" für den Post Type "%s" gefunden.', $field_group_key_filter, $post_type);
            }
            return new WP_REST_Response(array('message' => $message), 404);
        }

        // Wenn kein Filter gesetzt war, gib die gesammelten Felder zurück.
        return new WP_REST_Response($all_fields_for_post_type, 200);
    }
}
