(function(){
	'use strict';

	function showNotice(message, type) {
		var wrap = document.querySelector('.cto-wrap') || document.querySelector('.wrap');
		if (!wrap) { return; }
		var notice = document.createElement('div');
		notice.className = 'notice notice-' + (type || 'success') + ' is-dismissible cto-ajax-notice';
		notice.innerHTML = '<p>' + String(message || '').replace(/[&<>"]/g, function (char) {
			return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[char]);
		}) + '</p>';
		wrap.insertBefore(notice, wrap.children[1] || null);
		window.setTimeout(function(){ notice.remove(); }, 4500);
	}

	function setBusy(link, busy) {
		if (busy) {
			link.dataset.ctoOriginalText = link.textContent;
			link.textContent = (window.ctoAdmin && ctoAdmin.working) || 'Wird verarbeitet…';
			link.classList.add('cto-is-busy');
			link.setAttribute('aria-disabled', 'true');
		} else {
			link.textContent = link.dataset.ctoOriginalText || link.textContent;
			link.classList.remove('cto-is-busy');
			link.removeAttribute('aria-disabled');
		}
	}



	function escapeHtml(value) {
		return String(value).replace(/[&<>"]/g, function (char) {
			return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[char]);
		});
	}

	function updateScoreCells(postId, scores) {
		if (!postId || !scores) { return; }
		var row = document.querySelector('.cto-content-row[data-post-id="' + postId + '"]');
		if (!row) { return; }
		[['tax_score', scores.tax_score], ['struct_score', scores.struct_score], ['total_score', scores.total_score], ['words', scores.words], ['internal', scores.internal], ['external', scores.external], ['h2', scores.h2], ['categories', scores.categories], ['tags', scores.tags], ['custom_tax', scores.custom_tax]].forEach(function(item){
			var cell = row.querySelector('[data-cto-column="' + item[0] + '"]');
			if (cell && typeof item[1] !== 'undefined') {
				cell.innerHTML = item[0] === 'total_score' ? '<strong>' + escapeHtml(item[1]) + '</strong>' : escapeHtml(item[1]);
			}
		});
		var noticeCell = row.querySelector('[data-cto-column="notice"]');
		if (noticeCell && scores.notice) {
			noticeCell.innerHTML = '<span class="cto-status cto-status-' + String(scores.notice_class || 'unknown').replace(/[^a-z0-9_-]/gi, '') + '">' + String(scores.notice).replace(/[&<>"]/g, function (char) {
				return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[char]);
			}) + '</span>';
		}
	}

	function updateRecommendationCard(link, payload) {
		var action = link.dataset.recAction || '';
		var card = link.closest('.cto-workflow-item');
		if (!card) { return; }

		if (action === 'accept' || action === 'ignore' || action === 'checked') {
			card.classList.add('cto-workflow-item-processed');
			window.setTimeout(function(){ card.remove(); }, 220);
			return;
		}

		if (action === 'reset') {
			card.dataset.recStatus = (payload && payload.data && payload.data.status) || 'open';
			var status = card.querySelector('.cto-rec-status');
			if (status) { status.textContent = card.dataset.recStatus; }
		}
	}

	function markButtonDone(link) {
		link.classList.add('cto-action-done');
		window.setTimeout(function(){ link.classList.remove('cto-action-done'); }, 2200);
	}

	function ajaxAction(link) {
		var type = link.dataset.ctoAjax;
		var body = new window.URLSearchParams();
		if (type === 'analyze') {
			body.append('action', 'cto_analyze_single');
		} else if (type === 'ai_analyze') {
			body.append('action', 'cto_ai_analyze_single');
		} else if (type === 'recommendation') {
			body.append('action', 'cto_recommendation_action');
			body.append('rec_key', link.dataset.recKey || '');
			body.append('rec_action', link.dataset.recAction || '');
		} else {
			return;
		}
		body.append('post_id', link.dataset.postId || '0');
		body.append('nonce', link.dataset.nonce || '');

		setBusy(link, true);
		window.fetch((window.ctoAdmin && ctoAdmin.ajaxUrl) || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		}).then(function(response){ return response.json(); }).then(function(payload){
			if (!payload || !payload.success) {
				throw new Error(payload && payload.data && payload.data.message ? payload.data.message : ((window.ctoAdmin && ctoAdmin.error) || 'Fehler'));
			}
			showNotice(payload.data && payload.data.message ? payload.data.message : ((window.ctoAdmin && ctoAdmin.success) || 'Erfolgreich'), 'success');
			updateScoreCells(link.dataset.postId || '0', payload.data && payload.data.scores);
			if (type === 'recommendation') {
				updateRecommendationCard(link, payload);
			} else {
				markButtonDone(link);
			}
			setBusy(link, false);
		}).catch(function(error){
			showNotice(error.message, 'error');
			setBusy(link, false);
		});
	}

	document.addEventListener('click', function(event){
		var link = event.target.closest('.cto-ajax-action');
		if (!link || link.classList.contains('cto-is-busy')) { return; }
		event.preventDefault();
		ajaxAction(link);
	});
}());
