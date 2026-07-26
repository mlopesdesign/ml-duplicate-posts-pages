/**
 * Integracao com o editor de blocos (Gutenberg).
 *
 * Ate a 1.3.0 o plugin so tinha o botao do editor classico
 * (`post_submitbox_misc_actions`), que nao dispara no editor de blocos.
 * Em qualquer site moderno o botao simplesmente nao existia.
 *
 * Escrito com wp.element.createElement em vez de JSX de proposito: assim o
 * plugin nao precisa de build step (sem npm, sem webpack no pacote entregue).
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.plugins || !wp.element || !wp.components || !wp.data) {
		return;
	}

	var settings = window.mldppEditor || {};

	if (!settings.duplicateUrl || !settings.postId) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var Button = wp.components.Button;
	var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

	// wp.editor.PluginPostStatusInfo e o caminho atual; wp.editPost e o legado,
	// mantido para WordPress anteriores ao 6.6.
	var PluginPostStatusInfo =
		(wp.editor && wp.editor.PluginPostStatusInfo) ||
		(wp.editPost && wp.editPost.PluginPostStatusInfo);

	if (!PluginPostStatusInfo) {
		return;
	}

	function fetchPreview(onDone) {
		if (!settings.ajaxUrl || !settings.previewNonce || !window.fetch) {
			onDone('');
			return;
		}

		var body = new window.FormData();
		body.append('action', settings.previewAction || 'mldpp_preview_slug');
		body.append('nonce', settings.previewNonce);
		body.append('post_id', settings.postId);

		window.fetch(settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (response) { return response.json(); })
			.then(function (payload) {
				if (payload && payload.success && payload.data && payload.data.slug) {
					onDone(payload.data.slug);
				} else {
					onDone('');
				}
			})
			.catch(function () { onDone(''); });
	}

	function DuplicatePanel() {
		var slugState = useState(null);
		var slug = slugState[0];
		var setSlug = slugState[1];

		var isSaving = wp.data.useSelect(function (select) {
			var editor = select('core/editor');
			if (!editor) {
				return false;
			}
			return editor.isSavingPost() || editor.isAutosavingPost();
		}, []);

		useEffect(function () {
			fetchPreview(function (value) { setSlug(value); });
		}, []);

		var children = [
			el(
				'div',
				{ className: 'mldpp-editor-panel__label', key: 'label' },
				settings.i18n.panelTitle
			),
			el(
				Button,
				{
					key: 'button',
					variant: 'secondary',
					href: settings.duplicateUrl,
					disabled: isSaving,
					className: 'mldpp-editor-panel__button'
				},
				settings.i18n.buttonLabel
			)
		];

		if (slug === null) {
			children.push(
				el('p', { className: 'mldpp-editor-panel__hint', key: 'loading' }, settings.i18n.loading)
			);
		} else if (slug) {
			children.push(
				el(
					'p',
					{ className: 'mldpp-editor-panel__hint', key: 'slug' },
					settings.i18n.previewTitle + ' ',
					el('code', null, slug)
				)
			);
		} else {
			children.push(
				el('p', { className: 'mldpp-editor-panel__hint', key: 'error' }, settings.i18n.previewError)
			);
		}

		if (settings.unsavedWarning) {
			children.push(
				el('p', { className: 'mldpp-editor-panel__hint', key: 'warn' }, settings.i18n.unsavedWarning)
			);
		}

		return el(
			PluginPostStatusInfo,
			{ className: 'mldpp-editor-panel' },
			el('div', { className: 'mldpp-editor-panel__inner' }, children)
		);
	}

	wp.plugins.registerPlugin('ml-duplicate-posts-pages', {
		render: DuplicatePanel
	});
})(window.wp);
