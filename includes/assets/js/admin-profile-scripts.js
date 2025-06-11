jQuery(document).ready(function($) {
    console.log('Admin profile scripts loaded');
    // Check if the hwpUserGroupsProfileAdmin object is defined by wp_localize_script
    if (typeof hwpUserGroupsProfileAdmin === 'undefined') {
        console.error('hwpUserGroupsProfileAdmin object not found. wp_localize_script might not be working.');
        return;
    }

    // Initialize Select2 for group selection with AJAX on user profile pages
    $('.hwp-select2-ajax-groups').each(function() {
        var $this = $(this);
        var placeholderText = $this.data('placeholder') || 'Select an option';

        $this.select2({
            placeholder: placeholderText,
            allowClear: true, // Allows clearing selection
            multiple: true,   // These fields are always multiple in the profile
            ajax: {
                url: hwpUserGroupsProfileAdmin.ajax_url, // WordPress AJAX URL
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term, // search term
                        action: 'hwp_user_profile_group_search', // AJAX action for group search specific to profile
                        nonce: hwpUserGroupsProfileAdmin.group_search_nonce // Pass nonce
                    };
                },
                processResults: function (data) {
                    // Select2 expects results in a specific format: { results: [] }
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
        });
    });

    // Handle saving of groups when Select2 changes
    // This part is for the AJAX call hwp_update_user_groups_ajax if you use it for dynamic updates.
    // However, the current setup relies on the standard WordPress profile form submission (hwp_user_groups_profile_save).
    // If you plan to use dynamic AJAX updates without a full form submission, you'd need event listeners here.
    // For now, the hwp_user_groups_profile_save method handles saving on form submit.
});