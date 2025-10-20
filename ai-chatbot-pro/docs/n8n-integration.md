# Integrating the AI Chatbot with n8n

This guide explains how to connect the AI Chatbot Pro plugin to an n8n automation flow using the built-in message webhook. The webhook forwards each user prompt (plus context) to n8n and expects a JSON response with the reply that should be displayed in the chat.

## Prerequisites

* A working installation of **AI Chatbot Pro** configured with at least one assistant template. An LLM API key is only required if you want to fall back to OpenAI inside WordPress.
* An n8n instance (self-hosted or cloud) with permissions to create and execute workflows.
* A publicly reachable URL for n8n webhooks. If you are running n8n locally, expose it through a tunneling service such as `n8n tunnel`, `ngrok`, or `cloudflared`.

## High-level architecture

1. The WordPress site forwards user prompts from the chatbot widget to n8n via an HTTP request.
2. n8n processes the text (e.g., enriches context, hits external APIs) and returns JSON with the answer that the chatbot must show.
3. The chatbot displays the response to the end user.

## Step-by-step setup

### 1. Create an n8n workflow

1. Add a **Webhook** trigger node configured with `POST` and a path such as `/chatbot/in`. Keep *Respond to Webhook Immediately* enabled so the reply reaches the user in the same request.
2. Add a **Function** or **Set** node to inspect the incoming JSON payload. The default AI Chatbot Pro payload contains:

   ```json
   {
     "conversation_id": "aicp_e0c4...",
     "assistant_id": 123,
     "message": "User prompt text",
     "system_prompt": "Compiled instructions used by the assistant",
     "history": [
       { "role": "system", "content": "..." },
       { "role": "user", "content": "Previous turn" },
       { "role": "assistant", "content": "Previous reply" }
     ],
     "site": {
       "url": "https://example.com",
       "name": "Example Site"
     },
     "meta": {
       "page_context": "Optional text extracted from the current page",
       "lead_data": {
         "email": null,
         "phone": null
       }
     }
   }
   ```

3. Connect any processing nodes you need (e.g., OpenAI, database lookups). When you are ready to send a reply, make sure you output a JSON object with a `reply` field containing the text response.

### 2. Configure AI Chatbot Pro to call n8n

1. In the WordPress admin, open **AI Chatbot Pro → Assistants** and edit the assistant you want to connect.
2. Go to the **Integraciones** tab and locate the *Webhook de Mensajes* section.
3. Tick **Reenviar cada mensaje a un webhook externo** and paste the n8n webhook URL (e.g., `https://your-n8n-host/webhook/chatbot/in`).
4. (Optional) Define a secret token. AI Chatbot Pro will send it in the `X-AICP-Webhook-Secret` header so you can validate requests in n8n.
5. Adjust the timeout if your workflow needs more than the default 15 seconds. The chatbot will show an error if the webhook does not answer within that limit.

### 3. Return the response from n8n

Finish the workflow with a node that returns JSON like the following:

```json
{
  "reply": "Processed answer that the chatbot should display",
  "metadata": {
    "confidence": 0.92,
    "sources": ["https://..."]
  }
}
```

Only the `reply` field is required. Any extra data under `metadata` is stored in the AJAX response so you can log or debug it in the browser console.

## Tips

* Use the **Test Webhook** feature in n8n while configuring the assistant to capture the exact payload AI Chatbot Pro sends. Adjust field mappings accordingly.
* Looking for Make (Integromat) instead? Follow the dedicated [Make webhook guide](./make-integration.md) for equivalent instructions.
* Protect your webhook URLs with secret tokens or IP whitelists. The **Cabecera secreta opcional** field in the assistant screen adds an `X-AICP-Webhook-Secret` header automatically.
* Log requests on both sides during development to troubleshoot payload mismatches. The browser console shows the `webhook_metadata` object when it is returned by n8n.
* If you leave the webhook disabled, the assistant will continue using the configured LLM provider inside WordPress.

## Troubleshooting

### Where to see webhook test results

When you click **Enviar mensaje de prueba** inside the assistant editor (tab **Integraciones → Webhook de Mensajes**), the plugin shows a notice right below the button with every diagnostic field it captured: HTTP status code, raw response body, headers, duration, and the JSON payload that WordPress sent. Use this panel to confirm whether the request actually left your site and what came back from n8n.

### Common issues

* **Timeouts** – Increase the request timeout in the assistant settings if the n8n workflow takes longer than expected.
* **Invalid JSON** – Ensure that any text you send back is properly JSON encoded. Avoid raw newline characters without escaping.
* **Authentication failures** – Verify any API keys or basic auth credentials configured on the webhook node, and confirm that the shared secret matches the value set in WordPress.

With this setup you can orchestrate complex automations in n8n while keeping the chatbot experience consistent on your WordPress site.
