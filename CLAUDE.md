# AI Response Generator Plugin for osTicket

## Overview

This plugin adds AI-generated responses to osTicket. Agents can generate a draft reply with one click based on the ticket conversation. The plugin supports both OpenAI-compatible APIs and Anthropic Claude.

## Resources

The osTicket repository: https://github.com/osTicket/osTicket/

The code should remain simple and easy to maintain. This is a plugin for OSticket so try to make use of OStickets libaries and tools as much as possible. Make sure it stays a plugin and not too complex.

## File Structure

```
ai-response-generator/
├── plugin.php                  # Plugin entry point & metadata
├── src/
│   ├── AIResponsePlugin.php   # Main plugin class (signal handlers, bootstrap)
│   ├── Config.php             # Admin configuration form definition
│   ├── AIAjax.php             # AJAX controller for response generation
│   └── Constants.php          # Constants (defaults, limits)
├── api/
│   └── AIClient.php            # API client for OpenAI, Anthropic & compatible APIs
├── assets/
│   ├── js/
│   │   └── main.js            # Frontend JavaScript (button handlers, modal)
│   └── css/
│       └── style.css          # Styling for buttons, modal, toast notifications
├── osticket/                  # osTicket codebase for reference (NOT in git)
│   ├── include/               # osTicket core classes
│   │   ├── class.ticket.php
│   │   ├── class.thread.php
│   │   └── ...
│   └── ...
├── .gitignore                 # Ignores osticket/ directory
└── CLAUDE.md                  # This documentation
```

## Features

### Core Functionality
- **AI Response Button**: Button in ticket toolbar and "More" dropdown menu
- **Multi-instance support**: Multiple AI providers simultaneously (e.g., GPT-4 and Claude)
- **Optional Instructions Modal**: Agents can provide extra instructions (e.g., "Offer customer a refund")
- **Automatic Thread Context**: Plugin sends last N messages from ticket thread (configurable)
- **Response Template**: Configurable template with placeholders ({user_name}, {ticket_number}, etc.)
- **RAG Content**: Add extra context for better AI responses
- **Streaming Responses**: Real-time typewriter effect as AI generates responses (configurable per instance)

### Supported AI Providers
- **OpenAI**: GPT-4, GPT-3.5, etc. (via Chat Completions API)
- **Anthropic Claude**: Claude 3 Opus, Sonnet, Haiku (via Messages API)
- **OpenAI-compatible APIs**: LM Studio, Ollama, etc.
- **Auto-detection**: Detects provider based on URL/model name

## Configuration Options

### Admin Settings (per instance)
- **API URL**: Endpoint URL (e.g., `https://api.openai.com/v1/chat/completions`)
- **API Key**: Authentication key
- **Model Name**: Model identifier (e.g., `gpt-4o`, `claude-3-opus-20240229`)
- **Max Tokens**: Maximum response length (default: 512)
- **Temperature**: Randomness (0.0-2.0, default: 1.0)
- **Timeout**: Request timeout in seconds (default: 60)
- **Max Thread Entries**: Maximum number of ticket messages in context (default: 20)
- **System Prompt**: Instructions for AI behavior and tone
- **Response Template**: Template with placeholders for formatted output
- **RAG Content**: Extra knowledge base content (max 20,000 characters)
- **Show Instructions Popup**: Enable/disable modal for extra instructions
- **Anthropic Version**: API version header for Anthropic (default: 2023-06-01)
- **Max Tokens Parameter Name**: Parameter name for max tokens (API-specific)
- **Enable Streaming Responses**: Enable real-time streaming (typewriter effect) instead of waiting for full response
- **Enable Vision Support**: Enable AI analysis of image attachments (requires vision-capable models)
- **Max Images per Request**: Maximum images to send (default: 5, OpenAI max: 10, Anthropic max: 100)
- **Max Image Size (MB)**: Maximum size per image in megabytes (default: 5 MB)
- **Include Inline Images**: Include embedded images like email signatures (usually disabled)

## Streaming Responses

### Overview
When streaming is enabled, AI responses appear in real-time as they are generated, creating a typewriter effect. This provides immediate feedback to agents and improves perceived performance.

### How It Works

**Backend (Server-Sent Events):**
1. Frontend makes POST request to `/ajax.php/ai/response/stream`
2. Backend sets SSE headers (`Content-Type: text/event-stream`)
3. AIClient streams chunks via callback function
4. Each chunk is sent as SSE event: `event: chunk\ndata: {"text":"..."}\n\n`
5. Final event sent: `event: done\ndata: {"text":"<full response>"}\n\n`

