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
			window.setTimeout(function(){ window.location.reload(); }, 700);
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
