/**
 * Drives the "Generate AI Content" metabox.
 *
 * Talks exclusively to the Abilities REST surface:
 *   POST /wp-json/wp-abilities/v1/abilities/ai-by-roadmap/compose-page/run
 *   POST /wp-json/wp-abilities/v1/abilities/ai-by-roadmap/get-job-status/run
 *
 * Long-running compositions return a job_id; we poll until done.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.querySelector('.ai-by-roadmap-metabox');
		if (!root) {
			return;
		}

		const postId   = parseInt(root.dataset.postId, 10);
		const btn      = document.getElementById('ai-by-roadmap-generate');
		const status   = document.getElementById('ai-by-roadmap-status');
		const spinner  = root.querySelector('.spinner');

		const runAbility = function (abilityId, input) {
			return wp.apiFetch({
				path: '/wp-abilities/v1/abilities/' + abilityId + '/run',
				method: 'POST',
				data: { input: input },
			});
		};

		const pollJob = function (jobId) {
			return new Promise(function (resolve, reject) {
				const tick = function () {
					runAbility('ai-by-roadmap/get-job-status', { job_id: jobId })
						.then(function (response) {
							const result = response.result || response;
							if (result.status === 'done') {
								resolve(result);
								return;
							}
							if (result.status === 'failed') {
								reject(new Error(result.error || 'Job failed'));
								return;
							}
							setTimeout(tick, 2500);
						})
						.catch(reject);
				};
				tick();
			});
		};

		btn.addEventListener('click', function () {
			const content  = document.getElementById('ai-by-roadmap-content').value.trim();
			const audience = document.getElementById('ai-by-roadmap-audience').value.trim();
			const replace  = document.getElementById('ai-by-roadmap-replace').checked;

			if (!content) {
				status.innerHTML = '<div class="notice notice-error"><p>Please paste some content.</p></div>';
				return;
			}

			btn.disabled = true;
			spinner.classList.add('is-active');
			status.innerHTML = '<div class="notice notice-info"><p>Queueing job…</p></div>';

			runAbility('ai-by-roadmap/compose-page', {
				content: content,
				target_audience: audience,
				post_id: postId,
				replace_content: replace,
			})
				.then(function (response) {
					const result = response.result || response;
					status.innerHTML = '<div class="notice notice-info"><p>Generating… (job ' + result.job_id + ')</p></div>';
					return pollJob(result.job_id);
				})
				.then(function (jobResult) {
					const payload = jobResult.result || jobResult;
					const editLink = payload && payload.edit_link;
					const newPostId = payload && payload.post_id;

					if (newPostId && newPostId !== postId && editLink) {
						status.innerHTML =
							'<div class="notice notice-success"><p>Draft page created. ' +
							'<a href="' + editLink + '">Open it in the editor →</a></p></div>';
					} else if (replace) {
						status.innerHTML = '<div class="notice notice-success"><p>Done — reloading to show new content.</p></div>';
						setTimeout(function () { window.location.reload(); }, 1200);
					} else {
						status.innerHTML = '<div class="notice notice-success"><p>Done. Blocks generated but not applied (uncheck "Replace existing content" only if you have somewhere else to use them).</p></div>';
					}
				})
				.catch(function (err) {
					status.innerHTML = '<div class="notice notice-error"><p>' + (err.message || 'Error generating content.') + '</p></div>';
				})
				.finally(function () {
					btn.disabled = false;
					spinner.classList.remove('is-active');
				});
		});
	});
})();
