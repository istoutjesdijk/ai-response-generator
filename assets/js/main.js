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

  function showInstructionsModal(callback) {
    // Create modal HTML - osTicket dialog style
    var modalHtml =
      '<div class="ai-modal-overlay">' +
        '<div class="ai-modal">' +
          '<div class="ai-modal-header">' +
            '<h3>AI Response Instructions</h3>' +
            '<button class="ai-modal-close" title="Close">&times;</button>' +
          '</div>' +
          '<div class="ai-modal-body">' +
            '<label for="ai-extra-instructions">Additional context or instructions:</label>' +
            '<textarea id="ai-extra-instructions" class="ai-instructions-textarea" ' +
              'placeholder="Example: Offer the customer a refund and apologize for the inconvenience&#10;&#10;Leave empty to generate a response based on the conversation history alone."></textarea>' +
            '<div style="margin-top: 12px; padding: 10px; background-color: #f9f9f9; border-left: 3px solid #0e76a8; font-size: 12px; color: #666; border-radius: 3px;">' +
              '<strong style="color: #444;">Tip:</strong> You can provide specific guidance like tone (formal/casual), actions to take (refund, escalate), ' +
              'or information to include. The AI will use the ticket conversation history along with your instructions.' +
            '</div>' +
          '</div>' +
          '<div class="ai-modal-footer">' +
            '<button class="ai-modal-btn ai-modal-cancel">Cancel</button>' +
            '<button class="ai-modal-btn ai-modal-generate">Generate Response</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    var $modal = $(modalHtml);
    $('body').append($modal);

    // Focus on textarea after animation
    setTimeout(function() {
      $modal.find('#ai-extra-instructions').focus();
    }, 350);

    // Close handlers
    $modal.find('.ai-modal-close, .ai-modal-cancel').on('click', function() {
      $modal.fadeOut(200, function() {
        $modal.remove();
      });
      callback(null);
    });

    // Generate handler
    $modal.find('.ai-modal-generate').on('click', function() {
      var instructions = $modal.find('#ai-extra-instructions').val().trim();
      $modal.fadeOut(200, function() {
        $modal.remove();
      });
      callback(instructions);
    });

    // Close on overlay click
    $modal.on('click', function(e) {
      if ($(e.target).hasClass('ai-modal-overlay')) {
        $modal.fadeOut(200, function() {
          $modal.remove();
        });
        callback(null);
      }
    });

    // Keyboard shortcuts
    $modal.find('#ai-extra-instructions').on('keydown', function(e) {
      // Ctrl+Enter or Cmd+Enter to submit
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        $modal.find('.ai-modal-generate').trigger('click');
      }
      // Escape to cancel
      if (e.key === 'Escape') {
        e.preventDefault();
        $modal.find('.ai-modal-cancel').trigger('click');
      }
    });
  }

  function generateAIResponse($a, tid, iid, extraInstructions) {
    var key = tid + ':' + iid;

    setLoading($a, true);
    var url = (window.AIResponseGen && window.AIResponseGen.ajaxEndpoint) || 'ajax.php/ai/response';

    var requestData = { ticket_id: tid, instance_id: iid };
    if (extraInstructions) {
      requestData.extra_instructions = extraInstructions;
    }

    var jq = $.ajax({
      url: url,
      method: 'POST',
      data: requestData,
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
  }

  // Helper function to get current ticket ID from URL
  function getCurrentTicketId() {
    // Try to extract ticket ID from URL (?id=123)
    var match = window.location.search.match(/[?&]id=(\d+)/);
    if (match) return parseInt(match[1], 10);

    // Fallback: try to find ticket ID in the page
    var $ticketId = $('input[name="id"]').first();
    if ($ticketId.length) return parseInt($ticketId.val(), 10);

    return 0;
  }

  // Remove any previous namespaced handler and (re)bind once
  // Always unbind before binding (namespaced) to avoid duplicates
  $(document).off('click.ai-gen', 'a.ai-generate-reply');
  $(document).on('click.ai-gen', 'a.ai-generate-reply', function (e) {
    e.preventDefault();
    var $a = $(this);

    // IMPORTANT: Always read ticket ID from current page URL, not from cached button data
    // This fixes the issue where switching tickets via pjax would use the wrong ticket ID
    var tid = getCurrentTicketId();
    if (!tid) {
      // Fallback to button data attribute only if URL parsing fails
      tid = $a.data('ticket-id');
    }
    if (!tid) return false;

    var iid = ($a.data('instance-id') || '').toString();
    var showPopup = $a.data('show-popup') !== '0' && $a.data('show-popup') !== 0;
    var key = tid + ':' + iid;

    // Re-entrancy guard (covers accidental double fires from duplicate bindings or rapid clicks)
    if ($a.data('aiBusy')) return false;
    // In-flight dedupe across handlers/elements for the same ticket/instance
    if (window.AIResponseGen.inflight[key]) return false;
    $a.data('aiBusy', true);

    // Check if popup should be shown
    if (showPopup) {
      // Show modal to get extra instructions
      showInstructionsModal(function(extraInstructions) {
        // User cancelled
        if (extraInstructions === null) {
          $a.data('aiBusy', false);
          return;
        }

        // Proceed with generation
        generateAIResponse($a, tid, iid, extraInstructions);
      });
    } else {
      // Generate directly without popup
      generateAIResponse($a, tid, iid, '');
    }

    return false;
  });

  window.AIResponseGen.bound = true;
})();