**Frontend (Fetch API + ReadableStream):**
1. Uses `fetch()` with `response.body.getReader()`
2. Reads stream chunks incrementally
3. Parses SSE format (event/data lines)
4. Appends each text chunk to reply textarea in real-time
5. Handles both plain textarea and Redactor rich text editor

**Provider Support:**
- **OpenAI**: Uses `stream: true` parameter, parses `{"choices":[{"delta":{"content":"..."}}]}`
- **Anthropic**: Uses `stream: true` parameter, parses `{"type":"content_block_delta","delta":{"text":"..."}}`

### Configuration
Set "Enable Streaming Responses" to Yes in plugin instance configuration. Streaming works independently per instance, so you can have one instance with streaming and another without.

### Technical Details
- Uses PHP cURL's `CURLOPT_WRITEFUNCTION` callback for streaming API responses
- Buffers incomplete SSE lines to handle chunked network data
- Disables output buffering (`ob_end_clean()`) for immediate flushing
- Frontend tracks `startedWriting` state to handle initial spacing correctly
- Error events abort stream and show error toast

## Vision Support (Image Analysis)

### Overview
When vision support is enabled, the plugin can send image attachments from ticket messages to vision-capable AI models for analysis. This allows the AI to:
- Analyze screenshots of errors or issues
- Read text from images (OCR)
- Interpret charts, diagrams, and technical drawings
- Understand visual context from customer-provided images

### Supported Models
- **OpenAI**: GPT-4o, GPT-4-turbo with vision, GPT-4.5
- **Anthropic**: All Claude 3 models (Haiku, Sonnet, Opus) and Claude 4 models

### How It Works

**Backend Processing:**
1. Plugin checks if vision is enabled in configuration
2. For each thread entry, image attachments are extracted
3. Images are filtered by:
   - MIME type (JPEG, PNG, GIF, WebP only)
   - File size (configurable max size in MB)
   - Inline status (configurable whether to include embedded images)
4. Images are base64-encoded and added to message content arrays
5. Provider-specific formatting is applied:
   - **OpenAI**: `{"type":"image_url","image_url":{"url":"data:image/jpeg;base64,..."}}`
   - **Anthropic**: `{"type":"image","source":{"type":"base64","media_type":"image/jpeg","data":"..."}}`

**Configuration:**
- **Enable Vision Support**: Master switch (default: OFF)
- **Max Images**: Limit per request (default: 5, respects provider limits)
- **Max Image Size**: Size limit in MB (default: 5 MB)
- **Include Inline Images**: Whether to include embedded images (default: OFF)

**Supported Image Formats:**
- image/jpeg, image/jpg
- image/png
- image/gif
- image/webp

**Limits:**
- OpenAI: Maximum 10 images per request, recommended 20MB per image
- Anthropic: Maximum 100 images per request, 32MB total request size

### Cost Considerations
⚠️ **IMPORTANT**: Vision-enabled API calls are significantly more expensive than text-only calls. The cost increases with:
- Number of images
- Image resolution/size
- Model capability (e.g., GPT-4o vision is more expensive than GPT-3.5)

**Recommendations:**
- Keep vision support disabled by default
- Set conservative image limits (5 images, 5MB max)
- Exclude inline images (email signatures, logos) to reduce costs
- Use only when visual context is necessary for support

### Use Cases
1. **Technical Support**: Analyze error screenshots to identify issues
2. **Product Support**: Review photos of damaged/defective products
3. **Documentation**: Extract information from forms, receipts, or documents
4. **UI/UX Issues**: Understand visual problems customers are experiencing

## Architecture

### Signal Flow

1. **Bootstrap** (`AIResponsePlugin.php:23`)
   - `ticket.view.more`: Add menu item to "More" dropdown
   - `object.view`: Inject JS/CSS assets and toolbar button
   - `ajax.scp`: Register AJAX routes `/ai/response` and `/ai/response/stream`

2. **Frontend Click** (`main.js:317`)
   - User clicks AI Response button
   - Optional: Modal shows for extra instructions
   - Checks `data-enable-streaming` attribute
   - Calls either `generateAIResponse()` or `generateAIResponseStreaming()`

