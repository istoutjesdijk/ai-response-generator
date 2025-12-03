# AI Response Generator Plugin for osTicket (Enhanced Fork)

> **Note:** This is a fork of [mhajder/ai-response-generator](https://github.com/mhajder/ai-response-generator) with significant enhancements including vision support, streaming responses, performance optimizations, and improved AI context.

An AI-powered response generator plugin for osTicket that helps agents generate intelligent, context-aware replies using OpenAI, Anthropic Claude, or any OpenAI-compatible API.

## ✨ Enhanced Features (This Fork)

### 🎯 Core Features
- **Multi-AI Provider Support**: OpenAI (GPT-4, GPT-4o), Anthropic (Claude 3/4), and OpenAI-compatible APIs
- **Multi-Instance Support**: Configure multiple AI providers simultaneously for different teams/workflows
- **Smart Context Building**: Includes recent ticket conversation with message types (Customer, Agent, Internal Note)
- **Response Templates**: Customizable output format with placeholders (`{user_name}`, `{ticket_number}`, etc.)
- **RAG Support**: Add custom knowledge base content to enrich AI responses

### 🚀 New in This Fork

#### 👁️ Vision Support (v0.3.0)
- **AI Image Analysis**: Send image attachments to vision-capable models (GPT-4o, Claude 3+)
- Analyze screenshots, error messages, product photos, documents
- Configurable limits: max images, file size, inline/attachment filtering
- Automatic format conversion for OpenAI and Anthropic
- **Cost protection**: Disabled by default, conservative limits

#### ⚡ Streaming Responses (v0.2.0)
- Real-time typewriter effect for AI responses
- Works with both OpenAI and Anthropic APIs
- Server-Sent Events (SSE) implementation
- Configurable per instance (enable/disable)

#### 🔧 Performance Optimizations (v0.2.3)
- Efficient database queries using osTicket's QuerySet methods
- Only loads required thread entries (not all messages)
- Better message type labeling for improved AI context
- Fixed message ordering (newest first → most relevant context)

#### 🐛 Bug Fixes
- **v0.2.1**: Fixed pjax navigation ticket context bug
- **v0.2.2**: Fixed thread message order (was sending oldest instead of newest)

## 📋 Requirements

- **osTicket**: Version 1.18 or later
- **PHP**: Version 8.2 - 8.4 (8.4 recommended)
- **AI Provider**:
  - OpenAI API account (for GPT models)
  - Anthropic API account (for Claude models)
  - Or any OpenAI-compatible API endpoint

## 🚀 Installation

1. **Download/Clone** this repository
2. **Copy** the plugin folder to `include/plugins/` in your osTicket installation:
   ```bash
   cd /path/to/osticket/include/plugins/
   git clone https://github.com/istoutjesdijk/ai-response-generator.git
   ```
3. **Navigate** to osTicket Admin Panel → **Manage** → **Plugins**
4. Click **Add New Plugin** and select **AI Response Generator**
5. **Configure** the instance (see Configuration section below)

## ⚙️ Configuration

### Basic Setup

1. **API URL**: Your AI provider endpoint
   - OpenAI: `https://api.openai.com/v1/chat/completions`
   - Anthropic: `https://api.anthropic.com/v1/messages`
   - Custom: Your OpenAI-compatible endpoint

2. **API Key**: Your authentication key

3. **Model Name**: Model identifier
   - OpenAI: `gpt-4o`, `gpt-4-turbo`, `gpt-3.5-turbo`
   - Anthropic: `claude-3-opus-20240229`, `claude-3-sonnet-20240229`, `claude-3-haiku-20240307`

### Advanced Options

- **Max Tokens**: Response length limit (default: 512)
- **Temperature**: Creativity level 0.0-2.0 (default: 1.0)
- **Timeout**: API request timeout in seconds (default: 60)
- **Max Thread Entries**: Number of recent messages to include (default: 20)
- **System Prompt**: Custom instructions for AI behavior
- **Response Template**: Format output with placeholders
- **RAG Content**: Additional context/knowledge base (max 20,000 chars)
- **Show Instructions Popup**: Allow agents to add special instructions per response
- **Enable Streaming**: Real-time typewriter effect (default: disabled)

### Vision Support Configuration

⚠️ **Warning**: Vision increases API costs significantly!

- **Enable Vision Support**: Master switch (default: disabled)
- **Max Images**: Limit per request (default: 5, max: 10 for OpenAI, 100 for Anthropic)
- **Max Image Size**: Size limit in MB (default: 5 MB)
- **Include Inline Images**: Include embedded images like signatures (default: disabled)

**Supported formats**: JPEG, PNG, GIF, WebP

## 📖 Usage

### For Agents

1. **Open a ticket** in the agent panel
2. **Click** the "AI Response" button (in toolbar or "More" dropdown)
3. **(Optional)** Add special instructions in the popup (e.g., "Offer refund")
4. **Wait** for the AI to generate a response
5. **Review** and edit the generated response as needed
6. **Send** the reply to the customer

### With Vision Support

When vision is enabled and a customer includes screenshots or images:
- The AI automatically analyzes the images
- You'll get context-aware responses based on visual content
- Useful for error screenshots, product photos, diagrams, etc.

### Multiple Instances

Configure multiple plugin instances for:
- Different AI providers (GPT-4 for complex tickets, Claude for cost savings)
- Different departments (Sales uses Claude, Support uses GPT-4o)
- A/B testing different models or prompts

## 🔒 Security

- Only staff with **reply permission** can use AI response generation
- API keys stored securely using osTicket's `PasswordField`
- All requests go through backend (no client-side API calls)
- Vision support processes images server-side only

## 🐛 Troubleshooting

### Button doesn't appear
- Check plugin instance is enabled in Admin Panel
- Verify user has staff reply permissions
- Check browser console for JavaScript errors

### API errors
- Verify API URL format (must include full endpoint path)
- Check API key is valid and has sufficient credits
- Increase timeout for slow API responses
- Ensure model name matches provider format

### Vision not working
- Confirm vision support is enabled in config
- Use vision-capable model (GPT-4o, Claude 3+)
- Check image format (JPEG, PNG, GIF, WebP only)
- Verify image size is under configured limit

## 🆚 Differences from Original

| Feature | Original | This Fork |
|---------|----------|-----------|
| Vision Support | ❌ | ✅ GPT-4o & Claude 3+ |
| Streaming Responses | ❌ | ✅ Real-time SSE |
| Message Type Labels | ❌ | ✅ Customer/Agent/Note |
| Performance Optimized | ❌ | ✅ QuerySet methods |
| Bug Fixes | - | ✅ Pjax, message order |
| Anthropic Support | ❌ | ✅ Full Claude support |
| RAG Content | Basic | ✅ Enhanced (20K chars) |

## 🙏 Credits

- **Original Plugin**: [Mateusz Hajder](https://github.com/mhajder) - [mhajder/ai-response-generator](https://github.com/mhajder/ai-response-generator)
- **Enhanced Fork**: Additional features and improvements by the osTicket community

## 📄 License

MIT License - See [LICENSE](LICENSE) file for details

This fork maintains the original MIT license while adding significant enhancements. All original copyright notices are preserved.

## 🤝 Contributing

Contributions are welcome! Please:
1. Fork this repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## 📧 Support

- **Issues**: [GitHub Issues](https://github.com/istoutjesdijk/ai-response-generator/issues)
- **Original Plugin**: [mhajder/ai-response-generator](https://github.com/mhajder/ai-response-generator)
- **osTicket**: [osTicket Documentation](https://docs.osticket.com/)

---

**⚠️ Cost Warning**: Using AI APIs, especially with vision support, incurs costs. Monitor your usage and set appropriate limits. This plugin is provided as-is with no warranty.
