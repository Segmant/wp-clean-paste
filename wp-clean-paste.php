<?php
/**
 * Plugin Name:       WP Clean Paste
 * Plugin URI:        https://github.com/Segmant/wp-clean-paste
 * Description:       Intercepts paste events in the WordPress admin and strips HTML, Word, Google Docs, and JSON formatting — showing a confirmation modal before inserting clean plain text. Works in ACF fields, the Gutenberg block editor, and the classic TinyMCE editor.
 * Version:           1.0.0
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

add_action( 'admin_footer', function () {
	?>
	<script>
	(function () {

		const SELECTOR = [
			'.acf-input [contenteditable]',
			'.acf-input input[type="text"]',
			'.acf-input textarea',
			'.block-editor-rich-text__editable',
		].join(', ');

		const GUTENBERG_CODE_BLOCKS = [
			'core/code',
			'core/html',
			'core/preformatted',
			'core/shortcode',
		];

		function hasRealHtml(html) {
			return html && /<(b|i|u|strong|em|span|div|p|table|font|style|h[1-6]|ul|ol|li|a)[^>]*>/i.test(html);
		}

		function looksLikeJson(text) {
			if (!text) return false;
			const t = text.trim();
			return (t.startsWith('{') || t.startsWith('[')) && /"[^"]+"\s*:/.test(t);
		}

		function detectSource(html, plain) {
			if (plain && looksLikeJson(plain)) return 'JSON / Code';
			if (/mso-|urn:schemas-microsoft-com/i.test(html))  return 'Word / Excel';
			if (/google-docs/i.test(html))                      return 'Google Docs';
			if (/<table/i.test(html))                           return 'Table / Excel';
			return 'HTML';
		}

		function insertPlain(el, text) {
			el.focus();
			if (el.isContentEditable) {
				document.execCommand('insertText', false, text);
			} else {
				const s = el.selectionStart, e = el.selectionEnd;
				el.value = el.value.slice(0, s) + text + el.value.slice(e);
				el.selectionStart = el.selectionEnd = s + text.length;
			}
		}

		function buildModal(plain, html, insertFn) {
			const overlay = document.createElement('div');
			overlay.style.cssText =
				'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999999;display:flex;align-items:center;justify-content:center;';

			overlay.innerHTML = `
				<div style="background:#fff;border-radius:8px;padding:24px;width:90%;max-width:500px;font-family:sans-serif;box-shadow:0 8px 30px rgba(0,0,0,0.15);">
					<div style="width:32px;height:32px;border-radius:50%;background:#FAEEDA;display:flex;align-items:center;justify-content:center;margin-bottom:12px;font-size:16px;">&#9888;</div>
					<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
						<h3 style="margin:0;font-size:15px;font-weight:600;">You are not pasting raw text</h3>
						<span style="font-size:11px;background:#FAEEDA;color:#854F0B;padding:2px 8px;border-radius:4px;font-weight:500;">${detectSource(html, plain)}</span>
					</div>
					<p style="font-size:13px;color:#555;margin:0 0 14px;line-height:1.6;">
						We have stripped out all the unnecessary formatting from your content. The clean plain text preview is shown below —
						click <strong>Paste raw text</strong> to continue.
					</p>
					<div style="display:flex;gap:8px;margin-bottom:10px;">
						<button id="wpci-tab-plain" style="font-size:12px;padding:4px 10px;border:1px solid #1d2327;border-radius:4px;cursor:pointer;background:#fff;color:#1d2327;">Plain text (what will be pasted)</button>
						<button id="wpci-tab-html"  style="font-size:12px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;background:#f6f7f7;color:#777;">Original HTML</button>
					</div>
					<div id="wpci-preview" style="border:1px solid #e0e0e0;border-radius:6px;padding:10px 12px;max-height:130px;overflow-y:auto;font-size:13px;line-height:1.6;background:#f9f9f9;white-space:pre-wrap;word-break:break-word;margin-bottom:16px;"></div>
					<div style="display:flex;gap:8px;">
						<button id="wpci-confirm" style="flex:1;padding:9px;border:none;border-radius:6px;cursor:pointer;background:#1d2327;color:#fff;font-size:13px;font-weight:500;">Paste raw text</button>
						<button id="wpci-cancel"  style="padding:9px 16px;border:1px solid #ddd;border-radius:6px;cursor:pointer;background:#f6f7f7;font-size:13px;">Cancel</button>
					</div>
				</div>
			`;

			overlay.querySelector('#wpci-preview').textContent = plain;
			document.body.appendChild(overlay);

			overlay.querySelector('#wpci-tab-plain').onclick = function () {
				overlay.querySelector('#wpci-preview').textContent = plain;
				this.style.borderColor = '#1d2327'; this.style.background = '#fff'; this.style.color = '#1d2327';
				const t = overlay.querySelector('#wpci-tab-html');
				t.style.borderColor = '#ddd'; t.style.background = '#f6f7f7'; t.style.color = '#777';
			};

			overlay.querySelector('#wpci-tab-html').onclick = function () {
				overlay.querySelector('#wpci-preview').textContent = html;
				this.style.borderColor = '#1d2327'; this.style.background = '#fff'; this.style.color = '#1d2327';
				const t = overlay.querySelector('#wpci-tab-plain');
				t.style.borderColor = '#ddd'; t.style.background = '#f6f7f7'; t.style.color = '#777';
			};

			overlay.querySelector('#wpci-confirm').onclick = () => {
				document.body.removeChild(overlay);
				insertFn(plain);
			};

			overlay.querySelector('#wpci-cancel').onclick = () => {
				document.body.removeChild(overlay);
			};
		}

		// ── Standard fields + Gutenberg ──────────────────────────────────────────
		document.addEventListener('paste', function (e) {
			if (!e.target.closest(SELECTOR)) return;
			if (GUTENBERG_CODE_BLOCKS.some(type => e.target.closest('[data-type="' + type + '"]'))) return;

			const cd    = e.clipboardData || window.clipboardData;
			const html  = cd.getData('text/html');
			const plain = cd.getData('text/plain');

			if (hasRealHtml(html) || looksLikeJson(plain)) {
				e.preventDefault();
				e.stopImmediatePropagation();
				buildModal(plain, html, (text) => insertPlain(e.target, text));
			}
		}, true);

		// ── TinyMCE visual editor (runs inside an iframe — needs its own hook) ──
		if (typeof tinymce !== 'undefined') {
			tinymce.on('AddEditor', function (ev) {
				ev.editor.on('paste', function (e) {
					const cd    = e.clipboardData || (e.originalEvent && e.originalEvent.clipboardData);
					if (!cd) return;
					const html  = cd.getData('text/html');
					const plain = cd.getData('text/plain');

					if (hasRealHtml(html) || looksLikeJson(plain)) {
						e.preventDefault();
						e.stopImmediatePropagation();
						const editor = ev.editor;
						buildModal(plain, html, (text) => editor.insertContent(text));
					}
				});
			});
		}

	})();
	</script>
	<?php
} );
