# AI Response Generator Plugin for osTicket

This plugin adds an AI-powered "Generate Response" button to the agent ticket view in osTicket. It allows agents to generate suggested replies using an OpenAI-compatible API and optionally enriches responses with custom context (RAG content).

## Features

- Adds a "Generate AI Response" button to each ticket for agents
- **Supports multiple plugin instances:** You can add and configure multiple instances of the plugin, each with its own API URL, key, model, and settings. This allows you to use different AI providers or configurations for different teams or workflows.
- Configurable API URL, API key, and model
- Optional system prompt and response template
- Supports pasting additional context (RAG content) to enrich AI responses
- Secure API key storage (with PasswordField)

## Requirements

- osTicket (latest stable version recommended)
- Access to an OpenAI-compatible API (e.g., OpenAI, OpenRouter, or local Ollama server)

## Installation

1. Copy the plugin folder to your osTicket `include/plugins/` directory.
2. In the osTicket admin panel, go to **Manage → Plugins**.
3. Click **Add New Plugin** and select **AI Response Generator**.
4. Configure the plugin:
    - Set the API URL (e.g., `https://api.openai.com/v1/chat/completions`)
    - Enter your API key
    - Specify the model (e.g., `gpt-5-nano-2025-08-07`)
    - (Optional) Add a system prompt or response template
    - (Optional) Paste RAG content to provide extra context for AI replies
5. Save changes.

## Usage

- In the agent panel, open any ticket.
- Click the **Generate AI Response** button in the ticket actions menu.
- The plugin will call the configured API and insert the suggested reply into the response box.

## Configuration Options

- **API URL**: The endpoint for your OpenAI-compatible API.
- **API Key**: The key used for authentication (stored securely).
- **Model Name**: The model to use (e.g., `gpt-5-nano-2025-08-07`).
- **AI System Prompt**: (Optional) Custom instructions for the AI.
- **Response Template**: (Optional) Template for formatting the AI response.
- **RAG Content**: (Optional) Paste additional context to enrich AI responses.

## Security

- Only staff with reply permission can use the AI response feature.

## License

MIT License
