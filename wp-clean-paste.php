<?php
/**
 * Plugin Name:       WP Clean Paste
 * Plugin URI:        https://github.com/Segmant/wp-clean-paste
 * Description:       Intercepts paste events in the WordPress admin and strips HTML, Word, Google Docs, and JSON formatting — showing a confirmation modal before inserting clean plain text. Works in ACF fields, the Gutenberg block editor, and the classic TinyMCE editor.
 * Version:           1.3.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Chris Mulholland
 * Author URI:        https://github.com/Segmant
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-clean-paste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inject our PastePreProcess handler into every TinyMCE instance via the
 * tiny_mce_before_init PHP filter. This fires before any editor initialises,
 * so it catches ACF WYSIWYG fields that are set up before admin_footer runs.
 * Helper functions are defined on window.wpCleanPaste (see admin_footer below)
 * and will always exist by the time a user can physically paste anything.
 */
add_filter( 'tiny_mce_before_init', function ( $settings ) {
	$our_setup = <<<'JS'
function(editor) {
	editor.on('PastePreProcess', function(e) {
		var cp = window.wpCleanPaste;
		if (!cp) return;

		var html = e.content;
		if (!cp.hasRealHtml(html)) return;

		// Strip HTML to get plain text using the editor's own document
		var tmp = editor.getDoc().createElement('div');
		tmp.innerHTML = html;
		var plain = (tmp.textContent || tmp.innerText || '').trim();

		// Cancel the default paste — modal will re-insert on confirm
		e.content = '';

		cp.buildModal(plain, html, function(text) {
			// insertContent expects HTML; encode entities and convert newlines
			var safe = editor.dom.encode(text).replace(/\r\n|\r|\n/g, '<br>');
			editor.insertContent(safe);
		});
	});
}
JS;

	if ( ! empty( $settings['setup'] ) ) {
		// Merge with any existing setup callback
		$settings['setup'] = 'function(editor){(' . $settings['setup'] . ')(editor);(' . $our_setup . ')(editor);}';
	} else {
		$settings['setup'] = $our_setup;
	}

	return $settings;
} );

/**
 * Define window.wpCleanPaste helpers and handle standard fields + Gutenberg.
 * TinyMCE WYSIWYG is handled via tiny_mce_before_init above; its paste handler
 * references window.wpCleanPaste which will be defined by the time any user
 * can paste (page is fully loaded long before a paste can occur).
 */
