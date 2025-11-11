(function () {
  // Ensure we only ever bind handlers once even if the script is injected multiple times (pjax reloads)
  window.AIResponseGen = window.AIResponseGen || {};
  // Track in-flight requests per ticket/instance to dedupe network calls
  window.AIResponseGen.inflight = window.AIResponseGen.inflight || {};
  function setReplyText(text) {
    var $ta = $('#response');
    if (!$ta.length) return false;

    // Ensure the Post Reply tab is active so editor is initialized
    var $postBtn = $('a.post-response.action-button').first();
    if ($postBtn.length && !$postBtn.hasClass('active')) {
      try { $postBtn.trigger('click'); } catch (e) { }
    }

    // Prefer Redactor source.setCode when richtext is enabled
    try {
      if (typeof $ta.redactor === 'function' && $ta.hasClass('richtext')) {
        var current = $ta.redactor('source.getCode') || '';
        var newText = current ? (current + "\n\n" + text) : text;
        $ta.redactor('source.setCode', newText);
        return true;
      }
    } catch (e) { }

    // Fallback to plain textarea append
    var current = $ta.val() || '';
    $ta.val(current ? (current + "\n\n" + text) : text).trigger('change');
    return true;
  }

  function setLoading($a, loading) {
    if (loading) {
      $a.addClass('ai-loading');
    } else {
      $a.removeClass('ai-loading');
    }
  }

  function showToast(message, type) {
    type = type || 'error'; // 'success' or 'error'

    // Try osTicket's native alertBox if available
    if (typeof alertBox !== 'undefined') {
      alertBox(message, type === 'error' ? 'warn' : 'success');
      return;
    }

    // Fallback to custom toast implementation
    var $toast = $('<div class="ai-toast ai-toast-' + type + '"></div>').text(message);
    $('body').append($toast);

    setTimeout(function() {
      $toast.addClass('ai-toast-show');
    }, 10);

    setTimeout(function() {
      $toast.removeClass('ai-toast-show');
      setTimeout(function() {
        $toast.remove();
      }, 300);
    }, 4000);
  }

  // Remove any previous namespaced handler and (re)bind once
  // Always unbind before binding (namespaced) to avoid duplicates
  $(document).off('click.ai-gen', 'a.ai-generate-reply');
  $(document).on('click.ai-gen', 'a.ai-generate-reply', function (e) {
    e.preventDefault();
    var $a = $(this);
    var tid = $a.data('ticket-id');
    if (!tid) return false;
    var iid = ($a.data('instance-id') || '').toString();
    var key = tid + ':' + iid;

    // Re-entrancy guard (covers accidental double fires from duplicate bindings or rapid clicks)
    if ($a.data('aiBusy')) return false;
    // In-flight dedupe across handlers/elements for the same ticket/instance
    if (window.AIResponseGen.inflight[key]) return false;
    $a.data('aiBusy', true);

    setLoading($a, true);
    var url = (window.AIResponseGen && window.AIResponseGen.ajaxEndpoint) || 'ajax.php/ai/response';

    var jq = $.ajax({
      url: url,
      method: 'POST',
      data: { ticket_id: tid, instance_id: iid },
      dataType: 'json'
    });
    // mark as in-flight
    window.AIResponseGen.inflight[key] = jq;

    jq.done(function (resp) {
      if (resp && resp.ok) {
        if (!setReplyText(resp.text || '')) {
          showToast('AI response generated, but reply box not found.', 'error');
        }
      } else {
        showToast((resp && resp.error) ? resp.error : 'Failed to generate response', 'error');
      }
    }).fail(function (xhr) {
      var msg = 'Request failed';
      try {
        var r = JSON.parse(xhr.responseText);
        if (r && r.error) msg = r.error;
      } catch (e) { }
      showToast(msg, 'error');
    }).always(function () {
      setLoading($a, false);
      $a.data('aiBusy', false);
      delete window.AIResponseGen.inflight[key];
    });

    return false;
  });

  window.AIResponseGen.bound = true;
})();
