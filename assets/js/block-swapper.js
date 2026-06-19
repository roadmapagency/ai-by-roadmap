/**
 * Adds an "AI Block Switcher" panel to the right-sidebar inspector for any
 * ACF block that carries an ai_content field.
 *
 * Design: one button per registered block type (excluding the current one).
 * Click a button → POST to ai-by-roadmap/fill-block with the existing
 * ai_content → swap the block in place. The same ai_content is re-stored on
 * the new block, so the user can keep switching types without losing the
 * original source material.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.hooks || !wp.element) {
		return;
	}

	const { createElement: el, useState, Fragment } = wp.element;
	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { InspectorControls } = wp.blockEditor || wp.editor;
	const { PanelBody, Button, Spinner, Notice } = wp.components;
	const { dispatch } = wp.data;
	const { parse }    = wp.blocks;
	const { __ }       = wp.i18n;
	const settings     = window.aiByRoadmap || { blockOptions: [], i18n: {} };

	console.info('[ai-by-roadmap] block-swapper v4', {
		blockOptions: settings.blockOptions,
		blockOptionsCount: (settings.blockOptions || []).length,
	});

	/**
	 * Pull the source content out of an ACF block's attributes.
	 *
	 * In PHP-serialized form the field is at `data.ai_content`. In JS-land
	 * ACF gives us field VALUES keyed by FIELD KEY (e.g.
	 * `field_faqs_ai_content`), so we scan every key in `data` for one that
	 * ends in `_ai_content` (or is exactly `ai_content`).
	 */
	const aiSourceContent = function (attributes) {
		if (!attributes || !attributes.data) {
			return '';
		}

		const seek = function (bag) {
			if (!bag || typeof bag !== 'object') {
				return '';
			}
			if (typeof bag.ai_content === 'string' && bag.ai_content !== '') {
				return bag.ai_content;
			}
			for (const key of Object.keys(bag)) {
				if ((key === 'ai_content' || /_ai_content$/.test(key)) && !key.startsWith('_')) {
					if (typeof bag[key] === 'string' && bag[key] !== '') {
						return bag[key];
					}
				}
			}
			return '';
		};

		return seek(attributes.data) || seek(attributes.data.data || null);
	};

	const isAiBlock = function (name, attributes) {
		return typeof name === 'string' &&
			name.indexOf('acf/') === 0 &&
			aiSourceContent(attributes) !== '';
	};

	const swapTargets = function (currentName) {
		return (settings.blockOptions || []).filter(function (opt) {
			return opt.value !== '' && opt.value !== currentName;
		});
	};

	const withSwapPanel = createHigherOrderComponent(function (BlockEdit) {
		return function SwapPanelWrapper(props) {
			const [swapping, setSwapping] = useState(false);
			const [target, setTarget]     = useState('');
			const [errorMsg, setError]    = useState('');
			const [showSource, setShow]   = useState(false);

			if (!isAiBlock(props.name, props.attributes)) {
				return el(BlockEdit, props);
			}

			const source  = aiSourceContent(props.attributes);
			const targets = swapTargets(props.name);

			const swapTo = function (blockType, label) {
				setSwapping(true);
				setTarget(blockType);
				setError('');

				wp.apiFetch({
					path: '/wp-abilities/v1/abilities/ai-by-roadmap/fill-block/run',
					method: 'POST',
					data: {
						input: {
							block_type: blockType,
							content: source,
						},
					},
				})
					.then(function (response) {
						const result = (response && response.result) ? response.result : response;
						if (!result || !result.serialized) {
							throw new Error('Empty response from fill-block.');
						}
						const parsed = parse(result.serialized);
						if (!parsed || parsed.length === 0) {
							throw new Error('Could not parse returned block.');
						}
						dispatch('core/block-editor').replaceBlock(props.clientId, parsed[0]);
						dispatch('core/notices').createSuccessNotice(
							__('Swapped to ') + label,
							{ type: 'snackbar' }
						);
					})
					.catch(function (err) {
						setError((err && err.message) ? err.message : __('Swap failed.'));
					})
					.finally(function () {
						setSwapping(false);
						setTarget('');
					});
			};

			const buttons = targets.length === 0
				? el(
					'p',
					{ style: { color: '#777', fontStyle: 'italic' } },
					__('No other AI-compatible block types are registered.')
				)
				: el(
					'div',
					{ style: { display: 'flex', flexWrap: 'wrap', gap: '8px' } },
					targets.map(function (opt) {
						const isThis = swapping && target === opt.value;
						return el(
							Button,
							{
								key: opt.value,
								variant: 'secondary',
								disabled: swapping,
								onClick: function () { swapTo(opt.value, opt.label); },
							},
							isThis ? el(Spinner) : null,
							isThis ? ' ' : '',
							opt.label
						);
					})
				);

			const sourcePreview = el(
				'div',
				{ style: { marginTop: '16px' } },
				el(
					Button,
					{
						variant: 'link',
						onClick: function () { setShow(!showSource); },
						style: { padding: 0, height: 'auto' },
					},
					showSource
						? __('Hide source content used by this block')
						: __('Show source content used by this block') + ' (' + source.length + ' chars)'
				),
				showSource
					? el(
						'div',
						{
							style: {
								marginTop: '8px',
								maxHeight: '160px',
								overflow: 'auto',
								padding: '8px',
								background: '#f6f7f7',
								border: '1px solid #ddd',
								fontSize: '11px',
								whiteSpace: 'pre-wrap',
							},
						},
						source
					)
					: null
			);

			const panel = el(
				PanelBody,
				{
					title: __('Swap with AI'),
					initialOpen: true,
				},
				el(
					'p',
					{ style: { fontSize: '12px', color: '#555' } },
					__('Re-generate this block as a different block type, using the same source content.')
				),
				errorMsg ? el(Notice, { status: 'error', isDismissible: false }, errorMsg) : null,
				buttons,
				sourcePreview
			);

			return el(
				Fragment,
				{},
				el(BlockEdit, props),
				el(InspectorControls, {}, panel)
			);
		};
	}, 'withAiByRoadmapSwapPanel');

	addFilter('editor.BlockEdit', 'ai-by-roadmap/swap-panel', withSwapPanel);
})(window.wp);
