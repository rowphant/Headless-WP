jQuery(document).ready(function ($) {
  $(".hwp-user-select").each(function () {
    var $select = $(this);
    var fieldId = $select.data("field-id");
    var $sortable = $('.hwp-selected-users[data-field-id="' + fieldId + '"]');

    $select.select2({
      ajax: {
        url: HWP_User_Groups.ajax_url,
        dataType: "json",
        delay: 250,
        data: function (params) {
          return {
            q: params.term,
            action: "hwp_user_search",
            nonce: HWP_User_Groups.nonce,
          };
        },
        processResults: function (data) {
          return { results: data };
        },
        cache: true,
      },
      placeholder: "Select users",
      minimumInputLength: 2,
      allowClear: false,
    });

    $select.on("select2:select", function (e) {
      var user = e.params.data;
      if ($sortable.find('[data-user-id="' + user.id + '"]').length === 0) {
        $sortable.append(
          '<li data-user-id="' +
            user.id +
            '">' +
            user.text +
            ' <span class="remove">×</span></li>'
        );
      }
    });

    $sortable.sortable();

    $sortable.on("click", ".remove", function () {
      var userId = $(this).parent().data("user-id");
      $sortable.find('[data-user-id="' + userId + '"]').remove();
      $select
        .find('option[value="' + userId + '"]')
        .remove()
        .trigger("change");
    });
  });

  // Before Save: Sync list order back to select
  $("form#post").on("submit", function () {
    $(".hwp-selected-users").each(function () {
      var $sortable = $(this);
      var fieldId = $sortable.data("field-id");
      var $select = $('select[data-field-id="' + fieldId + '"]');

      var selectedIds = [];
      $sortable.children("li").each(function () {
        selectedIds.push($(this).data("user-id"));
      });

      $select.val(selectedIds);
    });
  });
});

jQuery(document).ready(function ($) {
  $(".hwp-user-group-select")
    .select2({
      placeholder: "Select Groups",
      allowClear: true,
    })
    .on("change", function () {
      const $select = $(this);
      const meta_key = $select.attr("name").replace("[]", "");
      const user_id = $("#hwp_user_id").val();

      const group_ids = $select.val() ? $select.val() : [];

      $select
        .parent()
        .append('<span class="hwp-saving-spinner">Saving...</span>');

      $.ajax({
        url: hwp_user_groups_ajax.ajax_url,
        method: "POST",
        data: {
          action: "hwp_update_user_groups",
          nonce: hwp_user_groups_ajax.nonce,
          user_id: user_id,
          meta_key: meta_key,
          group_ids: group_ids,
        },
        success: function (response) {
          console.log(hwp_user_groups_ajax);
          if (response.success) {
            console.log("Saved:", meta_key, response.data);

            $select
              .parent()
              .append(
                '<span class="hwp-save-success" style="color:green;margin-left:10px;">✓ Saved</span>'
              );
            setTimeout(() => {
              $select
                .parent()
                .find(".hwp-save-success")
                .fadeOut(500, function () {
                  $(this).remove();
                });
            }, 1500);
          } else {
            console.error(
              "Error: " +
                (response.data || response.message || "Unexpected error")
            );
          }
        },
        error: function (jqXHR, textStatus, errorThrown) {
          console.error("AJAX Error: " + textStatus + " - " + errorThrown);
        },
        complete: function () {
          $select
            .parent()
            .find(".hwp-saving-spinner")
            .fadeOut(300, function () {
              $(this).remove();
            });
        },
      });
    });
});
