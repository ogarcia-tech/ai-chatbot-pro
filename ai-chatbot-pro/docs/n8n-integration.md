# Integrating the AI Chatbot with n8n

This guide explains how to connect the AI Chatbot Pro plugin to an n8n automation flow using plain text inputs and outputs. You can reuse this approach to exchange structured payloads as well, but the focus here is simple text.

## Prerequisites

* A working installation of **AI Chatbot Pro** configured with at least one assistant template and an API key for your LLM provider.
* An n8n instance (self-hosted or cloud) with permissions to create and execute workflows.
* A publicly reachable URL for n8n webhooks. If you are running n8n locally, expose it through a tunneling service such as `n8n tunnel`, `ngrok`, or `cloudflared`.

## High-level architecture

1. The WordPress site forwards user prompts from the chatbot widget to n8n via an HTTP Request node.
2. n8n processes the text (e.g., enriches context, hits external APIs) and calls back to the chatbot endpoint with the response text.
3. The chatbot displays the response to the end user.

The communication can be synchronous (waiting for an immediate reply) or asynchronous (n8n responds later via a follow-up request or webhook trigger).

## Step-by-step setup

### 1. Create an n8n workflow

1. Add a **Webhook** trigger node configured with `POST` and a path such as `/chatbot/in`. Enable *Respond to Webhook Immediately* if you want to return a response directly from the workflow execution.
2. Add a **Function** or **Set** node to parse the incoming JSON payload. The default AI Chatbot Pro message payload structure is:

   ```json
   {
     "conversation_id": "string",
     "message": "User prompt text",
     "session": { /* optional session metadata */ }
   }
   ```

3. Connect any processing nodes you need (e.g., OpenAI, database lookups). When you are ready to send a reply, make sure you output a JSON object with a `reply` field containing the text response.
4. If you prefer asynchronous handling, configure the workflow to return immediately (e.g., `{ "status": "accepted" }`) and later trigger a separate webhook to push the reply back to WordPress.

### 2. Configure AI Chatbot Pro to call n8n

1. In the WordPress admin, open **AI Chatbot Pro → Assistants** and edit the assistant you want to connect.
2. In the **Post-processing** or **External API** section (depending on your version), enable the option to forward messages to an external endpoint.
3. Enter the n8n webhook URL (e.g., `https://your-n8n-host/webhook/chatbot/in`).
4. Map the request payload to match the structure n8n expects. At minimum send the user message and an identifier for the conversation/session.
5. Define how the plugin should interpret the n8n response. For synchronous replies, map the `reply` field to the assistant answer. For asynchronous flows, enable the webhook listener and supply the return endpoint URL found under **AI Chatbot Pro → Settings → Webhooks**.

### 3. Handle asynchronous callbacks (optional)

If you opted for asynchronous processing, n8n will need to call the chatbot webhook when it has a final answer:

1. Add another **HTTP Request** node in n8n pointing to the AI Chatbot Pro webhook URL (e.g., `https://your-site.com/wp-json/ai-chatbot-pro/v1/webhook`).
2. Send a JSON body like:

   ```json
   {
     "conversation_id": "same as original",
     "reply": "Final answer text"
   }
   ```

3. The plugin matches the `conversation_id` and displays the reply to the user.

## Tips

* Use the **Test Webhook** feature in n8n while configuring the assistant to capture the exact payload AI Chatbot Pro sends. Adjust field mappings accordingly.
* Protect your webhook URLs with secret tokens or IP whitelists. You can include a shared secret in the request headers from WordPress and validate it within n8n.
* Log requests on both sides during development to troubleshoot payload mismatches.

## Troubleshooting

* **Timeouts** – Increase the request timeout in AI Chatbot Pro or switch to asynchronous mode if the n8n workflow takes longer than a few seconds.
* **Invalid JSON** – Ensure that any text you send back is properly JSON encoded. Avoid raw newline characters without escaping.
* **Authentication failures** – Verify any API keys or basic auth credentials configured on the webhook node.

With this setup you can orchestrate complex automations in n8n while keeping the chatbot experience consistent on your WordPress site.
