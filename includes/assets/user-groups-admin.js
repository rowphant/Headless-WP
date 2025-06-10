jQuery(document).ready(function($) {
    // Initialize Select2 for all user_select fields
    $('.hwp-user-search-select').select2({
        ajax: {
            url: hwpUserGroupsAdmin.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term, // search term
                    action: 'hwp_user_search', // AJAX action for user search
                    nonce: hwpUserGroupsAdmin.nonce // Nonce for security
                };
            },
            processResults: function (data) {
                return {
                    results: data
                };
            },
            cache: true
        },
        placeholder: 'Search for users...',
        minimumInputLength: 3 // Minimum characters to start searching
    });

    // If you have a group search select2 as well, similar logic applies
    // $('.hwp-group-search-select').select2({ ... });
});