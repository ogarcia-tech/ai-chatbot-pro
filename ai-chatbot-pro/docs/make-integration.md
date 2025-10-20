# Integrating the AI Chatbot with Make (Integromat)

The AI Chatbot Pro plugin can forward every conversation turn to any HTTPS endpoint, including Make (formerly Integromat) custom webhooks. This guide explains how to capture the payload, secure the requests, and send a response back to the chatbot.

## Prerequisites

* AI Chatbot Pro installed on your WordPress site with at least one assistant configured.
* A Make account with permission to create and edit scenarios.
* A publicly reachable WordPress site so Make can contact it when returning the webhook response.

## Step-by-step setup

### 1. Create a custom webhook in Make

1. In the Make dashboard click **Create a new scenario**.
2. Search for the **Webhooks** app and choose the **Custom webhook** trigger.
3. Click **Add** to create a new webhook, give it a recognizable name, and copy the generated URL. Keep the *Determine data structure* dialog open—you will send a sample payload from WordPress in the next step so Make learns the schema automatically.

### 2. Configure AI Chatbot Pro to call the webhook

1. In WordPress go to **AI Chatbot Pro → Assistants** and edit the assistant you want to connect.
2. Open the **Integraciones → Webhook de Mensajes** section.
3. Enable **Reenviar cada mensaje a un webhook externo** and paste the webhook URL you copied from Make.
4. (Optional) Enter a secret token in **Cabecera secreta opcional**. The plugin will include it in the `X-AICP-Webhook-Secret` header. You can validate it in Make using a filter or a script module.
5. Adjust the timeout if your scenario performs long-running operations. The chatbot reports an error if Make takes longer than the configured limit to answer.
6. Click **Enviar mensaje de prueba** so Make receives a request while the *Determine data structure* dialog is open. Once Make captures the payload, click **OK** to store the structure.

The JSON body looks like this:

```json
{
  "conversation_id": "aicp_123...",
  "assistant_id": 456,
  "message": "Texto del usuario",
  "system_prompt": "Instrucciones completas para el asistente",
  "history": [
    { "role": "system", "content": "…" },
    { "role": "user", "content": "Turno anterior" },
    { "role": "assistant", "content": "Respuesta anterior" }
  ],
  "site": {
    "url": "https://tu-sitio.com",
    "name": "Tu sitio"
  },
  "meta": {
    "page_context": null,
    "lead_data": {
      "email": null,
      "phone": null
    }
  }
}
```

### 3. Build the scenario logic

1. Add the modules you need after the webhook trigger (e.g., OpenAI, HTTP, Datastore) to transform the user message or fetch external data.
2. When you are ready to reply, insert a **Webhooks → Respond to a webhook** module (or an **HTTP → Make a request** module pointing to the `respond` URL supplied by Make). Set it to return `application/json` with a body similar to:

   ```json
   {
     "reply": "Texto de respuesta para el chatbot",
     "metadata": {
       "confidence": 0.92,
       "links": ["https://ejemplo.com/fuente"]
     }
   }
   ```

   *Only `reply` is required.* Any additional fields placed under `metadata` appear in the webhook diagnostics inside WordPress and are also dispatched to the frontend as `webhook_metadata` for custom JavaScript handlers.

3. Save the scenario and switch it to **ON**. Make will now handle live traffic from the chatbot.

## Troubleshooting tips

* Use the **Enviar mensaje de prueba** button in the assistant editor to see the HTTP status code, response body, headers, duration, and request URL. This mirrors what the chatbot will send during real conversations.
* If Make never receives the request, confirm that your WordPress host allows outbound HTTPS calls to `hook.eu1.make.com` (or the domain shown in the webhook URL). The plugin surfaces `WP_HTTP_BLOCK_EXTERNAL` errors with a hint when external requests are blocked.
* To enforce the shared secret, insert a **Flow Control → Router** with a filter that checks `{{headers["x-aicp-webhook-secret"]}}` before executing the rest of the scenario.
* Make scenarios time out after 40 seconds by default. Keep the chatbot timeout lower to avoid WordPress waiting indefinitely.

With this configuration, the AI Chatbot Pro webhook works seamlessly with Make, enabling you to orchestrate complex automations without writing custom WordPress code.