add_action( 'admin_footer', function () {
	?>
	<script>
	(function () {

		// ── Shared helpers exposed on window so the TinyMCE setup callback ───────
		// (injected via PHP tiny_mce_before_init) can reach them.
		window.wpCleanPaste = {

			hasRealHtml: function (html) {
				return html && /<(b|i|u|strong|em|span|div|p|table|font|style|h[1-6]|ul|ol|li|a)[^>]*>/i.test(html);
			},

			looksLikeJson: function (text) {
				if (!text) return false;
				var t = text.trim();
				return (t.charAt(0) === '{' || t.charAt(0) === '[') && /"[^"]+"\s*:/.test(t);
			},

			detectSource: function (html, plain) {
				if (plain && window.wpCleanPaste.looksLikeJson(plain)) return 'JSON / Code';
				if (/mso-|urn:schemas-microsoft-com/i.test(html))       return 'Word / Excel';
				if (/google-docs/i.test(html))                           return 'Google Docs';
				if (/<table/i.test(html))                                return 'Table / Excel';
				return 'HTML';
			},

			insertPlain: function (el, text) {
				el.focus();
				if (el.isContentEditable) {
					document.execCommand('insertText', false, text);
				} else {
					var s = el.selectionStart, e = el.selectionEnd;
					el.value = el.value.slice(0, s) + text + el.value.slice(e);
					el.selectionStart = el.selectionEnd = s + text.length;
				}
			},

			buildModal: function (plain, html, insertFn) {
				var cp      = window.wpCleanPaste;
				var overlay = document.createElement('div');
				overlay.style.cssText =
					'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999999;display:flex;align-items:center;justify-content:center;';

				overlay.innerHTML =
					'<div style="background:#fff;border-radius:8px;padding:24px;width:90%;max-width:500px;font-family:sans-serif;box-shadow:0 8px 30px rgba(0,0,0,0.15);">' +
						'<div style="width:32px;height:32px;border-radius:50%;background:#FAEEDA;display:flex;align-items:center;justify-content:center;margin-bottom:12px;font-size:16px;">&#9888;</div>' +
						'<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">' +
							'<h3 style="margin:0;font-size:15px;font-weight:600;">You are not pasting raw text</h3>' +
							'<span style="font-size:11px;background:#FAEEDA;color:#854F0B;padding:2px 8px;border-radius:4px;font-weight:500;">' + cp.detectSource(html, plain) + '</span>' +
						'</div>' +
						'<p style="font-size:13px;color:#555;margin:0 0 14px;line-height:1.6;">We have stripped out all the unnecessary formatting from your content. The clean plain text preview is shown below — click <strong>Paste raw text</strong> to continue.</p>' +
						'<div style="display:flex;gap:8px;margin-bottom:10px;">' +
							'<button id="wpci-tab-plain" style="font-size:12px;padding:4px 10px;border:1px solid #1d2327;border-radius:4px;cursor:pointer;background:#fff;color:#1d2327;">Plain text (what will be pasted)</button>' +
							'<button id="wpci-tab-html"  style="font-size:12px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;background:#f6f7f7;color:#777;">Original HTML</button>' +
						'</div>' +
						'<div id="wpci-preview" style="border:1px solid #e0e0e0;border-radius:6px;padding:10px 12px;max-height:130px;overflow-y:auto;font-size:13px;line-height:1.6;background:#f9f9f9;white-space:pre-wrap;word-break:break-word;margin-bottom:16px;"></div>' +
						'<div style="display:flex;gap:8px;">' +
							'<button id="wpci-confirm" style="flex:1;padding:9px;border:none;border-radius:6px;cursor:pointer;background:#1d2327;color:#fff;font-size:13px;font-weight:500;">Paste raw text</button>' +
							'<button id="wpci-cancel"  style="padding:9px 16px;border:1px solid #ddd;border-radius:6px;cursor:pointer;background:#f6f7f7;font-size:13px;">Cancel</button>' +
						'</div>' +
					'</div>';

				overlay.querySelector('#wpci-preview').textContent = plain;
				document.body.appendChild(overlay);

				overlay.querySelector('#wpci-tab-plain').onclick = function () {
					overlay.querySelector('#wpci-preview').textContent = plain;
					this.style.borderColor = '#1d2327'; this.style.background = '#fff'; this.style.color = '#1d2327';
					var t = overlay.querySelector('#wpci-tab-html');
					t.style.borderColor = '#ddd'; t.style.background = '#f6f7f7'; t.style.color = '#777';
				};

				overlay.querySelector('#wpci-tab-html').onclick = function () {
					overlay.querySelector('#wpci-preview').textContent = html;
					this.style.borderColor = '#1d2327'; this.style.background = '#fff'; this.style.color = '#1d2327';
					var t = overlay.querySelector('#wpci-tab-plain');
					t.style.borderColor = '#ddd'; t.style.background = '#f6f7f7'; t.style.color = '#777';
				};

				overlay.querySelector('#wpci-confirm').onclick = function () {
					document.body.removeChild(overlay);
					insertFn(plain);
				};

				overlay.querySelector('#wpci-cancel').onclick = function () {
					document.body.removeChild(overlay);
				};
			}
		};

		// ── Selectors for standard (non-TinyMCE) fields ──────────────────────────
		var SELECTOR = [
			'.acf-input [contenteditable]',
			'.acf-input input[type="text"]',
			'.acf-input textarea',
			'.block-editor-rich-text__editable',
		].join(', ');

		var GUTENBERG_CODE_BLOCKS = [
			'core/code',
			'core/html',
			'core/preformatted',
			'core/shortcode',
		];

		// ── Standard fields + Gutenberg ──────────────────────────────────────────
		document.addEventListener('paste', function (e) {
			if (!e.target.closest(SELECTOR)) return;
			if (GUTENBERG_CODE_BLOCKS.some(function(type) {
				return e.target.closest('[data-type="' + type + '"]');
			})) return;

			var cd    = e.clipboardData || window.clipboardData;
			var html  = cd.getData('text/html');
			var plain = cd.getData('text/plain');
			var cp    = window.wpCleanPaste;

			if (cp.hasRealHtml(html) || cp.looksLikeJson(plain)) {
				e.preventDefault();
				e.stopImmediatePropagation();
				cp.buildModal(plain, html, function(text) { cp.insertPlain(e.target, text); });
			}
		}, true);

		// ── ACF WYSIWYG fields ───────────────────────────────────────────────────
		// acf.addAction('wysiwyg_tinymce_init') fires every time ACF initialises a
		// TinyMCE editor — including ones inside repeaters and flexible content.
		// This is more reliable than tinymce.on('AddEditor') or tiny_mce_before_init
		// because ACF calls it after its own setup is complete.
		function attachAcfWysiwyg(ed) {
			ed.on('PastePreProcess', function (e) {
				var cp   = window.wpCleanPaste;
				var html = e.content;
				if (!cp || !cp.hasRealHtml(html)) return;

				var tmp = ed.getDoc().createElement('div');
				tmp.innerHTML = html;
				var plain = (tmp.textContent || tmp.innerText || '').trim();

				e.content = ''; // cancel default paste

				cp.buildModal(plain, html, function (text) {
					var safe = ed.dom.encode(text).replace(/\r\n|\r|\n/g, '<br>');
					ed.insertContent(safe);
				});
			});
		}

		if (typeof acf !== 'undefined') {
			acf.addAction('wysiwyg_tinymce_init', attachAcfWysiwyg);
		} else {
			// acf object loads after this script — wait for it
			document.addEventListener('acf-init', function () {
				if (typeof acf !== 'undefined') {
					acf.addAction('wysiwyg_tinymce_init', attachAcfWysiwyg);
				}
			});
		}

	})();
	</script>
	<?php
} );
