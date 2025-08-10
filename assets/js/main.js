(function () {
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

  $(document).on('click', 'a.ai-generate-reply', function (e) {
    e.preventDefault();
    var $a = $(this);
    var tid = $a.data('ticket-id');
    if (!tid) return false;

    setLoading($a, true);
    var url = (window.AIResponseGen && window.AIResponseGen.ajaxEndpoint) || 'ajax.php/ai/response';

    $.ajax({
      url: url,
      method: 'POST',
      data: { ticket_id: tid, instance_id: $a.data('instance-id') || '' },
      dataType: 'json'
    }).done(function (resp) {
      if (resp && resp.ok) {
        if (!setReplyText(resp.text || '')) {
          alert('AI response generated, but reply box not found.');
        }
      } else {
        alert((resp && resp.error) ? resp.error : 'Failed to generate response');
      }
    }).fail(function (xhr) {
      var msg = 'Request failed';
      try {
        var r = JSON.parse(xhr.responseText);
        if (r && r.error) msg = r.error;
      } catch (e) { }
      alert(msg);
    }).always(function () {
      setLoading($a, false);
    });

    return false;
  });
})();
