<?php
/**
 * La política de privacidad en español (España): el documento completo como datos,
 * renderizado por partials/legal_page.php. Lo carga privacy.php para la página en
 * español. Este archivo refleja el inglés: los mismos ids de sección en el mismo
 * orden, las mismas ramas condicionales y los mismos hechos; solo cambian las
 * palabras. Ambos llevan la misma fecha 'updated' (ISO Y-m-d; legal_page.php la
 * renderiza con i18nDate()).
 *
 * Todo flujo de datos descrito aquí es uno que el código tiene, y la lista debe
 * mantenerse al día con él: la dirección de la cuenta (cifrada en reposo,
 * pii_crypto.php), el correo recibido y su conservación (cron/cleanup.php,
 * MailboxQuota y la pausa de borrado que puede iniciar una cuenta Pro -
 * retention_hold.php), los registros del sistema con direcciones IP
 * (LOG_RETENTION_DAYS), las direcciones personales eliminadas en la lista de
 * reserva (address_cooldown.php, ADDRESS_COOLDOWN_MONTHS), el registro de prueba
 * con hash (pro_trial.php, cinco años), las tablas espejo de Paddle
 * (paddle_sync.php), las integraciones que configura el propio usuario, las
 * aplicaciones que un usuario conecta al endpoint MCP - aprobándolas en la
 * pantalla de consentimiento OAuth (oauth_server.php: el nombre que registró y
 * el host de redirección, más un hash de cada token) o con un token de acceso
 * propio (mcp_tokens.php: solo se guarda un hash del token) -, Google Analytics
 * cargado solo tras el consentimiento en las páginas públicas
 * (partials/analytics.php, sujeto a consentimiento; register.php ya no lo carga),
 * y las cookies de sesión y de navegador de confianza de 2FA
 * (TwoFactorAuth::TRUSTED_DEVICE_COOKIE, 30 días), y la cookie de sesión
 * mantenida y sus filas de token (pro_remember.php: los 1/7/30 días elegidos,
 * deslizantes, nunca más de 90 días desde el inicio de sesión).
 * Añadir un encargado del tratamiento o un rastreador significa actualizar esta
 * página en el mismo cambio.
 *
 * Variables que privacy.php define para este archivo:
 *   $f     array    los datos legales (partials/legal_facts.php), ya escapados
 *   $ref   array    los ajustes del programa de invitaciones (referralSettings())
 * y define los números fijos indicados debajo de la propia guarda.
 *
 * 'html' contiene marcado de confianza escrito aquí, con todo valor interpolado
 * escapado, nunca nada que aporte un visitante. Nunca escribas un número en el
 * texto: viene de $f o de $ref.
 *
 * @return array<string, mixed>
 */

if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

// Números que fija el código en lugar de $config (cron/cleanup.php, pro_remember.php,
// TwoFactorAuth, php_imap_processor.php); se nombran aquí para que ninguno se
// escriba en la prosa. El archivo en sueco declara los mismos nombres.
$ttlMinDays = 1;        // la vida más corta de una dirección Timed en Pro
$ttlMaxDays = 7;        // la más larga
$graceDays = 7;         // las direcciones Sticky de una cuenta Pro caducada se eliminan pasado este plazo
$trustedDays = 30;      // cuánto duran un navegador de confianza y su cookie
$maxSignedInDays = 90;  // mantener la sesión iniciada: el tope contado desde el inicio de sesión
$stayDays = [1, 7, 30]; // las opciones para mantener la sesión iniciada
$tokenSweepDays = 30;   // un token de acceso revocado o caducado se elimina pasado este plazo
$pushoverChars = '4.000'; // el extracto que recibe Pushover, escrito para este idioma
$abuseEventDays = 90;   // el registro de pausas, avisos y otras medidas de protección
$minAge = 16;           // la edad por debajo de la cual Mail Shield no está pensado para menores

