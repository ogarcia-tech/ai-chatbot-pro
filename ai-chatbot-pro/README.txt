=== AI Chatbot Pro ===
Plugin URI: https://metricaweb.es/
Description: Crea y gestiona asistentes de chat personalizables con la API de OpenAI mediante shortcodes.
Version: 5.1.0
Author: Óscar García / CEO Metricaweb
Author URI: https://metricaweb.es/
Co-developed by: Su Asistente de IA de Confianza 😉
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: ai-chatbot-pro
Domain Path: /languages
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Network: false
Stable tag: 5.1.0

== Description ==
AI Chatbot Pro te permite crear asistentes de chat personalizables usando la API de OpenAI. Utiliza shortcodes para colocar el chatbot en cualquier página o entrada.

== Installation ==
1. Sube la carpeta `ai-chatbot-pro` al directorio `/wp-content/plugins/`.
2. Activa el plugin desde el menú "Plugins" en WordPress.
3. Configura tu clave de OpenAI en los ajustes del plugin y usa el shortcode correspondiente donde quieras mostrar el chatbot.

== Frequently Asked Questions ==
= ¿Necesito una clave de API de OpenAI? =
Sí, debes introducir tu clave de API de OpenAI para que el chatbot pueda comunicarse con la plataforma.

== JavaScript hooks ==
Se expone la función global `aicpLeadMissing` para que los temas o plugins puedan reaccionar cuando el bot detecta un lead incompleto.

Ejemplo básico:

```
<script>
window.aicpLeadMissing = function(info) {
    // info.missingFields contiene los campos faltantes
    // Aquí podrías mostrar tu propio formulario
    console.log('Faltan datos:', info.missingFields);
};
</script>
```

== Changelog ==
= 5.1.0 =
* Versión inicial del plugin.
