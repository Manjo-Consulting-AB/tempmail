<?php
/**
 * Las preguntas frecuentes en español (España): la respuesta sobre asistentes de IA y
 * toda la página como datos (grupos, preguntas, respuestas). Lo carga faq.php para la
 * página en español. Refleja la estructura de faq.en.php: las mismas claves de grupo,
 * el mismo número de preguntas por grupo y las mismas ramas condicionales (periodo de
 * prueba activado/desactivado, texto con inicio de sesión activado/desactivado); solo
 * cambian las palabras.
 *
 * Variables que faq.php define para este archivo:
 *   $msU            closure: ruta de una página pública -> su URL escapada y con idioma
 *   $msT            duración del periodo de prueba en días (0 = sin periodo de prueba)
 *   $msDomain       el dominio de correo, ya escapado para HTML
 *   $msMcpUrl       la URL del endpoint MCP, ya escapada para HTML
 *   $msMcpSignIn    si un cliente puede conectarse iniciando sesión (OAuth activado)
 *   $msHoldDays     duración de la pausa de borrado en días
 *   $msHoldMax      pausas de borrado por año
 *
 * 'a' contiene el HTML interno de una respuesta: marcado escrito aquí, nunca nada que
 * aporte un visitante. Los ids de grupo (las claves del array) siguen en inglés en todos
 * los idiomas para que los #anclajes sigan funcionando.
 *
 * @return array<string, array{title: string, items: list<array{q: string, a: string}>}>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// La respuesta sobre asistentes de IA, en la forma que corresponda al interruptor
// (#331, paso 6). Ambas son solo para Pro, ambas se limitan a la lista de herramientas
// del brief §9 y ninguna menciona un precio ni una fecha; la de inicio de sesión solo se
// muestra mientras OAuth esté realmente activado.
$msAiSignInAnswer = <<<HTML
<p>Sí, con Pro. Añade <code>{$msMcpUrl}</code> en tu aplicación e inicia sesión cuando te lo pida: Mail Shield te muestra lo que quiere la aplicación y tú la apruebas. Un cliente que solo acepta una cabecera usa en su lugar uno de tus tokens de acceso. En ambos casos el asistente se conecta mediante el Model Context Protocol (MCP) y, a partir de ahí, puede listar tus direcciones, leer tu correo y, si le diste acceso de escritura, crear y eliminar direcciones por ti.</p>
<p>El asistente actúa como tu cuenta, así que trata lo que le des como una contraseña. Todo lo que lea se envía a quien lo gestione: ese proveedor lo eliges tú, no nosotros, y no somos su encargado del tratamiento. Desconecta una aplicación, o revoca un token, en la tarjeta Aplicaciones conectadas de tu perfil y deja de funcionar de inmediato; al cambiar tu contraseña o tu dirección de correo se cortan todas las conexiones a la vez.</p>
HTML;
$msAiTokenAnswer = <<<HTML
<p>Sí, con Pro. Creas un token de acceso en tu perfil y se lo das al asistente, que se conecta a Mail Shield mediante el Model Context Protocol (MCP) en <code>{$msMcpUrl}</code>. A partir de ahí puede listar tus direcciones, leer tu correo y, si diste al token acceso de escritura, crear y eliminar direcciones por ti.</p>
<p>El asistente actúa como tu cuenta, así que trata su token de acceso como una contraseña. Todo lo que lea se envía a quien lo gestione: ese proveedor lo eliges tú, no nosotros, y no somos su encargado del tratamiento. Revoca un token desde la tarjeta Aplicaciones conectadas de tu perfil y deja de funcionar de inmediato; al cambiar tu contraseña o tu dirección de correo se revocan todos los tokens a la vez.</p>
HTML;
$msAiAnswer = $msMcpSignIn ? $msAiSignInAnswer : $msAiTokenAnswer;

/**
 * Toda la página como datos: seis grupos, en el orden de la página, cada uno con sus preguntas.
 *
 * Tanto el texto visible como los datos estructurados FAQPage se generan a partir de este
 * único array, de modo que las respuestas que lee un rastreador no pueden diferir de las
 * que lee una persona.
 *
 * 'a' contiene el HTML interno de la respuesta (lo que va dentro de .ms-prose), no texto
 * escapado: es marcado que escribimos aquí, nunca nada que aporte un visitante.
 */
