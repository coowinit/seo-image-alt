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
})();