return [
    'title'       => 'Política de privacidad',
    'lede'        => 'Mail Shield existe para mantener tu dirección principal fuera de los lugares donde no tiene por qué estar. Esta página explica qué recogemos para lograrlo, por qué, cuánto tiempo lo conservamos y quién más lo ve.',
    'description' => 'Qué datos personales recoge Mail Shield, por qué, cuánto tiempo se conservan y quién los trata, incluidos los pagos gestionados por Paddle.',
    'path'        => '/privacy.php',
    'updated'     => '2026-10-11',
    'sections'    => [
        [
            'id'      => 'controller',
            'heading' => 'Quién es el responsable',
            'html'    => '<p>El responsable del tratamiento es ' . $f['company_full'] . ', que opera Mail Shield. Las preguntas sobre tus datos y las solicitudes para ejercer los derechos descritos más abajo se dirigen a ' . $f['contact'] . '.</p>'
                . '<p>En las compras, Paddle actúa como Merchant of Record y es el responsable del tratamiento de los datos de pago que recoge; consulta <a href="#payments">Pagos</a>.</p>',
        ],
        [
            'id'      => 'data',
            'heading' => 'Qué recogemos',
            'html'    => '<h3>Tu cuenta</h3>'
                . '<p>La dirección de correo con la que te registras y un hash de tu contraseña, nunca la contraseña en sí. Si activas la autenticación en dos pasos, guardamos su secreto cifrado y un registro de los navegadores que decides marcar como de confianza. Registramos cuándo se creó la cuenta y cuándo iniciaste sesión por última vez, y tus ajustes: plan, duración de las direcciones, resúmenes (digest) e integraciones. Si eliges un idioma, o te registras o inicias sesión en una página en otro idioma, la cuenta guarda esa preferencia de idioma.</p>'
                . '<p>Tu dirección de correo registrada se guarda cifrada y se busca mediante un hash con clave, no en texto plano.</p>'
                . '<h3>El correo que recibes</h3>'
                . '<p>Los mensajes enviados a tus direcciones de Mail Shield (cabeceras, contenido y archivos adjuntos) se almacenan para que puedas leerlos. Las personas de Mail Shield no miran tu correo como parte del funcionamiento del servicio, y nunca se usa para publicidad ni para elaborar perfiles. Se trata de forma automática para almacenarlo, mostrártelo y pasarlo a las integraciones que hayas configurado. Solo accedemos a un mensaje concreto cuando es necesario para investigar un abuso o un incidente de seguridad, para ayudarte cuando nos lo pides o cuando lo exige la ley.</p>'
                . '<h3>Aplicaciones conectadas</h3>'
                . '<p>Puedes conectar una aplicación o un asistente de IA a tu cuenta: bien iniciando sesión y aprobando la aplicación cuando lo solicita, bien creando un token de acceso y dándoselo a la aplicación. En ambos casos solo guardamos un hash unidireccional de la credencial (nunca el token en sí), junto con el acceso que concediste (solo lectura, o lectura y modificación) y cuándo se usó por última vez. Un token que hayas creado tú mismo lleva además el nombre que le diste y la caducidad que elegiste.</p>'
                . '<p>Para una aplicación que apruebas en la pantalla de inicio de sesión guardamos el nombre que la aplicación se dio a sí misma, el host de la dirección a la que pidió que se la devolviera y cuándo la conectaste. La propia aplicación aporta esos datos al registrarse; son lo que te muestra la tarjeta de Aplicaciones conectadas, para que puedas distinguir una aplicación de otra y desconectarla.</p>'
                . '<h3>Invitaciones</h3>'
                . '<p>Cuando te registras a través de un enlace de invitación, guardamos qué cuenta te invitó y el estado de la invitación, con sus fechas y, cuando compras Pro, el plan y la suscripción o transacción de Paddle a la que se refiere. El código de invitación se conserva en una cookie y en tu nueva cuenta hasta que verificas tu dirección. La persona que te invitó solo ve recuentos, nunca quién eres. El registro contiene números de cuenta, no direcciones de correo, y permanece cuando se elimina cualquiera de las dos cuentas. Además, cada cuenta tiene su propio código de invitación.</p>'
                . '<h3>Datos técnicos</h3>'
                . '<p>Los registros de nuestro servidor anotan eventos como inicios de sesión, errores y solicitudes sospechosas, con la dirección IP implicada. Los intentos de inicio de sesión se registran para evitar que se adivinen contraseñas. No escribimos en los registros tu dirección de correo registrada. En las páginas con sesión iniciada y en la bandeja de entrada se lee el ajuste de idioma de tu navegador para elegir el idioma de la interfaz; no se guarda.</p>'
                . '<p>Para proteger las direcciones frente a avalanchas de correo y el servicio frente a usos indebidos, contamos cuántos mensajes y cuántos bytes recibe cada dirección, cuántas direcciones se crean desde cada dirección IP y cada cuenta, y cuántas notificaciones envía cada integración. Cuando se alcanza un límite, pausamos la dirección o la integración durante un tiempo y te avisamos, y guardamos un registro de esa medida.</p>'
                . '<h3>Registro de prueba</h3>'
                . '<p>Para dar el periodo de prueba gratuito de Pro solo una vez a cada dirección de correo, guardamos un hash con clave de la dirección normalizada. No está vinculado a tu cuenta y no puede volver a convertirse en la dirección.</p>'
                . '<h3>Pagos</h3>'
                . '<p>Cuando compras Pro, Paddle nos comunica tu ID de cliente, la dirección de correo con la que pagaste y el estado de tu suscripción y de tus transacciones, para que podamos dar Pro a tu cuenta. Nunca recibimos ni guardamos los datos de tu tarjeta.</p>'
                . '<h3>Soporte</h3>'
                . '<p>Si te pones en contacto con nosotros, conservamos la conversación para poder responderte.</p>',
        ],
        [
            'id'      => 'purposes',
            'heading' => 'Por qué, y con qué base jurídica',
            'html'    => '<ul>'
                . '<li><strong>Prestar el servicio al que te registraste</strong>: tu cuenta, tus direcciones, tu correo, tus integraciones y tu plan. Base jurídica: la ejecución de nuestro contrato contigo (artículo 6.1.b del RGPD).</li>'
                . '<li><strong>Mantener la seguridad del servicio</strong>: registros, límites de inicio de sesión, detección de abusos y el registro de prueba. Base jurídica: nuestro interés legítimo en proteger Mail Shield y a sus usuarios frente a usos indebidos (artículo 6.1.f).</li>'
                . '<li><strong>Contabilidad</strong>: registros de pago. Base jurídica: nuestra obligación legal conforme a la ley sueca de contabilidad (artículo 6.1.c).</li>'
                . '<li><strong>Entender cómo se usan las páginas públicas</strong>: Google Analytics, solo después de que lo aceptes en el aviso de cookies. Base jurídica: tu consentimiento (artículo 6.1.a del RGPD), solicitado antes de instalar cualquier cookie de analítica; rechazarlo no cambia nada más en el sitio.</li>'
                . '<li><strong>Informarte sobre tu cuenta</strong>: verificación, enlaces de inicio de sesión, avisos de seguridad, avisos de inactividad y cambios en nuestros términos. Son mensajes del servicio, no de marketing; no enviamos boletines.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'retention',
            'heading' => 'Cuánto tiempo lo conservamos',
            'html'    => '<ul>'
                . '<li><strong>Direcciones Timed y su correo</strong>: ' . $f['free_hours'] . ' horas en una cuenta gratuita, o entre ' . $ttlMinDays . ' y ' . $ttlMaxDays . ' días a tu elección en Pro. Después se eliminan automáticamente.</li>'
                . '<li><strong>Direcciones Sticky</strong>: hasta que las elimines, o ' . $graceDays . ' días después de que termine tu plan Pro. Su correo se elimina con ellas.</li>'
                . '<li><strong>Correo que decides conservar más tiempo</strong>: una cuenta Pro puede pausar la limpieza del correo de sus direcciones Sticky; hasta ' . $f['hold_max'] . ' veces al año, una pausa amplía ese correo hasta ' . $f['hold_days'] . ' días desde el día en que llegó. Terminar una pausa antes de tiempo deja el correo ya ampliado como está, y una dirección Timed siempre caduca según su propio calendario. El límite de almacenamiento indicado a continuación sigue aplicándose.</li>'
                . '<li><strong>Cualquier correo, antes de que caduque</strong>: se aplica una cuota de almacenamiento a tu cuenta (o a cada dirección, si no está vinculada a una cuenta): ' . $f['quota_free_mb'] . ' MB en una cuenta gratuita y ' . $f['quota_pro_mb'] . ' MB en Pro, con la cifra de Pro también durante el periodo de prueba. Cuando se llena, se elimina el correo más antiguo de ese ámbito para hacer sitio, sea cual sea la dirección en la que esté. La cuota se comprueba cuando llega un mensaje, así que una cuenta que pasa a gratuita (porque termina un plan o un periodo de prueba) se recorta, empezando por lo más antiguo, a medida que entra correo nuevo.</li>'
                . '<li><strong>Direcciones Sticky eliminadas</strong>: la dirección en sí (no su correo) se conserva en una lista reservada durante ' . $f['cooldown_months'] . ' meses tras eliminarla, vinculada al número de tu cuenta, para que nadie más pueda quedársela y recibir correo destinado a ti. Esto también se aplica después de que se elimine tu cuenta.</li>'
                . '<li><strong>Tu cuenta</strong>: hasta que la elimines. Una cuenta Regular gratuita en la que nadie inicia sesión durante ' . $f['delete_days'] . ' días se elimina, tras un aviso por correo.</li>'
                . '<li><strong>Registros del servidor</strong>: ' . $f['log_days'] . ' días.</li>'
                . '<li><strong>Contadores de abuso</strong> (mensajes por dirección, direcciones nuevas por dirección IP y cuenta): dos días. El registro de pausas, avisos y otras medidas de protección: ' . $abuseEventDays . ' días.</li>'
                . '<li><strong>Navegadores de confianza para la autenticación en dos pasos</strong>: ' . $trustedDays . ' días, o hasta que los elimines.</li>'
                . '<li><strong>Dispositivos en los que mantienes la sesión iniciada</strong> (el nombre de un navegador y de un sistema operativo, y cuándo se usó por última vez): el periodo que elegiste, contado desde la última visita y nunca más de ' . $maxSignedInDays . ' días después de iniciar sesión, o hasta que elimines el dispositivo, cierres sesión en él o cambies tu contraseña o tu dirección de correo.</li>'
                . '<li><strong>Tokens de acceso que creaste tú mismo</strong>: hasta que los revoques, o hasta la caducidad que elegiste. Un token revocado o caducado se elimina ' . $tokenSweepDays . ' días después. Cambiar tu contraseña o tu dirección de correo, o eliminar tu cuenta, los revoca todos a la vez.</li>'
                . '<li><strong>Aplicaciones que conectaste iniciando sesión</strong>: hasta que las desconectes, o ' . $f['grant_idle_days'] . ' días después de que la aplicación usara su acceso por última vez, lo que ocurra antes. Un inicio de sesión que empezaste pero nunca aprobaste caduca a los ' . $f['code_seconds'] . ' segundos, y una aplicación que se registró por sí misma y nunca se usó se elimina a los ' . $f['client_idle_days'] . ' días. Desconectar una aplicación, cambiar tu contraseña o tu dirección de correo, o eliminar tu cuenta les pone fin de inmediato.</li>'
                . '<li><strong>Registro de prueba</strong>: cinco años desde la primera vez que se usó la dirección.</li>'
                . '<li><strong>Registros de pago</strong>: siete años, como exige la ley sueca de contabilidad.</li>'
                . '</ul>',
        ],
        [
            'id'      => 'sharing',
            'heading' => 'Quién más trata tus datos',
            'html'    => '<p>No vendemos tus datos ni los compartimos con anunciantes. Los tratan estas partes, y solo por los motivos indicados:</p>'
                . '<ul>'
                . '<li><strong>Nuestro proveedor de alojamiento, ' . $f['hosting'] . '</strong>, que gestiona los servidores que reciben, almacenan y sirven tu correo, como nuestro encargado del tratamiento en virtud de un contrato de encargo del tratamiento. Tu cuenta y tu correo se almacenan en Suecia.</li>'
                . '<li><strong>Paddle</strong> (Paddle.com Market Ltd), para los pagos; consulta más abajo.</li>'
                . '<li><strong>Google</strong> (Google Ireland Ltd), para Google Analytics en nuestras páginas públicas, si aceptas la analítica.</li>'
                . '<li><strong>Los destinos que elijas</strong>, cuando configuras una integración:'
                . '<ul>'
                . '<li><strong>Webhook:</strong> el remitente, la dirección del destinatario, el asunto, el cuerpo completo del mensaje y la hora a la que llegó. No se envían los archivos adjuntos, solo cuántos hay.</li>'
                . '<li><strong>Pushover:</strong> el asunto y un extracto en texto plano del cuerpo (hasta unos ' . $pushoverChars . ' caracteres), con la dirección del destinatario como título, enviado a Pushover y de ahí a tus dispositivos.</li>'
                . '<li><strong>Feed RSS:</strong> el remitente, el asunto, la fecha y el cuerpo completo del mensaje, con enlaces a los archivos adjuntos, disponible para cualquiera que tenga el enlace del feed.</li>'
                . '<li><strong>Una aplicación o asistente de IA que conectes</strong>, ya la hayas aprobado en la pantalla de inicio de sesión o le hayas dado un token de acceso: tus direcciones y el correo que solicite (remitente, asunto, cuerpo y enlaces a los archivos adjuntos). A diferencia de las integraciones anteriores, esa aplicación lo obtiene ella misma, y lo que haga con ello es asunto entre tú y quien la gestione.</li>'
                . '</ul>'
                . 'Esos servicios los eliges tú y tratan los datos conforme a sus propios términos. Un asistente de IA que conectes no es diferente: su proveedor responde ante ti, no ante nosotros, y no es nuestro encargado del tratamiento.</li>'
                . '<li><strong>Autoridades</strong>, cuando la ley nos obliga a comunicar datos.</li>'
                . '</ul>'
                . '<p>Un correo de resumen (digest) no se comparte con nadie más: enumera el remitente, el asunto y la hora de tus mensajes pendientes y va a tu propia dirección registrada.</p>',
        ],
        [
            'id'      => 'automated-limits',
            'heading' => 'Límites automáticos',
            'html'    => '<p>Unos límites automáticos protegen las direcciones y el servicio: cuando se alcanza uno, una dirección o una integración se pausa durante un tiempo y se te avisa. Estas pausas son temporales. La suspensión de una cuenta siempre la decide una persona. No tomamos decisiones basadas únicamente en un tratamiento automatizado que produzcan efectos jurídicos o te afecten significativamente de modo similar (artículo 22 del RGPD). Si crees que un límite te ha afectado por error, <a href="#controller">ponte en contacto con nosotros</a>.</p>',
        ],
        [
            'id'      => 'payments',
            'heading' => 'Los pagos los procesa Paddle',
            'html'    => '<p>Nuestro proceso de pedido lo gestiona nuestro revendedor en línea Paddle.com. Paddle.com es el Merchant of Record de todos nuestros pedidos. Cuando pagas, entregas tus datos de pago, tu nombre, tu país y tu dirección de correo a Paddle, no a nosotros, y Paddle los trata como responsable independiente conforme a la <a href="https://www.paddle.com/legal/privacy" rel="noopener">política de privacidad de Paddle</a>.</p>'
                . '<p>La página de precios y la sección de planes de la página principal cargan el script de Paddle para mostrar los precios en tu moneda, y el proceso de pago lo usa para abrirse. Al cargarlo, tu navegador contacta con Paddle, que entonces ve tu dirección IP; Paddle puede instalar allí sus propias cookies.</p>',
        ],
        [
            'id'      => 'cookies',
            'heading' => 'Cookies',
            'html'    => '<ul>'
                . '<li><strong>Una cookie de sesión</strong>, que mantiene tu sesión iniciada. Es necesaria para el servicio y termina cuando cierras sesión o cierras la sesión del navegador.</li>'
                . '<li><strong>Una cookie de navegador de confianza</strong>, que se instala solo si decides que se recuerde un navegador para la autenticación en dos pasos. Dura ' . $trustedDays . ' días.</li>'
                . '<li><strong>Una cookie para mantener la sesión iniciada</strong>, que se instala solo si decides mantener la sesión iniciada en un dispositivo. Dura el periodo que elegiste (' . $stayDays[0] . ', ' . $stayDays[1] . ' o ' . $stayDays[2] . ' días desde tu última visita, como máximo ' . $maxSignedInDays . ' días) y se elimina cuando cierras sesión.</li>'
                . '<li><strong>Cookies de Google Analytics</strong>, que se instalan solo en las páginas públicas (nunca en la bandeja de entrada, tu cuenta ni las páginas de inicio de sesión) y solo después de que pulses Aceptar en el aviso de cookies. Usa Ajustes de cookies en el pie de página para retirar tu consentimiento; eso evita que se instalen estas cookies en páginas posteriores y elimina las ya instaladas.</li>'
                . '<li><strong>Una cookie de invitación, <code>ms_ref</code></strong>, que se instala solo cuando sigues un enlace de invitación mientras el programa de invitaciones está abierto. Contiene el código de invitación, dura hasta ' . $ref['cookie_days'] . ' días y se elimina cuando verificas tu cuenta.</li>'
                . '<li><strong>Cookies de Paddle</strong> en la página principal, en la página de precios y en el proceso de pago.</li>'
                . '<li><strong>Tu elección sobre las cookies</strong> se recuerda en el almacenamiento local de tu navegador, no en una cookie, para que podamos respetarla; es estrictamente necesaria y no se nos envía.</li>'
                . '</ul>'
                . '<p>Puedes bloquear o eliminar las cookies en tu navegador. Si bloqueas la cookie de sesión, no podrás iniciar sesión. No se usa ninguna cookie para el idioma.</p>',
        ],
        [
            'id'      => 'transfers',
            'heading' => 'Transferencias fuera de la UE',
            'html'    => '<p>Mail Shield en sí está alojado en Suecia, y tu correo se almacena allí. Solo Google y Paddle tratan datos fuera de la UE y del EEE, incluidos Estados Unidos y el Reino Unido. Cuando lo hacen, la transferencia está amparada por una decisión de adecuación (como el Marco de Privacidad de Datos UE-EE. UU. o la decisión relativa al Reino Unido) o por las cláusulas contractuales tipo de la UE.</p>',
        ],
        [
            'id'      => 'security',
            'heading' => 'Cómo lo protegemos',
            'html'    => '<p>Las conexiones con Mail Shield están cifradas. Tu dirección registrada, los secretos de la autenticación en dos pasos y las credenciales de tus integraciones (secretos y cabeceras de webhook, claves de Pushover y enlaces de feed) están cifrados en reposo; las contraseñas y los tokens de acceso de las aplicaciones conectadas se guardan como hash en lugar de tal cual. Los archivos adjuntos solo se sirven mediante enlaces firmados que caducan. El acceso a los datos de producción está limitado a las personas que gestionan el servicio.</p>'
                . '<p>Ten presente que el correo electrónico en sí normalmente no está cifrado de extremo a extremo, y que un enlace de bandeja de entrada compartido puede leerlo cualquiera que lo tenga.</p>',
        ],
        [
            'id'      => 'rights',
            'heading' => 'Tus derechos',
            'html'    => '<p>Conforme al RGPD, tienes derecho a acceder a los datos personales que conservamos sobre ti, a que se rectifiquen o se supriman, a limitar su tratamiento o a oponerte a él, y a recibirlos en un formato portable. Cuando nos basamos en tu consentimiento, puedes retirarlo en cualquier momento. Cuando nos basamos en nuestro interés legítimo, tienes derecho a oponerte a ese tratamiento.</p>'
                . '<p>Algunos de estos derechos tienen límites. Los registros de pago se conservan siete años conforme a la ley sueca de contabilidad, incluso después de que se elimine tu cuenta, y los datos necesarios para gestionar un incidente de seguridad o una reclamación legal pueden conservarse mientras estos duren.</p>'
                . '<p>Gran parte de esto puedes hacerlo tú mismo: tu perfil muestra los datos de tu cuenta, y eliminar tu cuenta los suprime, salvo lo que debemos conservar por motivos contables. Para cualquier otra cosa, escribe a ' . $f['contact'] . '. Respondemos en el plazo de un mes.</p>'
                . '<p>Si crees que tratamos tus datos de forma indebida, puedes presentar una reclamación ante la Autoridad Sueca de Protección de Datos (Integritetsskyddsmyndigheten, <a href="https://www.imy.se" rel="noopener">imy.se</a>) o ante la autoridad del lugar donde vives.</p>',
        ],
        [
            'id'      => 'children',
            'heading' => 'Menores',
            'html'    => '<p>Mail Shield no está pensado para menores de ' . $minAge . ' años, y no recogemos datos suyos a sabiendas.</p>',
        ],
        [
            'id'      => 'changes',
            'heading' => 'Cambios en esta política',
            'html'    => '<p>Cuando esta política cambia, actualizamos la fecha de la parte superior. Si el cambio es importante, te lo comunicamos por correo antes de que entre en vigor.</p>',
        ],
    ],
];