3. **AJAX Handler**
   - **Non-streaming** (`AIAjax.php:173`): Returns JSON response `{"ok":true,"text":"..."}`
   - **Streaming** (`AIAjax.php:19`): Sends SSE events with chunks
   - Loads configurable number of thread entries (default: 20) using QuerySet methods
   - Uses osTicket's native patterns: `clone`, `order_by('-created')`, `limit()` for efficiency
   - Builds messages array in this order:
     1. System prompt
     2. Extra instructions (as user message) - FIRST
     3. Ticket thread messages (with type labels: Customer Message, Agent Response, Internal Note)
     4. RAG content (last)
   - Calls AIClient with optional streaming callback

4. **API Client** (`AIClient.php:39`)
   - Auto-detect provider (OpenAI vs Anthropic)
   - Format request according to provider spec
   - If streaming callback provided: Enable `stream: true` and use cURL `WRITEFUNCTION`
   - Parse SSE chunks and call callback for each text fragment
   - If non-streaming: Return complete text

5. **Response Injection**
   - **Non-streaming** (`main.js:270`): AJAX response injected into textarea with spacing
   - **Streaming** (`main.js:154`): Each chunk appended incrementally for typewriter effect
   - Supports both plain text and Redactor rich text editor

### Provider Detection Logic (`AIClient.php:46`)

```php
if (stripos($baseUrl, 'anthropic.com') !== false ||
    stripos($model, 'claude') === 0 ||
    preg_match('#/v1/messages$#', $baseUrl)) {
    $provider = 'anthropic';
} else {
    $provider = 'openai';
}
```

### Message Formatting & Order

Messages are sent to the AI API in this specific order:

1. **System prompt** (role: system) - Base instructions from configuration
2. **Extra instructions** (role: system) - If provided via popup, meta-instruction for this specific response
3. **Thread messages** (role: user/assistant) - Ticket conversation (configurable number)
4. **RAG content** (role: system) - Extra knowledge base context, last

