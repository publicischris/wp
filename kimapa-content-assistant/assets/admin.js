(function ($) {
  'use strict';

  function setStatus($box, message, isError) {
    $box.find('.kimapa-ca-status').text(message).toggleClass('is-error', !!isError);
  }

  function renderChecks($box, checks) {
    var $list = $box.find('.kimapa-ca-checks').empty();
    if (!checks || !checks.length) {
      $list.append($('<li>').text(kimapaCA.i18n.noChecks || 'No checks available.'));
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
    $box.find('textarea:not([readonly]), input[type="url"], input[type="number"], select').each(function () {
      data[this.name] = $(this).val();
    });
    return data;
  }

  function getFieldValue($target) {
    return $target.is('textarea, input') ? $target.val() : $target.text();
  }

  function fallbackCopy(text) {
    var $tmp = $('<textarea readonly>').css({ position: 'fixed', top: '-9999px', left: '-9999px' }).val(text).appendTo('body');
    $tmp[0].select();
    var ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (e) {
      ok = false;
    }
    $tmp.remove();
    return ok ? Promise.resolve() : Promise.reject();
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return fallbackCopy(text);
  }

  function normalizeAiResult(raw) {
    var data = JSON.parse(raw);
    return data.output || data.result || data;
  }

  function toText(value) {
    if (Array.isArray(value)) {
      return value.join('\n');
    }
    if (value && typeof value === 'object') {
      return JSON.stringify(value, null, 2);
    }
    return value ? String(value) : '';
  }

  function setTextarea($box, name, value) {
    $box.find('[name="' + name + '"]').val(toText(value));
  }


  $(document).on('toggle', '.kimapa-ca-checklist', function () {
    $(this).children('summary').text(this.open ? kimapaCA.i18n.hideChecklist : kimapaCA.i18n.showChecklist);
  });

  $(document).on('click', '.kimapa-ca-snapshot', function () {
    var $button = $(this);
    var $box = $button.closest('.kimapa-ca');
    var data = collectFields($box);
    data.action = 'kimapa_ca_snapshot';
    data.channel = $box.find('[name="_kimapa_newsletter_teaser"]').length && !$box.find('[name="_kimapa_instagram_caption"]').length ? 'newsletter' : 'instagram';
    $button.prop('disabled', true).text(kimapaCA.i18n.saving);
    $.post(kimapaCA.ajaxUrl, data).done(function (response) {
      setStatus($box, response && response.success ? kimapaCA.i18n.snapshotSaved : kimapaCA.i18n.error, !response || !response.success);
    }).fail(function () {
      setStatus($box, kimapaCA.i18n.error, true);
    }).always(function () {
      $button.prop('disabled', false).text(kimapaCA.i18n.saveSnapshot);
    });
  });

  $(document).on('click', '.kimapa-ca-analyze', function () {
    var $button = $(this);
    var $box = $button.closest('.kimapa-ca');
    $button.prop('disabled', true).text(kimapaCA.i18n.analyzing);
    setStatus($box, '', false);

    $.post(kimapaCA.ajaxUrl, {
      action: 'kimapa_ca_analyze',
      nonce: kimapaCA.nonce,
      post_id: $box.data('post-id'),
      prompt_language: $box.find('[name="_content_assistant_prompt_language"]').val() || 'auto'
    }).done(function (response) {
      if (!response || !response.success) {
        setStatus($box, kimapaCA.i18n.error, true);
        return;
      }
      $box.find('.kimapa-ca-score strong').text(response.data.analysis.score);
      $box.find('textarea[name="_kimapa_generated_prompt"]').val(response.data.prompt);
      renderChecks($box, response.data.analysis.checks);
      setStatus($box, kimapaCA.i18n.analysisComplete, false);
    }).fail(function () {
      setStatus($box, kimapaCA.i18n.error, true);
    }).always(function () {
      $button.prop('disabled', false).text(kimapaCA.i18n.analyze);
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
      $button.prop('disabled', false).text(kimapaCA.i18n.save);
    });
  });

  $(document).on('click', '.kimapa-ca-copy', function () {
    var $button = $(this);
    var $box = $button.closest('.kimapa-ca');
    var $target = $box.find($button.data('copy-target'));
    var text = getFieldValue($target);
    if (!text) {
      setStatus($box, kimapaCA.i18n.nothingToCopy, true);
      return;
    }
    var original = $button.text();
    $button.prop('disabled', true);
    copyText(text).then(function () {
      $button.text(kimapaCA.i18n.copied);
      setStatus($box, kimapaCA.i18n.copied, false);
      window.setTimeout(function () {
        $button.text(original).prop('disabled', false);
      }, 1200);
    }).catch(function () {
      setStatus($box, kimapaCA.i18n.error, true);
      $button.prop('disabled', false);
    });
  });

  $(document).on('click', '.kimapa-ca-apply-ai-result', function () {
    var $button = $(this);
    var $box = $button.closest('.kimapa-ca');
    var raw = $box.find('[name="_kimapa_ai_result_raw"]').val();
    var data;

    try {
      data = normalizeAiResult(raw);
    } catch (e) {
      setStatus($box, kimapaCA.i18n.invalidJson, true);
      return;
    }

    if (!window.confirm(kimapaCA.i18n.overwriteWarning)) {
      return;
    }

    setTextarea($box, '_kimapa_instagram_caption', data.instagram_caption_variant_1_emotional || data.instagram_caption_variant_1 || data.caption || '');
    setTextarea($box, '_kimapa_instagram_caption_variant_1', data.instagram_caption_variant_1_emotional || data.instagram_caption_variant_1 || '');
    setTextarea($box, '_kimapa_instagram_caption_variant_2', data.instagram_caption_variant_2_practical || data.instagram_caption_variant_2 || '');
    setTextarea($box, '_kimapa_instagram_caption_variant_3', data.instagram_caption_variant_3_short || data.instagram_caption_variant_3 || '');
    setTextarea($box, '_kimapa_hook', data.hook || '');
    setTextarea($box, '_kimapa_instagram_cta', data.cta || '');
    setTextarea($box, '_kimapa_instagram_hashtags', data.hashtags || '');
    setTextarea($box, '_kimapa_instagram_story_idea', data.story_idea || '');
    setTextarea($box, '_kimapa_instagram_carousel_idea', data.carousel_idea || '');
    setTextarea($box, '_kimapa_newsletter_teaser', data.newsletter_teaser || '');
    setTextarea($box, '_kimapa_editorial_improvement_notes', data.editorial_improvement_notes || '');

    $button.prop('disabled', true);
    window.setTimeout(function () { $button.prop('disabled', false); }, 800);
    setStatus($box, kimapaCA.i18n.applied, false);
  });
})(jQuery);
