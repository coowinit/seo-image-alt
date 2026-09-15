(function () {
	'use strict';

	const cfg = window.wiaaAdmin || {};
	const progress = document.getElementById('wiaa-progress');
	const summary = document.getElementById('wiaa-batch-summary');
	const retryButton = document.getElementById('wiaa-retry-failed');
	let lastRetryRows = [];
	let lastRetryWorker = null;
	let lastRetryLabel = '';

	function formatNumber(value) {
		try {
			return new Intl.NumberFormat().format(Number(value || 0));
		} catch (e) {
			return String(value || 0);
		}
	}

	function updateCounts(counts) {
		if (!counts) return;

		Object.keys(counts).forEach((key) => {
			document.querySelectorAll('[data-count-key="' + key + '"]').forEach((node) => {
				node.textContent = formatNumber(counts[key]);
			});

			document.querySelectorAll('[data-tab-count-key="' + key + '"]').forEach((node) => {
				node.textContent = formatNumber(counts[key]);
			});
		});
	}

	function api(action, data) {
		const body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce || '');

		Object.keys(data || {}).forEach((key) => body.set(key, data[key]));

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin'
		}).then((response) => response.json()).then((json) => {
			if (!json.success) {
				const error = new Error((json.data && json.data.message) || '操作失败。');
				if (json.data && json.data.counts) {
					error.counts = json.data.counts;
					updateCounts(json.data.counts);
				}
				throw error;
			}

			if (json.data && json.data.counts) {
				updateCounts(json.data.counts);
			}

			return json.data || {};
		});
	}

	function getRow(el) {
		return el ? el.closest('tr[data-id]') : null;
	}

	function getCandidateInput(row) {
		return row ? row.querySelector('.wiaa-candidate-input') : null;
	}

	function setFeedback(row, message, isError) {
		if (!row) return;
		const node = row.querySelector('.wiaa-runtime-message');
		if (!node) return;

		node.textContent = message || '';
		node.classList.toggle('is-error', !!isError);
	}

	function setStatus(row, status, label) {
		if (!row) return;
		row.dataset.status = status;

		const node = row.querySelector('[data-status-label]');
		if (node) {
			node.className = 'wiaa-status is-' + status;
			node.textContent = label;
		}
	}

	function setIntent(row, intent, note) {
		if (!row) return;
		row.dataset.intent = intent || '';

		const box = row.querySelector('.wiaa-ai-judgement');
		const label = row.querySelector('[data-intent-label]');
		const noteNode = row.querySelector('[data-ai-note]');

		if (!box || !label) return;

		if (!intent) {
			box.hidden = true;
			return;
		}

		const labels = {
			content: 'AI：内容图片',
			decorative: 'AI：可能无需 ALT',
			uncertain: 'AI：不确定'
		};

		box.hidden = false;
		label.className = 'wiaa-intent is-' + intent;
		label.textContent = labels[intent] || 'AI：待判断';
		if (noteNode) noteNode.textContent = note || '';
	}

	function setReviewedUI(row, reviewed) {
		if (!row) return;
		row.dataset.reviewed = reviewed ? '1' : '0';

		const check = row.querySelector('.wiaa-review-check');
		const state = row.querySelector('.wiaa-review-state');
		const apply = row.querySelector('.wiaa-apply');
		const input = getCandidateInput(row);
		const hasCandidate = !!(input && input.value.trim());

		if (check) {
			check.checked = !!reviewed;
			check.disabled = !hasCandidate;
		}

		if (state) {
			state.textContent = reviewed ? '已审核' : '未审核';
			state.classList.toggle('is-reviewed', !!reviewed);
		}

		if (apply) {
			apply.disabled = !reviewed || !hasCandidate;
		}

		if (row.dataset.status === 'candidate' || row.dataset.status === 'reviewed') {
			setStatus(row, reviewed ? 'reviewed' : 'candidate', reviewed ? '已审核' : '待审核');
		}
	}

	function cancelAutosave(row) {
		const input = getCandidateInput(row);
		if (input && input._wiaaSaveTimer) {
			window.clearTimeout(input._wiaaSaveTimer);
			input._wiaaSaveTimer = null;
		}
	}

	function saveCandidateRow(row, options) {
		options = options || {};
		const input = getCandidateInput(row);
		if (!input) return Promise.resolve({});

		const candidate = input.value.trim();
		const saved = input.dataset.savedCandidate || '';

		if (candidate === saved && !options.force) {
			return Promise.resolve({ alt: candidate, reviewed: row.dataset.reviewed === '1' });
		}

		return api('wiaa_save_candidate', {
			attachment_id: row.dataset.id,
			candidate: candidate
		}).then((data) => {
			input.dataset.savedCandidate = data.alt || '';
			setReviewedUI(row, false);
			setIntent(row, candidate ? 'content' : (row.dataset.intent || ''), candidate ? '' : undefined);
			setStatus(row, 'candidate', '待审核');
			if (!options.silent) setFeedback(row, '候选已自动保存，请重新审核。', false);
			return data;
		}).catch((error) => {
			setFeedback(row, error.message || '候选保存失败。', true);
			throw error;
		});
	}

	function generateRow(row) {
		cancelAutosave(row);
		if (!row) return Promise.reject(new Error('找不到图片行。'));
		const button = row.querySelector('.wiaa-generate');

		if (button) {
			button.disabled = true;
			button.textContent = (cfg.strings && cfg.strings.generating) || '正在生成…';
		}

		row.classList.remove('wiaa-batch-failed');
		setFeedback(row, '', false);

		return api('wiaa_generate_alt', { attachment_id: row.dataset.id }).then((data) => {
			const input = getCandidateInput(row);
			if (input) {
				input.value = data.alt || '';
				input.dataset.savedCandidate = data.alt || '';
			}

			setIntent(row, data.intent || 'uncertain', data.note || '');
			setStatus(row, 'candidate', '待审核');
			setReviewedUI(row, false);

			if ('decorative' === data.intent) {
				setFeedback(row, 'AI 建议这可能是装饰性图片；请人工确认后选择“无需 ALT”。', false);
			} else if ('uncertain' === data.intent) {
				setFeedback(row, 'AI 无法可靠判断，请人工复核。', false);
			} else {
				setFeedback(row, '候选已生成，请审核后应用。', false);
			}

			return data;
		}).catch((error) => {
			row.classList.add('wiaa-batch-failed');
			setStatus(row, 'failed', '失败');
			setReviewedUI(row, false);
			setFeedback(row, error.message || '生成失败。', true);
			throw error;
		}).finally(() => {
			if (button) {
				button.disabled = false;
				button.textContent = '重新生成';
			}
		});
	}

	function setReviewed(row, reviewed) {
		cancelAutosave(row);
		const input = getCandidateInput(row);
		const candidate = input ? input.value.trim() : '';
		const check = row.querySelector('.wiaa-review-check');

		if (check) check.disabled = true;

		return api('wiaa_set_reviewed', {
			attachment_id: row.dataset.id,
			candidate: candidate,
			reviewed: reviewed ? '1' : ''
		}).then((data) => {
			if (input) input.dataset.savedCandidate = data.alt || '';
			setReviewedUI(row, !!data.reviewed);
			setFeedback(row, data.reviewed ? '已审核，可以应用 ALT。' : '已取消审核。', false);
			return data;
		}).catch((error) => {
			setReviewedUI(row, !reviewed);
			setFeedback(row, error.message || '审核状态保存失败。', true);
			throw error;
		}).finally(() => {
			const inputNow = getCandidateInput(row);
			if (check) check.disabled = !(inputNow && inputNow.value.trim());
		});
	}

	function applyRow(row) {
		cancelAutosave(row);
		const input = getCandidateInput(row);
		const candidate = input ? input.value.trim() : '';
		const button = row.querySelector('.wiaa-apply');

		if (row.dataset.reviewed !== '1') {
			const error = new Error('请先勾选“审核通过”。');
			setFeedback(row, error.message, true);
			return Promise.reject(error);
		}

		if (button) {
			button.disabled = true;
			button.textContent = (cfg.strings && cfg.strings.applying) || '正在应用…';
		}

		row.classList.remove('wiaa-batch-failed');

		return api('wiaa_apply_candidate', {
			attachment_id: row.dataset.id,
			candidate: candidate
		}).then((data) => {
			setStatus(row, 'complete', '已完成');
			setFeedback(row, '已写入 WordPress 原生 ALT。', false);
			row.classList.add('is-resolved');

			const check = row.querySelector('.wiaa-row-check');
			if (check) {
				check.checked = false;
				check.disabled = true;
			}

			const actions = row.querySelector('.wiaa-actions');
			if (actions) {
				actions.innerHTML = '<span class="wiaa-resolved-label">已应用</span>';
			}

			return data;
		}).catch((error) => {
			row.classList.add('wiaa-batch-failed');
			setFeedback(row, error.message || '应用失败。', true);
			throw error;
		}).finally(() => {
			if (button && document.body.contains(button)) {
				button.textContent = '应用 ALT';
				button.disabled = row.dataset.reviewed !== '1';
			}
		});
	}

	function markNoAlt(row, mode) {
		cancelAutosave(row);
		const label = mode === 'ignored' ? '忽略这张图片' : '确认这张图片无需描述性 ALT';
		if (!window.confirm(label + '？以后它将不再进入默认待处理队列。')) {
			return Promise.reject(new Error('cancelled'));
		}

		return api('wiaa_mark_no_alt', {
			attachment_id: row.dataset.id,
			mode: mode
		}).then((data) => {
			row.dataset.intent = data.intent || mode;
			setStatus(row, 'no_alt', '无需 ALT');
			row.classList.add('is-resolved');
			const check = row.querySelector('.wiaa-row-check');
			if (check) {
				check.checked = false;
				check.disabled = true;
			}

			const cell = row.querySelector('.wiaa-candidate-cell');
			if (cell) {
				cell.innerHTML = '<div class="wiaa-no-alt-decision"><strong>' + (mode === 'ignored' ? '已忽略' : '已确认无需描述性 ALT') + '</strong><p>' + (mode === 'ignored' ? '该媒体已从默认处理队列中排除。' : '空 ALT 被视为有意保留，而不是遗漏。') + '</p></div>';
			}

			const actions = row.querySelector('.wiaa-actions');
			if (actions) {
				actions.innerHTML = '<button type="button" class="button wiaa-restore">恢复待处理</button>';
			}
			return data;
		}).catch((error) => {
			if (error.message !== 'cancelled') setFeedback(row, error.message || '操作失败。', true);
			throw error;
		});
	}

	function restoreRow(row) {
		cancelAutosave(row);
		return api('wiaa_restore_pending', { attachment_id: row.dataset.id }).then((data) => {
			setStatus(row, 'pending', '待处理');
			row.classList.remove('is-resolved');
			const actions = row.querySelector('.wiaa-actions');
			if (actions) {
				actions.innerHTML = '<span class="wiaa-muted">已恢复，请刷新页面继续处理。</span>';
			}
			return data;
		});
	}

	function selectedRows() {
		return Array.from(document.querySelectorAll('.wiaa-row-check:checked'))
			.map((check) => check.closest('tr[data-id]'))
			.filter(Boolean);
	}

	function setProgress(done, total, text) {
		if (!progress) return;
		progress.hidden = false;
		const label = progress.querySelector('.wiaa-progress-text');
		const bar = progress.querySelector('.wiaa-progress-track span');
		const percent = total ? Math.round((done / total) * 100) : 0;
		if (label) label.textContent = text || (done + ' / ' + total);
		if (bar) bar.style.width = percent + '%';
	}

	function showSummary(total, success, failed, skipped, failedRows, worker, label) {
		if (!summary) return;
		const text = summary.querySelector('[data-summary-text]');
		if (text) {
			text.textContent = '共 ' + total + ' 张：成功 ' + success + '，失败 ' + failed + '，跳过 ' + skipped + '。';
		}
		summary.hidden = false;

		lastRetryRows = failedRows || [];
		lastRetryWorker = worker || null;
		lastRetryLabel = label || '重试';

		if (retryButton) {
			retryButton.hidden = !lastRetryRows.length || !lastRetryWorker;
		}
	}

	async function runSequential(rows, worker, label, skipped) {
		skipped = skipped || 0;
		if (!rows.length && !skipped) {
			window.alert('请先选择图片。');
			return;
		}

		let success = 0;
		let failed = 0;
		const failedRows = [];
		const total = rows.length + skipped;
		let done = skipped;
		setProgress(done, total, label + ' ' + done + ' / ' + total);

		for (const row of rows) {
			try {
				await worker(row);
				success += 1;
			} catch (error) {
				if (error && error.message === 'cancelled') {
					skipped += 1;
				} else {
					failed += 1;
					failedRows.push(row);
				}
			}
			done += 1;
			setProgress(done, total, label + ' ' + done + ' / ' + total);
		}

		showSummary(total, success, failed, skipped, failedRows, worker, label);
	}

	// Initialize saved candidate snapshots.
	document.querySelectorAll('.wiaa-candidate-input').forEach((input) => {
		input.dataset.savedCandidate = input.value.trim();
	});

	// Editing invalidates prior approval and auto-saves after a short pause.
	document.addEventListener('input', function (event) {
		if (!event.target.matches('.wiaa-candidate-input')) return;
		const row = getRow(event.target);
		const input = event.target;
		setReviewedUI(row, false);

		// A manually edited test candidate must be reviewed as a manual item.
		// Do not keep the previous server-side quality-gate result after editing.
		if (row && Object.prototype.hasOwnProperty.call(row.dataset, 'qualityEligible')) {
			row.dataset.qualityEligible = '0';
			const quality = row.querySelector('.wiaa-test-quality');
			if (quality) {
				quality.className = 'wiaa-test-quality is-manual';
				quality.textContent = '已编辑，请人工审核';
			}
		}

		const check = row.querySelector('.wiaa-review-check');
		if (check) check.disabled = !input.value.trim();

		if (input._wiaaSaveTimer) window.clearTimeout(input._wiaaSaveTimer);
		input._wiaaSaveTimer = window.setTimeout(function () {
			input._wiaaSaveTimer = null;
			saveCandidateRow(row, { silent: true }).catch(() => {});
		}, 800);
	});

	document.addEventListener('change', function (event) {
		if (!event.target.matches('.wiaa-review-check')) return;
		const row = getRow(event.target);
		setReviewed(row, event.target.checked).catch(() => {});
	});

	document.addEventListener('click', function (event) {
		const generate = event.target.closest('.wiaa-generate');
		if (generate) {
			generateRow(getRow(generate)).catch(() => {});
			return;
		}

		const apply = event.target.closest('.wiaa-apply');
		if (apply) {
			applyRow(getRow(apply)).catch(() => {});
			return;
		}

		const noAlt = event.target.closest('.wiaa-no-alt');
		if (noAlt) {
			markNoAlt(getRow(noAlt), noAlt.dataset.mode || 'decorative').catch(() => {});
			return;
		}

		const restore = event.target.closest('.wiaa-restore');
		if (restore) {
			restore.disabled = true;
			restoreRow(getRow(restore)).catch((error) => {
				window.alert(error.message || '恢复失败。');
			}).finally(() => {
				if (document.body.contains(restore)) restore.disabled = false;
			});
			return;
		}

		const thumb = event.target.closest('.wiaa-thumb-button');
		if (thumb) {
			openImageModal(thumb.dataset.full || '');
		}
	});

	const selectAll = document.getElementById('wiaa-select-all');
	if (selectAll) {
		selectAll.addEventListener('click', function () {
			const checks = Array.from(document.querySelectorAll('.wiaa-row-check:not(:disabled)'));
			const allChecked = checks.length && checks.every((check) => check.checked);
			checks.forEach((check) => { check.checked = !allChecked; });
			selectAll.textContent = allChecked ? '选择本页' : '取消选择';
		});
	}

	const batchGenerate = document.getElementById('wiaa-batch-generate');
	if (batchGenerate) {
		batchGenerate.addEventListener('click', function () {
			batchGenerate.disabled = true;
			runSequential(selectedRows(), generateRow, '正在生成').finally(() => {
				batchGenerate.disabled = false;
			});
		});
	}

	const batchReview = document.getElementById('wiaa-batch-review');
	if (batchReview) {
		batchReview.addEventListener('click', function () {
			const selected = selectedRows();
			const eligible = selected.filter((row) => {
				const input = getCandidateInput(row);
				return row.dataset.reviewed !== '1' && input && input.value.trim();
			});
			const skipped = selected.length - eligible.length;

			if (!selected.length) {
				window.alert('请先选择图片。');
				return;
			}

			if (!eligible.length) {
				window.alert('所选图片中没有可审核的 ALT 候选。');
				return;
			}

			if (!window.confirm('确认将所选 ' + eligible.length + ' 张候选标记为“审核通过”？请确保已经逐张检查图片与 ALT。')) {
				return;
			}

			batchReview.disabled = true;
			runSequential(eligible, function (row) {
				return setReviewed(row, true);
			}, '正在审核', skipped).finally(() => {
				batchReview.disabled = false;
			});
		});
	}

	const batchUnreview = document.getElementById('wiaa-batch-unreview');
	if (batchUnreview) {
		batchUnreview.addEventListener('click', function () {
			const selected = selectedRows();
			const eligible = selected.filter((row) => row.dataset.reviewed === '1');
			const skipped = selected.length - eligible.length;

			if (!selected.length) {
				window.alert('请先选择图片。');
				return;
			}

			if (!eligible.length) {
				window.alert('所选图片中没有已审核候选。');
				return;
			}

			if (!window.confirm('确认取消所选 ' + eligible.length + ' 张图片的审核状态？')) {
				return;
			}

			batchUnreview.disabled = true;
			runSequential(eligible, function (row) {
				return setReviewed(row, false);
			}, '正在取消审核', skipped).finally(() => {
				batchUnreview.disabled = false;
			});
		});
	}

	const batchApply = document.getElementById('wiaa-batch-apply');
	if (batchApply) {
		batchApply.addEventListener('click', function () {
			const selected = selectedRows();
			const eligible = selected.filter((row) => {
				const input = getCandidateInput(row);
				return row.dataset.reviewed === '1' && input && input.value.trim();
			});
			const skipped = selected.length - eligible.length;

			if (!selected.length) {
				window.alert('请先选择图片。');
				return;
			}

			if (!eligible.length) {
				window.alert('所选图片中没有“已审核”的 ALT 候选。');
				return;
			}

			if (!window.confirm('确认把所选且已审核的候选 ALT 写入 WordPress？已有 ALT 不会被覆盖。')) {
				return;
			}

			batchApply.disabled = true;
			runSequential(eligible, applyRow, '正在应用', skipped).finally(() => {
				batchApply.disabled = false;
			});
		});
	}

	if (retryButton) {
		retryButton.addEventListener('click', function () {
			if (!lastRetryRows.length || !lastRetryWorker) return;
			retryButton.disabled = true;
			runSequential(lastRetryRows.slice(), lastRetryWorker, lastRetryLabel).finally(() => {
				retryButton.disabled = false;
			});
		});
	}

	// Lightweight image preview modal.
	const modal = document.getElementById('wiaa-image-modal');
	function openImageModal(url) {
		if (!modal || !url) return;
		const img = modal.querySelector('img');
		if (img) img.src = url;
		modal.hidden = false;
		modal.setAttribute('aria-hidden', 'false');
		document.body.classList.add('wiaa-modal-open');
	}

	function closeImageModal() {
		if (!modal) return;
		modal.hidden = true;
		modal.setAttribute('aria-hidden', 'true');
		const img = modal.querySelector('img');
		if (img) img.src = '';
		document.body.classList.remove('wiaa-modal-open');
	}

	if (modal) {
		modal.addEventListener('click', function (event) {
			if (event.target.matches('[data-modal-close], .wiaa-modal-close')) {
				closeImageModal();
			}
		});
	}

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && modal && !modal.hidden) {
			closeImageModal();
		}
	});

	// v1.1.1 · persistent site-wide tasks + visible 20-image test results.
	const globalBulk = document.getElementById('wiaa-global-bulk');
	let currentBulkTask = cfg.bulkTask || null;
	let bulkLoopBusy = false;
	let bulkLoopTimer = null;

	function bulkStatusLabel(status) {
		const labels = {
			running: '运行中',
			paused: '已暂停',
			cooldown: 'API 限流等待',
			completed: '已完成',
			stopped: '已停止',
			error: '需要处理'
		};
		return labels[status] || '暂无任务';
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function testIntentLabel(intent) {
		const labels = {
			content: 'AI：内容图片',
			decorative: 'AI：可能无需 ALT',
			uncertain: 'AI：不确定'
		};
		return labels[intent] || 'AI：待判断';
	}

	function testStatusLabel(status, reviewed) {
		if (status === 'complete') return '已有 ALT';
		if (status === 'failed') return '失败';
		if (status === 'no_alt') return '无需 ALT';
		if (reviewed || status === 'reviewed') return '已审核';
		if (status === 'candidate') return '待审核';
		return '待处理';
	}

	function renderTestResults(task, autoReveal) {
		const panel = document.getElementById('wiaa-test-results');
		if (!panel || !globalBulk) return;

		const toggle = globalBulk.querySelector('[data-test-results-toggle]');
		const body = panel.querySelector('[data-test-results-body]');
		const count = panel.querySelector('[data-test-result-count]');
		const results = task && task.is_test && Array.isArray(task.test_results) ? task.test_results : [];

		if (!results.length) {
			panel.hidden = true;
			if (toggle) toggle.hidden = true;
			if (body) body.innerHTML = '';
			return;
		}

		if (toggle) {
			toggle.hidden = false;
			toggle.textContent = panel.hidden ? '查看测试结果' : '隐藏测试结果';
		}
		if (count) count.textContent = formatNumber(results.length);

		if (body) {
			body.innerHTML = results.map((item) => {
				const candidate = item.candidate || '';
				const reviewed = !!item.reviewed;
				const quality = item.quality || 'manual';
				const status = item.status || 'candidate';
				const intent = item.intent || '';
				const metrics = item.is_cjk
					? formatNumber(item.chars) + ' 字符'
					: formatNumber(item.chars) + ' 字符 · ' + formatNumber(item.words) + ' 词';
				const image = item.thumb_url
					? '<button type="button" class="wiaa-thumb-button" data-full="' + escapeHtml(item.url || '') + '" aria-label="查看大图"><img src="' + escapeHtml(item.thumb_url) + '" alt=""></button>'
					: '';
				const note = item.ai_note ? '<span class="wiaa-ai-note" data-ai-note>' + escapeHtml(item.ai_note) + '</span>' : '<span class="wiaa-ai-note" data-ai-note></span>';
				const error = item.error ? '<small class="wiaa-row-error">' + escapeHtml(item.error) + '</small>' : '';
				const meta = item.model ? '<small class="wiaa-meta">' + escapeHtml(item.model) + (item.generated_at ? ' · ' + escapeHtml(item.generated_at) : '') + '</small>' : '';
				const canEdit = !['complete', 'no_alt'].includes(status);
				const candidateBlock = canEdit
					? '<div class="wiaa-ai-judgement"' + (intent ? '' : ' hidden') + '><span class="wiaa-intent is-' + escapeHtml(intent || 'unknown') + '" data-intent-label>' + escapeHtml(testIntentLabel(intent)) + '</span>' + note + '</div>' +
						'<textarea class="wiaa-candidate-input" rows="3" placeholder="AI 候选 ALT，可人工编辑；停止输入后自动保存">' + escapeHtml(candidate) + '</textarea>' +
						'<div class="wiaa-review-row"><label><input type="checkbox" class="wiaa-review-check" ' + (reviewed ? 'checked ' : '') + (!candidate ? 'disabled ' : '') + '>审核通过</label><span class="wiaa-review-state' + (reviewed ? ' is-reviewed' : '') + '">' + (reviewed ? '已审核' : '未审核') + '</span></div>' + meta +
						'<div class="wiaa-feedback">' + error + '<small class="wiaa-runtime-message"></small></div>'
					: '<span class="wiaa-muted">该图片已经不需要候选编辑。</span>';
				const action = canEdit
					? '<button type="button" class="button button-primary wiaa-apply" ' + ((!reviewed || !candidate) ? 'disabled' : '') + '>应用 ALT</button>'
					: '<span class="wiaa-resolved-label">已处理</span>';

				return '<tr data-id="' + Number(item.id || 0) + '" data-status="' + escapeHtml(status) + '" data-intent="' + escapeHtml(intent) + '" data-reviewed="' + (reviewed ? '1' : '0') + '" data-quality-eligible="' + (item.quality_eligible ? '1' : '0') + '">' +
					'<td class="wiaa-image-cell">' + image + '<div><strong>' + escapeHtml(item.title || item.filename || ('Attachment #' + item.id)) + '</strong><code>' + escapeHtml(item.filename || '') + '</code></div></td>' +
					'<td class="wiaa-candidate-cell">' + candidateBlock + '</td>' +
					'<td><strong class="wiaa-test-quality is-' + escapeHtml(quality) + '">' + escapeHtml(item.quality_label || '建议人工检查') + '</strong><small class="wiaa-test-metrics">' + escapeHtml(metrics) + '</small><span class="wiaa-status is-' + escapeHtml(status) + '" data-status-label>' + escapeHtml(testStatusLabel(status, reviewed)) + '</span></td>' +
					'<td class="wiaa-actions">' + action + '</td>' +
				'</tr>';
			}).join('');

			body.querySelectorAll('.wiaa-candidate-input').forEach((input) => {
				input.dataset.savedCandidate = input.value.trim();
			});
		}

		if (autoReveal) panel.hidden = false;
		if (toggle) toggle.textContent = panel.hidden ? '查看测试结果' : '隐藏测试结果';
	}

	function renderBulkTask(task) {
		currentBulkTask = task || null;
		if (!globalBulk) return;

		const panel = globalBulk.querySelector('[data-bulk-task]');
		const state = globalBulk.querySelector('[data-bulk-state-label]');
		if (state) state.textContent = task ? bulkStatusLabel(task.status) : '暂无任务';

		if (!task) {
			if (panel) panel.hidden = true;
			renderTestResults(null, false);
			globalBulk.querySelectorAll('[data-bulk-create]').forEach((button) => {
				button.disabled = button.dataset.requiresApi === '1' && !cfg.isConfigured;
			});
			return;
		}

		if (panel) panel.hidden = false;
		const setText = (selector, value) => {
			const node = globalBulk.querySelector(selector);
			if (node) node.textContent = value;
		};

		setText('[data-bulk-label]', task.label || '全站任务');
		setText('[data-bulk-total]', formatNumber(task.total));
		setText('[data-bulk-processed]', formatNumber(task.processed));
		setText('[data-bulk-success]', formatNumber(task.success));
		setText('[data-bulk-failed]', formatNumber(task.failed));
		setText('[data-bulk-skipped]', formatNumber(task.skipped));
		setText('[data-bulk-excluded]', formatNumber(task.excluded));
		setText('[data-bulk-progress-text]', formatNumber(task.processed) + ' / ' + formatNumber(task.total) + ' · ' + (task.percent || 0) + '%');

		const bar = globalBulk.querySelector('[data-bulk-progress-bar]');
		if (bar) bar.style.width = (task.percent || 0) + '%';

		const excludedWrap = globalBulk.querySelector('[data-bulk-excluded-wrap]');
		if (excludedWrap) excludedWrap.hidden = !(Number(task.excluded || 0) > 0);

		let message = '';
		if (task.status === 'cooldown') {
			const remaining = Math.max(0, Math.ceil(((Number(task.cooldown_until || 0) * 1000) - Date.now()) / 1000));
			message = 'DeepSeek 返回限流信号，约 ' + remaining + ' 秒后自动继续。';
		} else if (task.status === 'error') {
			message = '任务已暂停：' + (task.last_error || 'API 配置或请求异常。修复后可点击“继续”。');
		} else if (task.status === 'paused') {
			message = '任务已暂停，可随时继续。';
		} else if (task.status === 'completed') {
			message = task.is_test ? '测试完成。请查看下方生成结果，确认 ALT 质量后再决定是否生成全部剩余图片。' : '任务完成。请查看上方统计与异常队列。';
		} else if (task.status === 'stopped') {
			message = '任务已停止；已生成或已应用的数据不会回滚。';
		} else if (Number(task.retry_count || 0) > 0) {
			message = '当前图片请求失败，正在进行第 ' + task.retry_count + ' 次自动重试。' + (task.last_error ? ' ' + task.last_error : '');
		} else {
			message = '任务进行中。生成任务每次只调用一张图片，避免单次 PHP 请求过长。';
		}
		setText('[data-bulk-message]', message);

		const canShowTestResults = !!(task.is_test && Array.isArray(task.test_results) && task.test_results.length);
		renderTestResults(task, canShowTestResults && task.status === 'completed');

		globalBulk.querySelectorAll('[data-bulk-create]').forEach((button) => {
			const requiresApi = button.dataset.requiresApi === '1';
			button.disabled = ['running', 'paused', 'cooldown'].includes(task.status) || (requiresApi && !cfg.isConfigured);
		});

		const pause = globalBulk.querySelector('[data-bulk-control="pause"]');
		const resume = globalBulk.querySelector('[data-bulk-control="resume"]');
		const stop = globalBulk.querySelector('[data-bulk-control="stop"]');
		if (pause) pause.disabled = !['running', 'cooldown'].includes(task.status);
		if (resume) resume.disabled = !['paused', 'error'].includes(task.status);
		if (stop) stop.disabled = ['completed', 'stopped'].includes(task.status);
	}

	function scheduleBulkStep(delay) {
		if (bulkLoopTimer) window.clearTimeout(bulkLoopTimer);
		bulkLoopTimer = window.setTimeout(runBulkStep, Math.max(100, delay || 250));
	}

	function runBulkStep() {
		if (bulkLoopBusy || !currentBulkTask) return;
		if (!['running', 'cooldown'].includes(currentBulkTask.status)) return;

		if (currentBulkTask.status === 'cooldown') {
			const remainingMs = (Number(currentBulkTask.cooldown_until || 0) * 1000) - Date.now();
			if (remainingMs > 0) {
				scheduleBulkStep(Math.max(1000, remainingMs + 250));
				return;
			}
		}

		bulkLoopBusy = true;
		api('wiaa_bulk_step', {}).then((data) => {
			renderBulkTask(data.task || null);
			if (!data.task) return;

			if (data.task.status === 'running') {
				const retryDelay = Number(data.task.retry_count || 0) > 0 ? 3000 : 250;
				scheduleBulkStep(retryDelay);
			} else if (data.task.status === 'cooldown') {
				const remainingMs = Math.max(1000, (Number(data.task.cooldown_until || 0) * 1000) - Date.now() + 250);
				scheduleBulkStep(remainingMs);
			}
		}).catch((error) => {
			const message = globalBulk ? globalBulk.querySelector('[data-bulk-message]') : null;
			if (message) message.textContent = error.message || '全站任务请求失败，请刷新页面后继续。';
		}).finally(() => {
			bulkLoopBusy = false;
		});
	}

	if (globalBulk) {
		globalBulk.addEventListener('click', function (event) {
			const testToggle = event.target.closest('[data-test-results-toggle]');
			if (testToggle) {
				const panel = document.getElementById('wiaa-test-results');
				if (panel) {
					panel.hidden = !panel.hidden;
					testToggle.textContent = panel.hidden ? '查看测试结果' : '隐藏测试结果';
					if (!panel.hidden) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
				return;
			}

			const testReview = event.target.closest('[data-test-review-good]');
			if (testReview) {
				const panel = document.getElementById('wiaa-test-results');
				const rows = panel ? Array.from(panel.querySelectorAll('tr[data-id]')) : [];
				const eligible = rows.filter((row) => {
					const input = getCandidateInput(row);
					return row.dataset.qualityEligible === '1' && row.dataset.reviewed !== '1' && input && input.value.trim();
				});
				if (!eligible.length) {
					window.alert('这批测试结果中没有新的“合格内容图”需要审核。');
					return;
				}
				if (!window.confirm('确认审核通过这批测试结果中 ' + eligible.length + ' 张通过安全门槛的内容图？装饰图、不确定和异常候选不会被审核。')) return;
				testReview.disabled = true;
				runSequential(eligible, (row) => setReviewed(row, true), '正在审核测试结果', rows.length - eligible.length).finally(() => {
					testReview.disabled = false;
				});
				return;
			}

			const testApply = event.target.closest('[data-test-apply-reviewed]');
			if (testApply) {
				const panel = document.getElementById('wiaa-test-results');
				const rows = panel ? Array.from(panel.querySelectorAll('tr[data-id]')) : [];
				const eligible = rows.filter((row) => {
					const input = getCandidateInput(row);
					return row.dataset.reviewed === '1' && input && input.value.trim();
				});
				if (!eligible.length) {
					window.alert('这批测试结果中没有已审核的 ALT 候选。');
					return;
				}
				if (!window.confirm('确认应用这批测试结果中已审核的 ' + eligible.length + ' 个 ALT？已有原生 ALT 不会被覆盖。')) return;
				testApply.disabled = true;
				runSequential(eligible, applyRow, '正在应用测试 ALT', rows.length - eligible.length).finally(() => {
					testApply.disabled = false;
				});
				return;
			}

			const testGenerateAll = event.target.closest('[data-test-generate-all]');
			if (testGenerateAll) {
				const allButton = globalBulk.querySelector('[data-bulk-create][data-operation="generate"][data-scope="pending"][data-limit="0"]');
				if (allButton) allButton.click();
				return;
			}

			const create = event.target.closest('[data-bulk-create]');
			if (create) {
				const operation = create.dataset.operation || '';
				const scope = create.dataset.scope || 'pending';
				const limit = create.dataset.limit || '0';
				let confirmText = '创建这个全站任务？';

				if (operation === 'generate' && limit === '20') {
					confirmText = '先对最多 20 张待处理图片进行测试生成？不会自动审核或应用 ALT。';
				} else if (operation === 'generate') {
					confirmText = scope === 'failed' ? '重试当前所有失败图片？任务会逐张调用 DeepSeek。' : '为当前全部待处理图片生成候选 ALT？任务会逐张调用 DeepSeek，请保持此后台页面打开。';
				} else if (operation === 'review') {
					confirmText = '确认批量审核所有通过安全门槛的内容图候选？建议先随机检查 20–50 张，装饰图、不确定和异常候选不会被自动审核。';
				} else if (operation === 'apply') {
					confirmText = '确认把全部已审核候选写入 WordPress 原生 ALT？已有 ALT 永远不会被覆盖。';
				}

				if (!window.confirm(confirmText)) return;
				create.disabled = true;
				api('wiaa_bulk_create', { operation: operation, scope: scope, limit: limit }).then((data) => {
					renderBulkTask(data.task || null);
					if (data.task && data.task.status === 'running') scheduleBulkStep(100);
				}).catch((error) => {
					window.alert(error.message || '无法创建全站任务。');
				}).finally(() => {
					renderBulkTask(currentBulkTask);
				});
				return;
			}

			const control = event.target.closest('[data-bulk-control]');
			if (control) {
				const action = control.dataset.bulkControl || '';
				if (action === 'stop' && !window.confirm('确认停止当前任务？已经完成的结果会保留。')) return;
				control.disabled = true;
				api('wiaa_bulk_control', { control: action }).then((data) => {
					renderBulkTask(data.task || null);
					if (data.task && data.task.status === 'running') scheduleBulkStep(100);
				}).catch((error) => {
					window.alert(error.message || '任务控制失败。');
				}).finally(() => {
					renderBulkTask(currentBulkTask);
				});
			}
		});

		renderBulkTask(currentBulkTask);
		if (currentBulkTask && ['running', 'cooldown'].includes(currentBulkTask.status)) {
			scheduleBulkStep(300);
		}
	}

})();
