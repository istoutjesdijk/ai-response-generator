(function () {
  // Ensure we only ever bind handlers once even if the script is injected multiple times (pjax reloads)
  window.AIResponseGen = window.AIResponseGen || {};
  // Track in-flight requests per ticket/instance to dedupe network calls
  window.AIResponseGen.inflight = window.AIResponseGen.inflight || {};
  function setReplyText(text, append) {
    var $ta = $('#response');
    if (!$ta.length) {
      console.warn('AI Response: #response textarea not found');
      return false;
    }

    // Ensure the Post Reply tab is active so editor is initialized
    var $postBtn = $('a.post-response.action-button').first();
    if ($postBtn.length && !$postBtn.hasClass('active')) {
      try { $postBtn.trigger('click'); } catch (e) { }
    }

    // Prefer Redactor insert when richtext is enabled
    try {
      if (typeof $ta.redactor === 'function' && $ta.hasClass('richtext')) {
        if (append) {
          // Append mode: insert at end
          // Use insertion.insertHtml to add content at cursor position
          try {
            $ta.redactor('insertion.insertHtml', text);
            console.log('AI Response: Redactor insert (append)');
          } catch (e) {
            // Fallback to source.setCode if insertion doesn't work
            var current = $ta.redactor('source.getCode') || '';
            var newContent = current + text;
            $ta.redactor('source.setCode', newContent);
            console.log('AI Response: Redactor setCode (fallback), new length:', newContent.length);
          }
        } else {
          // Replace mode: add with spacing
          var current = $ta.redactor('source.getCode') || '';
          var newText = current ? (current + "\n\n" + text) : text;
          $ta.redactor('source.setCode', newText);
          // Move cursor to end for subsequent appends
          try {
            $ta.redactor('selection.setEnd');
          } catch (e) {}
          console.log('AI Response: Redactor replace, new length:', newText.length);
        }
        return true;
      }
    } catch (e) {
      console.error('AI Response: Redactor error:', e);
    }

    // Fallback to plain textarea
    if (append) {
      // Append mode: add to existing content
      var current = $ta.val() || '';
      var newContent = current + text;
      $ta.val(newContent).trigger('change');
      console.log('AI Response: Textarea append, new length:', newContent.length);
    } else {
      // Replace mode: add with spacing
      var current = $ta.val() || '';
      var newText = current ? (current + "\n\n" + text) : text;
      $ta.val(newText).trigger('change');
      console.log('AI Response: Textarea replace, new length:', newText.length);
    }
    return true;
  }

  function setLoading($a, loading) {
    var $icon = $a.find('i').first();
    if (loading) {
      $a.addClass('ai-loading');
      // Swap to osTicket's native spinner icon
      $icon.data('original-class', $icon.attr('class'));
      $icon.attr('class', 'icon-spinner icon-spin');
    } else {
      $a.removeClass('ai-loading');
      // Restore original icon
      var originalClass = $icon.data('original-class');
      if (originalClass) {
        $icon.attr('class', originalClass);
      }
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

  // Build request data with CSRF token
  function buildRequestData(tid, iid, extraInstructions) {
    var data = { ticket_id: tid, instance_id: iid };
    if (extraInstructions) {
      data.extra_instructions = extraInstructions;
    }
    var csrfToken = $('input[name="__CSRFToken__"]').val() ||
                    $('meta[name="csrf_token"]').attr('content') || '';
    if (csrfToken) {
      data.__CSRFToken__ = csrfToken;
    }
    return data;
  }

  function showStreamingOverlay() {
    var overlayHtml =
      '<div class="ai-streaming-overlay">' +
        '<div class="ai-streaming-modal">' +
          '<div class="ai-streaming-header">' +
            '<h3>AI Response Generating...</h3>' +
          '</div>' +
          '<div class="ai-streaming-body">' +
            '<div class="ai-streaming-content"></div>' +
          '</div>' +
        '</div>' +
      '</div>';

    var $overlay = $(overlayHtml);
    $('body').append($overlay);

    // Return functions to update and close
    return {
      update: function(text) {
        $overlay.find('.ai-streaming-content').text(text);
        // Auto-scroll to bottom
        var $content = $overlay.find('.ai-streaming-content');
        $content.scrollTop($content[0].scrollHeight);
      },
      close: function() {
        $overlay.fadeOut(200, function() {
          $overlay.remove();
        });
      }
    };
  }

  function generateAIResponseStreaming($a, tid, iid, extraInstructions) {
    var key = tid + ':' + iid;
    setLoading($a, true);

    var streamBuffer = '';
    var streamingUI = showStreamingOverlay();
    var requestData = buildRequestData(tid, iid, extraInstructions);

    // Convert to URLSearchParams for fetch
    var formData = new URLSearchParams();
    for (var k in requestData) {
      formData.append(k, requestData[k]);
    }

    var baseUrl = (window.AIResponseGen && window.AIResponseGen.ajaxEndpoint) || 'ajax.php/ai/response';
    var streamUrl = baseUrl.replace(/\/response$/, '/response/stream');

    fetch(streamUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin', // Include cookies for session/CSRF
      headers: {
        'Accept': 'text/event-stream'
      }
    }).then(function(response) {
      if (!response.ok) {
        // Try to read error response
        return response.text().then(function(text) {
          console.error('Streaming request failed:', response.status, text);
          throw new Error('HTTP ' + response.status + ': ' + (text || 'Unknown error'));
        });
      }

      var reader = response.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';
      var currentEvent = null; // Track event type across chunks

      // Mark as in-flight
      window.AIResponseGen.inflight[key] = { abort: function() { reader.cancel(); } };

      // Helper function to process SSE lines
      function processLines(lines) {
        lines.forEach(function(line) {
          line = line.trim();
          if (!line) return;

          // Parse SSE format: "event: chunk" or "data: {...}"
          if (line.indexOf('event:') === 0) {
            // Store event type for next data line
            currentEvent = line.substring(6).trim();
            return;
          }

          if (line.indexOf('data:') === 0) {
            var jsonStr = line.substring(5).trim();
            try {
              var data = JSON.parse(jsonStr);

              // Handle different event types
              if (currentEvent === 'chunk' && data.text) {
                console.log('AI Response: Received chunk:', data.text.substring(0, 50) + '...');

                // Add to buffer
                streamBuffer += data.text;

                // Update streaming overlay in real-time
                if (streamingUI) {
                  streamingUI.update(streamBuffer);
                }
              } else if (currentEvent === 'done') {
                console.log('AI Response: Stream completed, writing to textarea');

                // Close streaming overlay
                if (streamingUI) {
                  streamingUI.close();
                  streamingUI = null;
                }

                // Write final content to textarea in one go
                if (streamBuffer) {
                  if (!setReplyText(streamBuffer, false)) {
                    showToast('Failed to write response to textarea', 'error');
                  }
                } else if (data.text) {
                  // Fallback: use done event text if no chunks received
                  setReplyText(data.text, false);
                }

                // Clean up after done event
                setLoading($a, false);
                $a.data('aiBusy', false);
                delete window.AIResponseGen.inflight[key];
              } else if (currentEvent === 'error' && data.message) {
                // Error event
                console.error('AI Response: Error:', data.message);

                // Close streaming overlay
                if (streamingUI) {
                  streamingUI.close();
                  streamingUI = null;
                }

                showToast(data.message, 'error');
                setLoading($a, false);
                $a.data('aiBusy', false);
                delete window.AIResponseGen.inflight[key];
                reader.cancel();
              }

              currentEvent = null; // Reset after processing data
            } catch (e) {
              console.error('Failed to parse SSE data:', e);
            }
          }
        });
      }

      function processStream() {
        return reader.read().then(function(result) {
          if (result.done) {
            // Process any remaining data in buffer before finishing
            if (buffer.trim()) {
              console.log('AI Response: Processing remaining buffer on stream end');
              var remainingLines = buffer.split('\n');
              processLines(remainingLines);
            }

            // Ensure overlay is closed and state is cleaned up even if done event was missed
            if (streamingUI) {
              console.warn('AI Response: Stream ended without done event, closing overlay');
              streamingUI.close();
              streamingUI = null;

              // Write whatever we have in the buffer
              if (streamBuffer) {
                if (!setReplyText(streamBuffer, false)) {
                  showToast('Failed to write response to textarea', 'error');
                }
              }
            }

            setLoading($a, false);
            $a.data('aiBusy', false);
            delete window.AIResponseGen.inflight[key];
            return;
          }

          buffer += decoder.decode(result.value, { stream: true });
          var lines = buffer.split('\n');
          buffer = lines.pop(); // Keep incomplete line in buffer

          processLines(lines);

          return processStream();
        });
      }

      return processStream();

    }).catch(function(error) {
      // Close streaming overlay on error
      if (streamingUI) {
        streamingUI.close();
        streamingUI = null;
      }

      showToast('Streaming failed: ' + error.message, 'error');
      setLoading($a, false);
      $a.data('aiBusy', false);
      delete window.AIResponseGen.inflight[key];
    });

    return false;
  }

  function generateAIResponse($a, tid, iid, extraInstructions) {
    var key = tid + ':' + iid;
    setLoading($a, true);

    var url = (window.AIResponseGen && window.AIResponseGen.ajaxEndpoint) || 'ajax.php/ai/response';
    var jq = $.ajax({
      url: url,
      method: 'POST',
      data: buildRequestData(tid, iid, extraInstructions),
      dataType: 'json'
    });
    window.AIResponseGen.inflight[key] = jq;

    jq.done(function (resp) {
      if (resp && resp.ok) {
        if (!setReplyText(resp.text || '', false)) {
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
    var enableStreaming = $a.data('enable-streaming') === '1' || $a.data('enable-streaming') === 1;
    var key = tid + ':' + iid;

    // Re-entrancy guard (covers accidental double fires from duplicate bindings or rapid clicks)
    if ($a.data('aiBusy')) return false;
    // In-flight dedupe across handlers/elements for the same ticket/instance
    if (window.AIResponseGen.inflight[key]) return false;
    $a.data('aiBusy', true);

    // Choose between streaming and non-streaming
    var generateFunc = enableStreaming ? generateAIResponseStreaming : generateAIResponse;

    // Debug: log which function is being used
    console.log('AI Response: Using ' + (enableStreaming ? 'STREAMING' : 'NON-STREAMING') + ' mode');

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
        generateFunc($a, tid, iid, extraInstructions);
      });
    } else {
      // Generate directly without popup
      generateFunc($a, tid, iid, '');
    }

    return false;
  });

  window.AIResponseGen.bound = true;
})();
