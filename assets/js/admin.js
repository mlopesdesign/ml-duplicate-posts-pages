jQuery(function($){
    $(document).on('click', '.mldpp-copy-link', function(e){
        e.preventDefault();
        var text = $(this).data('copy');
        if (!text || !navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(text);
    });
});
