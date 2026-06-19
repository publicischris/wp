(function ($) {
  'use strict';

  function setStatus($box, message, isError) {
    $box.find('.kimapa-ca-status').text(message).toggleClass('is-error', !!isError);
  }

  function renderChecks($box, checks) {
    var $list = $box.find('.kimapa-ca-checks').empty();
    if (!checks || !checks.length) {
      $list.append($('<li>').text('Keine Checks vorhanden.'));
      return;
    }
    checks.forEach(function (check) {
      $('<li>')
        .addClass(check.passed ? 'is-passed' : 'is-open')
        .append($('<strong>').text(check.label || 'Check'))
        .append($('<br>'))
        .append($('<span>').text(check.message || ''))
        .appendTo($list);
    });
  }

  function collectFields($box) {
    var data = {
      action: 'kimapa_ca_save',
      nonce: kimapaCA.nonce,
      post_id: $box.data('post-id')
    };
    $box.find('textarea:not([readonly]), input[type="url"], input[type="number"]').each(function () {
      data[this.name] = $(this).val();
    });
    return data;
  }

  $(document).on('click', '.kimapa-ca-analyze', function () {
    var $button = $(this);
    var $box = $button.closest('.kimapa-ca');
    $button.prop('disabled', true).text(kimapaCA.i18n.analyzing);
    setStatus($box, '', false);

    $.post(kimapaCA.ajaxUrl, {
      action: 'kimapa_ca_analyze',
      nonce: kimapaCA.nonce,
      post_id: $box.data('post-id')
    }).done(function (response) {
      if (!response || !response.success) {
        setStatus($box, kimapaCA.i18n.error, true);
        return;
      }
      $box.find('.kimapa-ca-score strong').text(response.data.analysis.score);
      $box.find('textarea[name="_kimapa_generated_prompt"]').val(response.data.prompt);
      renderChecks($box, response.data.analysis.checks);
      setStatus($box, 'Analyse abgeschlossen.', false);
    }).fail(function () {
      setStatus($box, kimapaCA.i18n.error, true);
    }).always(function () {
      $button.prop('disabled', false).text('Beitrag analysieren');
    });
  });

  $(document).on('click', '.kimapa-ca-save', function () {
    var $button = $(this);
    var $box = $button.closest('.kimapa-ca');
    $button.prop('disabled', true).text(kimapaCA.i18n.saving);
    setStatus($box, '', false);

    $.post(kimapaCA.ajaxUrl, collectFields($box)).done(function (response) {
      setStatus($box, response && response.success ? kimapaCA.i18n.saved : kimapaCA.i18n.error, !response || !response.success);
    }).fail(function () {
      setStatus($box, kimapaCA.i18n.error, true);
    }).always(function () {
      $button.prop('disabled', false).text('Speichern');
    });
  });
})(jQuery);
