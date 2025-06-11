jQuery(document).ready(function($) {
    // Check if the hwpUserGroupsAdminAjax object is defined by wp_localize_script
    if (typeof hwpUserGroupsAdminAjax === 'undefined') {
        console.error('hwpUserGroupsAdminAjax object not found. wp_localize_script might not be working.');
        return;
    }

    // Initialize Select2 for user selection with AJAX
    $('.hwp-select2-ajax-users').each(function() {
        var $this = $(this);
        var isMultiple = $this.prop('multiple');
        var placeholderText = $this.data('placeholder') || 'Select an option';

        $this.select2({
            placeholder: placeholderText,
            allowClear: true, // Allows clearing selection
            multiple: isMultiple,
            ajax: {
                url: hwpUserGroupsAdminAjax.ajax_url, // WordPress AJAX URL
                dataType: 'json',
                delay: 250, // wait for 250 milliseconds before triggering the request
                data: function (params) {
                    return {
                        q: params.term, // search term
                        action: 'hwp_user_search', // AJAX action for user search
                        nonce: hwpUserGroupsAdminAjax.user_search_nonce // Pass nonce
                    };
                },
                processResults: function (data) {
                    // Select2 expects results in a specific format: { results: [] }
                    // Make sure your PHP AJAX endpoint returns data in this format.
                    if (data && data.results) {
                        return {
                            results: data.results
                        };
                    }
                    return {
                        results: []
                    };
                },
                cache: true
            },
            minimumInputLength: 1, // Minimum characters to type before a search is performed
            // Optional: If you want to customize how results are rendered
            templateResult: function(user) {
                if (user.loading) {
                    return user.text;
                }
                return user.text; // Or customize with more user details
            },
            templateSelection: function(user) {
                return user.text || user.id;
            }
        });

        // If the field already has selected values (e.g., on edit screen),
        // ensure Select2 displays them correctly. This is handled by PHP,
        // but this ensures Select2 is aware of initial selections.
        // Select2 often handles initial <option selected> well, but this is a double-check.
        // No explicit JS init for initial selections is needed if PHP correctly renders <option selected>
        // and Select2 initializes on page load.
    });

    // You can add more Select2 initializations here if you have other AJAX fields
    // For example, for group selection:
    /*
    $('.hwp-select2-ajax-groups').select2({
        // ... similar AJAX configuration but with 'action: 'hwp_group_search''
    });
    */
});