jQuery(document).ready(function($) {
    // Select2 for User Multiselect in Group Post Type
    $('.hwp-user-select').each(function() {
        var $this = $(this);
        $this.select2({
            ajax: {
                url: HWP_User_Groups.ajax_url, // Uses HWP_User_Groups for user search
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term, // search term
                        action: 'hwp_user_search',
                        nonce: HWP_User_Groups.nonce
                    };
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                },
                cache: true
            },
            placeholder: 'Search for a user',
            minimumInputLength: 2,
            allowClear: true
        });
    });

    // Select2 for Group Multiselect in User Profile
    $('.hwp-user-group-select').each(function() {
        var $this = $(this);
        var fieldName = $this.data('field-name'); // Get the field name from data attribute

        $this.select2({
            ajax: {
                url: hwp_user_groups_profile_ajax.ajax_url, // Uses the new localized variable for profile AJAX
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term, // search term
                        action: 'hwp_group_search', // New AJAX action for group search
                        nonce: hwp_user_groups_profile_ajax.nonce // Use the new nonce
                    };
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                },
                cache: true
            },
            placeholder: 'Search for a group',
            minimumInputLength: 2,
            allowClear: true
        });
    });

    // Optional: If you want to use the hwp_update_user_groups_ajax action via JS
    // This is not strictly needed if hwp_user_groups_profile_save handles all updates
    // but if you have a specific scenario where you want to trigger an update via AJAX
    // for a single field, here's how you might set it up.
    // $('.hwp-user-group-select').on('change', function() {
    //     var $this = $(this);
    //     var userId = $('#hwp_user_id').val(); // Assuming you have a hidden input with the user ID
    //     var metaKey = $this.attr('name').replace('[]', ''); // Get the name attribute without []
    //     var selectedGroupIds = $this.val(); // Get selected values

    //     $.ajax({
    //         url: hwp_user_groups_profile_ajax.ajax_url,
    //         type: 'POST',
    //         data: {
    //             action: 'hwp_update_user_groups',
    //             nonce: hwp_user_groups_profile_ajax.nonce,
    //             user_id: userId,
    //             meta_key: metaKey,
    //             group_ids: selectedGroupIds
    //         },
    //         success: function(response) {
    //             console.log('Update successful:', response);
    //         },
    //         error: function(xhr, status, error) {
    //             console.error('Update failed:', error);
    //         }
    //     });
    // });
});