**Thread Entry Formatting:**
- **Customer Message** (type 'M', role: user)
  - With known name: `John Doe: message text`
  - Without known name: `message text` (role already indicates it's from customer)
- **Agent Response** (type 'R', role: assistant) - Format: `Agent Name: message text`
- **Internal Note** (type 'N', role: assistant) - Format: `[Internal Note - Name] message text`

Only internal notes have a special prefix to distinguish them from public agent responses.

**OpenAI Format Example:**
```json
{
  "model": "gpt-4",
  "messages": [
    {"role": "system", "content": "You are a helpful support agent..."},
    {"role": "system", "content": "Special instructions for this response: Offer refund"},
    {"role": "user", "content": "My order hasn't arrived"},
    {"role": "assistant", "content": "Support Team: Can you provide order number?"},
    {"role": "assistant", "content": "[Internal Note - Jane Smith] Customer is VIP, expedite"},
    {"role": "system", "content": "Additional knowledge base context:\n..."}
  ],
  "temperature": 1,
  "max_tokens": 512
}
```

**Anthropic Format:**
```json
{
  "model": "claude-3-opus-20240229",
  "system": "You are a helpful support agent...\n\nSpecial instructions for this response: Offer refund\n\nAdditional knowledge base context:\n...",
  "messages": [
    {"role": "user", "content": "My order hasn't arrived"},
    {"role": "assistant", "content": "Support Team: Can you provide order number?"},
    {"role": "assistant", "content": "[Internal Note - Jane Smith] Customer is VIP, expedite"}
  ],
  "temperature": 1,
  "max_tokens": 512
}
```
*Note: For Anthropic, system messages are combined into the 'system' parameter*

## Important Constants (`Constants.php`)

**Thread and Content:**
- `MAX_THREAD_ENTRIES`: 20 messages (default value, now configurable per instance)
- `MAX_RAG_CONTENT_LENGTH`: 20,000 chars

**API Configuration:**
- `DEFAULT_MAX_TOKENS`: 512
- `DEFAULT_TEMPERATURE`: 1
- `DEFAULT_TIMEOUT`: 60 seconds
- `DEFAULT_ANTHROPIC_VERSION`: '2023-06-01'
- `DEFAULT_MAX_TOKENS_PARAM`: 'max_tokens'

**Vision Support:**
- `DEFAULT_MAX_IMAGES`: 5 images per request
- `DEFAULT_MAX_IMAGE_SIZE_MB`: 5 MB per image
- `OPENAI_MAX_IMAGES`: 10 (provider limit)
- `ANTHROPIC_MAX_IMAGES`: 100 (provider limit)
- `SUPPORTED_IMAGE_TYPES`: ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp']

## Development Guidelines

### Local Development Setup

For easier plugin development, you can clone the osTicket codebase locally for reference:

**Setup:**
```bash
cd ai-response-generator
git clone --depth 1 https://github.com/osTicket/osTicket.git osticket
```

The `osticket/` directory is already in `.gitignore` and will NOT be committed to version control.

**Benefits:**
- Quick reference to osTicket core classes (`Ticket`, `Thread`, `ThreadEntry`, etc.)
- See available methods and properties without web lookup
- Use Grep/Glob to search osTicket codebase for examples
- Better understanding of osTicket's internal architecture

**Important:**
- The `osticket/` folder is for reference only during development
- It is NOT part of the plugin distribution
- Keep plugin code in `src/`, `api/`, `assets/` directories only
- Never modify files in `osticket/` directory

### Code Principles
1. **Simple and maintainable**: No over-engineering
2. **Compatible with osTicket 1.18 and later**: Backwards compatibility not required
3. **Error handling**: Clear error messages via toast notifications
4. **Deduplication**: Prevent duplicate button renders and AJAX calls
5. **Security**: Sanitize user input, escape output

### Testing Checklist
- [ ] Test with OpenAI API
- [ ] Test with Anthropic Claude API
- [ ] Test multi-instance setup (multiple providers simultaneously)
- [ ] Test instructions modal (enabled/disabled)
- [ ] Test response template with all placeholders
- [ ] Test RAG content injection
- [ ] Test error handling (invalid API key, timeout, etc.)
- [ ] Test rich text editor (Redactor) compatibility
- [ ] Test pjax navigation (osTicket's dynamic page loads)

### Making Changes

**IMPORTANT: Keeping plugin.php synchronized**
When making significant changes or adding features, always update `plugin.php` to reflect the current state:
- **Version number**: Increment version (e.g., 0.3.0 → 0.3.1 for patches, → 0.4.0 for new features)
- **Description**: Update feature list if new functionality was added
- **Author credits**: Maintain dual attribution: `'Mateusz Hajder (original), Ide Stoutjesdijk (enhanced fork)'`
- **URL**: Keep pointing to fork repository

**Adding a new configuration option:**
1. Add field in `Config.php:getFields()`
2. Use option in `AIAjax.php` or `AIClient.php`
3. Update this documentation
4. Update `plugin.php` description if it's a major feature

**Supporting a new AI provider:**
1. Add provider detection in `AIClient.php:46`
2. Implement provider-specific formatting
3. Test response parsing logic
4. Update documentation and `plugin.php` if applicable

**Frontend changes:**
1. Edit `assets/js/main.js` for behavior
2. Edit `assets/css/style.css` for styling
3. Test in different browsers

## Common Issues

### Button doesn't appear
- Check if plugin instance is enabled in Admin Panel
- Check if user has staff permissions
- Check browser console for JS errors

### API errors
- Verify API URL format (should end with `/chat/completions` or `/v1/messages`)
- Check API key validity
- Check timeout setting (increase for slow responses)
- Check model name (use correct identifier)

### Response not injected
- Check if Post Reply tab is active
- Check if Redactor editor is initialized
- Check browser console for JS errors

### Wrong ticket gets AI response (FIXED in v0.2.1)
**Issue**: When switching between tickets via pjax navigation, the AI response would be generated for the previous ticket instead of the current one.

**Root cause**: The ticket ID was cached in the button's data attributes from the initial page load. When navigating to a new ticket via pjax, the old ticket ID was still used.

**Fix**: The plugin now dynamically reads the ticket ID from the URL query parameter (`?id=123`) when the button is clicked, ensuring the correct ticket context is always used regardless of pjax navigation.

## Styling & Design

The plugin is fully styled to match osTicket's design language:

### Design Principles
- **osTicket colors**: Primary blue (#0e76a8), consistent grays and borders
- **Gradient buttons**: Linear gradients like osTicket's native buttons
- **Form styling**: Matching input fields, borders and focus states
- **Modal dialogs**: Header with gradient, gray footer, osTicket-style close button
- **FontAwesome icons**: Lightbulb icon (f0eb) in modal header
- **Smooth animations**: Slide-up modal, slide-in toast notifications

### UI Components
1. **Toolbar Button**: Gradient button with icon, hover state with blue border
2. **Dropdown Menu Item**: Flexbox layout with icon spacing
3. **Modal Dialog**:
   - Gradient header with FontAwesome icon
   - Textarea with osTicket focus state (blue border + shadow)
   - Tip box with border-left accent
   - Gradient buttons (cancel gray, generate blue)
4. **Toast Notifications**: Slide in from right, osTicket error/success colors
5. **Loading Spinner**: Blue spinner next to button during API call

### Keyboard Shortcuts
- **Ctrl+Enter** (or Cmd+Enter): Submit modal and generate response
- **Escape**: Close modal

### Version 0.1.3
- **IMPORTANT**: Extra instructions from popup are now sent FIRST as user message (no longer appended to system prompt)
- Thread message count now configurable via "Max Thread Entries" setting (default remains 20)
- Improved context priority: special instructions → thread history → RAG content
- Updated message formatting documentation with examples

### Version 0.1.2
- Complete UI redesign to match osTicket's design language
- Gradient buttons and headers like native osTicket elements
- Enhanced modal with tip box and keyboard shortcuts (Ctrl+Enter, Escape)
- Smoother animations (slide-up modal, slide-in toasts)
- FontAwesome icon in modal header

### Version 0.1.1
- Added modal for extra instructions before generation
- Enhanced configuration with toast notifications
- Anthropic Claude support
- Configurable parameters (max tokens, temperature, timeout)
- Auto-detection of provider type

## Recent Changes

### Version 0.3.0 (Vision Support + Message Format Improvements)
- **NEW**: Vision support for image attachments in ticket messages
- **NEW**: AI can analyze screenshots, photos, diagrams, and documents
- Support for OpenAI (GPT-4o, GPT-4-turbo) and Anthropic (Claude 3/4) vision models
- Configurable image limits, size limits, and inline image handling
- Automatic provider-specific format conversion (Anthropic → OpenAI transformation)
- Base64 encoding and MIME type filtering (JPEG, PNG, GIF, WebP)
- Cost warnings and conservative defaults (disabled by default, 5 images max, 5MB max)
- Works with both streaming and non-streaming response modes
- **IMPROVED**: Extra instructions now use 'system' role (semantically correct)
- **IMPROVED**: Simplified message format - only Internal Notes have special prefix
- **IMPROVED**: Cleaner thread messages - no "User:" prefix for anonymous customers (role already indicates user)
- **IMPROVED**: Customer names only shown when available, agent names always shown

### Version 0.2.3 (Performance & Context Improvements)
- **OPTIMIZED**: Thread entry loading now uses osTicket's QuerySet methods (clone, order_by, limit)
- **IMPROVED**: Messages now include type labels (Customer Message, Agent Response, Internal Note)
- **PERFORMANCE**: Significantly faster for tickets with many messages (database-level filtering vs loading all entries)
- Uses native osTicket patterns: `clone $thread->getEntries()` + `order_by('-created')` + `limit()`
- Better AI context with message type information helps generate more appropriate responses

### Version 0.2.2 (Bug Fix - Thread Message Order)
- **FIXED**: Thread messages now correctly sends the most recent X messages instead of the oldest X messages
- Changed from taking first N entries to taking last N entries using `array_slice($allEntries, -$max_thread_entries)`
- Ensures AI has the most relevant recent conversation context
- Applied fix to both streaming and non-streaming response generation

### Version 0.2.1 (Bug Fix - Pjax Navigation)
- **FIXED**: Wrong ticket context when switching between tickets
- Plugin now dynamically reads ticket ID from URL instead of cached button data
- Resolves issue where AI responses were generated for previous ticket after pjax navigation
- Improved reliability when navigating between tickets in osTicket

### Version 0.2.0 (Streaming Support)
- **NEW**: Real-time streaming responses with typewriter effect
- **NEW**: Configuration option to enable/disable streaming per instance
- Backend streaming via Server-Sent Events (SSE)
- Frontend streaming via Fetch API + ReadableStream
- Full support for OpenAI and Anthropic streaming APIs
- Incremental text updates to textarea and Redactor editor
- Error handling and graceful fallback for streaming failures

## Future Improvements

Possible extensions (only implement when needed):
- Response caching (prevent duplicate API calls)
- Custom prompt templates per department
- Token usage tracking and budgeting
- Response history/audit log