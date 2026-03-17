(function ($) {
    function togglePasswordRow(enabled) {
        $('ul[id^="typecho-option-item-password-"]').toggle(enabled === '1');
    }

    $(document).ready(function () {
        togglePasswordRow($('input[name="enableAuth"]:checked').val());

        $('input[name="enableAuth"]').on('change', function () {
            togglePasswordRow($(this).val());
        });
    });
})(jQuery);