return [
    'general' => [
        'title' => 'General',
        'items' => [
            [
                'q' => '¿Qué es Mail Shield?',
                'a' => <<<HTML
<p>Una bandeja de entrada aparte para el correo que no quieres en la principal. Compras, boletines, registros, respuestas de foros, correo automático y los servicios que solo estás probando tienen otro sitio adonde ir, y los lees todos en un mismo lugar en la web.</p>
<p>Tienes direcciones propias: direcciones Timed (direcciones temporales que se eliminan solas) y direcciones Sticky (direcciones permanentes que no caducan) que conservas. No es una página que muestra una dirección desechable y se olvida de ti en cuanto cierras la pestaña: aquí una dirección pertenece a una cuenta, así que una confirmación que llegue una hora más tarde sigue esperándote.</p>
HTML,
            ],
            [
                'q' => '¿Cómo consigo una dirección Timed?',
                'a' => <<<HTML
<p>Crea una cuenta con tu propia dirección de correo, verifícala y crea la dirección Timed desde tu bandeja de entrada. No hay ningún botón en la página de inicio que la genere de forma anónima: una dirección necesita una cuenta detrás, y eso es lo que te permite volver a por un mensaje que llegue más tarde.</p>
HTML,
            ],
            [
                'q' => '¿Cuánto tiempo se conservan los mensajes y las direcciones?',
                'a' => <<<HTML
<p>Una dirección Timed de una cuenta gratuita se elimina automáticamente <strong>24 horas</strong> después de crearla, y los mensajes que contiene se van con ella. Con Pro eliges la duración de cada dirección Timed, de <strong>1 a 7 días</strong>.</p>
<p>Las direcciones Sticky no funcionan con un temporizador: se quedan hasta que las eliminas.</p>
HTML,
            ],
            [
                'q' => '¿Cuesta algo?',
                'a' => $msT > 0 ? <<<HTML
<p>Una cuenta gratuita no cuesta nada y no necesita tarjeta, y cada cuenta nueva empieza con <strong>{$msT} días de Pro</strong> sin coste. Después, puedes seguir con Pro con un <a href="{$msU('/pricing.php')}">plan mensual, anual o de pago único</a>, o con un código de canje.</p>
HTML : <<<HTML
<p>Una cuenta gratuita no cuesta nada y no necesita tarjeta. Pro es un <a href="{$msU('/pricing.php')}">plan mensual, anual o de pago único</a>, o un código de canje.</p>
HTML,
            ],
        ],
    ],
    'accounts' => [
        'title' => 'Cuentas y planes',
        'items' => [
            [
                'q' => '¿Cuál es la diferencia entre Gratis y Pro?',
                'a' => <<<HTML
<p>Una cuenta gratuita cubre el correo temporal: una dirección Timed a la vez, que se elimina 24 horas después de crearla.</p>
<p>Pro añade la parte permanente del producto y la automatización: hasta 10 direcciones Sticky, una duración de las direcciones Timed de 1 a 7 días que eliges tú, RSS, webhooks, Pushover, correos de resumen y el Agent.</p>
HTML . ($msT > 0 ? <<<HTML
<p>Toda cuenta nueva tiene Pro durante sus primeros {$msT} días, para que puedas probarlo todo antes de decidir.</p>
HTML : ''),
            ],
            ...($msT > 0 ? [[
                'q' => "¿Cómo funciona el periodo de prueba de Pro de {$msT} días?",
                'a' => <<<HTML
<p>Toda cuenta nueva tiene Pro durante sus primeros {$msT} días, a partir del momento en que confirmas tu dirección de correo. No tienes que introducir ninguna tarjeta ni cancelar nada.</p>
<p>El periodo de prueba es una vez por dirección de correo. Si eliminas tu cuenta y te registras de nuevo con la misma dirección, el reloj sigue corriendo desde tu primer registro: no empieza de cero.</p>
<p>Cuando termina el periodo de prueba, tu cuenta continúa como una cuenta gratuita. La automatización se desactiva y las direcciones Sticky, con el correo que contienen, se eliminan 7 días después, así que traslada antes lo que aún necesites. Para seguir con Pro, elige uno de nuestros <a href="{$msU('/pricing.php')}">planes</a> o canjea un código de canje en tu perfil.</p>
HTML,
            ]] : []),
            [
                'q' => '¿Cuántas direcciones puedo crear?',
                'a' => <<<HTML
<p>Las direcciones pertenecen a tu cuenta, no a una sesión del navegador. Cada cuenta tiene una dirección Timed a la vez, y al crear una nueva se sustituye la anterior.</p>
<p>Las cuentas Pro tienen además hasta 10 direcciones Sticky, que conviven con la Timed y entregan en la misma bandeja de entrada.</p>
HTML,
            ],
            [
                'q' => '¿Cómo creo una cuenta Pro?',
                'a' => $msT > 0 ? <<<HTML
<p>Crea una cuenta: empieza con Pro durante sus primeros {$msT} días. Para mantener Pro después, elige un <a href="{$msU('/pricing.php')}">plan</a> con la sesión iniciada o canjea un código de canje en tu perfil; en ambos casos, el tiempo se añade cuando termina el periodo de prueba. Las cuentas inician sesión con un enlace mágico enviado a su correo y pueden, si quieren, establecer una contraseña en su perfil.</p>
HTML : <<<HTML
<p>Crea una cuenta gratuita y elige un <a href="{$msU('/pricing.php')}">plan</a> con la sesión iniciada, o regístrate con un código de canje: elige Pro en la página de registro e introduce el código. Las cuentas inician sesión con un enlace mágico enviado a su correo y pueden, si quieren, establecer una contraseña en su perfil.</p>
HTML,
            ],
            [
                'q' => '¿Qué ajustes hay disponibles con Pro?',
                'a' => <<<HTML
<ul>
<li>Establecer una contraseña para iniciar sesión, además del enlace por correo.</li>
<li>Elegir cuánto duran las nuevas direcciones Timed, de 1 a 7 días.</li>
<li>Crear, revisar y eliminar hasta 10 direcciones Sticky.</li>
<li>Activar o desactivar la autenticación en dos pasos y gestionar los navegadores de confianza.</li>
<li>Conservar el correo durante {$msHoldDays} días, hasta {$msHoldMax} veces al año.</li>
<li>Configurar la automatización: RSS, webhooks y correos de resumen.</li>
</ul>
HTML,
            ],
            [
                'q' => '¿Puedo conservar mi correo durante más tiempo?',
                'a' => <<<HTML
<p>Sí, con Pro. Hasta {$msHoldMax} veces al año puedes pausar el borrado: el correo de tus direcciones Sticky que de otro modo se eliminaría se conserva durante {$msHoldDays} días desde el día en que llegó, en lugar de los habituales 1 a 7. Inicia una pausa de borrado eligiendo {$msHoldDays} días en <strong>Perfil &rarr; Duración predeterminada</strong>.</p>
<p>Si terminas una pausa antes de tiempo, el correo que ya se había ampliado se queda como está. Las direcciones Timed no se ven afectadas: siguen caducando según lo previsto y se llevan sus mensajes con ellas. Se sigue aplicando el límite de almacenamiento de tu buzón y, cuando se alcanza, se elimina primero el correo más antiguo.</p>
HTML,
            ],
        ],
    ],
    'temporary' => [
        'title' => 'Direcciones Timed y Sticky',
        'items' => [
            [
                'q' => '¿Cuál es la diferencia entre una dirección Timed y una Sticky?',
                'a' => <<<HTML
<p>Una dirección Timed es la versión de Mail Shield del correo temporal: está pensada para tirarla. Caduca con un temporizador en el que no tienes que pensar (24 horas en una cuenta gratuita, de 1 a 7 días con Pro) y se lleva sus mensajes con ella.</p>
<p>Una dirección Sticky es una que conservas: un nombre legible como <span class="ms-addr">compras@{$msDomain}</span> en lugar de una cadena aleatoria, sin caducidad, y eres tú quien decide cuándo desaparece. Los dos tipos llegan a la misma bandeja de entrada.</p>
HTML,
            ],
            [
                'q' => '¿Puedo usar Mail Shield para direcciones permanentes?',
                'a' => <<<HTML
<p>Sí, para eso sirven las direcciones Sticky. Una cuenta Pro puede tener hasta 10, todas permanentes en lo que importa: no caducan y las eliminas cuando has terminado con ellas.</p>
<p>Todas las direcciones usan el dominio {$msDomain} y todas entregan en la misma bandeja de entrada.</p>
HTML,
            ],
            [
                'q' => '¿Cuánto duran las direcciones Sticky?',
                'a' => <<<HTML
<p>Hasta que las eliminas. No tienen temporizador de caducidad ni les afecta la limpieza que elimina las direcciones Timed. Al eliminar una desde tu perfil se borra la dirección y deja de recibir correo desde ese momento.</p>
HTML,
            ],
        ],
    ],
    'automation' => [
        'title' => 'Automatización',
        'items' => [
            [
                'q' => '¿Con qué puedo conectarlo?',
                'a' => <<<HTML
<p>Las cuentas Pro pueden reenviar el correo de varias maneras:</p>
<ul>
<li>Un feed RSS privado, que se lee en cualquier lector de feeds y está protegido por un token: para toda tu cuenta o para una sola dirección Sticky.</li>
<li>Webhooks que envían cada mensaje entrante como JSON, mediante POST, a un endpoint que controlas.</li>
<li>Pushover, para recibir el correo entrante como notificación en tu móvil.</li>
<li>Correos de resumen (digest), un resumen periódico de lo que ha llegado.</li>
</ul>
<p>Si gestionas tu propio servidor de correo, también tienes el Agent: gestionas las listas de remitentes y las reglas de filtrado en Mail Shield, y el Agent las aplica a un buzón de tu servidor. Ese correo se queda en tu servidor: no llega a Mail Shield.</p>
<p>También puedes conectar un asistente de IA a tu cuenta para que lea y gestione tus direcciones por ti. Eso tiene su propia pregunta más abajo.</p>
HTML,
            ],
            [
                'q' => '¿Puedo conectar un asistente de IA a mi cuenta?',
                'a' => $msAiAnswer,
            ],
            [
                'q' => '¿La automatización está incluida en una cuenta gratuita?',
                'a' => $msT > 0 ? <<<HTML
<p>Durante los primeros {$msT} días, sí: toda cuenta nueva empieza con Pro, automatización incluida. Después del periodo de prueba, RSS, webhooks, Pushover, los resúmenes y el Agent forman parte de Pro, y una cuenta gratuita cubre el correo temporal.</p>
HTML : <<<HTML
<p>No. RSS, webhooks, Pushover, los resúmenes y el Agent forman parte de Pro. Una cuenta gratuita cubre el correo temporal.</p>
HTML,
            ],
        ],
    ],
    'security' => [
        'title' => 'Seguridad y privacidad',
        'items' => [
            [
                'q' => '¿Es privado el contenido de mi correo?',
                'a' => <<<HTML
<p>La respuesta depende del tipo de dirección, así que conviene ser exactos. A una dirección Timed se accede mediante su enlace: quien tenga ese enlace puede leer la bandeja de entrada, y también cualquiera que lo encuentre. Trata una dirección Timed como un sitio donde recibir, no donde guardar un secreto.</p>
<p>Las direcciones Sticky son distintas. Pertenecen a tu cuenta y solo se pueden leer mientras tienes la sesión iniciada como propietario.</p>
<p>En cualquier caso, no envíes datos personales sensibles (banca, documentos de identidad, datos médicos) a una dirección que piensas tirar.</p>
HTML,
            ],
            [
                'q' => '¿Cómo se gestionan los archivos adjuntos?',
                'a' => <<<HTML
<p>Los archivos adjuntos se almacenan temporalmente, y los enlaces de descarga están firmados y tienen una validez limitada, de modo que no se puede adivinar un enlace ni pasarlo de mano en mano indefinidamente. Los archivos se limpian junto con los mensajes en los que llegaron.</p>
HTML,
            ],
            [
                'q' => '¿Qué protege la autenticación en dos pasos (2FA)?',
                'a' => <<<HTML
<p>La 2FA protege el inicio de sesión con contraseña en tu cuenta: una vez activada, iniciar sesión con tu contraseña requiere además un código de 6 dígitos de una aplicación de autenticación. No añade ningún paso a los enlaces de inicio de sesión por correo: un enlace mágico ya demuestra que tienes acceso a la bandeja de entrada, lo que en sí mismo es un segundo factor.</p>
HTML,
            ],
            [
                'q' => '¿Qué aplicaciones de autenticación son compatibles?',
                'a' => <<<HTML
<p>Funciona cualquier aplicación de autenticación TOTP estándar, incluidas 1Password, Authy y Google Authenticator. Configúrala desde la sección de autenticación en dos pasos de tu perfil, escaneando el código QR o introduciendo la clave manualmente.</p>
HTML,
            ],
            [
                'q' => '¿Qué pasa si pierdo el móvil o la aplicación de autenticación?',
                'a' => <<<HTML
<p>No te quedas fuera. Inicia sesión con tu enlace de acceso por correo (enlace mágico) como de costumbre, que funciona incluso con la 2FA activada, y desactiva después la autenticación en dos pasos desde tu perfil. Desde ahí puedes volver a configurar la 2FA en un dispositivo nuevo o dejarla desactivada. También puedes usar uno de los códigos de recuperación de un solo uso que se mostraron al activarla, si los guardaste.</p>
HTML,
            ],
            [
                'q' => '¿Es seguro el Agent?',
                'a' => <<<HTML
<p>El Agent se ejecuta en tu propio servidor, así que tus datos siguen bajo tu control. Verifica los webhooks firmados con RSA de Mail Shield antes de actuar sobre ellos y guarda cifrados, en lugar de en texto plano, los secretos que necesita. El filtrado se hace en tu máquina, así que tu correo no tiene que salir de ella para clasificarse.</p>
HTML,
            ],
        ],
    ],
    'troubleshooting' => [
        'title' => 'Solución de problemas',
        'items' => [
            [
                'q' => 'No he recibido un mensaje que esperaba: ¿qué hago?',
                'a' => <<<HTML
<p>Comprueba la dirección completa que facilitaste, tanto la parte local como el dominio. Si es una dirección Sticky, asegúrate de haber iniciado sesión en la cuenta que la posee. Después espera un momento y actualiza: el correo se almacena en cuanto el servidor del remitente lo entrega, y la bandeja de entrada lo recoge en su siguiente comprobación o cuando actualizas, aunque un remitente puede retrasar o reintentar la entrega. Si aun así no llega, puede que el remitente lo haya retrasado o rechazado.</p>
HTML,
            ],
            [
                'q' => '¿Cómo obtengo soporte?',
                'a' => <<<HTML
<p>Lo mejor es resolver los problemas de cuenta desde dentro de tu cuenta: <a href="/pro_login.php">inicia sesión</a>, usa la página de contacto e incluye la dirección o el mensaje sobre el que preguntas. Estas preguntas frecuentes están pensadas para responder primero a los casos generales.</p>
HTML,
            ],
        ],
    ],
];
