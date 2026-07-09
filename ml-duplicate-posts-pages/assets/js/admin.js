jQuery(function($){
    $(document).on('click', '.mldpp-copy-link', function(e){
        e.preventDefault();
        var text = $(this).data('copy');
        if (!text || !navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(text);
    });

    var mldppState = window.mldppAdmin || {};
    var ajaxUrl = mldppState.ajaxUrl || '';
    var previewNonce = mldppState.previewNonce || '';
    var previewAction = mldppState.previewAction || 'mldpp_preview_slug';
    var i18n = mldppState.i18n || {};
    var previewTitle = i18n.previewTitle || 'Slug previsto:';
    var previewError = i18n.previewError || 'Nao foi possivel calcular o slug.';

    function renderPreview($target, slug) {
        $target.text(slug ? (previewTitle + ' ' + slug) : previewError);
        $target.addClass('mldpp-preview-slug--ready');
    }

    function loadPreview($target) {
        if ($target.data('mldppLoaded')) {
            return;
        }
        var postId = parseInt($target.data('post-id'), 10);
        if (!postId || !ajaxUrl || !previewNonce) {
            return;
        }
        $target.data('mldppLoaded', true);
        $.post(ajaxUrl, {
            action: previewAction,
            nonce: previewNonce,
            post_id: postId
        }).done(function(response){
            if (response && response.success && response.data && response.data.slug) {
                renderPreview($target, response.data.slug);
            } else {
                renderPreview($target, '');
            }
        }).fail(function(){
            renderPreview($target, '');
        });
    }

    $(document).on('mouseenter focus', '.mldpp-preview-slug', function(){
        loadPreview($(this));
    });

    $(document).on('mouseenter focus', '.mldpp-duplicate-link', function(){
        var postId = parseInt($(this).data('post-id'), 10);
        if (!postId) {
            return;
        }
        var $container = $(this).siblings('.mldpp-preview-slug');
        if ($container.length === 0) {
            $container = $(this).parent().find('.mldpp-preview-slug').first();
        }
        if ($container.length === 0) {
            return;
        }
        $container.data('post-id', postId);
        loadPreview($container);
    });
});